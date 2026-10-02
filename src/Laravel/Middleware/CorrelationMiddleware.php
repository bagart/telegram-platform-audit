<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Middleware;

use BAGArt\TelegramBotAudit\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP middleware that sets the correlation context from the request.
 *
 * Extracts or generates a correlation ID and stores it in the
 * CorrelationContext singleton for the duration of the request.
 */
final class CorrelationMiddleware
{
    public function __construct(
        private readonly CorrelationContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Use existing header or generate a new correlation ID
        $correlationId = $request->header('X-Correlation-Id')
            ?? $request->header('X-Request-Id')
            ?? Str::uuid()->toString();

        // Set the correlation ID on the context
        $this->context->setId($correlationId);

        $response = $next($request);

        // Add correlation ID to response headers
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
