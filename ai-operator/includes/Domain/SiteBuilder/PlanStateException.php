<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * An operation was attempted against a plan whose current state does not
 * permit it -- approving an already-running plan, resuming a cancelled
 * one, executing an unapproved one.
 *
 * Deliberately its own type rather than a generic exception: every one of
 * these is a security-relevant refusal (see PlanStatus's transition table),
 * so call sites must not accidentally catch it alongside ordinary failures.
 */
final class PlanStateException extends \RuntimeException {
}
