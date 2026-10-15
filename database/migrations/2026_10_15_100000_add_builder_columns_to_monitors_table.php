<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable "custom" monitors: a widget layout (JSON text, cast to array), a description,
     * a template flag and access control. Allowed roles are a small JSON list on the monitor itself
     * (there are only three roles, a pivot table would be overkill); allowed users are a real pivot
     * because they are rows of another table. Empty roles AND no users = everyone who may open monitors.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->longText('layout')->nullable();
            $table->unsignedInteger('layout_version')->default(1);
            $table->string('description', 255)->nullable();
            $table->boolean('is_template')->default(false);
            $table->text('roles')->nullable();
        });

        Schema::create('monitor_user', function (Blueprint $table) {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_user');

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['layout', 'layout_version', 'description', 'is_template', 'roles']);
        });
    }
};
