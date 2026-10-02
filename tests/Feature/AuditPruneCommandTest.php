<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $sink = app(AuditSinkContract::class);

    $sink->append(new AuditEntry(
        id: 'old-entry',
        actor: new AuditActor(type: 'system', id: 'sys'),
        target: new AuditTarget(botId: 'bot-1', subjectType: 'module', subjectId: 'cinema'),
        operation: 'module.enabled',
        oldState: null,
        newState: null,
        source: 'engine',
        occurredAt: now()->subDays(90)->format(\DateTimeImmutable::ATOM),
    ));

    $sink->append(AuditEntry::now(
        id: 'recent-entry',
        actor: new AuditActor(type: 'system', id: 'sys'),
        target: new AuditTarget(botId: 'bot-1', subjectType: 'module', subjectId: 'cinema'),
        operation: 'module.disabled',
        source: 'engine',
    ));
});

it('prunes old entries', function () {
    $this->artisan('audit:prune', ['--days' => 30])
        ->expectsOutputToContain('Pruned 1 entries older than 30 days');

    $remaining = DB::table('audit_entries')->count();
    $this->assertSame(1, $remaining);
});

it('dry run does not delete entries', function () {
    $this->artisan('audit:prune', ['--days' => 30, '--dry-run' => true])
        ->expectsOutputToContain('Would prune 1 entries older than 30 days');

    $remaining = DB::table('audit_entries')->count();
    $this->assertSame(2, $remaining);
});

it('keeps recent entries when retention is high', function () {
    $this->artisan('audit:prune', ['--days' => 365])
        ->expectsOutputToContain('Pruned 0 entries older than 365 days');

    $remaining = DB::table('audit_entries')->count();
    $this->assertSame(2, $remaining);
});
