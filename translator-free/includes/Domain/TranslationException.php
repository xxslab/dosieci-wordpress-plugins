<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TranslationException extends \RuntimeException {

	public function __construct( string $message, public readonly int $statusCode = 0 ) {
		parent::__construct( $message );
	}
}
