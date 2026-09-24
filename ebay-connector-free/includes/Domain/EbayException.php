<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Domain;

final class EbayException extends \RuntimeException {

	public function __construct( string $message, public readonly int $statusCode = 0 ) {
		parent::__construct( $message );
	}
}
