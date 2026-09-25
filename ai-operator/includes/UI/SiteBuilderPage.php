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
	 * Count-dependent strings (categoryCount, productCount, planHeading) are
	 * arrays of three pre-translated "%d ..." templates -- one, few (2-4),
	 * many (5+, and 12-14) -- extracted from _n() with representative
	 * sample numbers, because the actual count is only known in the browser
	 * at render time. site-builder.js's tn() picks the right one of the
	 * three for whatever count it is actually showing. English (and most
	 * other languages) only distinguish two forms, so their own "few" and
	 * "many" entries are simply the same translated string twice, which is
	 * harmless: the choice between two identical strings has no visible
	 * effect.
	 *
	 * @return array<string, string|string[]>
	 */
	public static function scriptStrings(): array {
		return array(
			'whatIUnderstood' => __( 'What I understood', 'dosieci-ai-operator' ),
			'businessLabel'   => __( 'Business', 'dosieci-ai-operator' ),
			'typeLabel'       => __( 'Type', 'dosieci-ai-operator' ),
			'pagesLabel'      => __( 'Pages', 'dosieci-ai-operator' ),
			'featuresLabel'   => __( 'Features', 'dosieci-ai-operator' ),
			'none'            => __( 'none', 'dosieci-ai-operator' ),
			'storeLabel'      => __( 'Store', 'dosieci-ai-operator' ),
			'categoryCount'   => array(
				/* translators: %d: number of store categories (this is the "one" form, shown for exactly 1) */
				_n( '%d category', '%d categories', 1, 'dosieci-ai-operator' ),
				/* translators: %d: number of store categories (this is the "few" form, shown for 2-4) */
				_n( '%d category', '%d categories', 2, 'dosieci-ai-operator' ),
				/* translators: %d: number of store categories (this is the "many" form, shown for 5+) */
				_n( '%d category', '%d categories', 5, 'dosieci-ai-operator' ),
			),
			'productCount'    => array(
				/* translators: %d: number of store products (this is the "one" form, shown for exactly 1) */
				_n( '%d product', '%d products', 1, 'dosieci-ai-operator' ),
				/* translators: %d: number of store products (this is the "few" form, shown for 2-4) */
				_n( '%d product', '%d products', 2, 'dosieci-ai-operator' ),
				/* translators: %d: number of store products (this is the "many" form, shown for 5+) */
				_n( '%d product', '%d products', 5, 'dosieci-ai-operator' ),
			),
			'planHeading'     => array(
				/* translators: %d: number of steps in the build plan (this is the "one" form, shown for exactly 1) */
				_n( 'Build plan — %d step', 'Build plan — %d steps', 1, 'dosieci-ai-operator' ),
				/* translators: %d: number of steps in the build plan (this is the "few" form, shown for 2-4) */
				_n( 'Build plan — %d step', 'Build plan — %d steps', 2, 'dosieci-ai-operator' ),
				/* translators: %d: number of steps in the build plan (this is the "many" form, shown for 5+) */
				_n( 'Build plan — %d step', 'Build plan — %d steps', 5, 'dosieci-ai-operator' ),
			),
			'reviewStepsNotice' => __( 'Review every step. You approve them together — nothing runs before that.', 'dosieci-ai-operator' ),
			'irreversibleSuffix' => __( ' (irreversible)', 'dosieci-ai-operator' ),
			'approveAllLabel' => __( 'Approve the whole plan', 'dosieci-ai-operator' ),
			'cancelLabel'     => __( 'Cancel', 'dosieci-ai-operator' ),
			'finalAuditLabel' => __( 'Final audit', 'dosieci-ai-operator' ),
			/* translators: 1: literal token replaced client-side with the number of steps done, 2: literal token replaced client-side with the total number of steps -- kept as %1$d/%2$d so translators see real placeholders */
			'progressHeading' => __( 'Progress — %1$d / %2$d', 'dosieci-ai-operator' ),
			'pauseLabel'      => __( 'Pause', 'dosieci-ai-operator' ),
			'stopLabel'       => __( 'Stop', 'dosieci-ai-operator' ),
			'resumeLabel'     => __( 'Resume', 'dosieci-ai-operator' ),
			'rollbackLabel'   => __( 'Undo the changes made', 'dosieci-ai-operator' ),
			'genericError'    => __( 'Something went wrong.', 'dosieci-ai-operator' ),
			'preparingLabel'  => __( 'Preparing…', 'dosieci-ai-operator' ),
			'prepareDescriptionLabel' => __( 'Prepare description', 'dosieci-ai-operator' ),
			'storeTypeNeedsData'   => __( 'Site type “Store” is selected — fill in the store data below.', 'dosieci-ai-operator' ),
			'storeInvalidCountry'  => __( 'Enter a valid store country code (e.g. PL).', 'dosieci-ai-operator' ),
			'storeInvalidCurrency' => __( 'Enter a valid currency code (e.g. PLN).', 'dosieci-ai-operator' ),
			'storeNeedsCategory'   => __( 'Add at least one product category.', 'dosieci-ai-operator' ),
			'storeNeedsProduct'    => __( 'Add at least one product.', 'dosieci-ai-operator' ),
			'productNeedsName'     => __( 'Every product must have a name.', 'dosieci-ai-operator' ),
			/* translators: %s: literal token replaced client-side with the product name -- kept as %s so translators see a real placeholder */
			'productInvalidPrice'  => __( 'Product “%s” has an invalid price.', 'dosieci-ai-operator' ),
			/* translators: %s: literal token replaced client-side with the product name -- kept as %s so translators see a real placeholder */
			'productNeedsCategory' => __( 'Product “%s” has no assigned category.', 'dosieci-ai-operator' ),
		);
	}

	public function render(): void {
		$writesOn = $this->plugin->writesEnabled();

		echo '<div class="wrap dosieci-ai-site-builder">';
		echo '<h1>' . esc_html__( 'Site Builder', 'dosieci-ai-operator' ) . '</h1>';

		if ( ! $writesOn ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Site Builder requires write tools to be enabled in Settings. Without them, the AI can only read the site.', 'dosieci-ai-operator' )
				. '</p></div>';
		}

		echo '<p class="description">'
			. esc_html__( 'Describe the site you want to build. You’ll see the full action plan and approve it with one click — no step runs before that.', 'dosieci-ai-operator' )
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
		echo '<h2>' . esc_html__( 'Describe the site in your own words', 'dosieci-ai-operator' ) . '</h2>';
		echo '<textarea id="dosieci-sb-request" class="large-text" rows="5" maxlength="4000" placeholder="'
			. esc_attr__( 'Build me a professional website for HydroMax, a plumbing company in Warsaw. Pages: Home, Services, About, Our Work, Contact. Add a contact form. Colors: navy blue and white.', 'dosieci-ai-operator' )
			. '"></textarea>';
		echo '<p class="description">'
			. esc_html__( 'The AI will only prepare a DESCRIPTION of the site for you to review. Nothing will be installed or changed at this stage.', 'dosieci-ai-operator' )
			. '</p>';
		echo '<p class="submit"><button type="submit" class="button">'
			. esc_html__( 'Prepare description', 'dosieci-ai-operator' ) . '</button></p>';
		echo '</form><hr />';
	}

	private function renderForm(): void {
		$types = array(
			SiteBlueprint::TYPE_SERVICE_BUSINESS => __( 'Service business', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_LANDING_PAGE     => __( 'Landing page', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_PORTFOLIO        => __( 'Portfolio', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_RESTAURANT       => __( 'Restaurant', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_BLOG             => __( 'Blog', 'dosieci-ai-operator' ),
			SiteBlueprint::TYPE_STORE            => __( 'Store', 'dosieci-ai-operator' ),
		);

		echo '<form id="dosieci-sb-form" class="dosieci-sb-form">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="dosieci-sb-name">'
			. esc_html__( 'Business name', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-name" class="regular-text" required maxlength="120" />'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-type">'
			. esc_html__( 'Site type', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<select id="dosieci-sb-type">';
		foreach ( $types as $value => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-pages">'
			. esc_html__( 'Pages', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-pages" class="large-text" value="Start, Oferta, O nas, Realizacje, Kontakt" />'
			. '<p class="description">' . esc_html__( 'Separate with commas. The first page will become the homepage.', 'dosieci-ai-operator' ) . '</p>'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-description">'
			. esc_html__( 'Description', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<textarea id="dosieci-sb-description" class="large-text" rows="3" maxlength="2000"></textarea>'
			. '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Features', 'dosieci-ai-operator' ) . '</th><td>'
			. '<label><input type="checkbox" id="dosieci-sb-contact-form" checked /> '
			. esc_html__( 'Contact form', 'dosieci-ai-operator' ) . '</label>'
			. '</td></tr>';

		echo '</tbody></table>';

		$this->renderStoreSection();

		echo '<p class="submit"><button type="submit" class="button button-primary">'
			. esc_html__( 'Prepare plan', 'dosieci-ai-operator' ) . '</button></p>';
		echo '<p id="dosieci-sb-store-error" class="notice notice-error" hidden></p>';
		echo '</form>';
	}

	/**
	 * The commercial facts of a store blueprint (country, currency,
	 * categories, products, prices) reviewed and edited here, visibly, before
	 * "Prepare plan" can run -- never carried through only as a hidden JS
	 * variable the user never sees. Hidden by default; site-builder.js shows
	 * it when "Site type" is Store, either because the user picked it
	 * manually or because a natural-language description already produced
	 * store data.
	 */
	private function renderStoreSection(): void {
		echo '<div id="dosieci-sb-store" hidden>';
		echo '<h3>' . esc_html__( 'Store details', 'dosieci-ai-operator' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-country">'
			. esc_html__( 'Country (ISO code, e.g. PL)', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-store-country" class="small-text" maxlength="9" />'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-currency">'
			. esc_html__( 'Currency (e.g. PLN)', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<input type="text" id="dosieci-sb-store-currency" class="small-text" maxlength="3" />'
			. '</td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-weight-unit">'
			. esc_html__( 'Weight unit', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<select id="dosieci-sb-store-weight-unit">';
		foreach ( StoreBlueprint::WEIGHT_UNITS as $unit ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $unit ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="dosieci-sb-store-dimension-unit">'
			. esc_html__( 'Dimension unit', 'dosieci-ai-operator' ) . '</label></th><td>'
			. '<select id="dosieci-sb-store-dimension-unit">';
		foreach ( StoreBlueprint::DIMENSION_UNITS as $unit ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $unit ) );
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		echo '<h4>' . esc_html__( 'Categories', 'dosieci-ai-operator' ) . '</h4>';
		echo '<table class="widefat dosieci-sb-store-table"><thead><tr>'
			. '<th>' . esc_html__( 'Name', 'dosieci-ai-operator' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody id="dosieci-sb-categories"></tbody></table>';
		echo '<p><button type="button" class="button" id="dosieci-sb-add-category">'
			. esc_html__( '+ Add category', 'dosieci-ai-operator' ) . '</button></p>';

		echo '<h4>' . esc_html__( 'Products', 'dosieci-ai-operator' ) . '</h4>';
		echo '<table class="widefat dosieci-sb-store-table"><thead><tr>'
			. '<th>' . esc_html__( 'Name', 'dosieci-ai-operator' ) . '</th>'
			. '<th>' . esc_html__( 'Price', 'dosieci-ai-operator' ) . '</th>'
			. '<th>' . esc_html__( 'Category', 'dosieci-ai-operator' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody id="dosieci-sb-products"></tbody></table>';
		echo '<p><button type="button" class="button" id="dosieci-sb-add-product">'
			. esc_html__( '+ Add product', 'dosieci-ai-operator' ) . '</button></p>';

		echo '</div>';
	}
}
