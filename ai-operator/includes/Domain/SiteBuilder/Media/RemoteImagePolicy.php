<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * What a URL must satisfy before anything on this site may fetch it.
 *
 * ## Why this exists before any downloader does
 *
 * "Illustrate the site" is the most natural-sounding way to ask a plugin
 * running inside a customer's hosting to make an outbound request to an
 * address of somebody else's choosing. On a typical host that address
 * reaches the cloud metadata endpoint, the database, the admin panel of a
 * neighbouring site, and every internal service the firewall assumes is
 * unreachable. A stock-photo feature is therefore an SSRF feature unless
 * the URL is constrained first.
 *
 * So this class is the gate, and it runs in StockImage's constructor: a
 * remote image candidate that fails it cannot be represented as an object
 * at all, which means no provider implementation can hand one onward, and
 * no downloader can be written that accidentally skips the check.
 *
 * ## What it deliberately does NOT do
 *
 * It cannot close DNS rebinding. A hostname that passes here may still
 * resolve to 127.0.0.1 or 169.254.169.254 at connect time, and no
 * examination of a string can tell. **Any downloader added later must
 * re-check the resolved address immediately before connecting, refuse
 * redirects, and stop reading at MAX_BYTES** -- a Content-Length header is
 * a claim, not a limit. Those are runtime duties this class cannot
 * discharge on its behalf.
 *
 * At present nothing in the plugin downloads anything: no stock provider
 * ships (see StockImageProviderInterface), so the only images the builder
 * can use are the ones already in the site's own media library.
 */
final class RemoteImagePolicy {

	/**
	 * A ceiling a downloader must enforce by counting bytes as they
	 * arrive, not by trusting Content-Length.
	 */
	public const MAX_BYTES = 8 * 1024 * 1024;

	/** @var string[] */
	public const ALLOWED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	/** @var string[] */
	private const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'avif' );

	/**
	 * Hostnames that name something inside the network rather than on the
	 * internet. Matched as whole labels, so "notlocalhost.example.com" is
	 * not caught by accident.
	 *
	 * @var string[]
	 */
	private const BLOCKED_SUFFIXES = array( 'localhost', 'local', 'internal', 'lan', 'home', 'intranet', 'corp' );

	/** The reason this URL is unacceptable, or null when it is fine. */
	public function rejectionReason( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url || strlen( $url ) > 2048 ) {
			return __( 'The image address is empty or too long.', 'dosieci-ai-operator' );
		}

		// wp_parse_url() rather than PHP's own parse_url(): WordPress.org's
		// Plugin Check flags the bare function, and a stub mirroring core
		// closely enough keeps this class testable with no WordPress loaded.
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return __( 'The image address is not a valid URL.', 'dosieci-ai-operator' );
		}

		// Plaintext would let anything on the path swap the image, and a
		// stock provider that cannot serve TLS is not one worth supporting.
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return __( 'Only https addresses are allowed.', 'dosieci-ai-operator' );
		}

		// user:pass@host is a classic way to make a URL read as one host to
		// a human and resolve as another to a parser.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( 'The image address must not contain login credentials.', 'dosieci-ai-operator' );
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return __( 'Only port 443 is allowed.', 'dosieci-ai-operator' );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );

		if ( '' === $host ) {
			return __( 'The image address does not name a host.', 'dosieci-ai-operator' );
		}

		// Literal addresses are refused outright rather than by enumerating
		// private ranges. Every legitimate image CDN uses a hostname, and
		// "is this IP internal" has more edge cases (IPv6 mapped v4,
		// octal notation, 0.0.0.0) than a blocklist reliably covers.
		if ( $this->isIpLiteral( $host ) ) {
			return __( 'The image address must name a hostname, not an IP address.', 'dosieci-ai-operator' );
		}

		if ( $this->isInternalName( $host ) ) {
			return __( 'The image address names an internal host.', 'dosieci-ai-operator' );
		}

		// A public name must have a dot: bare "intranet-server" is a
		// single-label name that resolves through the local search domain.
		if ( ! str_contains( $host, '.' ) ) {
			return __( 'The image address must be a fully qualified domain name.', 'dosieci-ai-operator' );
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( ! $this->hasImageExtension( $path ) ) {
			return __( 'The image address must point to an image file (jpg, png, webp, avif).', 'dosieci-ai-operator' );
		}

		return null;
	}

	public function allows( string $url ): bool {
		return null === $this->rejectionReason( $url );
	}

	public function allowsMimeType( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), self::ALLOWED_MIME_TYPES, true );
	}

	private function isIpLiteral( string $host ): bool {
		// [::1] and friends arrive bracketed from parse_url.
		$bare = trim( $host, '[]' );

		if ( false !== filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// Not a valid IP by PHP's rules, but still parsed as one by some
		// resolvers: all-numeric labels, octal and hex forms.
		return 1 === preg_match( '/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $bare );
	}

	private function isInternalName( string $host ): bool {
		$labels = explode( '.', $host );
		$last   = (string) end( $labels );

		return in_array( $last, self::BLOCKED_SUFFIXES, true );
	}

	private function hasImageExtension( string $path ): bool {
		if ( 1 !== preg_match( '/\.([a-z0-9]{2,5})$/i', $path, $m ) ) {
			return false;
		}

		return in_array( strtolower( $m[1] ), self::ALLOWED_EXTENSIONS, true );
	}
}
