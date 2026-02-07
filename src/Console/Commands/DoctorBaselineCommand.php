<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Console\Commands;

use Illuminate\Console\Command;
use QDenka\QueryDoctor\Application\BaselineService;

final class DoctorBaselineCommand extends Command
{
    /** @var string */
    protected $signature = 'doctor:baseline
        {action=create : Action to perform (create, clear)}
        {--clear : Alternative way to clear baseline}';

    /** @var string */
    protected $description = 'Manage the query issue baseline';

    public function handle(BaselineService $baselineService): int
    {
        /** @var string $action */
        $action = $this->argument('action') ?? 'create';

        if ($this->option('clear') || $action === 'clear') {
            $baselineService->clear();
            $this->info('Baseline cleared.');

            return self::SUCCESS;
        }

        $count = $baselineService->create();
        $this->info("Baseline created with {$count} issues. New issues will be reported against this baseline.");

        return self::SUCCESS;
    }
}
