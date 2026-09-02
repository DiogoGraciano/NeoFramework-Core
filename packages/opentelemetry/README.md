# NeoFramework OpenTelemetry

Adapter opcional dos contratos de métricas e tracing do NeoFramework Core para
a API do OpenTelemetry.

```bash
composer require diogodg/neoframework-opentelemetry
```

```php
use NeoFramework\OpenTelemetry\{OpenTelemetryMetricsExporter, OpenTelemetryTracer};

$tracer = new OpenTelemetryTracer($otelTracer);
$metrics = new OpenTelemetryMetricsExporter($otelMeter);
```

O pacote adapta a API; a configuração do SDK, propagadores, amostragem e
exportadores continua pertencendo à aplicação.
