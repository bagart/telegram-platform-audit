<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Audit failure policy — determines caller behaviour when audit append fails.
 *
 * The policy is resolved per-entry by AuditFailurePolicyResolver and enforced
 * at the call site, NOT inside the sink. This keeps the sink composable and
 * the policy explicit.
 */
enum AuditFailurePolicy: string
{
    /** Audit failure is logged but does not block the operation. */
    case FailOpen = 'fail_open';

    /** Audit failure causes the operation to be rolled back / rejected. */
    case FailClosed = 'fail_closed';
}
