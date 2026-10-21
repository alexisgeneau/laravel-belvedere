<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Belvedere\Alerts\Alert;
use Belvedere\Alerts\AlertEvaluator;
use Belvedere\Collector\Reconciler;
use Illuminate\Console\Command;

final class CheckCommand extends Command
{
    protected $signature = 'belvedere:check';

    protected $description = 'Mark the executions whose outcome will never arrive as lost, then evaluate queue backlogs, wait times, failure rates and dead workers and send the configured alerts';

    public function handle(Reconciler $reconciler, AlertEvaluator $evaluator): int
    {
        foreach (array_filter($reconciler->run()) as $cause => $count) {
            $this->components->twoColumnDetail("Lost executions ({$cause})", (string) $count);
        }

        $alerts = $evaluator->evaluate();

        if ($alerts->isEmpty()) {
            $this->components->info('No alert.');

            return self::SUCCESS;
        }

        $alerts->each(fn (Alert $alert) => $this->components->warn($alert->message));

        return self::SUCCESS;
    }
}
