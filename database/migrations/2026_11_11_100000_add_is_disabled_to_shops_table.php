<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Set in this app only (1C never sends it; ProcessLocalItemsBatch updates ['name'] only). A disabled shop is hidden from report select menus. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_disabled')->default(false)->after('map_url');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_disabled');
        });
    }
};
