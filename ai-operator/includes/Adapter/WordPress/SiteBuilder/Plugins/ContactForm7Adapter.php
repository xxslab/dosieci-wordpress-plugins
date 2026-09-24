<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Plugins;

use DoSieci\AiOperator\Domain\SiteBuilder\Plugins\PluginConfigurationException;
use DoSieci\AiOperator\Domain\SiteBuilder\Plugins\PluginConfiguratorInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;

/**
 * Creates a working contact form with Contact Form 7 and returns the
 * shortcode that embeds it.
 *
 * ## Built against the confirmed public API, not guessed internals
 *
 * Verified against Contact Form 7 6.1.6 in a disposable install before a
 * line of this was written. Everything used here is public API on
 * WPCF7_ContactForm:
 *
 *   get_template( ['locale' => ...] )  a new unsaved form pre-filled with
 *                                       CF7's own defaults, including a
 *                                       correct mail template
 *   set_title() / set_properties()      the documented mutators
 *   save()                              persists, returns the post id
 *   shortcode()                         CF7's OWN canonical embed string
 *
 * That last one matters more than it looks. CF7 6.x emits a HASH-based
 * shortcode (`id="8040402"`), not the post id the older docs show. Both
 * happen to render today, but hand-building the string would mean guessing
 * at a format the plugin already knows how to produce -- so we ask it.
 *
 * Starting from get_template() rather than an empty form is also
 * deliberate: it inherits CF7's own default mail configuration, so the
 * form actually delivers without this adapter inventing a mail template.
 *
 * ## Identity, so re-running does not accumulate forms
 *
 * A generated form is tagged with post meta naming this plugin and the
 * plan that produced it. Lookup is by that meta, never by title -- titles
 * are user-editable and duplicable, so title-matching is how you end up
 * with "Kontakt", "Kontakt (2)", "Kontakt (3)" after three plan revisions.
 *
 * ## What it will not do
 *
 * No SMTP, no third-party mail credentials, no arbitrary CF7 option
 * writing. The recipient is the site's own configured admin address, which
 * WordPress already knows and the operator already controls.
 */
final class ContactForm7Adapter implements PluginConfiguratorInterface {

	/** Marks a form as created by this plugin, for idempotent reuse. */
	public const META_MARKER = '_dosieci_ai_operator_form';

	/** Records which plan created it, for rollback and diagnosis. */
	public const META_PLAN = '_dosieci_ai_operator_plan_id';

	public function isAvailable(): bool {
		return class_exists( '\WPCF7_ContactForm' );
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws PluginConfigurationException
	 */
	public function configure( SiteBlueprint $blueprint, string $planId ): array {
		if ( ! $this->isAvailable() ) {
			throw new PluginConfigurationException( 'Contact Form 7 nie jest aktywny.' );
		}

		$existing = $this->findGeneratedForm();

		if ( null !== $existing ) {
			// Idempotent: a revised plan reuses the form it already made.
			$form = \WPCF7_ContactForm::get_instance( $existing );

			if ( $form instanceof \WPCF7_ContactForm ) {
				return array(
					'form_id'   => $form->id(),
					'shortcode' => $form->shortcode(),
					'created'   => false,
					'reused'    => true,
				);
			}
		}

		$form = \WPCF7_ContactForm::get_template( array( 'locale' => $this->locale( $blueprint ) ) );

		if ( ! $form instanceof \WPCF7_ContactForm ) {
			throw new PluginConfigurationException( 'Nie udało się utworzyć szablonu formularza.' );
		}

		$form->set_title( $this->formTitle( $blueprint ) );
		$form->set_properties(
			array(
				'form' => $this->formBody( $blueprint ),
				// Mail is inherited from CF7's own template except for the
				// recipient, which is set to the address WordPress already
				// holds. No third-party credentials are involved.
				'mail' => array_merge(
					(array) $form->prop( 'mail' ),
					array( 'recipient' => (string) get_option( 'admin_email' ) )
				),
			)
		);

		$formId = $form->save();

		if ( ! is_int( $formId ) || $formId <= 0 ) {
			throw new PluginConfigurationException( 'Contact Form 7 nie zapisał formularza.' );
		}

		update_post_meta( $formId, self::META_MARKER, '1' );
		update_post_meta( $formId, self::META_PLAN, $planId );

		// Re-read so shortcode() reflects the saved post rather than the
		// in-memory template (which has no id yet).
		$saved = \WPCF7_ContactForm::get_instance( $formId );

		return array(
			'form_id'   => $formId,
			'shortcode' => $saved instanceof \WPCF7_ContactForm ? $saved->shortcode() : '',
			'created'   => true,
			'reused'    => false,
		);
	}

	/** The post id of a form this plugin previously generated, if any. */
	public function findGeneratedForm(): ?int {
		$found = get_posts(
			array(
				'post_type'        => 'wpcf7_contact_form',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'meta_key'         => self::META_MARKER,
				'meta_value'       => '1',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : null;
	}

	/**
	 * Localised labels. The blueprint's language decides -- hard-coding
	 * Polish would produce a Polish form on an English site.
	 */
	private function formBody( SiteBlueprint $blueprint ): string {
		$labels = $this->labels( $blueprint->language );

		return sprintf(
			"<label> %s\n    [text* your-name autocomplete:name] </label>\n\n"
			. "<label> %s\n    [email* your-email autocomplete:email] </label>\n\n"
			. "<label> %s\n    [tel your-phone autocomplete:tel] </label>\n\n"
			. "<label> %s\n    [textarea* your-message] </label>\n\n"
			. "[submit \"%s\"]",
			$labels['name'],
			$labels['email'],
			$labels['phone'],
			$labels['message'],
			$labels['submit']
		);
	}

	/** @return array<string, string> */
	private function labels( string $language ): array {
		if ( str_starts_with( strtolower( $language ), 'pl' ) ) {
			return array(
				'name'    => 'Imię i nazwisko',
				'email'   => 'Adres e-mail',
				'phone'   => 'Telefon',
				'message' => 'Wiadomość',
				'submit'  => 'Wyślij',
			);
		}

		return array(
			'name'    => 'Your name',
			'email'   => 'Your email',
			'phone'   => 'Phone',
			'message' => 'Your message',
			'submit'  => 'Send',
		);
	}

	private function formTitle( SiteBlueprint $blueprint ): string {
		return sprintf( '%s — formularz kontaktowy', $blueprint->businessName );
	}

	private function locale( SiteBlueprint $blueprint ): string {
		return str_starts_with( strtolower( $blueprint->language ), 'pl' ) ? 'pl_PL' : 'en_US';
	}
}
