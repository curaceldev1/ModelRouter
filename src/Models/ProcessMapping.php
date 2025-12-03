<?php

namespace Curacel\LlmOrchestrator\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessMapping extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('llm-orchestrator.tables.process_mappings') ?: parent::getTable();
    }
}
