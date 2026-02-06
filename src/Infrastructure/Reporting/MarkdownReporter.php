<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Reporting;

use QDenka\QueryDoctor\Domain\Contracts\ReporterInterface;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Issue;

final class MarkdownReporter implements ReporterInterface
{
    public function render(array $issues): string
    {
        if ($issues === []) {
            return "# Query Doctor Report\n\nNo issues found.\n";
        }

        $counts = $this->countBySeverity($issues);
        $total = count($issues);
        $now = date('Y-m-d H:i:s');

        $lines = [
            '# Query Doctor Report',
            '',
            "Generated: {$now}",
            "Issues: {$total} ({$counts['critical']} critical, {$counts['high']} high, {$counts['medium']} medium, {$counts['low']} low)",
            '',
        ];

        // Group by severity and render
        $bySeverity = $this->groupBySeverity($issues);

        foreach (['critical', 'high', 'medium', 'low'] as $level) {
            if (empty($bySeverity[$level])) {
                continue;
            }

            $lines[] = '## '.ucfirst($level);
            $lines[] = '';

            foreach ($bySeverity[$level] as $issue) {
                $lines[] = "### {$issue->type->label()}: {$issue->title}";
                $lines[] = '';

                if ($issue->sourceContext?->route !== null) {
                    $lines[] = "- **Route**: {$issue->sourceContext->route}";
                }

                $lines[] = "- **Query**: `{$issue->evidence->fingerprint->value}`";
                $lines[] = "- **Occurrences**: {$issue->evidence->queryCount}";
                $lines[] = sprintf('- **Total time**: %.1fms', $issue->evidence->totalTimeMs);
                $lines[] = sprintf('- **Confidence**: %.0f%%', $issue->confidence * 100);
                $lines[] = "- **Fix**: {$issue->recommendation->action}";

                if ($issue->recommendation->code !== null) {
                    $lines[] = "- **Code**: `{$issue->recommendation->code}`";
                }

                if ($issue->sourceContext?->file !== null) {
                    $location = $issue->sourceContext->file;
                    if ($issue->sourceContext->line !== null) {
                        $location .= ":{$issue->sourceContext->line}";
                    }
                    $lines[] = "- **Location**: `{$location}`";
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    public function format(): string
    {
        return 'md';
    }

    /**
     * @param  Issue[]  $issues
     * @return array<string, int>
     */
    private function countBySeverity(array $issues): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($issues as $issue) {
            $counts[$issue->severity->value]++;
        }

        return $counts;
    }

    /**
     * @param  Issue[]  $issues
     * @return array<string, Issue[]>
     */
    private function groupBySeverity(array $issues): array
    {
        $groups = ['critical' => [], 'high' => [], 'medium' => [], 'low' => []];

        foreach ($issues as $issue) {
            $groups[$issue->severity->value][] = $issue;
        }

        return $groups;
    }
}
