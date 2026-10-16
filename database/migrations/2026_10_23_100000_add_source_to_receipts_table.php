<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a receipt came from: "pos" = sent by a till in real time (POST /api/receipts), "history" = delivered by 1C
     * in the history migration (POST /api/receipts/batch). Every existing receipt is a till receipt (default "pos").
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('source', 12)->default('pos');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
