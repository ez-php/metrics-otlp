<?php

declare(strict_types=1);

namespace Tests\MetricsOtlp;

use EzPhp\Application\Application;
use EzPhp\Metrics\MetricsServiceProvider;
use EzPhp\MetricsOtlp\MetricsOtlpServiceProvider;
use EzPhp\Testing\ApplicationTestCase as EzPhpApplicationTestCase;
use RuntimeException;

/**
 * Base class for metrics-otlp tests that need a bootstrapped Application.
 *
 * Writes config/metrics_otlp.php (and config/app.php) before boot, registers
 * ez-php/metrics and this module, then a test provider that rebinds the
 * transport to a RecordingMetricsTransport — register() phases all run before
 * boot(), so the listener the module attaches in boot() already uses it.
 *
 * @package Tests\MetricsOtlp
 */
abstract class ApplicationTestCase extends EzPhpApplicationTestCase
{
    public static RecordingMetricsTransport $transport;

    /**
     * Config file name (without .php) => array it returns.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function configFiles(): array;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        self::$transport = new RecordingMetricsTransport();

        parent::setUp();
    }

    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(MetricsServiceProvider::class);
        $app->register(MetricsOtlpServiceProvider::class);
        $app->register(RecordingTransportProvider::class);
    }

    /**
     * @return string
     */
    protected function getBasePath(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ez-metrics-otlp-test-' . uniqid('', true);
        $configDir = $path . DIRECTORY_SEPARATOR . 'config';

        mkdir($configDir, 0o777, true);

        foreach ($this->configFiles() as $name => $values) {
            $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";

            if (file_put_contents($configDir . DIRECTORY_SEPARATOR . $name . '.php', $content) === false) {
                throw new RuntimeException("Failed to write {$name}.php for test at {$configDir}");
            }
        }

        return $path;
    }
}
