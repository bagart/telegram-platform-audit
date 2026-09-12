<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Http\Controllers;

use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditQueryFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Admin controller for querying audit entries.
 *
 * Provides paginated listing and single-entry lookup.
 * Gated by platform admin middleware (applied in routes).
 */
final class AuditController extends Controller
{
    public function __construct(
        private readonly AuditQueryContract $query,
    ) {}

    /**
     * Paginated audit entries for a bot (or platform scope).
     *
     * GET /admin/audit?bot_id=...&operation=...&after=...&before=...&limit=50&offset=0
     */
    public function index(Request $request): JsonResponse
    {
        $filter = new AuditQueryFilter(
            botId: $request->input('bot_id'),
            actorType: $request->input('actor_type'),
            actorId: $request->input('actor_id'),
            subjectType: $request->input('subject_type'),
            subjectId: $request->input('subject_id'),
            operation: $request->input('operation'),
            source: $request->input('source'),
            correlationId: $request->input('correlation_id'),
            after: $request->input('after'),
            before: $request->input('before'),
            limit: min((int) $request->input('limit', 50), 200),
            offset: max((int) $request->input('offset', 0), 0),
        );

        $entries = iterator_to_array($this->query->query($filter));
        $total = $this->query->count($filter);

        return response()->json([
            'data' => array_map(
                static fn (mixed $entry) => $entry->jsonSerialize(),
                $entries,
            ),
            'meta' => [
                'total' => $total,
                'limit' => $filter->limit,
                'offset' => $filter->offset,
            ],
        ]);
    }

    /**
     * Single audit entry by ID.
     *
     * GET /admin/audit/{id}
     */
    public function show(string $id): JsonResponse
    {
        $row = DB::table('audit_entries')
            ->where('id', $id)
            ->first();

        if ($row === null) {
            return response()->json(['error' => 'Audit entry not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => (string) $row->id,
                'actor' => [
                    'type' => (string) $row->actor_type,
                    'id' => (string) $row->actor_id,
                    'displayName' => $row->actor_display_name,
                ],
                'target' => [
                    'botId' => $row->bot_id,
                    'subjectType' => (string) $row->subject_type,
                    'subjectId' => (string) $row->subject_id,
                    'chatId' => $row->chat_id,
                ],
                'operation' => (string) $row->operation,
                'oldState' => $row->old_state ? json_decode((string) $row->old_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'newState' => $row->new_state ? json_decode((string) $row->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'source' => (string) $row->source,
                'occurredAt' => (string) $row->occurred_at,
                'correlationId' => $row->correlation_id,
                'sourceVersion' => $row->source_version,
                'metadata' => $row->metadata ? json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR) : null,
            ],
        ]);
    }
}
