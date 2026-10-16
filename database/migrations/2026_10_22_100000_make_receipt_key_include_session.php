<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt numbers restart (per shift) in 1C, so a receipt is identified by (pos_id, session, number) and not by
     * (pos_id, number): with the old key a new shift would overwrite an old receipt that has the same number.
     * NULL sessions are set to 0 first and new receipts always store 0 when no session is sent, because NULLs are
     * never equal inside a unique index and would let duplicates in. Rebuilding the index on a big receipts table
     * takes time: run it outside the busy hours.
     */
    public function up(): void
    {
        DB::table('receipts')->whereNull('session')->update(['session' => 0]);

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['pos_id', 'number']);
            $table->unique(['pos_id', 'session', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['pos_id', 'session', 'number']);
            $table->unique(['pos_id', 'number']);
        });
    }
};
