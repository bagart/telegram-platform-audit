<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\Laravel\AuditHasher;

it('computes deterministic hash for same entry data', function () {
    $hasher = new AuditHasher();
    $entry = AuditEntry::now(
        id: 'test-id-1',
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    );

    $hash1 = $hasher->computeHash($entry, null);
    $hash2 = $hasher->computeHash($entry, null);

    expect($hash1)->toBe($hash2)
        ->and(strlen($hash1))->toBe(64);
});

it('produces different hashes with different prevHash', function () {
    $hasher = new AuditHasher();
    $entry = AuditEntry::now(
        id: 'test-id-1',
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    );

    $hash1 = $hasher->computeHash($entry, null);
    $hash2 = $hasher->computeHash($entry, $hash1);

    expect($hash1)->not->toBe($hash2);
});

it('produces different hashes for different entries', function () {
    $hasher = new AuditHasher();
    $entry1 = AuditEntry::now(
        id: 'test-id-1',
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    );
    $entry2 = AuditEntry::now(
        id: 'test-id-2',
        actor: new AuditActor(type: 'user', id: '2'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '2'),
        operation: 'test.updated',
        source: 'test',
    );

    $hash1 = $hasher->computeHash($entry1, null);
    $hash2 = $hasher->computeHash($entry2, null);

    expect($hash1)->not->toBe($hash2);
});
