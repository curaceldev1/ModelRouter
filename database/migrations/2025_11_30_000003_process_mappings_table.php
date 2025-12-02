<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('llm-orchestrator.tables.process_mappings');

        throw_if(! $tableName, \RuntimeException::class, 'llm-orchestrator.tables.process_mappings config is not set');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('process_name')->index();
            $table->string('client')->index();
            $table->string('model');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tableName = config('llm-orchestrator.tables.metrics');

        if ($tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
