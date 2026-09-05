<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

use RuntimeException;

/**
 * Thrown when an audit append is required (FAIL_CLOSED) but fails.
 *
 * Callers catching this exception MUST roll back the originating operation.
 */
final class AuditException extends RuntimeException
{
}
