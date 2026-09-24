<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\SiteAuditReport;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBuildAuditorInterface;

/**
 * The independent, read-only audit that runs after every step has passed,
 * and decides whether the plan may be called SUCCEEDED.
 *
 * ## Why per-step verification is not enough
 *
 * Per-step verification asks "did step 7 do what step 7 said?". This asks
 * "is the site the blueprint described actually there?" -- a different
 * question that can fail while every step passed. A later step can undo an
 * earlier one, a theme activation hook can reset an option, another plugin
 * can intercept a save. Checking the whole result once, against the
 * BLUEPRINT rather than against the plan, is what catches that class of
 * divergence.
 *
 * Reads only. It runs after the build, on a site the user is about to be
 * told is finished; a "check" with a side effect would be an unapproved
 * write.
 *
 * A failing audit does NOT trigger rollback. The user is shown exactly
 * which expectations were not met and decides -- automatically undoing a
 * mostly-correct site because one check failed would be far more
 * destructive than reporting the mismatch.
 */
final class WpSiteBuildAuditor implements SiteBuildAuditorInterface {

	public function __construct(
		/**
		 * Null on a site with no WooCommerce. A store blueprint then simply
		 * contributes no store checks -- the ordinary site audit is
		 * unchanged, which is what keeps commerce from touching the builds
		 * that never asked for it.
		 */
		private ?\DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooStoreAuditor $store = null,
		private string $projectId = 'default'
	) {
	}

	public function audit( SiteBlueprint $blueprint, int $now ): SiteAuditReport {
		$checks = array();

		$checks[] = $this->checkPages( $blueprint );
		$checks[] = $this->checkGutenberg( $blueprint );
		$checks[] = $this->checkTheme();
		$checks[] = $this->checkHomepage( $blueprint );
		$checks[] = $this->checkSiteTitle( $blueprint );
		$checks[] = $this->checkNavigation( $blueprint );

		if ( $blueprint->hasFeature( 'contact_form' ) ) {
			$checks[] = $this->checkContactFormPlugin();
			$checks[] = $this->checkContactFormEmbedded( $blueprint );
		}

		// The shop is audited independently of the steps that built it: a
		// product created as a draft can be published by something else
		// between step 9 and the end of the run, and "it was a draft when
		// we wrote it" is not the claim that matters to a merchant.
		if ( $blueprint->hasStore() && null !== $this->store ) {
			$checks[] = $this->store->audit( $blueprint->storeBlueprint, $this->projectId );
		}

		// Flatten: some checks return several rows.
		$flat = array();
		foreach ( $checks as $check ) {
			if ( isset( $check['check'] ) ) {
				$flat[] = $check;
				continue;
			}

			foreach ( $check as $row ) {
				$flat[] = $row;
			}
		}

		return SiteAuditReport::fromChecks( $flat, $now );
	}

	/** @return array<string, mixed> */
	private function checkPages( SiteBlueprint $blueprint ): array {
		$missing = array();

		foreach ( $blueprint->pages as $title ) {
			$page = $this->pageByTitle( $title );

			if ( ! $page instanceof \WP_Post ) {
				$missing[] = $title;
				continue;
			}

			if ( 'publish' !== $page->post_status ) {
				$missing[] = sprintf( '%s (status: %s)', $title, $page->post_status );
			}
		}

		return $this->row(
			'pages_exist',
			array() === $missing,
			array() === $missing
				? sprintf( 'Wszystkie %d stron istnieje i jest opublikowanych.', count( $blueprint->pages ) )
				: 'Brakuje opublikowanych stron: ' . implode( ', ', $missing )
		);
	}

	/** @return array<string, mixed> */
	private function checkGutenberg( SiteBlueprint $blueprint ): array {
		$broken = array();

		foreach ( $blueprint->pages as $title ) {
			$page = $this->pageByTitle( $title );

			if ( ! $page instanceof \WP_Post ) {
				continue; // Already reported by checkPages().
			}

			$blocks = parse_blocks( $page->post_content );
			$named  = array_filter( $blocks, static fn( array $b ): bool => null !== $b['blockName'] );

			if ( array() === $named ) {
				$broken[] = $title;
				continue;
			}

			// Round-tripping is the real test of validity: if re-serialising
			// the parsed blocks does not reproduce the stored markup, the
			// editor will flag the block as invalid when a human opens it.
			if ( serialize_blocks( $blocks ) !== $page->post_content ) {
				$broken[] = sprintf( '%s (nieprawidłowy markup bloków)', $title );
			}
		}

		return $this->row(
			'gutenberg_valid',
			array() === $broken,
			array() === $broken
				? 'Treść wszystkich stron poprawnie parsuje się do bloków Gutenberga.'
				: 'Strony z nieprawidłową treścią blokową: ' . implode( ', ', $broken )
		);
	}

	/** @return array<string, mixed> */
	private function checkTheme(): array {
		$theme = wp_get_theme();

		return $this->row(
			'theme_active',
			$theme->exists(),
			$theme->exists()
				? sprintf( 'Aktywny motyw: %s.', $theme->get( 'Name' ) )
				: 'Aktywny motyw nie istnieje.'
		);
	}

