<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('llm-orchestrator.tables.execution_logs');

        throw_if(! $tableName, \RuntimeException::class, 'llm-orchestrator.tables.execution_logs config is not set');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('client')->index();
            $table->string('driver')->index();
            $table->string('model')->index();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost', 15, 12)->nullable();
            $table->boolean('is_successful')->index();
            $table->string('finish_reason', 50)->nullable();
            $table->text('failed_reason')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tableName = config('llm-orchestrator.tables.execution_logs');

        if ($tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
