<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People who wrote to the bot and the conversation with them. Incoming messages are collected by
     * `telegram:poll` (getUpdates, no webhook); outgoing ones are written when an admin replies from the panel.
     */
    public function up(): void
    {
        Schema::create('telegrams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 12)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            // Telegram's update id; null for messages the panel sent (unique allows many nulls).
            $table->unsignedBigInteger('update_id')->nullable()->unique();
            $table->foreignId('telegram_id')->nullable()->constrained('telegrams')->nullOnDelete();
            $table->string('direction', 3)->default('in'); // in = from the person, out = sent by an admin
            $table->bigInteger('chat_id');
            $table->string('chat_type', 20)->nullable();
            // Telegram id of the sender (the person for "in"; the bot for "out" it is null).
            $table->bigInteger('from_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('type', 20)->default('text');
            $table->text('text')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['telegram_id', 'sent_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegrams');
    }
};
