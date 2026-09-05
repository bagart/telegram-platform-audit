<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

use DateTimeImmutable;
use JsonSerializable;
use RuntimeException;

/**
 * A single append-only audit event.
 *
 * Versioned persisted DTO (SCHEMA_VERSION + fromJsonV1), serialized as JSON
 * into the audit sink. Carries actor, tenant/bot/chat scope, subject,
 * operation, old->new state, source and timestamp, plus a correlation ID
 * propagated from the request context.
 *
 * Secrets must never be placed in this DTO: there is no credential field by
 * design (see tests). The metadata field must also never contain credentials.
 */
final readonly class AuditEntry implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  string  $id  Unique entry ID (e.g. ULID/UUID assigned by the producer).
     * @param  AuditActor  $actor  Who performed the operation.
     * @param  AuditTarget  $target  Tenant/bot/chat scope + what was acted upon.
     * @param  Operation|string  $operation  Platform enum or namespaced module string (e.g. "module.enabled", "proxy.probe.completed").
     * @param  array<string, mixed>|null  $oldState  State before the operation, if applicable.
     * @param  array<string, mixed>|null  $newState  State after the operation, if applicable.
     * @param  string  $source  Producing subsystem, e.g. "engine", "management", "access-control".
     * @param  string  $occurredAt  ISO 8601 event timestamp.
     * @param  string|null  $correlationId  Correlation ID propagated from request context.
     * @param  string|null  $sourceVersion  Version of the module/lib that produced this entry.
     * @param  array<string, mixed>|null  $metadata  Free-form structured data (reason, request_ip, module_version); credentials forbidden.
     */
    public function __construct(
        public string $id,
        public AuditActor $actor,
        public AuditTarget $target,
        public Operation|string $operation,
        public ?array $oldState,
        public ?array $newState,
        public string $source,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $sourceVersion = null,
        public ?array $metadata = null,
    ) {
    }

    /**
     * Build an entry stamped with the current time.
     */
    public static function now(
        string $id,
        AuditActor $actor,
        AuditTarget $target,
        Operation|string $operation,
        ?array $oldState = null,
        ?array $newState = null,
        string $source = 'unknown',
        ?string $correlationId = null,
        ?string $sourceVersion = null,
        ?array $metadata = null,
    ): self {
        return new self(
            id: $id,
            actor: $actor,
            target: $target,
            operation: $operation,
            oldState: $oldState,
            newState: $newState,
            source: $source,
            occurredAt: (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            correlationId: $correlationId,
            sourceVersion: $sourceVersion,
            metadata: $metadata,
        );
    }

    /**
     * Return the canonical string representation of the operation.
     */
    public function operationString(): string
    {
        return $this->operation instanceof Operation
            ? $this->operation->value
            : $this->operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'actor' => $this->actor->toArray(),
            'target' => $this->target->toArray(),
            'operation' => $this->operationString(),
            'oldState' => $this->oldState,
            'newState' => $this->newState,
            'source' => $this->source,
            'occurredAt' => $this->occurredAt,
            'correlationId' => $this->correlationId,
            'sourceVersion' => $this->sourceVersion,
            'metadata' => $this->metadata,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new RuntimeException("Unsupported AuditEntry schemaVersion: {$schemaVersion}"),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $operationValue = (string) $data['operation'];

        return new self(
            id: (string)$data['id'],
            actor: AuditActor::fromArray((array)$data['actor']),
            target: AuditTarget::fromArray((array)$data['target']),
            operation: Operation::tryFrom($operationValue) ?? $operationValue,
            oldState: isset($data['oldState']) ? (array)$data['oldState'] : null,
            newState: isset($data['newState']) ? (array)$data['newState'] : null,
            source: (string)$data['source'],
            occurredAt: (string)($data['occurredAt'] ?? (new DateTimeImmutable())->format(DateTimeImmutable::ATOM)),
            correlationId: isset($data['correlationId']) ? (string)$data['correlationId'] : null,
            sourceVersion: isset($data['sourceVersion']) ? (string) $data['sourceVersion'] : null,
            metadata: isset($data['metadata']) ? (array) $data['metadata'] : null,
        );
    }
}
