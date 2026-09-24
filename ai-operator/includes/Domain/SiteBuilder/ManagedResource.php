<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * A stable identity for something the Site Builder created, so a later run
 * can recognise its own work instead of duplicating it.
 *
 * ## Why not match on the title
 *
 * The obvious implementation -- "find the page called Kontakt and reuse
 * it" -- is wrong in both directions. Titles are editable, so a user who
 * renames "Kontakt" to "Napisz do nas" gets a second contact page on the
 * next run. Titles are also not unique and not ours, so matching one can
 * silently adopt a page a human wrote by hand and then overwrite it.
 *
 * So identity is explicit metadata written at creation time: a resource
 * KEY naming the logical role ("page:contact"), scoped to a project, plus
 * a content fingerprint taken when we last wrote it.
 *
 * ## The fingerprint is what makes human edits visible
 *
 * Without it, "we created this page" and "this page still looks the way we
 * left it" are the same claim, and a rebuild would quietly discard whatever
 * the user has since written. Storing a hash of the content we wrote lets
 * reconciliation tell the difference between a page we may safely rewrite
 * and one a human has taken over.
 *
 * The key is derived locally from the blueprint's own structure. It is
 * never supplied by the model -- see BlueprintGeneratorInterface on why
 * model output may describe intent but never internal identifiers.
 */
final class ManagedResource {

	public const TYPE_PAGE     = 'page';
	public const TYPE_MENU     = 'menu';
	public const TYPE_FORM     = 'form';
	public const TYPE_NAV      = 'navigation';
	public const TYPE_TERM     = 'term';
	public const TYPE_PRODUCT  = 'product';
	public const TYPE_MEDIA    = 'media';
	public const TYPE_PRODUCT_CATEGORY = 'product_category';

	private const VALID_TYPES = array(
		self::TYPE_PAGE,
		self::TYPE_MENU,
		self::TYPE_FORM,
		self::TYPE_NAV,
		self::TYPE_TERM,
		self::TYPE_PRODUCT,
		self::TYPE_MEDIA,
		self::TYPE_PRODUCT_CATEGORY,
	);

	/**
	 * Types stored as TAXONOMY TERMS rather than posts.
	 *
	 * The resolver has to look these up through term meta, and a resource
	 * type landing in the wrong family would silently find nothing and
	 * duplicate on every run.
	 *
	 * @var string[]
	 */
	private const TERM_TYPES = array( self::TYPE_TERM, self::TYPE_PRODUCT_CATEGORY );

	public function isTerm(): bool {
		return in_array( $this->type, self::TERM_TYPES, true );
	}

	private function __construct(
		public readonly string $type,
		public readonly string $slug
	) {
	}

	/**
	 * Builds a key like `page:kontakt`.
	 *
	 * The slug is derived from the blueprint role, normalised so that
	 * "O nas" and "o nas" are the same logical resource, and bounded so a
	 * pathological blueprint cannot produce an unusable meta key.
	 *
	 * @throws \InvalidArgumentException on an unknown type
	 */
	public static function forRole( string $type, string $role ): self {
		if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown managed resource type "%s".', $type ) );
		}

		return new self( $type, self::slugify( $role ) );
	}

	/** @throws \InvalidArgumentException on a malformed key */
	public static function fromKey( string $key ): self {
		$parts = explode( ':', $key, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[1] ) {
			throw new \InvalidArgumentException( sprintf( 'Malformed managed resource key "%s".', $key ) );
		}

		return self::forRole( $parts[0], $parts[1] );
	}

	/** True when the string is a key this class would itself produce. */
	public static function isValidKey( string $key ): bool {
		try {
			return self::fromKey( $key )->key() === $key;
		} catch ( \InvalidArgumentException ) {
			return false;
		}
	}

	public function key(): string {
		return $this->type . ':' . $this->slug;
	}

	/**
	 * A stable fingerprint of content we wrote.
	 *
	 * Normalised against the reformatting WordPress performs on save, so a
	 * byte we did not choose does not read as a human edit.
	 *
	 * Two rules, both from watching real saves rather than guessing:
	 *
	 * - Runs of whitespace collapse, because WordPress adds and removes
	 *   newlines freely.
	 * - `<hr/>` and `<hr />` are the same tag. WordPress inserts that space
	 *   when it balances tags, which made every page containing a separator
	 *   block differ from itself by exactly one byte -- enough to plan a
	 *   rewrite of that page on every single run.
	 *
	 * Neither rule can make two meaningfully different documents collide:
	 * both only erase whitespace nobody authored.
	 */
	public static function fingerprint( string $content ): string {
		$normalised = (string) preg_replace( '/\s+/u', ' ', $content );
		$normalised = (string) preg_replace( '/\s*\/>/', '/>', $normalised );

		return hash( 'sha256', trim( $normalised ) );
	}

	private static function slugify( string $role ): string {
		$role = mb_strtolower( trim( $role ) );

		// Transliterate the Polish characters this product sees constantly,
		// so "O nas" and "Realizacje" produce clean, stable keys.
		$role = strtr(
			$role,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
			)
		);

		$role = (string) preg_replace( '/[^a-z0-9]+/', '-', $role );
		$role = trim( $role, '-' );

		if ( '' === $role ) {
			// Never produce an empty slug: an empty key would collide with
			// every other empty one and make unrelated resources look like
			// the same thing.
			$role = 'resource';
		}

		return substr( $role, 0, 60 );
	}
}
