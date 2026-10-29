<?php

declare(strict_types=1);

namespace EzPhp\MetricsOtlp;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Metrics\MetricsRegistry;
use EzPhp\MetricsOtlp\Transport\MetricsTransportInterface;
use EzPhp\MetricsOtlp\Transport\StreamMetricsTransport;

/**
 * Class MetricsOtlpServiceProvider
 *
 * Binds the OTLP listener from config and, in boot(), registers it with the
 * MetricsRegistry — only when `metrics_otlp.endpoint` is set, so an empty
 * endpoint (the default) costs nothing. Register ez-php/metrics'
 * MetricsServiceProvider before this one.
 *
 * Configuration keys (in config/metrics_otlp.php or environment):
 *   - metrics_otlp.endpoint      — OTLP/HTTP metrics URL ('' = disabled)
 *   - metrics_otlp.service_name  — `service.name` resource attribute ('' = app.name, else 'ez-php-app')
 *   - metrics_otlp.headers       — extra headers, `key=value,key2=value2` or an array
 *   - metrics_otlp.max_points    — export once this many values are waiting (default 1000)
 *   - metrics_otlp.timeout       — HTTP timeout in seconds (default 5)
 *
 * @package EzPhp\MetricsOtlp
 */
final class MetricsOtlpServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(MetricsTransportInterface::class, function (ContainerInterface $app): MetricsTransportInterface {
            return new StreamMetricsTransport($this->int($app->make(ConfigInterface::class), 'metrics_otlp.timeout', 5));
        });

        $this->app->bind(OtlpMetricsListener::class, function (ContainerInterface $app): OtlpMetricsListener {
            $config = $app->make(ConfigInterface::class);

            return new OtlpMetricsListener(
                $app->make(MetricsTransportInterface::class),
                new OtlpMetricsEncoder($this->serviceName($config)),
                $app->make(MetricsRegistry::class)->storage(),
                $this->string($config, 'metrics_otlp.endpoint'),
                $this->headers($config->get('metrics_otlp.headers', '')),
                $this->int($config, 'metrics_otlp.max_points', 1000),
            );
        });
    }

    /**
     * Attach the listener to the registry when an endpoint is configured.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->string($this->app->make(ConfigInterface::class), 'metrics_otlp.endpoint') === '') {
            return;
        }

        $this->app->make(MetricsRegistry::class)->listen($this->app->make(OtlpMetricsListener::class));
    }

    /**
     * @param ConfigInterface $config
     *
     * @return string
     */
    private function serviceName(ConfigInterface $config): string
    {
        return $this->string($config, 'metrics_otlp.service_name')
            ?: $this->string($config, 'app.name')
            ?: 'ez-php-app';
    }

    /**
     * Parse `key=value,key2=value2` (the OTEL_EXPORTER_OTLP_HEADERS format) or an array.
     *
     * @param mixed $value
     *
     * @return array<string, string>
     */
    private function headers(mixed $value): array
    {
        if (is_array($value)) {
            return array_filter($value, static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
        }

        $headers = [];

        foreach (explode(',', is_string($value) ? $value : '') as $pair) {
            [$name, $header] = array_pad(explode('=', $pair, 2), 2, '');

            if (trim($name) !== '') {
                $headers[trim($name)] = trim(rawurldecode($header));
            }
        }

        return $headers;
    }

    /**
     * @param ConfigInterface $config
     * @param string          $key
     *
     * @return string
     */
    private function string(ConfigInterface $config, string $key): string
    {
        $value = $config->get($key, '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param ConfigInterface $config
     * @param string          $key
     * @param int             $default
     *
     * @return int
     */
    private function int(ConfigInterface $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
