<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Console\Commands;

use Illuminate\Console\Command;
use QDenka\QueryDoctor\Application\BaselineService;
use QDenka\QueryDoctor\Application\ReportService;
use QDenka\QueryDoctor\Domain\Enums\Severity;

final class DoctorCiReportCommand extends Command
{
    /** @var string */
    protected $signature = 'doctor:ci-report
        {--fail-on=high : Minimum severity that triggers failure (low, medium, high, critical)}
        {--output= : Write report to file}
        {--baseline : Exclude baselined issues}
        {--format=md : Output format (md, json)}';

    /** @var string */
    protected $description = 'Generate a CI report and exit with appropriate code';

    public function handle(ReportService $reportService, BaselineService $baselineService): int
    {
        try {
            $issues = $reportService->analyzeStored([]);
        } catch (\Throwable $e) {
            $this->error('Could not analyze stored queries: '.$e->getMessage());

            return 2;
        }

        if ($this->option('baseline')) {
            $issues = $baselineService->filterBaselined($issues);
        }

        /** @var string $format */
        $format = $this->option('format') ?? 'md';
        $content = $reportService->render($issues, $format);
        /** @var string|null $output */
        $output = $this->option('output');

        if (is_string($output)) {
            file_put_contents($output, $content);
            $this->info(sprintf('Report written to %s', $output));
        } else {
            $this->line($content);
        }

        /** @var string $failOnValue */
        $failOnValue = $this->option('fail-on') ?? 'high';
        $failOn = Severity::tryFrom($failOnValue) ?? Severity::High;

        if ($reportService->hasIssuesAtOrAbove($issues, $failOn)) {
            $counts = $reportService->countBySeverity($issues);
            $this->error(sprintf(
                'Found issues at or above "%s" severity: %d critical, %d high, %d medium, %d low',
                $failOn->value,
                $counts['critical'],
                $counts['high'],
                $counts['medium'],
                $counts['low'],
            ));

            return 1;
        }

        $this->info('No issues at or above "'.$failOn->value.'" severity.');

        return self::SUCCESS;
    }
}
