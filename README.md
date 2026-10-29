# ez-php/metrics-otlp

Exports [`ez-php/metrics`](https://github.com/ez-php/metrics) counters, gauges and histograms
to an OpenTelemetry collector as OTLP/HTTP JSON. It needs no SDK, no protobuf and nothing
beyond the PHP standard library. If you already send traces to a collector with `ez-php/otel`,
metrics can go there too, without a Prometheus scrape.

---

## Installation

```bash
composer require ez-php/metrics-otlp
```

---

## Setup

```php
// provider/modules.php
EzPhp\Metrics\MetricsServiceProvider::class,
EzPhp\MetricsOtlp\MetricsOtlpServiceProvider::class,
```

```dotenv
METRICS_OTLP_ENDPOINT=http://otel-collector:4318/v1/metrics
METRICS_OTLP_SERVICE_NAME=shop          # empty = APP_NAME
METRICS_OTLP_HEADERS=Authorization=Bearer%20token
```

With an endpoint set, everything recorded through `ez-php/metrics` (`Metrics::counter(...)`,
`RequestMetricsMiddleware`, …) is exported. Without one, nothing is attached.

| `ez-php/metrics` | OTLP |
|---|---|
| Counter | `sum`, monotonic, delta |
| Gauge | `gauge` (last value in the window) |
| Histogram | `histogram`, explicit buckets (the histogram's own bounds), delta |

Labels become attributes, and the metric's help text becomes its description.

---

## When is data sent?

- At the end of every PHP request or process, via a shutdown function.
- As soon as `max_points` values are waiting (default 1000).
- When you call `flush()`. Long-running workers should do this, for example after each job:

```php
$app->make(EzPhp\MetricsOtlp\OtlpMetricsListener::class)->flush();
```

A failed export is dropped and nothing is thrown, so metrics never break a request.

---

## Delta temporality

Each export contains only what happened since the previous one. That is the only model that
works across short-lived PHP processes. If your collector forwards to Prometheus, add the
`deltatocumulative` processor:

```yaml
processors:
  deltatocumulative: {}
service:
  pipelines:
    metrics:
      receivers: [otlp]
      processors: [deltatocumulative]
      exporters: [prometheus]
```

---

## Custom HTTP client

Bind your own `EzPhp\MetricsOtlp\Transport\MetricsTransportInterface`. The default uses PHP's
stream wrapper.

---

## License

MIT
