<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Unit;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\Operation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditEntryTest extends TestCase
{
    private const FIXED_TIME = '2026-08-27T12:00:00+00:00';

    public function test_json_round_trip_is_identical(): void
    {
        $entry = $this->entry();

        $json = json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = AuditEntry::fromJson($json);

        self::assertEquals($entry, $restored);
        self::assertSame(
            $json,
            json_decode(json_encode($restored, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_json_round_trip_with_null_optionals(): void
    {
        $entry = new AuditEntry(
            id: 'entry-2',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'scheduler'),
            target: new AuditTarget(null, 'bot_setting', 'timezone'),
            operation: 'config.read',
            oldState: null,
            newState: null,
            source: 'engine',
            occurredAt: self::FIXED_TIME,
        );

        $json = json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = AuditEntry::fromJson($json);

        self::assertEquals($entry, $restored);
        self::assertNull($restored->oldState);
        self::assertNull($restored->newState);
        self::assertNull($restored->correlationId);
        self::assertNull($restored->actor->displayName);
        self::assertNull($restored->target->botId);
        self::assertNull($restored->target->chatId);
        self::assertNull($restored->sourceVersion);
        self::assertNull($restored->metadata);
    }

    public function test_schema_version_is_serialized_and_supported(): void
    {
        $json = $this->entry()->jsonSerialize();

        self::assertSame(2, $json['schemaVersion']);
        self::assertSame(AuditEntry::SCHEMA_VERSION, AuditEntry::fromJson($json)->jsonSerialize()['schemaVersion']);
    }

    public function test_unsupported_schema_version_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        AuditEntry::fromJson(['schemaVersion' => 999]);
    }

    public function test_entry_is_immutable(): void
    {
        $entry = $this->entry();

        $this->expectException(\Error::class);
        $entry->operation = 'module.disable';
    }

    public function test_entry_has_no_credential_fields(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(AuditEntry::class))->getProperties(),
        );

        self::assertSame(
            ['id', 'actor', 'target', 'operation', 'oldState', 'newState', 'source', 'occurredAt', 'correlationId', 'sourceVersion', 'metadata', 'hash', 'prevHash'],
            $properties,
        );

        foreach ($properties as $property) {
            self::assertDoesNotMatchRegularExpression(
                '/credential|secret|token|password|apikey|api_key/i',
                $property,
                "AuditEntry property {$property} must never carry secrets",
            );
        }

        self::assertSame(['type', 'id', 'displayName'], $this->propertyNames(AuditActor::class));
        self::assertSame(['botId', 'subjectType', 'subjectId', 'chatId'], $this->propertyNames(AuditTarget::class));
    }

    public function test_operation_string_returns_canonical_value(): void
    {
        $enumEntry = new AuditEntry(
            id: 'e1',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: Operation::ModuleEnabled,
            oldState: null,
            newState: null,
            source: 'engine',
            occurredAt: self::FIXED_TIME,
        );

        self::assertSame('module.enabled', $enumEntry->operationString());

        $stringEntry = new AuditEntry(
            id: 'e2',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'proxy', 'pool-1'),
            operation: 'proxy.probe.completed',
            oldState: null,
            newState: null,
            source: 'proxy',
            occurredAt: self::FIXED_TIME,
        );

        self::assertSame('proxy.probe.completed', $stringEntry->operationString());
    }

    public function test_json_serializes_canonical_operation_string(): void
    {
        $entry = new AuditEntry(
            id: 'e1',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: Operation::ModuleEnabled,
            oldState: null,
            newState: null,
            source: 'engine',
            occurredAt: self::FIXED_TIME,
        );

        $json = $entry->jsonSerialize();
        self::assertSame('module.enabled', $json['operation']);
    }

    public function test_source_version_and_metadata_round_trip(): void
    {
        $entry = new AuditEntry(
            id: 'e1',
            actor: new AuditActor(AuditActor::TYPE_USER, '100'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: Operation::ModuleEnabled,
            oldState: null,
            newState: ['enabled' => true],
            source: 'engine',
            occurredAt: self::FIXED_TIME,
            sourceVersion: '1.2.3',
            metadata: ['reason' => 'admin request', 'request_ip' => '10.0.0.1'],
        );

        $json = json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = AuditEntry::fromJson($json);

        self::assertEquals($entry, $restored);
        self::assertSame('1.2.3', $restored->sourceVersion);
        self::assertSame(['reason' => 'admin request', 'request_ip' => '10.0.0.1'], $restored->metadata);
    }

    public function test_nullable_bot_id_in_target(): void
    {
        $target = new AuditTarget(null, 'platform', 'all');
        self::assertNull($target->botId);

        $json = $target->toArray();
        self::assertNull($json['botId']);

        $restored = AuditTarget::fromArray($json);
        self::assertNull($restored->botId);
    }

    private function entry(): AuditEntry
    {
        return new AuditEntry(
            id: 'entry-1',
            actor: new AuditActor(AuditActor::TYPE_USER, '100', 'Artur'),
            target: new AuditTarget('bot-1', 'module', 'cinema', chatId: 111),
            operation: 'module.enable',
            oldState: ['enabled' => false],
            newState: ['enabled' => true],
            source: 'engine',
            occurredAt: self::FIXED_TIME,
            correlationId: 'corr-abc',
        );
    }

    /**
     * @return list<string>
     */
    private function propertyNames(string $class): array
    {
        return array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass($class))->getProperties(),
        );
    }
}
