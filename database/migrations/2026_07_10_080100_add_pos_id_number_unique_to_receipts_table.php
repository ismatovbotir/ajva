<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Replace the single-column uniqueness on "number" with a
            // composite one: receipt numbers are only unique per till, and
            // the ingestion job keys its idempotent updateOrCreate on this
            // same (pos_id, number) pair so a retried submission updates the
            // existing receipt instead of creating a duplicate.
            $table->dropUnique(['number']);
            $table->unique(['pos_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['pos_id', 'number']);
            $table->unique('number');
        });
    }
};
