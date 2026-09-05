<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Filter DTO for querying audit entries.
 *
 * All fields are optional. NULL means "no filter on this field".
 * bot_id = NULL means platform scope; bot_id = X means bot scope.
 * operation supports prefix matching with trailing '*'.
 */
final readonly class AuditQueryFilter
{
    /**
     * @param  string|null  $botId  NULL = platform scope, non-NULL = bot scope.
     * @param  string|null  $actorType  Filter by actor type.
     * @param  string|null  $actorId  Filter by actor ID.
     * @param  string|null  $subjectType  Filter by subject type.
     * @param  string|null  $subjectId  Filter by subject ID.
     * @param  string|null  $operation  Exact match or prefix with trailing '*' (e.g. "module.*").
     * @param  string|null  $source  Filter by producing subsystem.
     * @param  string|null  $correlationId  Filter by correlation ID.
     * @param  string|null  $after  ISO 8601 lower bound (inclusive).
     * @param  string|null  $before  ISO 8601 upper bound (inclusive).
     * @param  int  $limit  Maximum entries to return.
     * @param  int  $offset  Number of entries to skip.
     */
    public function __construct(
        public ?string $botId = null,
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $operation = null,
        public ?string $source = null,
        public ?string $correlationId = null,
        public ?string $after = null,
        public ?string $before = null,
        public int $limit = 100,
        public int $offset = 0,
    ) {
    }
}
