<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResourceRecorderInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;

/**
 * Stamps ownership onto what a step just produced, so the next run
 * recognises it instead of building a duplicate.
 *
 * The object id comes from the step's own recorded RESULT, never from the
 * plan's arguments: a create_post does not know its id until WordPress
 * assigns one. The fingerprint is taken from the content we actually
 * wrote, which is what later lets the resolver distinguish our own work
 * from a human's edits.
 *
 * Runs only after the write succeeded AND verification passed -- claiming
 * a resource that does not exist would make the next run refuse to create
 * it.
 */
final class WpManagedResourceRecorder implements ManagedResourceRecorderInterface {

	public function __construct( private WpManagedResourceResolver $resolver ) {
	}

	public function record( PlanAction $action, string $projectId, array $result ): void {
		if ( '' === $action->managedResourceKey || ! ManagedResource::isValidKey( $action->managedResourceKey ) ) {
			return;
		}

		$objectId = $this->objectIdFrom( $result );

		if ( null === $objectId ) {
			return;
		}

		$resource = ManagedResource::fromKey( $action->managedResourceKey );

		// Fingerprint what WordPress actually stored, not what we asked it
		// to store: wp_kses_post() and friends may alter the content, and a
		// fingerprint of the pre-sanitised text would look like a human edit
		// on the very next run.
		//
		// A term has no post_content. Reading get_post() for a term id would
		// pick up whatever unrelated post shares that number, so the two
		// families are read from their own tables.
		$content = match ( true ) {
			$resource->isTerm() => $this->termContent( $objectId, $resource ),
			// A product's meaningful state is not its post_content: the price
			// lives in meta. Fingerprinting post_content while the resolver
			// compares a canonical product string meant the two never agreed,
			// and every product read as hand-edited from the second run on.
			// One concept, one spelling, one place -- the same lesson the
			// contact page and the term lookup each taught once.
			ManagedResource::TYPE_PRODUCT === $resource->type => $this->productContent( $objectId ),
			default => $this->postContent( $objectId ),
		};

		// Steps that AUTHOR the content carry it in their arguments; steps
		// that merely write to the resource (embedding a form into a page
		// somebody else's step wrote) do not. Only the former may update the
		// record of what the planner asked for -- otherwise the contact page
		// looks different from its own plan on every subsequent run.
		$authored = isset( $action->arguments['content'] ) && is_string( $action->arguments['content'] )
			? $action->arguments['content']
			: null;

		$this->resolver->markManaged(
			$resource,
			$projectId,
			$objectId,
			$content,
			$authored
		);
	}

	private function productContent( int $objectId ): string {
		$product = ( new \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter() )->readProduct( $objectId );

		if ( null === $product ) {
			return '';
		}

		return \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter::productContent(
			$product['name'],
			$product['description'],
			$product['short'],
			$product['price'],
			$product['categories'],
			$product['image_id']
		);
	}

	private function postContent( int $objectId ): string {
		$stored = get_post( $objectId );

		return $stored instanceof \WP_Post ? $stored->post_content : '';
	}

	/**
	 * A term's name and description, in the SAME spelling the resolver
	 * compares against. Two independent formulations of "name plus
	 * description" would mismatch forever -- the lesson the contact page
	 * fingerprint already taught once.
	 */
	private function termContent( int $objectId, ManagedResource $resource ): string {
		$taxonomy = WpManagedResourceResolver::taxonomyFor( $resource );
		$term     = '' === $taxonomy ? null : get_term( $objectId, $taxonomy );

		return $term instanceof \WP_Term
			? WpManagedResourceResolver::termContent( $term->name, $term->description )
			: '';
	}

	/** @param array<string, mixed> $result */
	private function objectIdFrom( array $result ): ?int {
		foreach ( array( 'post_id', 'page_id', 'form_id', 'navigation_id', 'term_id', 'menu_id', 'attachment_id' ) as $field ) {
			if ( isset( $result[ $field ] ) && (int) $result[ $field ] > 0 ) {
				return (int) $result[ $field ];
			}
		}

		return null;
	}
}
