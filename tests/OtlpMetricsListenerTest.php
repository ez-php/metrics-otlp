<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Metrics\Histogram;
use EzPhp\Metrics\MetricRecorded;
use EzPhp\Metrics\MetricsRegistry;
use EzPhp\Metrics\MetricType;
use EzPhp\MetricsOtlp\Aggregation;
use EzPhp\MetricsOtlp\OtlpMetricsEncoder;
use EzPhp\MetricsOtlp\OtlpMetricsListener;
use EzPhp\MetricsOtlp\Transport\MetricsTransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\MetricsOtlp\RecordingMetricsTransport;

/**
 * Class OtlpMetricsListenerTest
 *
 * Drives the listener through a real MetricsRegistry and checks the OTLP JSON.
 *
 * @package Tests
 */
#[CoversClass(OtlpMetricsListener::class)]
#[CoversClass(Aggregation::class)]
#[CoversClass(OtlpMetricsEncoder::class)]
final class OtlpMetricsListenerTest extends TestCase
{
    private const string URL = 'http://collector:4318/v1/metrics';

    private int $now = 1_000;

    private MetricsRegistry $registry;

    private RecordingMetricsTransport $transport;

    protected function setUp(): void
    {
        $this->registry = new MetricsRegistry();
        $this->transport = new RecordingMetricsTransport();
    }

    public function test_exports_a_delta_window_as_otlp_json(): void
    {
        $listener = $this->listener();
        $this->registry->listen($listener);

        $requests = $this->registry->counter('http_requests_total', 'Handled requests');
        $requests->inc(['method' => 'GET']);
        $requests->incBy(2, ['method' => 'GET']);
        $requests->inc(['method' => 'POST']);
        $this->now = 2_000;
        $this->registry->gauge('queue_size', 'Jobs waiting')->set(7);
        $this->registry->gauge('queue_size', 'Jobs waiting')->set(5);
        $latency = $this->registry->histogram('latency_seconds', 'Request latency', [0.1, 0.5]);
        $latency->observe(0.05);
        $latency->observe(0.1);
        $latency->observe(0.3);
        $latency->observe(2.0);
        $this->now = 3_000;

        self::assertTrue($listener->flush());

        $request = $this->transport->requests[0];
        self::assertSame(self::URL, $request['url']);
        self::assertSame(['Content-Type' => 'application/json', 'Authorization' => 'Bearer t'], $request['headers']);
        self::assertSame(
            [['key' => 'service.name', 'value' => ['stringValue' => 'shop']]],
            $this->transport->at(0, 'resourceMetrics', 0, 'resource', 'attributes'),
        );
        self::assertSame(['name' => 'ez-php/metrics-otlp'], $this->transport->at(0, 'resourceMetrics', 0, 'scopeMetrics', 0, 'scope'));

        $metrics = $this->transport->metrics();

        self::assertSame([
            'name' => 'http_requests_total',
            'description' => 'Handled requests',
            'sum' => [
                'dataPoints' => [
                    ['attributes' => [['key' => 'method', 'value' => ['stringValue' => 'GET']]], 'startTimeUnixNano' => '1000', 'timeUnixNano' => '3000', 'asDouble' => 3.0],
                    ['attributes' => [['key' => 'method', 'value' => ['stringValue' => 'POST']]], 'startTimeUnixNano' => '1000', 'timeUnixNano' => '3000', 'asDouble' => 1.0],
                ],
                'aggregationTemporality' => 1,
                'isMonotonic' => true,
            ],
        ], $metrics['http_requests_total']);

        self::assertSame(
            ['dataPoints' => [['attributes' => [], 'timeUnixNano' => '2000', 'asDouble' => 5.0]]],
            $metrics['queue_size']['gauge'],
        );

        self::assertSame([
            'dataPoints' => [[
                'attributes' => [],
                'startTimeUnixNano' => '1000',
                'timeUnixNano' => '3000',
                'count' => '4',
                'sum' => 2.45,
                'min' => 0.05,
                'max' => 2.0,
                'bucketCounts' => ['2', '1', '1'],
                'explicitBounds' => [0.1, 0.5],
            ]],
            'aggregationTemporality' => 1,
        ], $metrics['latency_seconds']['histogram']);
    }

