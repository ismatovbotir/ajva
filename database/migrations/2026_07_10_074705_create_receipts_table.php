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
            $table->string('barcode')->nullable();
            $table->string('card')->nullable();
            $table->string('client')->nullable();
            $table->string('cashier')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->boolean('active')->default(true); // receipt could be rejected or cancelled
            $table->boolean('sell')->default(true); // receipt could be sell and refund
            $table->unsignedSmallInteger('qty_buys')->nullable();
            $table->unsignedSmallInteger('qty_positions')->nullable();
            $table->unsignedInteger('session')->nullable();
            $table->unsignedTinyInteger('type')->nullable();
            $table->string('status')->nullable();
            $table->decimal('gross_total', 15, 2)->nullable();
            $table->string('fiscal')->nullable();
            $table->unsignedBigInteger('pos_user_id')->nullable();
            $table->string('pos_user_name')->nullable();
            $table->string('pos_user_text')->nullable();
            $table->json('aos')->nullable();
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
