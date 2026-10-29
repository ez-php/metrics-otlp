<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Metrics\MetricsRegistry;
use EzPhp\MetricsOtlp\Aggregation;
use EzPhp\MetricsOtlp\MetricsOtlpServiceProvider;
use EzPhp\MetricsOtlp\OtlpMetricsEncoder;
use EzPhp\MetricsOtlp\OtlpMetricsListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\MetricsOtlp\ApplicationTestCase;

/**
 * Class MetricsOtlpServiceProviderTest
 *
 * Endpoint configured: the listener is attached at boot and exports what the
 * registry records, with service name and headers from config.
 *
 * @package Tests
 */
#[CoversClass(MetricsOtlpServiceProvider::class)]
#[UsesClass(OtlpMetricsListener::class)]
#[UsesClass(OtlpMetricsEncoder::class)]
#[UsesClass(Aggregation::class)]
final class MetricsOtlpServiceProviderTest extends ApplicationTestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected function configFiles(): array
    {
        return [
            'app' => ['name' => 'shop'],
            'metrics_otlp' => [
                'endpoint' => 'http://collector:4318/v1/metrics',
                'service_name' => '',
                'headers' => 'Authorization=Bearer%20t, X-Scope = eu ,=ignored,',
                'max_points' => 2,
                'timeout' => 'invalid',
            ],
        ];
    }

    public function test_recorded_metrics_are_exported_with_config_values(): void
    {
        $counter = $this->app()->make(MetricsRegistry::class)->counter('orders_total', 'Orders');

        $counter->inc();
        $counter->inc(); // max_points = 2 → export

        $request = self::$transport->requests[0];
        self::assertSame('http://collector:4318/v1/metrics', $request['url']);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer t', 'X-Scope' => 'eu'],
            $request['headers'],
        );
        self::assertSame(
            [['key' => 'service.name', 'value' => ['stringValue' => 'shop']]],
            self::$transport->at(0, 'resourceMetrics', 0, 'resource', 'attributes'),
        );
        self::assertArrayHasKey('orders_total', self::$transport->metrics());
    }

    public function test_listener_and_transport_are_resolvable(): void
    {
        self::assertInstanceOf(OtlpMetricsListener::class, $this->app()->make(OtlpMetricsListener::class));
    }
}
