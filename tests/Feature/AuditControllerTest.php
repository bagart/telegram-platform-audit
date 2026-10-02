<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        \Illuminate\Auth\Middleware\Authenticate::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);
});

it('lists audit entries', function () {
    $sink = app(AuditSinkContract::class);
    $sink->append(new AuditEntry(
        id: Str::uuid()->toString(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot-1', subjectType: 'module', subjectId: 'antispam'),
        operation: 'module.enabled',
        oldState: null,
        newState: ['enabled' => true],
        source: 'management',
        occurredAt: now()->toIso8601String(),
    ));
    $sink->append(new AuditEntry(
        id: Str::uuid()->toString(),
        actor: new AuditActor(type: 'user', id: '2'),
        target: new AuditTarget(botId: 'bot-2', subjectType: 'module', subjectId: 'tts'),
        operation: 'module.disabled',
        oldState: ['enabled' => true],
        newState: ['enabled' => false],
        source: 'management',
        occurredAt: now()->toIso8601String(),
    ));

    $response = $this->getJson(route('audit.index'));

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'actor', 'target', 'operation', 'source', 'occurredAt'],
        ],
        'meta' => ['total', 'limit', 'offset'],
    ]);
    $response->assertJsonPath('meta.total', 2);
});

it('filters entries by bot_id', function () {
    $sink = app(AuditSinkContract::class);
    $sink->append(new AuditEntry(
        id: Str::uuid()->toString(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot-1', subjectType: 'module', subjectId: 'antispam'),
        operation: 'module.enabled',
        oldState: null,
        newState: null,
        source: 'management',
        occurredAt: now()->toIso8601String(),
    ));
    $sink->append(new AuditEntry(
        id: Str::uuid()->toString(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot-2', subjectType: 'module', subjectId: 'tts'),
        operation: 'module.enabled',
        oldState: null,
        newState: null,
        source: 'management',
        occurredAt: now()->toIso8601String(),
    ));

    $response = $this->getJson(route('audit.index', ['bot_id' => 'bot-1']));

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
});

it('returns 404 for non-existent entry', function () {
    $response = $this->getJson(route('audit.show', ['id' => 'non-existent-id']));

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Audit entry not found']);
});

it('returns single entry by id', function () {
    $sink = app(AuditSinkContract::class);
    $id = Str::uuid()->toString();
    $entry = new AuditEntry(
        id: $id,
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot-1', subjectType: 'module', subjectId: 'antispam'),
        operation: 'module.enabled',
        oldState: null,
        newState: null,
        source: 'management',
        occurredAt: now()->toIso8601String(),
    );
    $sink->append($entry);

    $response = $this->getJson(route('audit.show', ['id' => $id]));

    $response->assertOk();
    $response->assertJsonPath('data.operation', 'module.enabled');
    $response->assertJsonPath('data.id', $id);
});

it('respects pagination limits', function () {
    $sink = app(AuditSinkContract::class);
    for ($i = 0; $i < 5; $i++) {
        $sink->append(new AuditEntry(
            id: Str::uuid()->toString(),
            actor: new AuditActor(type: 'user', id: (string) ($i + 1)),
            target: new AuditTarget(botId: 'bot-1', subjectType: 'test', subjectId: "item-{$i}"),
            operation: 'test.operation',
            oldState: null,
            newState: null,
            source: 'test',
            occurredAt: now()->toIso8601String(),
        ));
    }

    $response = $this->getJson(route('audit.index', ['limit' => 2, 'offset' => 0]));

    $response->assertOk();
    $response->assertJsonPath('meta.limit', 2);
    $response->assertJsonCount(2, 'data');
});

it('redirects unverified users from audit routes', function () {
    $this->withoutMiddleware([\Illuminate\Auth\Middleware\EnsureEmailIsVerified::class]);

    $user = \App\Models\User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('audit.index'));

    $response->assertOk();
});
