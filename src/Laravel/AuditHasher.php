<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditEntry;

/**
 * Computes SHA-256 hash for audit entries to form a tamper-evident chain.
 *
 * hash = SHA256(json(entry_data) + prevHash)
 * The first entry in the chain has prevHash = null.
 */
final class AuditHasher
{
    public function computeHash(AuditEntry $entry, ?string $prevHash): string
    {
        $payload = json_encode($entry->jsonSerialize(), JSON_THROW_ON_ERROR) . ($prevHash ?? '');

        return hash('sha256', $payload);
    }
}
