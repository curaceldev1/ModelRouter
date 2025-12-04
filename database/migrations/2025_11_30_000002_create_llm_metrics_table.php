<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('llm-orchestrator.tables.metrics');

        throw_if(! $tableName, \RuntimeException::class, 'llm-orchestrator.tables.metrics config is not set');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('client')->index();
            $table->string('driver')->index();
            $table->string('model')->index();
            $table->integer('successful_requests')->default(0);
            $table->integer('failed_requests')->default(0);
            $table->integer('total_requests')->default(0);
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->bigInteger('total_tokens')->default(0);
            $table->decimal('total_cost', 15, 12)->default(0);
            $table->timestamps();

            $table->unique(['date', 'client', 'driver', 'model']);
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
