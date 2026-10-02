<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

/**
 * In-memory audit counters for real-time observability.
 *
 * Tracks append counts (by operation, by bot), failure counts (by operation),
 * and append latency histogram. Counters are process-scoped and reset on reboot.
 *
 * Usage:
 *   $counters->recordAppend('bot.created', 'bot1', 12);  // 12ms latency
 *   $counters->recordFailure('bot.created');
 *   $snapshot = $counters->snapshot();
 */
final class AuditCounters
{
    /** @var array<string, int> operation => count */
    private array $appendedByOperation = [];

    /** @var array<string, int> botId => count */
    private array $appendedByBot = [];

    /** @var array<string, int> operation => count */
    private array $failedByOperation = [];

    /** @var list<int> latency samples in ms */
    private array $latencySamples = [];

    private int $totalAppended = 0;

    private int $totalFailed = 0;

    private const int MAX_LATENCY_SAMPLES = 1000;

    public function recordAppend(string $operation, ?string $botId, int $latencyMs): void
    {
        $this->appendedByOperation[$operation] = ($this->appendedByOperation[$operation] ?? 0) + 1;
        $this->totalAppended++;

        if ($botId !== null) {
            $this->appendedByBot[$botId] = ($this->appendedByBot[$botId] ?? 0) + 1;
        }

        if (count($this->latencySamples) < self::MAX_LATENCY_SAMPLES) {
            $this->latencySamples[] = $latencyMs;
        }
    }

    public function recordFailure(string $operation): void
    {
        $this->failedByOperation[$operation] = ($this->failedByOperation[$operation] ?? 0) + 1;
        $this->totalFailed++;
    }

    /**
     * @return array{
     *     total_appended: int,
     *     total_failed: int,
     *     by_operation: array<string, int>,
     *     by_bot: array<string, int>,
     *     failed_by_operation: array<string, int>,
     *     latency: array{min: int, max: int, avg: float, p50: int, p95: int, p99: int, count: int},
     * }
     */
    public function snapshot(): array
    {
        arsort($this->appendedByOperation);
        arsort($this->appendedByBot);
        arsort($this->failedByOperation);

        return [
            'total_appended' => $this->totalAppended,
            'total_failed' => $this->totalFailed,
            'by_operation' => $this->appendedByOperation,
            'by_bot' => $this->appendedByBot,
            'failed_by_operation' => $this->failedByOperation,
            'latency' => $this->computeLatencyStats(),
        ];
    }

    /**
     * @return array{min: int, max: int, avg: float, p50: int, p95: int, p99: int, count: int}
     */
    private function computeLatencyStats(): array
    {
        if ($this->latencySamples === []) {
            return ['min' => 0, 'max' => 0, 'avg' => 0.0, 'p50' => 0, 'p95' => 0, 'p99' => 0, 'count' => 0];
        }

        $sorted = $this->latencySamples;
        sort($sorted);

        $count = count($sorted);

        return [
            'min' => $sorted[0],
            'max' => $sorted[$count - 1],
            'avg' => round(array_sum($sorted) / $count, 1),
            'p50' => $sorted[(int) ($count * 0.5)],
            'p95' => $sorted[(int) ($count * 0.95)],
            'p99' => $sorted[(int) ($count * 0.99)],
            'count' => $count,
        ];
    }
}
