<?php

declare(strict_types=1);

namespace EzPhp\MetricsOtlp\Transport;

/**
 * Interface MetricsTransportInterface
 *
 * The one outbound HTTP call the exporter needs, behind an interface so tests
 * inject a fake and an application can swap in its own client without this
 * module depending on it. Implementations must not throw.
 *
 * @package EzPhp\MetricsOtlp\Transport
 */
interface MetricsTransportInterface
{
    /**
     * @param string                $url
     * @param string                $payload JSON-encoded request body.
     * @param array<string, string> $headers
     *
     * @return bool True when the endpoint answered with a 2xx status.
     */
    public function send(string $url, string $payload, array $headers): bool;
}
