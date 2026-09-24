<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\Tools;

use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Navigation\BlockThemeNavigationAdapter;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Plugins\ContactForm7Adapter;
use DoSieci\AiOperator\Domain\SiteBuilder\Plugins\PluginConfigurationException;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;

/**
 * Write tools that exist for the Site Builder specifically: configuring a
 * plugin the build just installed, and wiring its output into a page.
 *
 * These are ordinary tools, registered in the same closed allowlist and
 * dispatched through the same ToolDispatcher gates as everything in
 * WriteToolFactory. They are separate only because they are meaningless
 * outside a build -- "create the site's contact form" is not a request a
 * user makes in single-action chat.
 *
 * Both take a blueprint-derived argument set that the deterministic planner
 * produced, and both are verifiable: the form either exists or it does
 * not, the page either references it or it does not.
 */
final class SiteBuilderToolFactory {

	private const DEFAULT_TIMEOUT = 45;

	public function register( ToolRegistry $registry ): void {
		foreach ( $this->definitions() as $definition ) {
			$registry->register( $definition );
		}
	}

	/** @return ToolDefinition[] */
	private function definitions(): array {
		return array(
			new ToolDefinition(
				'configure_contact_form',
				'Tworzy (lub ponownie wykorzystuje) formularz kontaktowy w aktywnej wtyczce formularzy i zwraca jego shortcode.',
				array(
					'type'       => 'object',
					'properties' => array(
						'business_name' => array( 'type' => 'string' ),
						'language'      => array( 'type' => 'string' ),
						'plan_id'       => array( 'type' => 'string' ),
					),
					'required'   => array( 'business_name' ),
				),
				// Creating a form is a content write, not a settings change:
				// publish_pages is the narrowest capability that fits.
				'publish_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'configureContactForm' )
			),
			new ToolDefinition(
				'set_block_navigation',
				'Tworzy lub aktualizuje nawigację motywu blokowego (wpis wp_navigation), którą renderuje blok nawigacji.',
				array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'   => array( 'type' => 'string' ),
									'page_id' => array( 'type' => 'integer' ),
								),
							),
						),
						'project_id' => array( 'type' => 'string' ),
					),
					'required'   => array( 'items' ),
				),
				'edit_theme_options',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'setBlockNavigation' )
			),
			new ToolDefinition(
				'set_featured_image',
				'Ustawia obrazek wyróżniający strony na wskazany załącznik, który już istnieje w bibliotece mediów.',
				array(
					'type'       => 'object',
					'properties' => array(
						'page_id'       => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'page_id', 'attachment_id' ),
				),
				'edit_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'setFeaturedImage' )
			),
			new ToolDefinition(
				'embed_contact_form',
				'Osadza istniejący formularz kontaktowy na wskazanej stronie, jeśli jeszcze go tam nie ma.',
				array(
					'type'       => 'object',
					'properties' => array(
						'page_id'   => array( 'type' => 'integer' ),
						'shortcode' => array( 'type' => 'string' ),
					),
					'required'   => array( 'page_id', 'shortcode' ),
				),
				'edit_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'embedContactForm' )
			),
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function configureContactForm( array $args ): array {
		$adapter = new ContactForm7Adapter();

		if ( ! $adapter->isAvailable() ) {
			return array(
				'success' => false,
				'error'   => 'Żadna obsługiwana wtyczka formularzy nie jest aktywna.',
			);
		}

		// A minimal blueprint is enough here: the adapter only reads the
		// business name and language.
		try {
			$blueprint = SiteBlueprint::fromArray(
				array(
					'site_type'     => SiteBlueprint::TYPE_SERVICE_BUSINESS,
					'business_name' => (string) $args['business_name'],
					'language'      => isset( $args['language'] ) ? (string) $args['language'] : 'pl',
					'pages'         => array( 'Kontakt' ),
				)
			);

			return $adapter->configure( $blueprint, isset( $args['plan_id'] ) ? (string) $args['plan_id'] : '' );
		} catch ( PluginConfigurationException | \Throwable $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function setBlockNavigation( array $args ): array {
		$adapter = new BlockThemeNavigationAdapter();

		if ( ! $adapter->supports() ) {
			return array(
				'success' => false,
				'error'   => 'Aktywny motyw nie jest motywem blokowym.',
			);
		}

		$items = is_array( $args['items'] ?? null ) ? $args['items'] : array();

		// Reuses the navigation post this project created earlier, so a
		// re-run updates it rather than stacking up wp_navigation entries.
		$existing = $adapter->findExisting( (string) ( $args['project_id'] ?? '' ) );

		return $adapter->apply( $items, $existing );
	}

	/**
	 * Points a page at an image the library ALREADY holds.
	 *
	 * This tool cannot upload, download or create anything: it writes one
	 * post meta value referencing an attachment that must already exist and
	 * must already be a raster image. That is what keeps "illustrate the
	 * site" from being a way to pull arbitrary bytes onto the server.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function setFeaturedImage( array $args ): array {
		$pageId       = (int) $args['page_id'];
		$attachmentId = (int) $args['attachment_id'];

		$page = get_post( $pageId );

		if ( ! $page instanceof \WP_Post ) {
			return array( 'success' => false, 'error' => sprintf( 'Strona %d nie istnieje.', $pageId ) );
		}

		if ( ! current_user_can( 'edit_post', $pageId ) ) {
			return array( 'success' => false, 'error' => 'Brak uprawnień do edycji tej strony.' );
		}

		$attachment = get_post( $attachmentId );

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Załącznik %d nie istnieje.', $attachmentId ),
			);
		}

		// SVG is script-capable, so it is refused even on a site whose
		// upload filters allow it. An image the builder promotes onto a
		// page is not the place to relax that.
		$mime = (string) get_post_mime_type( $attachmentId );

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Załącznik %d nie jest obsługiwanym obrazem (%s).', $attachmentId, $mime ),
			);
		}

		$previous = (int) get_post_thumbnail_id( $pageId );

		if ( $previous === $attachmentId ) {
			return array(
				'page_id'       => $pageId,
				'attachment_id' => $attachmentId,
				'already'       => true,
				'previous_id'   => $previous,
			);
		}

		if ( false === set_post_thumbnail( $pageId, $attachmentId ) ) {
			return array( 'success' => false, 'error' => 'WordPress odrzucił ustawienie obrazka wyróżniającego.' );
		}

		return array(
			'page_id'       => $pageId,
			'attachment_id' => $attachmentId,
			'already'       => false,
			'previous_id'   => $previous,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function embedContactForm( array $args ): array {
		$pageId    = (int) $args['page_id'];
		$shortcode = trim( (string) $args['shortcode'] );

		$page = get_post( $pageId );

		if ( ! $page instanceof \WP_Post ) {
			return array( 'success' => false, 'error' => sprintf( 'Strona %d nie istnieje.', $pageId ) );
		}

		if ( ! current_user_can( 'edit_post', $pageId ) ) {
			return array( 'success' => false, 'error' => 'Brak uprawnień do edycji tej strony.' );
		}

		// Only a well-formed contact-form shortcode is accepted. This value
		// is produced by the CF7 adapter, but it arrives here as a tool
		// argument like any other, so it is validated rather than trusted.
		if ( 1 !== preg_match( '/^\[contact-form-7\s[^<>\[\]]*\]$/', $shortcode ) ) {
			return array( 'success' => false, 'error' => 'Nieprawidłowy shortcode formularza.' );
		}

		if ( str_contains( $page->post_content, '[contact-form-7' ) ) {
			return array(
				'embedded'  => true,
				'already'   => true,
				'page_id'   => $pageId,
				'shortcode' => $shortcode,
			);
		}

		// Wrapped in a shortcode BLOCK, not appended as bare text, so the
		// page stays a valid block document and remains editable in
		// Gutenberg rather than degrading into a classic-editor blob.
		$block = "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";

		$updated = wp_update_post(
			array(
				'ID'           => $pageId,
				'post_content' => rtrim( $page->post_content ) . "\n\n" . $block,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return array( 'success' => false, 'error' => $updated->get_error_message() );
		}

		return array(
			'embedded'  => true,
			'already'   => false,
			'page_id'   => $pageId,
			'shortcode' => $shortcode,
		);
	}
}
