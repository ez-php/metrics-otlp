<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Metrics\MetricsRegistry;
use EzPhp\MetricsOtlp\Aggregation;
use EzPhp\MetricsOtlp\MetricsOtlpServiceProvider;
use EzPhp\MetricsOtlp\OtlpMetricsEncoder;
use EzPhp\MetricsOtlp\OtlpMetricsListener;
use EzPhp\MetricsOtlp\Transport\MetricsTransportInterface;
use EzPhp\MetricsOtlp\Transport\StreamMetricsTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\MetricsOtlp\ApplicationTestCase;

/**
 * Class MetricsOtlpDisabledProviderTest
 *
 * No endpoint: nothing is attached, nothing is sent. Header array form and
 * the 'ez-php-app' service-name fallback are checked on a resolved listener.
 *
 * @package Tests
 */
#[CoversClass(MetricsOtlpServiceProvider::class)]
#[UsesClass(OtlpMetricsListener::class)]
#[UsesClass(OtlpMetricsEncoder::class)]
#[UsesClass(Aggregation::class)]
#[UsesClass(StreamMetricsTransport::class)]
final class MetricsOtlpDisabledProviderTest extends ApplicationTestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected function configFiles(): array
    {
        return ['metrics_otlp' => ['endpoint' => '', 'headers' => ['X-Key' => 'k', 'bad' => 1, 0 => 'x'], 'max_points' => 1]];
    }

    public function test_no_endpoint_attaches_no_listener(): void
    {
        $this->app()->make(MetricsRegistry::class)->counter('orders_total', 'Orders')->inc();

        self::assertSame([], self::$transport->requests);
    }

    public function test_array_headers_and_default_service_name(): void
    {
        $listener = $this->app()->make(OtlpMetricsListener::class);
        $this->app()->make(MetricsRegistry::class)->listen($listener);
        $this->app()->make(MetricsRegistry::class)->counter('orders_total', 'Orders')->inc();

        $request = self::$transport->requests[0];
        self::assertSame(['Content-Type' => 'application/json', 'X-Key' => 'k'], $request['headers']);
        self::assertSame(
            [['key' => 'service.name', 'value' => ['stringValue' => 'ez-php-app']]],
            self::$transport->at(0, 'resourceMetrics', 0, 'resource', 'attributes'),
        );
    }

    public function test_the_module_binds_the_stream_transport(): void
    {
        $provider = new MetricsOtlpServiceProvider($this->app());
        $provider->register();

        self::assertInstanceOf(StreamMetricsTransport::class, $this->app()->make(MetricsTransportInterface::class));
    }
}
