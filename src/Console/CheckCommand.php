<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Belvedere\Alerts\Alert;
use Belvedere\Alerts\AlertEvaluator;
use Illuminate\Console\Command;

final class CheckCommand extends Command
{
    protected $signature = 'belvedere:check';

    protected $description = 'Evaluate queue backlogs, failure rates and dead workers, and send the configured alerts';

    public function handle(AlertEvaluator $evaluator): int
    {
        $alerts = $evaluator->evaluate();

        if ($alerts->isEmpty()) {
            $this->components->info('No alert.');

            return self::SUCCESS;
        }

        $alerts->each(fn (Alert $alert) => $this->components->warn($alert->message));

        return self::SUCCESS;
    }
}
