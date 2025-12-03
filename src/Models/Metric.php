<?php

namespace Curacel\LlmOrchestrator\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'total_cost' => 'decimal:6',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('llm-orchestrator.tables.metrics') ?: 'llm_metrics';
    }
}
