<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use QDenka\QueryDoctor\Application\AnalysisPipeline;
use QDenka\QueryDoctor\Application\BaselineService;
use QDenka\QueryDoctor\Application\QueryCaptureService;
use QDenka\QueryDoctor\Application\ReportService;
use QDenka\QueryDoctor\Console\Commands\DoctorBaselineCommand;
use QDenka\QueryDoctor\Console\Commands\DoctorCiReportCommand;
use QDenka\QueryDoctor\Console\Commands\DoctorReportCommand;
use QDenka\QueryDoctor\Domain\Analyzer\DuplicateQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\MissingIndexAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\NPlusOneAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\SelectStarAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\SlowQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Infrastructure\Capture\LaravelDbListenerAdapter;
use QDenka\QueryDoctor\Infrastructure\Reporting\JsonReporter;
use QDenka\QueryDoctor\Infrastructure\Reporting\MarkdownReporter;
use QDenka\QueryDoctor\Infrastructure\Storage\InMemoryStore;
use QDenka\QueryDoctor\Infrastructure\Storage\SqliteStore;

final class QueryDoctorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/query-doctor.php', 'query-doctor');

        $this->registerStorage();
        $this->registerAnalyzers();
        $this->registerServices();
    }

    public function boot(): void
    {
        $this->registerPublishing();

        if (! $this->isEnabled()) {
            return;
        }

        $this->registerCommands();
        $this->registerRoutes();
        $this->registerViews();
        $this->hookDbListener();
    }

    private function isEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('query-doctor.enabled', false);

        if (! $enabled) {
            return false;
        }

        /** @var string[] $allowedEnvs */
        $allowedEnvs = config('query-doctor.allowed_environments', []);

        return in_array($this->app->environment(), $allowedEnvs, true);
    }

    private function registerStorage(): void
    {
        $this->app->singleton(StorageInterface::class, function () {
            /** @var string $path */
            $path = config('query-doctor.storage.path', ':memory:');
            /** @var int $retentionDays */
            $retentionDays = config('query-doctor.storage.retention_days', 14);
            /** @var int $cleanupEvery */
            $cleanupEvery = config('query-doctor.storage.cleanup_every', 500);

            if ($path === ':memory:') {
                return new InMemoryStore;
            }

            try {
                return new SqliteStore($path, $retentionDays, $cleanupEvery);
            } catch (\Throwable $e) {
                Log::warning('Query Doctor: SQLite unavailable, using in-memory storage — '.$e->getMessage());

                return new InMemoryStore;
            }
        });
    }

    private function registerAnalyzers(): void
    {
        $this->app->singleton(AnalysisPipeline::class, function () {
            /** @var array<string, array<string, mixed>> $analyzerConfig */
            $analyzerConfig = config('query-doctor.analyzers', []);
            $analyzers = [];

            if ($analyzerConfig['slow']['enabled'] ?? true) {
                $analyzers[] = new SlowQueryAnalyzer(
                    thresholdMs: (float) ($analyzerConfig['slow']['threshold_ms'] ?? 100),
                );
            }

            if ($analyzerConfig['duplicate']['enabled'] ?? true) {
                $analyzers[] = new DuplicateQueryAnalyzer(
                    minCount: (int) ($analyzerConfig['duplicate']['min_count'] ?? 3),
                );
            }

            if ($analyzerConfig['n_plus_one']['enabled'] ?? true) {
                $analyzers[] = new NPlusOneAnalyzer(
                    minRepetitions: (int) ($analyzerConfig['n_plus_one']['min_repetitions'] ?? 5),
                    minTotalMs: (float) ($analyzerConfig['n_plus_one']['min_total_ms'] ?? 20),
                );
            }

            if ($analyzerConfig['missing_index']['enabled'] ?? true) {
                $analyzers[] = new MissingIndexAnalyzer(
                    minOccurrences: (int) ($analyzerConfig['missing_index']['min_occurrences'] ?? 5),
                    minAvgMs: (float) ($analyzerConfig['missing_index']['min_avg_ms'] ?? 50),
                );
            }

            if ($analyzerConfig['select_star']['enabled'] ?? true) {
                $analyzers[] = new SelectStarAnalyzer(
                    minOccurrences: (int) ($analyzerConfig['select_star']['min_occurrences'] ?? 3),
                );
            }

            return new AnalysisPipeline($analyzers);
        });
    }

    private function registerServices(): void
    {
        $this->app->singleton(QueryCaptureService::class);

        $this->app->singleton(ReportService::class, function () {
            $service = new ReportService(
                $this->app->make(StorageInterface::class),
                $this->app->make(AnalysisPipeline::class),
            );

            $service->addReporter(new JsonReporter);
            $service->addReporter(new MarkdownReporter);

            return $service;
        });

        $this->app->singleton(BaselineService::class);

        $this->app->singleton(LaravelDbListenerAdapter::class, function () {
            /** @var int $stackDepth */
            $stackDepth = config('query-doctor.stack_trace.depth', 10);
            /** @var string[] $excludePaths */
            $excludePaths = config('query-doctor.stack_trace.exclude_paths', ['vendor/']);
            /** @var string[] $ignoreSqlPatterns */
            $ignoreSqlPatterns = config('query-doctor.ignore.sql_patterns', []);

            return new LaravelDbListenerAdapter($stackDepth, $excludePaths, $ignoreSqlPatterns);
        });
    }

    private function hookDbListener(): void
    {
        try {
            $adapter = $this->app->make(LaravelDbListenerAdapter::class);
            $capture = $this->app->make(QueryCaptureService::class);

            $adapter->listen(fn ($event) => $capture->capture($event));
            $capture->startCapture();

            DB::listen(fn (QueryExecuted $query) => $adapter->handleQueryExecuted($query));

            $this->app->terminating(function () use ($capture): void {
                try {
                    $capture->flush();
                } catch (\Throwable $e) {
                    Log::warning('Query Doctor: Flush failed — '.$e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Query Doctor: Failed to hook DB listener — '.$e->getMessage());
        }
    }

    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'query-doctor');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../resources/views' => resource_path('views/vendor/query-doctor'),
            ], 'query-doctor-views');
        }
    }

    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/query-doctor.php' => config_path('query-doctor.php'),
            ], 'query-doctor-config');
        }
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorReportCommand::class,
                DoctorBaselineCommand::class,
                DoctorCiReportCommand::class,
            ]);
        }
    }
}
