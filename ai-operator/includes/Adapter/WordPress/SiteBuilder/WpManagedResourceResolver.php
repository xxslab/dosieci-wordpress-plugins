<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResourceResolverInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\ResourceResolution;

/**
 * Looks up what already exists for a logical resource, using post meta we
 * wrote ourselves rather than guessing from titles.
 *
 * ## The three cases that matter
 *
 * 1. **Nothing exists.** CREATE.
 *
 * 2. **We created it and it still carries our fingerprint.** Safe to
 *    UPDATE_MANAGED if the blueprint now wants different content, or REUSE
 *    if it does not. Nobody else has touched it, so rewriting destroys
 *    nothing.
 *
 * 3. **We created it and the content has since changed.** CONFLICT. The
 *    user has written something there; a rebuild that silently reverted it
 *    would be the single most destructive thing this plugin could do to
 *    someone's site. The plan says so and leaves the page alone.
 *
 * There is a fourth: an UNMANAGED page whose title matches the role. We
 * deliberately do not adopt it. A page a human wrote called "Kontakt" is
 * theirs, and inheriting it would mean a first-ever run could overwrite
 * pre-existing content. Instead the resolver reports it as a conflict so
 * the plan can show it and the user can decide.
 *
 * ## Project scoping
 *
 * Every marker carries the project id. Resources from one Site Builder
 * project are invisible to another, so a second project cannot claim,
 * update or roll back the first one's pages.
 */
final class WpManagedResourceResolver implements ManagedResourceResolverInterface {

	public const META_KEY         = '_dosieci_ai_resource_key';
	public const META_PROJECT     = '_dosieci_ai_project_id';
	public const META_FINGERPRINT = '_dosieci_ai_content_fingerprint';
	public const META_AUTHORED    = '_dosieci_ai_authored_fingerprint';
	public const META_MANAGED     = '_dosieci_ai_managed';

	/**
	 * Which taxonomy a term-shaped resource lives in.
	 *
	 * Kept here rather than on ManagedResource so the domain layer never
	 * has to know a WordPress taxonomy name. A type missing from this map
	 * simply has no term lookup, which is the safe direction: it resolves
	 * to CREATE rather than silently matching the wrong taxonomy.
	 *
	 * @var array<string, string>
	 */
	private const TAXONOMIES = array(
		ManagedResource::TYPE_TERM             => 'category',
		ManagedResource::TYPE_PRODUCT_CATEGORY => 'product_cat',
	);

	/** The taxonomy a term-shaped resource lives in, or '' if it is not one. */
	public static function taxonomyFor( ManagedResource $resource ): string {
		return self::TAXONOMIES[ $resource->type ] ?? '';
	}

	public function resolve( ManagedResource $resource, string $projectId, string $desiredContent ): ResourceResolution {
		if ( $resource->isTerm() ) {
			return $this->resolveTerm( $resource, $projectId, $desiredContent );
		}

		$key      = $resource->key();
		$existing = $this->findManaged( $resource, $projectId );

		if ( null !== $existing ) {
			return $this->resolveManaged( $resource, $existing, $desiredContent );
		}

		// Nothing of ours. Is there an unmanaged page occupying this role?
		if ( ManagedResource::TYPE_PAGE === $resource->type ) {
			$unmanaged = $this->findUnmanagedPageForRole( $resource );

			if ( null !== $unmanaged ) {
				return ResourceResolution::conflict(
					$key,
					$unmanaged,
					sprintf(
						'Na witrynie istnieje już strona o tej nazwie (id %d), której kreator nie tworzył.',
						$unmanaged
					)
				);
			}
		}

		return ResourceResolution::create( $key, 'Brak istniejącego zasobu.' );
	}

