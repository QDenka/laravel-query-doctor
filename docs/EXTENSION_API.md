# Extension API

How to add custom analyzers, reporters, and event sources.

## Custom Analyzer

To add your own detection rule, implement `AnalyzerInterface` and register it.

### Step 1: Implement the Interface

```php
<?php

declare(strict_types=1);

namespace App\QueryDoctor;

use QDenka\QueryDoctor\Domain\Contracts\AnalyzerInterface;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;

final class UnboundedSelectAnalyzer implements AnalyzerInterface
{
    /**
     * Detect SELECT queries without a LIMIT clause that return many rows.
     *
     * @param  QueryEvent[]  $events
     * @return Issue[]
     */
    public function analyze(array $events): array
    {
        $issues = [];

        foreach ($events as $event) {
            if ($this->isUnboundedSelect($event)) {
                $issues[] = $this->buildIssue($event);
            }
        }

        return $issues;
    }

    public function type(): IssueType
    {
        // Use an existing type or define a custom one.
        // For custom types, IssueType would need to be extensible
        // (this is a v2 consideration).
        return IssueType::Slow;
    }

    private function isUnboundedSelect(QueryEvent $event): bool
    {
        $sql = strtolower($event->sql);
        return str_starts_with($sql, 'select')
            && ! str_contains($sql, 'limit')
            && ! str_contains($sql, 'count(')
            && $event->timeMs > 200;
    }

    private function buildIssue(QueryEvent $event): Issue
    {
        // Build and return an Issue instance
        // ...
    }
}
```

### Step 2: Register It

In your app's service provider:

```php
use QDenka\QueryDoctor\Application\AnalysisPipeline;

public function boot(): void
{
    $this->app->afterResolving(AnalysisPipeline::class, function (AnalysisPipeline $pipeline) {
        $pipeline->addAnalyzer(new UnboundedSelectAnalyzer());
    });
}
```

Or via config (if the package supports tagged analyzers — planned for v2):

```php
// config/query-doctor.php
'custom_analyzers' => [
    \App\QueryDoctor\UnboundedSelectAnalyzer::class,
],
```

## Custom Reporter

To output issues in a new format, implement `ReporterInterface`.

### Interface

```php
interface ReporterInterface
{
    /**
     * @param  Issue[]  $issues
     * @return string  Formatted output
     */
    public function render(array $issues): string;

    public function format(): string;
}
```

### Example: Slack Reporter

```php
<?php

declare(strict_types=1);

namespace App\QueryDoctor;

use QDenka\QueryDoctor\Domain\Contracts\ReporterInterface;
use QDenka\QueryDoctor\Domain\Issue;

final class SlackReporter implements ReporterInterface
{
    /**
     * @param  Issue[]  $issues
     */
    public function render(array $issues): string
    {
        $blocks = [];

        foreach ($issues as $issue) {
            $blocks[] = sprintf(
                "*[%s]* %s\n>%s\n>Confidence: %.0f%%",
                strtoupper($issue->severity->value),
                $issue->title,
                $issue->recommendation->action,
                $issue->confidence * 100,
            );
        }

        return implode("\n\n", $blocks);
    }

    public function format(): string
    {
        return 'slack';
    }
}
```

### Register It

```php
use QDenka\QueryDoctor\Application\ReportService;

$this->app->afterResolving(ReportService::class, function (ReportService $service) {
    $service->addReporter(new SlackReporter());
});
```

Then use it:
```bash
php artisan doctor:report --format=slack
```

## Custom Event Source

To capture queries from a non-standard source (e.g. a custom database wrapper), implement `EventSourceInterface`:

```php
interface EventSourceInterface
{
    /**
     * Start capturing. Call $callback for each query.
     *
     * @param  callable(QueryEvent): void  $callback
     */
    public function listen(callable $callback): void;

    /**
     * Stop capturing.
     */
    public function stop(): void;
}
```

This is an advanced extension point. Most apps won't need it — the built-in `LaravelDbListenerAdapter` covers standard Eloquent/DB usage.

## Events

The package dispatches Laravel events you can listen to:

| Event | When | Payload |
|-------|------|---------|
| `QueryDoctor\Events\IssuesDetected` | After analysis finds issues | `Issue[]` |
| `QueryDoctor\Events\BaselineCreated` | After baseline snapshot | `int $issueCount` |
| `QueryDoctor\Events\CaptureStarted` | When capture begins for a context | `string $contextId`, `CaptureContext $context` |

### Listening

```php
// In EventServiceProvider
protected $listen = [
    \QDenka\QueryDoctor\Events\IssuesDetected::class => [
        \App\Listeners\NotifyOnCriticalIssues::class,
    ],
];
```

## Limitations

- Custom `IssueType` values aren't supported in v1. Custom analyzers must use one of the five built-in types. Enum extension is planned for v2.
- Custom reporters need to be registered programmatically. Config-based registration is planned.
- The `EventSourceInterface` is designed for the built-in listener. Custom implementations need to handle their own error isolation.
