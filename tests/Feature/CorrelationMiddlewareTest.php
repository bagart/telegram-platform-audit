<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\Laravel\Middleware\CorrelationMiddleware;
use BAGArt\TelegramBotAudit\MutableCorrelationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

it('sets correlation id from request header', function () {
    $context = new MutableCorrelationContext();
    $middleware = new CorrelationMiddleware($context);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_X_CORRELATION_ID' => 'my-correlation-id',
    ]);

    $response = $middleware->handle($request, fn ($r) => new Response('ok'));

    expect($context->id())->toBe('my-correlation-id');
    expect($response->headers->get('X-Correlation-Id'))->toBe('my-correlation-id');
});

it('generates correlation id when not provided', function () {
    $context = new MutableCorrelationContext();
    $middleware = new CorrelationMiddleware($context);

    $request = Request::create('/test', 'GET');

    $response = $middleware->handle($request, fn ($r) => new Response('ok'));

    expect($context->id())->not->toBeNull();
    expect($response->headers->get('X-Correlation-Id'))->not->toBeNull();
});

it('uses x-request-id as fallback', function () {
    $context = new MutableCorrelationContext();
    $middleware = new CorrelationMiddleware($context);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => 'request-id-123',
    ]);

    $response = $middleware->handle($request, fn ($r) => new Response('ok'));

    expect($context->id())->toBe('request-id-123');
});