	/**
	 * The same four outcomes, for a taxonomy term.
	 *
	 * Terms are not posts and are deliberately not pretended to be: they
	 * carry no post_content, they are found through get_terms rather than
	 * get_posts, and their ownership markers live in term meta. Treating
	 * them as posts would find nothing on every run and duplicate the
	 * category each time.
	 *
	 * What a term's "content" means here is its name plus description --
	 * the only fields the builder writes, and therefore the only ones a
	 * human edit could show up in.
	 */
	private function resolveTerm( ManagedResource $resource, string $projectId, string $desiredContent ): ResourceResolution {
		$key      = $resource->key();
		$taxonomy = self::taxonomyFor( $resource );

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			// The taxonomy is not registered, which on a store build means
			// WooCommerce is not loaded yet. Nothing to reconcile against.
			return ResourceResolution::create( $key, 'Taksonomia nie jest jeszcze dostępna.' );
		}

		$existing = $this->findManagedTerm( $resource, $projectId, $taxonomy );

		if ( null === $existing ) {
			$unmanaged = $this->findUnmanagedTermForRole( $resource, $taxonomy );

			if ( null !== $unmanaged ) {
				// A category the merchant created is theirs. Adopting it on a
				// name match would mean a first-ever run could rewrite or, on
				// rollback, delete something that predates the plugin.
				return ResourceResolution::conflict(
					$key,
					$unmanaged,
					sprintf(
						'Istnieje już kategoria o tej nazwie (id %d), której kreator nie tworzył.',
						$unmanaged
					)
				);
			}

			return ResourceResolution::create( $key, 'Brak istniejącego zasobu.' );
		}

		$term = get_term( $existing, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return ResourceResolution::create( $key, 'Poprzedni zasób został usunięty.' );
		}

		$recorded = (string) get_term_meta( $existing, self::META_FINGERPRINT, true );
		$current  = ManagedResource::fingerprint( self::termContent( $term->name, $term->description ) );

		if ( '' !== $recorded && ! hash_equals( $recorded, $current ) ) {
			return ResourceResolution::conflict(
				$key,
				$existing,
				'Kategoria została zmieniona ręcznie po ostatniej zmianie kreatora.'
			);
		}

		$authored = (string) get_term_meta( $existing, self::META_AUTHORED, true );
		$baseline = '' !== $authored ? $authored : $current;

		if ( hash_equals( $baseline, ManagedResource::fingerprint( $desiredContent ) ) ) {
			return ResourceResolution::reuse( $key, $existing, 'Kategoria jest już zgodna z planem.', true );
		}

