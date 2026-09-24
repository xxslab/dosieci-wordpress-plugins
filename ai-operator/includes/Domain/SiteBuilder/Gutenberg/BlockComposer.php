<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg;

/**
 * Emits valid Gutenberg block markup from structured input.
 *
 * ## Why the model does not write block markup directly
 *
 * Block markup is not HTML: `<!-- wp:paragraph -->` comments carry JSON
 * attributes that must exactly match the serialised HTML beneath them, or
 * the editor shows "This block contains unexpected or invalid content" and
 * the user has to click "Attempt recovery" on every section. A model
 * producing that markup free-hand gets it subtly wrong often enough to
 * matter, and the failure is invisible until a human opens the editor.
 *
 * So the model chooses SECTIONS and supplies TEXT; this class owns the
 * markup. That bounds the output space to structures known to round-trip
 * through parse_blocks(), and means a Gutenberg format change is one file
 * to fix rather than a prompt to re-tune.
 *
 * All text is escaped on the way in. Content originating from a model is
 * untrusted input like any other -- see AI_OPERATOR_SECURITY.md section 3.
 */
final class BlockComposer {

	/**
	 * Renders a list of section descriptors into one block document.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 */
	public function compose( array $sections ): string {
		$out = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$markup = $this->section( $section );

			if ( '' !== $markup ) {
				$out[] = $markup;
			}
		}

