<?php

declare(strict_types=1);

namespace EzPhp\MetricsOtlp;

use Closure;
use EzPhp\Metrics\Histogram;
use EzPhp\Metrics\MetricRecorded;
use EzPhp\Metrics\MetricsListenerInterface;
use EzPhp\Metrics\MetricType;
use EzPhp\Metrics\Storage\MetricsStorageInterface;
use EzPhp\MetricsOtlp\Transport\MetricsTransportInterface;

/**
 * Class OtlpMetricsListener
 *
 * Bridges ez-php/metrics into OpenTelemetry: register it with
 * `MetricsRegistry::listen()` and every recorded value is aggregated in memory
 * and exported as an OTLP/HTTP JSON request (delta temporality) — when
 * `$maxPoints` values are waiting, on flush(), and — unless disabled — at the
 * end of the PHP process via a shutdown function. Long-running processes (queue
 * workers) should call flush() themselves, e.g. after each job.
 *
 * Histogram bucket bounds and metric help texts are read from the registry's
 * storage descriptions (`MetricsRegistry::storage()`), since MetricRecorded
 * carries neither.
 *
 * Never throws: a failed export drops that window's data (delta points cannot
 * be re-sent meaningfully later), exactly like a lost StatsD packet.
 *
 * @package EzPhp\MetricsOtlp
 */
final class OtlpMetricsListener implements MetricsListenerInterface
{
    private Aggregation $aggregation;

    private bool $shutdownRegistered = false;

    /**
     * Bucket bounds per histogram name — read from storage once, since a Redis
     * or APCu storage would otherwise be queried on every observation.
     *
     * @var array<string, list<float>>
     */
    private array $bounds = [];

    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param MetricsTransportInterface $transport
     * @param OtlpMetricsEncoder        $encoder
     * @param MetricsStorageInterface   $storage         Source of bucket bounds and help texts.
     * @param string                    $endpoint        OTLP/HTTP metrics URL, e.g. http://collector:4318/v1/metrics.
     * @param array<string, string>     $headers         Extra request headers (e.g. auth).
     * @param int                       $maxPoints       Export once this many values are waiting.
     * @param bool                      $flushOnShutdown Export what is left when the process ends.
     * @param (Closure(): int)|null     $clock           Current Unix time in nanoseconds.
     */
    public function __construct(
        private readonly MetricsTransportInterface $transport,
        private readonly OtlpMetricsEncoder $encoder,
        private readonly MetricsStorageInterface $storage,
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly int $maxPoints = 1000,
        private readonly bool $flushOnShutdown = true,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1_000_000) * 1000;
        $this->aggregation = new Aggregation(($this->clock)());
    }

    /**
     * @param MetricRecorded $event
     *
     * @return void
     */
    public function recorded(MetricRecorded $event): void
    {
        if ($this->flushOnShutdown && !$this->shutdownRegistered) {
            register_shutdown_function(function (): void {
                $this->flush();
            });
            $this->shutdownRegistered = true;
        }

        $bounds = $event->type === MetricType::HISTOGRAM ? $this->bounds($event->name) : [];
        $this->aggregation->record($event, ($this->clock)(), $bounds);

        if ($this->aggregation->points() >= $this->maxPoints) {
            $this->flush();
        }
    }

    /**
     * Export the current window and start a new one. No request when nothing
     * was recorded.
     *
     * @return bool True when the window was empty or the collector accepted it.
     */
    public function flush(): bool
    {
        if ($this->aggregation->points() === 0) {
            return true;
        }

        $now = ($this->clock)();
        $window = $this->aggregation;
        $this->aggregation = new Aggregation($now);

        try {
            $payload = $this->encoder->encode($window, $now, $this->helpTexts());

            return $this->transport->send(
                $this->endpoint,
                $payload,
                array_merge(['Content-Type' => 'application/json'], $this->headers),
            );
        } catch (\Throwable) {
            return false; // Metrics must never break the request that recorded them.
        }
    }

    /**
     * @param string $name
     *
     * @return list<float>
     */
    private function bounds(string $name): array
    {
        return $this->bounds[$name] ??= $this->storage->descriptions()[$name]['buckets'] ?? Histogram::DEFAULT_BUCKETS;
    }

    /**
     * @return array<string, string>
     */
    private function helpTexts(): array
    {
        return array_map(static fn (array $description): string => $description['help'], $this->storage->descriptions());
    }
}
