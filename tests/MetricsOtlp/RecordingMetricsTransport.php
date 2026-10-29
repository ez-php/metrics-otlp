<?php

declare(strict_types=1);

namespace Tests\MetricsOtlp;

use EzPhp\MetricsOtlp\Transport\MetricsTransportInterface;

/**
 * Records every request instead of sending it; answers with $result.
 *
 * @package Tests\MetricsOtlp
 */
final class RecordingMetricsTransport implements MetricsTransportInterface
{
    /**
     * @var list<array{url: string, payload: array<string, mixed>, headers: array<string, string>}>
     */
    public array $requests = [];

    /**
     * @param bool $result
     */
    public function __construct(private readonly bool $result = true)
    {
    }

    /**
     * @param string                $url
     * @param string                $payload
     * @param array<string, string> $headers
     *
     * @return bool
     */
    public function send(string $url, string $payload, array $headers): bool
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $this->requests[] = ['url' => $url, 'payload' => $decoded, 'headers' => $headers];

        return $this->result;
    }

    /**
     * Read a nested value from the decoded payload of request $index, or null.
     *
     * @param int        $index
     * @param string|int ...$path
     *
     * @return mixed
     */
    public function at(int $index, string|int ...$path): mixed
    {
        $value = $this->requests[$index]['payload'] ?? null;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The metric entries of request $index, keyed by metric name.
     *
     * @param int $index
     *
     * @return array<string, array<string, mixed>>
     */
    public function metrics(int $index = 0): array
    {
        $entries = $this->at($index, 'resourceMetrics', 0, 'scopeMetrics', 0, 'metrics');
        $byName = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;

            if (is_array($entry) && is_string($name)) {
                /** @var array<string, mixed> $entry */
                $byName[$name] = $entry;
            }
        }

        return $byName;
    }
}
