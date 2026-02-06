<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Reporting;

use QDenka\QueryDoctor\Domain\Contracts\ReporterInterface;
use QDenka\QueryDoctor\Domain\Issue;

final class JsonReporter implements ReporterInterface
{
    public function render(array $issues): string
    {
        $data = array_map(static fn (Issue $issue) => [
            'id' => $issue->id,
            'type' => $issue->type->value,
            'severity' => $issue->severity->value,
            'confidence' => $issue->confidence,
            'title' => $issue->title,
            'description' => $issue->description,
            'evidence' => [
                'query_count' => $issue->evidence->queryCount,
                'total_time_ms' => $issue->evidence->totalTimeMs,
                'fingerprint' => $issue->evidence->fingerprint->value,
            ],
            'recommendation' => [
                'action' => $issue->recommendation->action,
                'code' => $issue->recommendation->code,
                'docs_url' => $issue->recommendation->docsUrl,
            ],
            'source' => $issue->sourceContext !== null ? [
                'route' => $issue->sourceContext->route,
                'file' => $issue->sourceContext->file,
                'line' => $issue->sourceContext->line,
                'controller' => $issue->sourceContext->controller,
            ] : null,
        ], $issues);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function format(): string
    {
        return 'json';
    }
}
