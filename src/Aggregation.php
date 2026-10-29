<?php

declare(strict_types=1);

namespace EzPhp\MetricsOtlp;

use EzPhp\Metrics\MetricRecorded;
use EzPhp\Metrics\MetricType;

/**
 * Class Aggregation
 *
 * The values recorded in one delta window, aggregated per metric name and
 * label set: counters summed, gauges keep their last value, histograms count
 * observations into explicit buckets (bucket i holds values in
 * (bounds[i-1], bounds[i]], the last bucket everything above the last bound —
 * the same upper-inclusive rule as Prometheus `le`).
 *
 * @phpstan-type Sum array{name: string, labels: array<string, string>, value: float}
 * @phpstan-type Gauge array{name: string, labels: array<string, string>, value: float, time: int}
 * @phpstan-type Histogram array{name: string, labels: array<string, string>, bounds: list<float>, count: int, sum: float, min: float, max: float, buckets: list<int>}
 *
 * @package EzPhp\MetricsOtlp
 */
final class Aggregation
{
    /**
     * @var array<string, Sum>
     */
    private array $sums = [];

    /**
     * @var array<string, Gauge>
     */
    private array $gauges = [];

    /**
     * @var array<string, Histogram>
     */
    private array $histograms = [];

    private int $points = 0;

    /**
     * @param int $startTime Window start, Unix nanoseconds.
     */
    public function __construct(private readonly int $startTime)
    {
    }

    /**
     * Add one recorded value.
     *
     * @param MetricRecorded $event
     * @param int            $time   Unix nanoseconds of the recording.
     * @param list<float>    $bounds Histogram bucket upper bounds, ascending (ignored for other types).
     *
     * @return void
     */
    public function record(MetricRecorded $event, int $time, array $bounds): void
    {
        $labels = $event->labels;
        ksort($labels);
        $key = $event->name . "\0" . http_build_query($labels);
        $this->points++;

        match ($event->type) {
            MetricType::COUNTER => $this->addSum($key, $event->name, $labels, $event->value),
            MetricType::GAUGE => $this->gauges[$key] = ['name' => $event->name, 'labels' => $labels, 'value' => $event->value, 'time' => $time],
            MetricType::HISTOGRAM => $this->observe($key, $event->name, $labels, $bounds, $event->value),
        };
    }

    /**
     * Values recorded in this window (each record() call counts once).
     *
     * @return int
     */
    public function points(): int
    {
        return $this->points;
    }

    /**
     * @return int Window start, Unix nanoseconds.
     */
    public function startTime(): int
    {
        return $this->startTime;
    }

    /**
     * @return list<Sum>
     */
    public function sums(): array
    {
        return array_values($this->sums);
    }

    /**
     * @return list<Gauge>
     */
    public function gauges(): array
    {
        return array_values($this->gauges);
    }

    /**
     * @return list<Histogram>
     */
    public function histograms(): array
    {
        return array_values($this->histograms);
    }

    /**
     * @param string                $key
     * @param string                $name
     * @param array<string, string> $labels
     * @param float                 $value
     *
     * @return void
     */
    private function addSum(string $key, string $name, array $labels, float $value): void
    {
        $this->sums[$key] ??= ['name' => $name, 'labels' => $labels, 'value' => 0.0];
        $this->sums[$key]['value'] += $value;
    }

    /**
     * @param string                $key
     * @param string                $name
     * @param array<string, string> $labels
     * @param list<float>           $bounds
     * @param float                 $value
     *
     * @return void
     */
    private function observe(string $key, string $name, array $labels, array $bounds, float $value): void
    {
        $histogram = $this->histograms[$key] ?? [
            'name' => $name,
            'labels' => $labels,
            'bounds' => $bounds,
            'count' => 0,
            'sum' => 0.0,
            'min' => $value,
            'max' => $value,
            'buckets' => array_fill(0, count($bounds) + 1, 0),
        ];

        $bucket = count($histogram['bounds']);

        foreach ($histogram['bounds'] as $index => $bound) {
            if ($value <= $bound) {
                $bucket = $index;

                break;
            }
        }

        $buckets = $histogram['buckets'];
        $buckets[$bucket]++;
        $histogram['buckets'] = array_values($buckets);
        $histogram['count']++;
        $histogram['sum'] += $value;
        $histogram['min'] = min($histogram['min'], $value);
        $histogram['max'] = max($histogram['max'], $value);

        $this->histograms[$key] = $histogram;
    }
}
