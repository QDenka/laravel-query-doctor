<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Contracts;

use QDenka\QueryDoctor\Domain\Issue;

interface ReporterInterface
{
    /**
     * Render a list of issues into a formatted string.
     *
     * @param  Issue[]  $issues  Issues to include in the report
     * @return string Formatted report content
     */
    public function render(array $issues): string;

    /**
     * The output format identifier (e.g. 'json', 'markdown', 'table').
     */
    public function format(): string;
}
