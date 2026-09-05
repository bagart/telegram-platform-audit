<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Resolves the failure policy for a given audit entry.
 *
 * Implementations map operation categories (or individual operations) to
 * FAIL_OPEN or FAIL_CLOSED. Security-sensitive operations should default
 * to FAIL_CLOSED; observational/telemetry operations to FAIL_OPEN.
 */
interface AuditFailurePolicyResolver
{
    public function resolve(AuditEntry $entry): AuditFailurePolicy;
}
