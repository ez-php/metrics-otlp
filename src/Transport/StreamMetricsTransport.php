<?php

declare(strict_types=1);

namespace EzPhp\MetricsOtlp\Transport;

/**
 * Class StreamMetricsTransport
 *
 * Default transport over PHP's stream wrapper HTTP client — stdlib only, no
 * ext-curl, no SDK. Connection errors and non-2xx answers return false.
 *
 * @package EzPhp\MetricsOtlp\Transport
 */
final readonly class StreamMetricsTransport implements MetricsTransportInterface
{
    /**
     * @param int $timeoutSeconds
     */
    public function __construct(private int $timeoutSeconds = 5)
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
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $payload,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        // A failed connection raises a warning; it is handled through the return value.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $result = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            return false;
        }

        $statusLine = http_get_last_response_headers()[0] ?? '';

        return preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine) === 1;
    }
}
