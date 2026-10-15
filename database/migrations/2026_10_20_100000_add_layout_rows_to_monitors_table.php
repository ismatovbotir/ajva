<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Row density of a custom monitor: how many grid rows the screen is divided into.
     * NULL (default) = the screen is divided into exactly the rows the layout uses (the original behaviour),
     * a number = a fixed row count, so row height stays the same however many widgets there are.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedTinyInteger('layout_rows')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('layout_rows');
        });
    }
};
