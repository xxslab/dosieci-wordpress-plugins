<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain;

/**
 * A connection-level failure reaching the Hub (DNS, TLS, timeout). Distinct
 * from HubException, which means "the Hub answered, and the answer was an
 * error" -- the two need different user-facing messages, because one is
 * "your site could not reach us" and the other is "we said no".
 */
final class TransportException extends \RuntimeException {
}
