<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Listeners;

use BAGArt\TelegramBot\Contracts\Modules\ModuleAuditEventContract;
use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditException;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records audit entries for module-owned events dispatched through
 * ModuleAuditEventContract.
 *
 * Respects failure policy: on FailClosed, rethrows as AuditException to
 * block the operation; on FailOpen, logs a warning and continues.
 */
final class GenericModuleAuditListener
{
    public function __construct(
        private readonly AuditSinkContract $sink,
        private readonly CorrelationContext $correlation,
        private readonly AuditFailurePolicyResolver $policyResolver,
    ) {
    }

    public function handle(ModuleAuditEventContract $event): void
    {
        $operation = $event->operation();

        $entry = AuditEntry::now(
            id: AuditEntry::generateId(),
            actor: new AuditActor(
                type: $event->actorType() ?? AuditActor::TYPE_SYSTEM,
                id: $event->actorId() ?? 'system',
            ),
            target: new AuditTarget(
                botId: $event->botId(),
                subjectType: $event->subjectType() ?? 'module_action',
                subjectId: $event->subjectId() ?? $operation,
            ),
            operation: $operation,
            oldState: $event->oldState(),
            newState: $event->newState(),
            source: $event->moduleId(),
            correlationId: $this->correlation->id(),
            metadata: $event->context(),
        );

        try {
            $this->sink->append($entry);
        } catch (Throwable $e) {
            $policy = $this->policyResolver->resolve($entry);

            if ($policy === AuditFailurePolicy::FailClosed) {
                throw new AuditException(
                    "Audit append failed for operation '{$operation}': {$e->getMessage()}",
                    previous: $e,
                );
            }

            Log::warning('Audit append failed (fail_open)', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
