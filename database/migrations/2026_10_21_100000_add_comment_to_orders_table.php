<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A free-text comment of the shop's responsible person (written on the phone page of the order link or in the panel
     * while the order is a draft). Sent to 1C with the order as an extra "comment" field.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
