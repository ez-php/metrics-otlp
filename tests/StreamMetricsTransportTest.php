<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\MetricsOtlp\Transport\StreamMetricsTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Class StreamMetricsTransportTest
 *
 * Runs against a real `php -S` server: the router answers 200 on /ok and 500
 * elsewhere, and records the request it received.
 *
 * @package Tests
 */
#[CoversClass(StreamMetricsTransport::class)]
final class StreamMetricsTransportTest extends TestCase
{
    /**
     * @var resource|null
     */
    private static $server = null;

    private static string $dir = '';

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/ez-metrics-otlp-' . uniqid('', true);
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/router.php', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/last.json', json_encode([
                'body' => file_get_contents('php://input'),
                'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
                'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            ]));
            http_response_code($_SERVER['REQUEST_URI'] === '/ok' ? 200 : 500);
            PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }

        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$dir . '/router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Could not start the test server.');
        }

        self::$server = $process;

        set_error_handler(static fn (): bool => true, E_WARNING); // refused probes warn until the server listens

        try {
            for ($i = 0; $i < 100; $i++) {
                $probe = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $error, 0.1);

                if ($probe !== false) {
                    fclose($probe);

                    return;
                }

                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        throw new RuntimeException('Test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        array_map('unlink', glob(self::$dir . '/*') ?: []);
        rmdir(self::$dir);
    }

    public function test_posts_payload_and_headers_and_reports_2xx(): void
    {
        $sent = (new StreamMetricsTransport(2))->send(
            'http://127.0.0.1:' . self::$port . '/ok',
            '{"resourceMetrics":[]}',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer t'],
        );

        /** @var array{body: string, contentType: string, auth: string} $last */
        $last = json_decode((string) file_get_contents(self::$dir . '/last.json'), true);

        self::assertTrue($sent);
        self::assertSame('{"resourceMetrics":[]}', $last['body']);
        self::assertSame('application/json', $last['contentType']);
        self::assertSame('Bearer t', $last['auth']);
    }

    public function test_reports_non_2xx_as_failure(): void
    {
        self::assertFalse((new StreamMetricsTransport(2))->send('http://127.0.0.1:' . self::$port . '/fail', '{}', []));
    }

    public function test_reports_an_unreachable_endpoint_as_failure(): void
    {
        self::assertFalse((new StreamMetricsTransport(1))->send('http://127.0.0.1:1/v1/metrics', '{}', []));
    }
}
