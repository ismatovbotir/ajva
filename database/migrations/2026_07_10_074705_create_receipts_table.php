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
            $table->string('number');
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

            // Receipt numbers are only unique per till; the ingestion job
            // keys its idempotent updateOrCreate on this same (pos_id, number)
            // pair so a retried submission updates the existing receipt
            // instead of creating a duplicate.
            $table->unique(['pos_id', 'number']);

            $table->index('created_at');
            $table->index(['shop_id', 'created_at']);
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
