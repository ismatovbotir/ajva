<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per finished run of a scheduled command (Monitoring > Tasks); pruned per task by TaskRuns. */
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at');
            $table->string('status', 12);
            $table->text('note')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->index(['name', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};
