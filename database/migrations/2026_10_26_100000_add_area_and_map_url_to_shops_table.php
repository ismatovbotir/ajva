<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two fields that are entered in this app only. 1C never sends them: ProcessLocalItemsBatch upserts
 * shops with the update list ['name'], so these columns are never overwritten by a sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('area_m2', 10, 2)->nullable()->after('name');     // sales floor area, square metres
            $table->string('map_url', 500)->nullable()->after('area_m2');     // Yandex Maps link of the shop
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['area_m2', 'map_url']);
        });
    }
};