	/** @return array<string, mixed> */
	private function checkHomepage( SiteBlueprint $blueprint ): array {
		$showOnFront = (string) get_option( 'show_on_front', 'posts' );
		$frontId     = (int) get_option( 'page_on_front', 0 );
		$expected    = $this->pageByTitle( $blueprint->pages[0] ?? '' );

		if ( 'page' !== $showOnFront || $frontId <= 0 ) {
			return $this->row( 'homepage', false, 'Witryna nie ma ustawionej strony głównej.' );
		}

		if ( $expected instanceof \WP_Post && $expected->ID !== $frontId ) {
			return $this->row(
				'homepage',
				false,
				sprintf( 'Stroną główną jest id %d, oczekiwano „%s” (id %d).', $frontId, $expected->post_title, $expected->ID )
			);
		}

		return $this->row( 'homepage', true, sprintf( 'Strona główna wskazuje na id %d.', $frontId ) );
	}

	/** @return array<string, mixed> */
	private function checkSiteTitle( SiteBlueprint $blueprint ): array {
		$actual = (string) get_option( 'blogname', '' );
		$want   = sanitize_text_field( $blueprint->businessName );

		return $this->row(
			'site_title',
			$actual === $want,
			$actual === $want
				? sprintf( 'Nazwa witryny: „%s”.', $actual )
				: sprintf( 'Nazwa witryny to „%s”, oczekiwano „%s”.', $actual, $want )
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function checkNavigation( SiteBlueprint $blueprint ): array {
		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) || array() === $menus ) {
			return array( $this->row( 'navigation', false, 'Witryna nie ma żadnego menu.' ) );
		}

		$linked = array();
		foreach ( $menus as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$linked[] = (int) $item->object_id;
			}
		}

		$missing = array();
		foreach ( $blueprint->pages as $title ) {
			$page = $this->pageByTitle( $title );

			if ( $page instanceof \WP_Post && ! in_array( $page->ID, $linked, true ) ) {
				$missing[] = $title;
			}
		}

		return array(
			$this->row(
				'navigation_exists',
				array() !== $linked,
				array() !== $linked
					? sprintf( 'Menu zawiera %d pozycji.', count( $linked ) )
					: 'Menu istnieje, ale nie zawiera żadnych pozycji.'
			),
			$this->row(
				'navigation_targets',
				array() === $missing,
				array() === $missing
					? 'Menu zawiera odnośniki do wszystkich stron z planu.'
					: 'Brak w menu odnośników do: ' . implode( ', ', $missing )
			),
		);
	}

	/** @return array<string, mixed> */
	private function checkContactFormPlugin(): array {
		$active = class_exists( '\WPCF7_ContactForm' );

		return $this->row(
			'contact_form_plugin',
			$active,
			$active ? 'Contact Form 7 jest aktywny.' : 'Contact Form 7 nie jest aktywny.'
		);
	}

	/** @return array<string, mixed> */
	private function checkContactFormEmbedded( SiteBlueprint $blueprint ): array {
		$page = null;

		foreach ( $blueprint->pages as $title ) {
			if ( str_contains( mb_strtolower( $title ), 'kontakt' ) || str_contains( mb_strtolower( $title ), 'contact' ) ) {
				$page = $this->pageByTitle( $title );
				break;
			}
		}

		if ( ! $page instanceof \WP_Post ) {
			return $this->row( 'contact_form_embedded', false, 'Nie znaleziono strony kontaktowej.' );
		}

		// Look for a real CF7 reference, and confirm the form it names
		// actually exists -- a shortcode pointing at a deleted form renders
		// as an error message to visitors, which is worse than no form.
		if ( 1 !== preg_match( '/\[contact-form-7[^\]]*id="?([A-Za-z0-9]+)"?/', $page->post_content, $match ) ) {
			return $this->row( 'contact_form_embedded', false, 'Strona kontaktowa nie zawiera formularza.' );
		}

		// CF7 6.x emits a HASH in the shortcode's id attribute, not the post
		// id the older documentation shows (confirmed against 6.1.6). Both
		// forms render, so both must resolve here -- matching only digits
		// against post ids would report a perfectly good hash-based embed
		// as broken.
		$reference = (string) $match[1];
		$formId    = $this->resolveContactForm( $reference );

		return $this->row(
			'contact_form_embedded',
			null !== $formId,
			null !== $formId
				? sprintf( 'Strona kontaktowa osadza istniejący formularz (id %d).', $formId )
				: sprintf( 'Strona kontaktowa odwołuje się do nieistniejącego formularza ("%s").', $reference )
		);
	}

	/** Resolves a CF7 shortcode id -- hash or post id -- to a real form. */
	private function resolveContactForm( string $reference ): ?int {
		if ( class_exists( '\WPCF7_ContactForm' ) ) {
			foreach ( (array) \WPCF7_ContactForm::find( array( 'posts_per_page' => 50 ) ) as $form ) {
				if ( ! $form instanceof \WPCF7_ContactForm ) {
					continue;
				}

				if ( $form->hash() === $reference || (string) $form->id() === $reference ) {
					return (int) $form->id();
				}
			}
		}

		if ( ctype_digit( $reference ) && 'wpcf7_contact_form' === get_post_type( (int) $reference ) ) {
			return (int) $reference;
		}

		return null;
	}

	private function pageByTitle( string $title ): ?\WP_Post {
		if ( '' === $title ) {
			return null;
		}

		$found = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'title'            => sanitize_text_field( $title ),
				'posts_per_page'   => 1,
				'suppress_filters' => false,
			)
		);

		return isset( $found[0] ) && $found[0] instanceof \WP_Post ? $found[0] : null;
	}

	/** @return array<string, mixed> */
	private function row( string $check, bool $passed, string $detail ): array {
		return array(
			'check'  => $check,
			'passed' => $passed,
			'detail' => $detail,
		);
	}
}
