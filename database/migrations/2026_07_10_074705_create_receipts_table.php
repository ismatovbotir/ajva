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
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->string('number')->unique();
            $table->string('client')->nullable();
            $table->string('cashier')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->boolean('active')->default(true); // receipt could be rejected or cancelled
            $table->boolean('sell')->default(true); // receipt could be sell and refund
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
