<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreBlueprint;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Plugin;

/**
 * The Site Builder screen: describe the site, review the blueprint, review
 * the plan, approve once, then watch it build.
 *
 * The three-stage shape is the product, not decoration. Showing the
 * blueprint before the plan means a wrong assumption ("you wanted a shop")
 * is corrected while it costs one click, instead of after eighteen steps
 * built the wrong site. Showing the full plan before execution is what
 * makes a single approval honest: the user is agreeing to a specific list
 * they can read, not to "the AI will do some things".
 *
 * Progress is rendered from server state on every poll rather than
 * accumulated in JS, so a refresh mid-build shows exactly where the build
 * actually is.
 */
final class SiteBuilderPage {

	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Translated strings for assets/site-builder.js, which builds its panels
	 * in the browser. Passed as window.dosieciAiSiteBuilder.strings by
	 * AdminMenu::enqueueAssets().
	 *
	 * @return array<string, string>
	 */
	public static function scriptStrings(): array {
		return array();
	}

	public function render(): void {
		$writesOn = $this->plugin->writesEnabled();

		echo '<div class="wrap dosieci-ai-site-builder">';
		echo '<h1>' . esc_html__( 'Kreator witryny', 'dosieci-ai-operator' ) . '</h1>';

		if ( ! $writesOn ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Kreator wymaga włączenia narzędzi zapisu w zakładce Ustawienia. Bez nich AI może tylko czytać witrynę.', 'dosieci-ai-operator' )
				. '</p></div>';
		}

		echo '<p class="description">'
			. esc_html__( 'Opisz witrynę, którą chcesz zbudować. Zobaczysz pełny plan działań i zatwierdzisz go jednym kliknięciem — żaden krok nie wykona się wcześniej.', 'dosieci-ai-operator' )
			. '</p>';

		$this->renderDescribeForm();
		$this->renderForm();

		// Filled from the server on propose/step/status; deliberately empty
		// in the initial HTML so there is never a stale plan on screen.
		echo '<div id="dosieci-sb-blueprint" class="dosieci-sb-panel" hidden></div>';
		echo '<div id="dosieci-sb-plan" class="dosieci-sb-panel" hidden></div>';
		echo '<div id="dosieci-sb-progress" class="dosieci-sb-panel" hidden></div>';

		echo '</div>';
	}

	/**
	 * The natural-language entry point. Produces a blueprint CANDIDATE only
	 * -- it changes nothing on the site, and the structured form below stays
	 * available for people who would rather fill it in directly.
	 */
	private function renderDescribeForm(): void {
		echo '<form id="dosieci-sb-describe" class="dosieci-sb-form">';
		echo '<h2>' . esc_html__( 'Opisz witrynę własnymi słowami', 'dosieci-ai-operator' ) . '</h2>';
		echo '<textarea id="dosieci-sb-request" class="large-text" rows="5" maxlength="4000" placeholder="'
			. esc_attr__( 'Zbuduj mi profesjonalną stronę firmy hydraulicznej HydroMax w Warszawie. Strony: Start, Oferta, O nas, Realizacje, Kontakt. Dodaj formularz kontaktowy. Kolory granatowy i biały.', 'dosieci-ai-operator' )
			. '"></textarea>';
		echo '<p class="description">'
			. esc_html__( 'AI przygotuje wyłącznie OPIS witryny do Twojej akceptacji. Nic nie zostanie zainstalowane ani zmienione na tym etapie.', 'dosieci-ai-operator' )
			. '</p>';
		echo '<p class="submit"><button type="submit" class="button">'
			. esc_html__( 'Przygotuj opis', 'dosieci-ai-operator' ) . '</button></p>';
		echo '</form><hr />';
	}

