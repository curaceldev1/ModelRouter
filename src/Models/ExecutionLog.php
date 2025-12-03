<?php

namespace Curacel\LlmOrchestrator\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutionLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'is_successful' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('llm-orchestrator.tables.execution_logs') ?: parent::getTable();
    }
}
