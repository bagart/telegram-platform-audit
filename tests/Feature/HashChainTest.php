<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\Laravel\AuditHasher;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditSink;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditQuery;
use BAGArt\TelegramBotAudit\AuditQueryFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('chains entries with hash and prev_hash', function () {
    $hasher = new AuditHasher();
    $sink = new DatabaseAuditSink(hasher: $hasher);

    $entry1 = AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    );
    $sink->append($entry1);

    $entry2 = AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '2'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '2'),
        operation: 'test.updated',
        source: 'test',
    );
    $sink->append($entry2);

    $row1 = DB::table('audit_entries')->orderBy('occurred_at')->first();
    $row2 = DB::table('audit_entries')->orderBy('occurred_at')->skip(1)->first();

    expect($row1->hash)->not->toBeNull()
        ->and($row1->prev_hash)->toBeNull()
        ->and($row2->hash)->not->toBeNull()
        ->and($row2->prev_hash)->toBe($row1->hash);
});

it('reads hash and prev_hash back from query', function () {
    $hasher = new AuditHasher();
    $sink = new DatabaseAuditSink(hasher: $hasher);
    $query = new DatabaseAuditQuery();

    $entry = AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    );
    $sink->append($entry);

    $entries = iterator_to_array($query->query(new AuditQueryFilter()));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->hash)->not->toBeNull()
        ->and($entries[0]->prevHash)->toBeNull();
});

it('verify command passes on clean chain', function () {
    $hasher = new AuditHasher();
    $sink = new DatabaseAuditSink(hasher: $hasher);

    foreach (range(1, 5) as $i) {
        $sink->append(AuditEntry::now(
            id: AuditEntry::generateId(),
            actor: new AuditActor(type: 'user', id: (string) $i),
            target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: (string) $i),
            operation: 'test.created',
            source: 'test',
        ));
    }

    $exitCode = \Illuminate\Support\Facades\Artisan::call('audit:verify');
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($output)->toContain('Chain OK')
        ->and($output)->toContain('5 entries verified')
        ->and($exitCode)->toBe(0);
});

it('verify command detects broken hash', function () {
    $hasher = new AuditHasher();
    $sink = new DatabaseAuditSink(hasher: $hasher);

    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    ));
    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '2'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '2'),
        operation: 'test.updated',
        source: 'test',
    ));

    // Tamper: overwrite hash in DB
    $firstId = DB::table('audit_entries')->orderBy('occurred_at')->first()->id;
    DB::table('audit_entries')
        ->where('id', $firstId)
        ->update(['hash' => 'tampered_hash_value']);

    $this->artisan('audit:verify')
        ->expectsOutputToContain('BREAK')
        ->expectsOutputToContain('Chain BROKEN')
        ->assertExitCode(1);
});