	private function renderForm(): void {
		$types = array(
			SiteBlueprint::TYPE_SERVICE_BUSINESS => __( 'Firma usługowa', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_LANDING_PAGE     => __( 'Landing page', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_PORTFOLIO        => __( 'Portfolio', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_RESTAURANT       => __( 'Restauracja', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_BLOG             => __( 'Blog', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_STORE            => __( 'Sklep', 'dosieci-ai-operator' ),
		);

		echo '<form id="dosieci-sb-form" class="dosieci-sb-form">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="dosieci-sb-name">'
			. esc_html__( 'Nazwa firmy', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-name" class="regular-text" required maxlength="120" />'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-type">'
			. esc_html__( 'Rodzaj witryny', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<select id="dosieci-sb-type">';
		foreach ( $types as $value => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-pages">'
			. esc_html__( 'Strony', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-pages" class="large-text" value="Start, Oferta, O nas, Realizacje, Kontakt" />'
			. '<p class="description">' . esc_html__( 'Oddziel przecinkami. Pierwsza strona zostanie stroną główną.', 'dosieci-ai-operator' ) . '</p>'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-description">'
			. esc_html__( 'Opis', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<textarea id="dosieci-sb-description" class="large-text" rows="3" maxlength="2000"></textarea>'
			. '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Funkcje', 'dosieci-ai-operator' ) . '</th><td>'
			. '<label><input type="checkbox" id="dosieci-sb-contact-form" checked /> '
			. esc_html__( 'Formularz kontaktowy', 'dosieci-ai-operator' ) . '</label>'
			. '</td></tr>';

		echo '</tbody></table>';

		$this->renderStoreSection();

		echo '<p class="submit"><button type="submit" class="button button-primary">'
			. esc_html__( 'Przygotuj plan', 'dosieci-ai-operator' ) . '</button></p>';
		echo '<p id="dosieci-sb-store-error" class="notice notice-error" hidden></p>';
		echo '</form>';
	}

	/**
	 * The commercial facts of a store blueprint (country, currency,
	 * categories, products, prices) reviewed and edited here, visibly, before
	 * "Przygotuj plan" can run -- never carried through only as a hidden JS
	 * variable the user never sees. Hidden by default; site-builder.js shows
	 * it when "Rodzaj witryny" is Sklep, either because the user picked it
	 * manually or because a natural-language description already produced
	 * store data.
	 */
	private function renderStoreSection(): void {
		echo '<div id="dosieci-sb-store" hidden>';
		echo '<h3>' . esc_html__( 'Dane sklepu', 'dosieci-ai-operator' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-country">'
			. esc_html__( 'Kraj (kod ISO, np. PL)', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-store-country" class="small-text" maxlength="9" />'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-currency">'
			. esc_html__( 'Waluta (np. PLN)', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-store-currency" class="small-text" maxlength="3" />'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-weight-unit">'
			. esc_html__( 'Jednostka wagi', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<select id="dosieci-sb-store-weight-unit">';
		foreach ( StoreBlueprint::WEIGHT_UNITS as $unit ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $unit ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-dimension-unit">'
			. esc_html__( 'Jednostka wymiaru', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<select id="dosieci-sb-store-dimension-unit">';
		foreach ( StoreBlueprint::DIMENSION_UNITS as $unit ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $unit ) );
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		echo '<h4>' . esc_html__( 'Kategorie', 'dosieci-ai-operator' ) . '</h4>';
		echo '<table class="widefat dosieci-sb-store-table"><thead><tr>'
			. '<th>' . esc_html__( 'Nazwa', 'dosieci-ai-operator' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody id="dosieci-sb-categories"></tbody></table>';
		echo '<p><button type="button" class="button" id="dosieci-sb-add-category">'
			. esc_html__( '+ Dodaj kategorię', 'dosieci-ai-operator' ) . '</button></p>';

		echo '<h4>' . esc_html__( 'Produkty', 'dosieci-ai-operator' ) . '</h4>';
		echo '<table class="widefat dosieci-sb-store-table"><thead><tr>'
			. '<th>' . esc_html__( 'Nazwa', 'dosieci-ai-operator' ) . '</th>'
			. '<th>' . esc_html__( 'Cena', 'dosieci-ai-operator' ) . '</th>'
			. '<th>' . esc_html__( 'Kategoria', 'dosieci-ai-operator' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody id="dosieci-sb-products"></tbody></table>';
		echo '<p><button type="button" class="button" id="dosieci-sb-add-product">'
			. esc_html__( '+ Dodaj produkt', 'dosieci-ai-operator' ) . '</button></p>';

		echo '</div>';
	}
}
