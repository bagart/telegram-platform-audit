<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Unit;

use BAGArt\TelegramBotAudit\MutableCorrelationContext;

it('returns null when no id set', function () {
    $context = new MutableCorrelationContext();
    expect($context->id())->toBeNull();
});

it('returns provided id', function () {
    $context = new MutableCorrelationContext('test-id');
    expect($context->id())->toBe('test-id');
});

it('can set id after construction', function () {
    $context = new MutableCorrelationContext();
    $context->setId('new-id');
    expect($context->id())->toBe('new-id');
});

it('can set id to null', function () {
    $context = new MutableCorrelationContext('initial');
    $context->setId(null);
    expect($context->id())->toBeNull();
});