    public function test_each_flush_starts_a_new_window_and_empty_windows_send_nothing(): void
    {
        $listener = $this->listener();
        $this->registry->listen($listener);
        $counter = $this->registry->counter('jobs_total', 'Jobs');

        self::assertTrue($listener->flush());
        self::assertSame([], $this->transport->requests);

        $counter->inc();
        $this->now = 2_000;
        $listener->flush();
        $counter->incBy(4);
        $this->now = 3_000;
        $listener->flush();

        $point = ['resourceMetrics', 0, 'scopeMetrics', 0, 'metrics', 0, 'sum', 'dataPoints', 0];
        self::assertSame('2000', $this->transport->at(1, ...[...$point, 'startTimeUnixNano']));
        self::assertSame(4.0, $this->transport->at(1, ...[...$point, 'asDouble']));
    }

    public function test_exports_when_max_points_are_reached(): void
    {
        $this->registry->listen($this->listener(maxPoints: 3));
        $counter = $this->registry->counter('hits_total', 'Hits');

        $counter->inc();
        $counter->inc();
        self::assertSame([], $this->transport->requests);

        $counter->inc();
        self::assertCount(1, $this->transport->requests);
    }

    public function test_histograms_without_description_use_the_default_buckets(): void
    {
        $listener = $this->listener();
        $listener->recorded(new MetricRecorded(MetricType::HISTOGRAM, 'unregistered', 0.2));
        $listener->flush();

        $metric = ['resourceMetrics', 0, 'scopeMetrics', 0, 'metrics', 0];
        self::assertSame(Histogram::DEFAULT_BUCKETS, $this->transport->at(0, ...[...$metric, 'histogram', 'dataPoints', 0, 'explicitBounds']));
        self::assertSame('', $this->transport->metrics()['unregistered']['description']);
    }

    public function test_a_failing_transport_drops_the_window_without_throwing(): void
    {
        $throwing = new class () implements MetricsTransportInterface {
            public function send(string $url, string $payload, array $headers): bool
            {
                throw new RuntimeException('collector down');
            }
        };
        $listener = new OtlpMetricsListener($throwing, new OtlpMetricsEncoder('shop'), $this->registry->storage(), self::URL, flushOnShutdown: false);
        $this->registry->listen($listener);
        $this->registry->counter('c_total', 'C')->inc();

        self::assertFalse($listener->flush());
        self::assertTrue($listener->flush()); // window already dropped

        $rejecting = new OtlpMetricsListener(new RecordingMetricsTransport(false), new OtlpMetricsEncoder('shop'), $this->registry->storage(), self::URL, flushOnShutdown: false);
        $rejecting->recorded(new MetricRecorded(MetricType::COUNTER, 'c_total', 1.0));
        self::assertFalse($rejecting->flush());
    }

    public function test_flush_on_shutdown_defers_the_export_instead_of_sending_at_once(): void
    {
        $listener = new OtlpMetricsListener($this->transport, new OtlpMetricsEncoder('shop'), $this->registry->storage(), self::URL);
        $listener->recorded(new MetricRecorded(MetricType::COUNTER, 'c_total', 1.0));
        $listener->recorded(new MetricRecorded(MetricType::COUNTER, 'c_total', 1.0));

        self::assertSame([], $this->transport->requests);

        $listener->flush(); // leave nothing for the registered shutdown function
        self::assertCount(1, $this->transport->requests);
    }

    public function test_default_clock_uses_wall_time_in_nanoseconds(): void
    {
        $before = (int) (microtime(true) * 1e9);
        $listener = new OtlpMetricsListener($this->transport, new OtlpMetricsEncoder('shop'), $this->registry->storage(), self::URL, flushOnShutdown: false);
        $listener->recorded(new MetricRecorded(MetricType::COUNTER, 'c_total', 1.0));
        $listener->flush();

        $start = $this->transport->at(0, 'resourceMetrics', 0, 'scopeMetrics', 0, 'metrics', 0, 'sum', 'dataPoints', 0, 'startTimeUnixNano');
        self::assertIsString($start);
        self::assertGreaterThanOrEqual($before - 1_000_000_000, (int) $start);
        self::assertLessThanOrEqual($before + 1_000_000_000, (int) $start);
    }

    /**
     * @param int $maxPoints
     *
     * @return OtlpMetricsListener
     */
    private function listener(int $maxPoints = 1000): OtlpMetricsListener
    {
        return new OtlpMetricsListener(
            $this->transport,
            new OtlpMetricsEncoder('shop'),
            $this->registry->storage(),
            self::URL,
            ['Authorization' => 'Bearer t'],
            $maxPoints,
            false,
            fn (): int => $this->now,
        );
    }
}
