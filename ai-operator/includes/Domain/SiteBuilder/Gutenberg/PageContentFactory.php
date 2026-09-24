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
					: sprintf( '%s — profesjonalne usługi dla klientów indywidualnych i firm.', $name ),
				'cta_label' => 'Skontaktuj się',
				'cta_url'   => '#kontakt',
			),
		);

		$services = $this->serviceItems( $blueprint );

		if ( array() !== $services ) {
			$sections[] = array(
				'type'  => 'services',
				'title' => 'Co robimy',
				'items' => $services,
			);
		}

		$sections[] = array( 'type' => 'separator' );

		$sections[] = array(
			'type'      => 'cta',
			'title'     => 'Potrzebujesz wyceny?',
			'text'      => 'Opisz swój problem, a odpowiemy z propozycją terminu i kosztu.',
			'cta_label' => 'Napisz do nas',
			'cta_url'   => '#kontakt',
		);

		return $sections;
	}

	/** @return array<int, array<string, mixed>> */
	private function innerSections( SiteBlueprint $blueprint, string $pageTitle ): array {
		$normalised = mb_strtolower( $pageTitle );

		if ( str_contains( $normalised, 'kontakt' ) ) {
			return array(
				array( 'type' => 'heading', 'text' => 'Kontakt', 'level' => 1 ),
				array(
					'type'  => 'contact',
					'title' => 'Napisz lub zadzwoń',
					'text'  => sprintf(
						'Chętnie odpowiemy na pytania dotyczące usług firmy %s.',
						$blueprint->businessName
					),
				),
			);
		}

		if ( str_contains( $normalised, 'oferta' ) || str_contains( $normalised, 'usług' ) ) {
			$items = $this->serviceItems( $blueprint );

			return array(
				array( 'type' => 'heading', 'text' => $pageTitle, 'level' => 1 ),
				array(
					'type' => 'paragraph',
					'text' => 'Poniżej znajdziesz zakres naszych usług. Każdą wycenę przygotowujemy indywidualnie.',
				),
				array( 'type' => 'services', 'items' => $items ),
			);
		}

		if ( str_contains( $normalised, 'o nas' ) || str_contains( $normalised, 'o firmie' ) ) {
			return array(
				array( 'type' => 'heading', 'text' => $pageTitle, 'level' => 1 ),
				array(
					'type' => 'paragraph',
					'text' => sprintf(
						'%s to zespół, który stawia na rzetelność i terminowość. Ta sekcja czeka na Twój opis — dodaj historię firmy, doświadczenie i to, co Was wyróżnia.',
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
				'text' => sprintf( 'Ta strona („%s”) czeka na treść. Możesz ją teraz uzupełnić w edytorze.', $pageTitle ),
			),
		);
	}

	/** @return array<int, array<string, string>> */
	private function serviceItems( SiteBlueprint $blueprint ): array {
		return match ( $blueprint->siteType ) {
			SiteBlueprint::TYPE_SERVICE_BUSINESS => array(
				array( 'title' => 'Szybka realizacja', 'text' => 'Podejmujemy się zleceń pilnych i umawiamy konkretny termin.' ),
				array( 'title' => 'Doświadczony zespół', 'text' => 'Pracujemy zgodnie ze sztuką i obowiązującymi normami.' ),
				array( 'title' => 'Przejrzysta wycena', 'text' => 'Koszt znasz przed rozpoczęciem prac — bez niespodzianek.' ),
			),
			SiteBlueprint::TYPE_RESTAURANT => array(
				array( 'title' => 'Świeże składniki', 'text' => 'Menu układamy wokół tego, co sezonowe i lokalne.' ),
				array( 'title' => 'Miejsce na spotkania', 'text' => 'Sala i rezerwacje dla większych grup.' ),
			),
			SiteBlueprint::TYPE_PORTFOLIO => array(
				array( 'title' => 'Wybrane realizacje', 'text' => 'Przegląd projektów, nad którymi pracowaliśmy.' ),
				array( 'title' => 'Proces', 'text' => 'Jak wygląda współpraca krok po kroku.' ),
			),
			default => array(),
		};
	}
}
