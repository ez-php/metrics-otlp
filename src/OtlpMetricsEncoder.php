<?php

declare(strict_types=1);

namespace EzPhp\MetricsOtlp;

/**
 * Class OtlpMetricsEncoder
 *
 * Turns an Aggregation into an OTLP/HTTP JSON `ExportMetricsServiceRequest`:
 * counters as monotonic `sum`, gauges as `gauge`, histograms as explicit-bucket
 * `histogram`, sums and histograms with AGGREGATION_TEMPORALITY_DELTA (1).
 * Data points of one metric name share one metric entry. 64-bit integers
 * (timestamps, counts) are JSON strings, as the OTLP JSON mapping requires.
 *
 * @phpstan-import-type Sum from Aggregation
 * @phpstan-import-type Gauge from Aggregation
 * @phpstan-import-type Histogram from Aggregation
 *
 * @package EzPhp\MetricsOtlp
 */
final readonly class OtlpMetricsEncoder
{
    public const string SCOPE = 'ez-php/metrics-otlp';

    private const int DELTA = 1;

    /**
     * @param string $serviceName Value of the `service.name` resource attribute.
     */
    public function __construct(private string $serviceName)
    {
    }

    /**
     * @param Aggregation           $aggregation
     * @param int                   $time         Window end, Unix nanoseconds.
     * @param array<string, string> $descriptions Metric name => help text.
     *
     * @return string JSON request body.
     */
    public function encode(Aggregation $aggregation, int $time, array $descriptions): string
    {
        $start = (string) $aggregation->startTime();
        $end = (string) $time;
        $metrics = [];

        foreach ($aggregation->sums() as $sum) {
            $metrics[$sum['name']]['sum']['dataPoints'][] = self::point($sum['labels'], $start, $end) + ['asDouble' => $sum['value']];
            $metrics[$sum['name']]['sum'] += ['aggregationTemporality' => self::DELTA, 'isMonotonic' => true];
        }

        foreach ($aggregation->gauges() as $gauge) {
            $metrics[$gauge['name']]['gauge']['dataPoints'][] = [
                'attributes' => self::attributes($gauge['labels']),
                'timeUnixNano' => (string) $gauge['time'],
                'asDouble' => $gauge['value'],
            ];
        }

        foreach ($aggregation->histograms() as $histogram) {
            $metrics[$histogram['name']]['histogram']['dataPoints'][] = self::point($histogram['labels'], $start, $end) + [
                'count' => (string) $histogram['count'],
                'sum' => $histogram['sum'],
                'min' => $histogram['min'],
                'max' => $histogram['max'],
                'bucketCounts' => array_map(strval(...), $histogram['buckets']),
                'explicitBounds' => $histogram['bounds'],
            ];
            $metrics[$histogram['name']]['histogram'] += ['aggregationTemporality' => self::DELTA];
        }

        $entries = [];

        foreach ($metrics as $name => $data) {
            $entries[] = ['name' => $name, 'description' => $descriptions[$name] ?? ''] + $data;
        }

        return json_encode([
            'resourceMetrics' => [[
                'resource' => ['attributes' => self::attributes(['service.name' => $this->serviceName])],
                'scopeMetrics' => [[
                    'scope' => ['name' => self::SCOPE],
                    'metrics' => $entries,
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $labels
     * @param string                $start
     * @param string                $end
     *
     * @return array{attributes: list<array{key: string, value: array{stringValue: string}}>, startTimeUnixNano: string, timeUnixNano: string}
     */
    private static function point(array $labels, string $start, string $end): array
    {
        return ['attributes' => self::attributes($labels), 'startTimeUnixNano' => $start, 'timeUnixNano' => $end];
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{key: string, value: array{stringValue: string}}>
     */
    private static function attributes(array $labels): array
    {
        $attributes = [];

        foreach ($labels as $key => $value) {
            $attributes[] = ['key' => $key, 'value' => ['stringValue' => $value]];
        }

        return $attributes;
    }
}
