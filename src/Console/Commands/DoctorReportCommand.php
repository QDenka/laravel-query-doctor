<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Console\Commands;

use Illuminate\Console\Command;
use QDenka\QueryDoctor\Application\ReportService;
use QDenka\QueryDoctor\Domain\Issue;

final class DoctorReportCommand extends Command
{
    /** @var string */
    protected $signature = 'doctor:report
        {--period=24h : Time window (1h, 6h, 24h, 7d, 30d)}
        {--format=table : Output format (table, json, md)}
        {--severity= : Filter by minimum severity}
        {--type= : Filter by issue type}
        {--route= : Filter by route pattern}
        {--output= : Write to file instead of stdout}';

    /** @var string */
    protected $description = 'Show detected query performance issues';

    public function handle(ReportService $reportService): int
    {
        $filters = array_filter([
            'period' => $this->option('period'),
            'severity' => $this->option('severity'),
            'type' => $this->option('type'),
            'route' => $this->option('route'),
        ]);

        $issues = $reportService->analyzeStored($filters);

        if ($issues === []) {
            $this->info('No issues found for the given filters.');

            return self::SUCCESS;
        }

        /** @var string $format */
        $format = $this->option('format') ?? 'table';
        /** @var string|null $output */
        $output = $this->option('output');

        if ($format === 'table') {
            $this->renderTable($issues);
        } else {
            $content = $reportService->render($issues, $format);

            if (is_string($output)) {
                file_put_contents($output, $content);
                $this->info(sprintf('Report written to %s', $output));
            } else {
                $this->line($content);
            }
        }

        $counts = $reportService->countBySeverity($issues);
        $total = count($issues);

        $this->newLine();
        $this->info(sprintf(
            'Found %d issues (%d critical, %d high, %d medium, %d low)',
            $total,
            $counts['critical'],
            $counts['high'],
            $counts['medium'],
            $counts['low'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Issue[]  $issues
     */
    private function renderTable(array $issues): void
    {
        $rows = array_map(static fn (Issue $issue) => [
            $issue->type->label(),
            $issue->severity->value,
            ($issue->sourceContext !== null ? $issue->sourceContext->route : null) ?? '—',
            mb_substr($issue->evidence->fingerprint->value, 0, 50),
            (string) $issue->evidence->queryCount,
            sprintf('%.0fms', $issue->evidence->totalTimeMs),
            sprintf('%.0f%%', $issue->confidence * 100),
        ], $issues);

        $this->table(
            ['Type', 'Severity', 'Route', 'Query', 'Count', 'Time', 'Confidence'],
            $rows,
        );
    }
}
