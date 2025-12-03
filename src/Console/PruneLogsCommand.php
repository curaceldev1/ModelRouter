<?php

namespace Curacel\LlmOrchestrator\Console;

use Carbon\Carbon;
use Curacel\LlmOrchestrator\Models\ExecutionLog;
use Illuminate\Console\Command;

class PruneLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'llm:prune-logs {--hours= : Prune logs older than this many hours}';

    /**
     * The console command description.
     */
    protected $description = 'Prune LLM execution logs';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $hours = $this->option('hours');

        // If no hours provided, prune all logs
        if ($hours === null) {
            $this->components->info('Pruning all execution logs...');

            $logsToDelete = ExecutionLog::count();

            if ($logsToDelete === 0) {
                $this->components->info('No logs found to prune.');

                return;
            }

            $deletedCount = ExecutionLog::query()->delete();
            $this->components->info("Successfully pruned all {$deletedCount} execution logs.");

            return;
        }

        // Validate hours parameter
        $hours = (int) $hours;
        if ($hours <= 0) {
            $this->error('Hours must be a positive integer.');

            return;
        }

        $cutoffDate = Carbon::now()->subHours($hours);

        $this->components->info("Pruning execution logs older than {$hours} hours (before {$cutoffDate->format('Y-m-d H:i:s')})...");

        // Count logs to be deleted
        $logsToDelete = ExecutionLog::where('created_at', '<', $cutoffDate)->count();

        if ($logsToDelete === 0) {
            $this->components->info('No logs found to prune.');

            return;
        }

        // Delete the logs
        $deletedCount = ExecutionLog::where('created_at', '<', $cutoffDate)->delete();

        $this->components->info("Successfully pruned {$deletedCount} execution logs.");
    }
}
