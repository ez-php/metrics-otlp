<?php

declare(strict_types=1);

namespace Tests\MetricsOtlp;

use EzPhp\Contracts\ServiceProvider;
use EzPhp\MetricsOtlp\Transport\MetricsTransportInterface;

/**
 * Rebinds the transport to the test's recording instance.
 *
 * @package Tests\MetricsOtlp
 */
final class RecordingTransportProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(MetricsTransportInterface::class, static fn (): MetricsTransportInterface => ApplicationTestCase::$transport);
    }
}
