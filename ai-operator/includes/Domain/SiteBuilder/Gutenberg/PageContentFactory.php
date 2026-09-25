<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg;

use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;

/**
 * Picks which curated sections a given page is composed of, and fills them
 * with copy derived from the blueprint.
 *
 * This is the "curated patterns" layer: the set of page shapes is fixed and
 * known-good, and the blueprint only chooses among them and supplies text.
 * A homepage gets a hero, a services grid and a call to action; a contact
 * page gets contact details and a form slot. That is a small, boring space
 * of outcomes -- which is the point, because every one of them is a
 * structure that has been checked to survive parse_blocks() and stay
 * editable afterwards.
 *
 * The copy here is intentionally plain and factual rather than florid
 * marketing prose. It is scaffolding the user is expected to edit, and text
 * that overclaims ("award-winning", "trusted by thousands") in a generated
 * draft is worse than text that is merely neutral -- it makes false claims
 * on the site owner's behalf.
 *
 * All strings are translatable (English source, Polish bundled as the
 * plugin's own translation): the copy this class writes becomes real
 * content on the user's site, so a Polish install should still get the
 * same Polish copy it always has, and an English install gets English.
 */
final class PageContentFactory {

	public function __construct( private BlockComposer $composer ) {
	}

	public function forPage( SiteBlueprint $blueprint, string $pageTitle, bool $isHome ): string {
		$sections = $isHome
			? $this->homeSections( $blueprint )
			: $this->innerSections( $blueprint, $pageTitle );

		return $this->composer->compose( $sections );
	}

	/** @return array<int, array<string, mixed>> */
	private function homeSections( SiteBlueprint $blueprint ): array {
		$name = $blueprint->businessName;

		$sections = array(
			array(
				'type'      => 'hero',
				'title'     => $name,
				'subtitle'  => '' !== $blueprint->description
					? $blueprint->description
					: sprintf(
						/* translators: %s: business name */
						__( '%s — professional services for individual and business clients.', 'dosieci-ai-operator' ),
						$name
					),
				'cta_label' => __( 'Get in touch', 'dosieci-ai-operator' ),
				'cta_url'   => '#kontakt',
			),
		);

		$services = $this->serviceItems( $blueprint );

		if ( array() !== $services ) {
			$sections[] = array(
				'type'  => 'services',
				'title' => __( 'What we do', 'dosieci-ai-operator' ),
				'items' => $services,
			);
		}

		$sections[] = array( 'type' => 'separator' );

		$sections[] = array(
			'type'      => 'cta',
			'title'     => __( 'Need a quote?', 'dosieci-ai-operator' ),
			'text'      => __( 'Describe what you need, and we will get back to you with a timeline and a price.', 'dosieci-ai-operator' ),
			'cta_label' => __( 'Write to us', 'dosieci-ai-operator' ),
			'cta_url'   => '#kontakt',
		);

		return $sections;
	}

	/** @return array<int, array<string, mixed>> */
	private function innerSections( SiteBlueprint $blueprint, string $pageTitle ): array {
		$normalised = mb_strtolower( $pageTitle );

		if ( str_contains( $normalised, 'kontakt' ) || str_contains( $normalised, 'contact' ) ) {
			return array(
				array( 'type' => 'heading', 'text' => __( 'Contact', 'dosieci-ai-operator' ), 'level' => 1 ),
				array(
					'type'  => 'contact',
					'title' => __( 'Write or call us', 'dosieci-ai-operator' ),
					'text'  => sprintf(
						/* translators: %s: business name */
						__( 'We are happy to answer any questions about %s’s services.', 'dosieci-ai-operator' ),
						$blueprint->businessName
					),
				),
			);
		}

		if ( str_contains( $normalised, 'oferta' ) || str_contains( $normalised, 'usług' )
			|| str_contains( $normalised, 'offer' ) || str_contains( $normalised, 'services' ) || str_contains( $normalised, 'pricing' )
		) {
			$items = $this->serviceItems( $blueprint );

			return array(
				array( 'type' => 'heading', 'text' => $pageTitle, 'level' => 1 ),
				array(
					'type' => 'paragraph',
					'text' => __( 'Below is an overview of our services. Every quote is prepared individually.', 'dosieci-ai-operator' ),
				),
				array( 'type' => 'services', 'items' => $items ),
			);
		}

		if ( str_contains( $normalised, 'o nas' ) || str_contains( $normalised, 'o firmie' )
			|| str_contains( $normalised, 'about' ) || str_contains( $normalised, 'team' )
		) {
			return array(
				array( 'type' => 'heading', 'text' => $pageTitle, 'level' => 1 ),
				array(
					'type' => 'paragraph',
					'text' => sprintf(
						/* translators: %s: business name */
						__( '%s is a team that values reliability and punctuality. This section is waiting for your own description — add the company’s history, experience and what sets you apart.', 'dosieci-ai-operator' ),
						$blueprint->businessName
					),
				),
			);
		}

		// Generic inner page: a heading and an honest placeholder, rather
		// than invented content presented as if the owner wrote it.
		return array(
			array( 'type' => 'heading', 'text' => $pageTitle, 'level' => 1 ),
			array(
				'type' => 'paragraph',
				'text' => sprintf(
					/* translators: %s: page title */
					__( 'This page (“%s”) is waiting for content. You can fill it in now in the editor.', 'dosieci-ai-operator' ),
					$pageTitle
				),
			),
		);
	}

	/** @return array<int, array<string, string>> */
	private function serviceItems( SiteBlueprint $blueprint ): array {
		return match ( $blueprint->siteType ) {
			SiteBlueprint::TYPE_SERVICE_BUSINESS => array(
				array(
					'title' => __( 'Fast turnaround', 'dosieci-ai-operator' ),
					'text'  => __( 'We take on urgent jobs and agree a firm date.', 'dosieci-ai-operator' ),
				),
				array(
					'title' => __( 'Experienced team', 'dosieci-ai-operator' ),
					'text'  => __( 'We work to established standards and best practice.', 'dosieci-ai-operator' ),
				),
				array(
					'title' => __( 'Transparent pricing', 'dosieci-ai-operator' ),
					'text'  => __( 'You know the cost before work starts — no surprises.', 'dosieci-ai-operator' ),
				),
			),
			SiteBlueprint::TYPE_RESTAURANT => array(
				array(
					'title' => __( 'Fresh ingredients', 'dosieci-ai-operator' ),
					'text'  => __( 'Our menu is built around what is seasonal and local.', 'dosieci-ai-operator' ),
				),
				array(
					'title' => __( 'A place to meet', 'dosieci-ai-operator' ),
					'text'  => __( 'A dining room and reservations for larger groups.', 'dosieci-ai-operator' ),
				),
			),
			SiteBlueprint::TYPE_PORTFOLIO => array(
				array(
					'title' => __( 'Selected work', 'dosieci-ai-operator' ),
					'text'  => __( 'An overview of projects we have worked on.', 'dosieci-ai-operator' ),
				),
				array(
					'title' => __( 'Process', 'dosieci-ai-operator' ),
					'text'  => __( 'How working together looks, step by step.', 'dosieci-ai-operator' ),
				),
			),
			default => array(),
		};
	}
}