		return implode( "\n\n", $out );
	}

	/** @param array<string, mixed> $section */
	private function section( array $section ): string {
		$type = isset( $section['type'] ) ? (string) $section['type'] : '';

		return match ( $type ) {
			'hero'         => $this->hero( $section ),
			'heading'      => $this->heading( $section ),
			'paragraph'    => $this->paragraph( $section ),
			'features'     => $this->features( $section ),
			'services'     => $this->features( $section ),
			'cta'          => $this->cta( $section ),
			'contact'      => $this->contact( $section ),
			'list'         => $this->listBlock( $section ),
			'separator'    => $this->separator(),
			'spacer'       => $this->spacer( $section ),
			default        => '',
		};
	}

	/** @param array<string, mixed> $section */
	private function hero( array $section ): string {
		$title    = $this->text( $section['title'] ?? '' );
		$subtitle = $this->text( $section['subtitle'] ?? '' );
		$cta      = $this->text( $section['cta_label'] ?? '' );
		$ctaUrl   = $this->url( $section['cta_url'] ?? '' );

		$inner = array( $this->headingMarkup( $title, 1 ) );

		if ( '' !== $subtitle ) {
			$inner[] = $this->paragraphMarkup( $subtitle );
		}

		if ( '' !== $cta ) {
			$inner[] = $this->buttonMarkup( $cta, $ctaUrl );
		}

		return $this->groupMarkup( implode( "\n\n", $inner ) );
	}

	/** @param array<string, mixed> $section */
	private function heading( array $section ): string {
		$level = (int) ( $section['level'] ?? 2 );

		return $this->headingMarkup( $this->text( $section['text'] ?? '' ), $level );
	}

	/** @param array<string, mixed> $section */
	private function paragraph( array $section ): string {
		return $this->paragraphMarkup( $this->text( $section['text'] ?? '' ) );
	}

	/**
	 * A columns section -- the workhorse of "services" and "features"
	 * blocks on a real business site.
	 *
	 * @param array<string, mixed> $section
	 */
	private function features( array $section ): string {
		$items = is_array( $section['items'] ?? null ) ? $section['items'] : array();
		$items = array_slice( $items, 0, 6 );

		if ( array() === $items ) {
			return '';
		}

		$columns = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$inner = $this->headingMarkup( $this->text( $item['title'] ?? '' ), 3 );

			$body = $this->text( $item['text'] ?? '' );
			if ( '' !== $body ) {
				$inner .= "\n\n" . $this->paragraphMarkup( $body );
			}

			$columns[] = "<!-- wp:column -->\n<div class=\"wp-block-column\">"
				. "\n" . $inner . "\n</div>\n<!-- /wp:column -->";
		}

		if ( array() === $columns ) {
			return '';
		}

		$heading = $this->text( $section['title'] ?? '' );
		$prefix  = '' !== $heading ? $this->headingMarkup( $heading, 2 ) . "\n\n" : '';

		return $prefix
			. "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n"
			. implode( "\n", $columns )
			. "\n</div>\n<!-- /wp:columns -->";
	}

	/** @param array<string, mixed> $section */
	private function cta( array $section ): string {
		$title = $this->text( $section['title'] ?? '' );
		$text  = $this->text( $section['text'] ?? '' );
		$label = $this->text( $section['cta_label'] ?? '' );
		$url   = $this->url( $section['cta_url'] ?? '' );

		$inner = array();

		if ( '' !== $title ) {
			$inner[] = $this->headingMarkup( $title, 2 );
		}

		if ( '' !== $text ) {
			$inner[] = $this->paragraphMarkup( $text );
		}

		if ( '' !== $label ) {
			$inner[] = $this->buttonMarkup( $label, $url );
		}

		return array() === $inner ? '' : $this->groupMarkup( implode( "\n\n", $inner ) );
	}

	/**
	 * A contact section. The form itself is NOT rendered here -- a form
	 * plugin's shortcode is inserted by its own configurator adapter once
	 * the form exists, because guessing a shortcode for a form id that was
	 * never created produces a page displaying raw `[contact-form-7 ...]`
	 * text to visitors.
	 *
	 * @param array<string, mixed> $section
	 */
	private function contact( array $section ): string {
		$inner = array();

		$title = $this->text( $section['title'] ?? '' );
		if ( '' !== $title ) {
			$inner[] = $this->headingMarkup( $title, 2 );
		}

		foreach ( array( 'text', 'address', 'phone', 'email' ) as $field ) {
			$value = $this->text( $section[ $field ] ?? '' );

			if ( '' !== $value ) {
				$inner[] = $this->paragraphMarkup( $value );
			}
		}

		$shortcode = isset( $section['form_shortcode'] ) ? (string) $section['form_shortcode'] : '';
		if ( '' !== $shortcode && 1 === preg_match( '/^\[[a-z0-9_\-]+[^<>]*\]$/i', $shortcode ) ) {
			$inner[] = "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";
		}

		return array() === $inner ? '' : implode( "\n\n", $inner );
	}

	/** @param array<string, mixed> $section */
	private function listBlock( array $section ): string {
		$items = is_array( $section['items'] ?? null ) ? $section['items'] : array();
		$items = array_slice( $items, 0, 20 );

		$rendered = array();
		foreach ( $items as $item ) {
			$text = $this->text( is_scalar( $item ) ? (string) $item : '' );

			if ( '' !== $text ) {
				$rendered[] = "<!-- wp:list-item -->\n<li>" . $text . "</li>\n<!-- /wp:list-item -->";
			}
		}

		if ( array() === $rendered ) {
			return '';
		}

		return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n"
			. implode( "\n", $rendered )
			. "\n</ul>\n<!-- /wp:list -->";
	}

	private function separator(): string {
		return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
	}

	/** @param array<string, mixed> $section */
	private function spacer( array $section ): string {
		$height = (int) ( $section['height'] ?? 48 );
		$height = max( 8, min( 240, $height ) );

		return sprintf(
			"<!-- wp:spacer {\"height\":\"%dpx\"} -->\n<div style=\"height:%dpx\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->",
			$height,
			$height
		);
	}

	// -----------------------------------------------------------------
	// Primitive block markup
	// -----------------------------------------------------------------

	private function headingMarkup( string $text, int $level ): string {
		if ( '' === $text ) {
			return '';
		}

		$level = max( 1, min( 6, $level ) );

		// The level attribute and the tag must agree, or the editor flags
		// the block as invalid. h2 is Gutenberg's default and is therefore
		// the one level omitted from the attribute JSON.
		$attributes = 2 === $level ? '' : sprintf( ' {"level":%d}', $level );

		return sprintf(
			"<!-- wp:heading%s -->\n<h%d class=\"wp-block-heading\">%s</h%d>\n<!-- /wp:heading -->",
			$attributes,
			$level,
			$text,
			$level
		);
	}

	private function paragraphMarkup( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
	}

	private function buttonMarkup( string $label, string $url ): string {
		$href = '' !== $url ? sprintf( ' href="%s"', $url ) : '';

		return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n"
			. "<!-- wp:button -->\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . $label . '</a></div>'
			. "\n<!-- /wp:button -->\n"
			. "</div>\n<!-- /wp:buttons -->";
	}

	private function groupMarkup( string $inner ): string {
		return "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n"
			. '<div class="wp-block-group">' . "\n" . $inner . "\n</div>\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * Model-supplied text is escaped, length-bounded, and stripped of any
	 * markup. A paragraph is a paragraph -- it does not get to smuggle in a
	 * <script>, and it does not get to be 400KB.
	 */
	private function text( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return '';
		}

		$value = mb_substr( $value, 0, 2000 );

		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Only same-document anchors and relative/absolute http(s) URLs. A
	 * javascript: or data: href in a generated button would be stored XSS
	 * authored by a prompt.
	 */
	private function url( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return '';
		}

		if ( 1 === preg_match( '#^(/|\#)[^\s"<>]*$#', $value ) ) {
			return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}

		if ( 1 === preg_match( '#^https?://[^\s"<>]+$#i', $value ) ) {
			return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}

		return '';
	}
}
