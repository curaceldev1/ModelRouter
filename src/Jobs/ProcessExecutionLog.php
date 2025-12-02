<?php

namespace Curacel\LlmOrchestrator\Jobs;

use Curacel\LlmOrchestrator\Models\ExecutionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessExecutionLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $logData) {}

    public function handle(): void
    {
        ExecutionLog::create($this->logData);
    }
}