		return ResourceResolution::updateManaged( $key, $existing, 'Kategoria utworzona przez kreator, bez ręcznych zmian.' );
	}

	/**
	 * The canonical string a term's fingerprint is taken over.
	 *
	 * Public because the planner has to produce the same string for the
	 * DESIRED side of the comparison, and two independent spellings of
	 * "name plus description" would mismatch forever -- exactly the bug
	 * the contact page taught.
	 */
	public static function termContent( string $name, string $description ): string {
		return trim( $name ) . "\n" . trim( $description );
	}

	private function resolveManaged( ManagedResource $resource, int $postId, string $desiredContent ): ResourceResolution {
		$key  = $resource->key();
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			// Ours once, but gone or trashed. Treat the identity as vacant
			// rather than trying to resurrect it -- an untrash would restore
			// content the user deliberately removed.
			return ResourceResolution::create( $key, 'Poprzedni zasób został usunięty.' );
		}

		$recorded = (string) get_post_meta( $postId, self::META_FINGERPRINT, true );
		$current  = ManagedResource::fingerprint( $post->post_content );

		if ( '' !== $recorded && ! hash_equals( $recorded, $current ) ) {
			// A human has edited what we wrote. Never silently revert it.
			// This compares the STORED fingerprint, so it stays sensitive to
			// any change to the page -- including one to a section a later
			// builder step appended.
			return ResourceResolution::conflict(
				$key,
				$postId,
				'Treść została zmieniona ręcznie po ostatniej zmianie kreatora.'
			);
		}

		// "Is there anything to write?" is a question about what the planner
		// authored, not about what the page ended up containing. A page a
		// later builder step also writes to (the contact page and its form
		// shortcode) legitimately holds more than the planner authored, and
		// comparing against the stored content made it drift forever.
		$authored = (string) get_post_meta( $postId, self::META_AUTHORED, true );
		$baseline = '' !== $authored ? $authored : $current;

		if ( hash_equals( $baseline, ManagedResource::fingerprint( $desiredContent ) ) ) {
			return ResourceResolution::reuse( $key, $postId, 'Treść jest już zgodna z planem.', true );
		}

		return ResourceResolution::updateManaged( $key, $postId, 'Zasób utworzony przez kreator, bez ręcznych zmian.' );
	}

	public function markManaged(
		ManagedResource $resource,
		string $projectId,
		int $objectId,
		string $storedContent,
		?string $authoredContent = null
	): void {
		if ( $objectId <= 0 ) {
			return;
		}

		// Term meta and post meta are different tables. Writing post meta
		// for a term id would silently stamp whatever post happens to share
		// that number, and the term would stay unmanaged and duplicate.
		$write = $resource->isTerm()
			? static fn( string $k, string $v ): mixed => update_term_meta( $objectId, $k, $v )
			: static fn( string $k, string $v ): mixed => update_post_meta( $objectId, $k, $v );

		$write( self::META_MANAGED, '1' );
		$write( self::META_KEY, $resource->key() );
		$write( self::META_PROJECT, $projectId );
		$write( self::META_FINGERPRINT, ManagedResource::fingerprint( $storedContent ) );

		// Left untouched by a step that wrote to the resource without
		// authoring its content, so the embed does not overwrite the record
		// of what the planner actually asked for.
		if ( null !== $authoredContent ) {
			$write( self::META_AUTHORED, ManagedResource::fingerprint( $authoredContent ) );
		}
	}

	/** A term we previously created for this key and project. */
	public function findManagedTerm( ManagedResource $resource, string $projectId, string $taxonomy ): ?int {
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => self::META_KEY, 'value' => $resource->key() ),
					array( 'key' => self::META_PROJECT, 'value' => $projectId ),
				),
			)
		);

		return is_array( $found ) && isset( $found[0] ) ? (int) $found[0] : null;
	}

	/**
	 * A term whose name matches the role and which we do NOT manage.
	 * Reported so the plan can surface it -- never adopted.
	 */
	private function findUnmanagedTermForRole( ManagedResource $resource, string $taxonomy ): ?int {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200 ) );

		if ( ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			// Same normalisation the key uses, so "Koszulki" and "koszulki"
			// are recognised as the same role.
			if ( ManagedResource::forRole( $resource->type, $term->name )->key() !== $resource->key() ) {
				continue;
			}

			if ( '1' === (string) get_term_meta( $term->term_id, self::META_MANAGED, true ) ) {
				// Ours, but from another project. Still not ours to take over.
				continue;
			}

			return (int) $term->term_id;
		}

		return null;
	}

	/** The id of a resource we previously created for this key and project. */
	public function findManaged( ManagedResource $resource, string $projectId ): ?int {
		$found = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => array(
					'relation' => 'AND',
					array( 'key' => self::META_KEY, 'value' => $resource->key() ),
					// Project scoping: another project's identically-keyed
					// resource must not be visible here.
					array( 'key' => self::META_PROJECT, 'value' => $projectId ),
				),
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : null;
	}

	/**
	 * A published page whose title matches the role and which we do NOT
	 * manage. Reported so the plan can surface it -- never adopted.
	 */
	private function findUnmanagedPageForRole( ManagedResource $resource ): ?int {
		$candidates = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => 20,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		foreach ( $candidates as $candidateId ) {
			$post = get_post( (int) $candidateId );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			// Compare on the same normalisation the key uses, so "O nas"
			// and "o-nas" are recognised as the same role.
			if ( ManagedResource::forRole( ManagedResource::TYPE_PAGE, $post->post_title )->key() !== $resource->key() ) {
				continue;
			}

			if ( '1' === (string) get_post_meta( (int) $candidateId, self::META_MANAGED, true ) ) {
				// Ours, but from a different project. Still not ours to
				// take over here.
				continue;
			}

			return (int) $candidateId;
		}

		return null;
	}
}
