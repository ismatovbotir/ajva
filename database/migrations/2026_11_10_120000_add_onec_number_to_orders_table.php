<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The order number 1C answers with after a successful POST /order (response "message"), e.g. 00-00002495.
            $table->string('onec_number', 60)->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('onec_number');
        });
    }
};
