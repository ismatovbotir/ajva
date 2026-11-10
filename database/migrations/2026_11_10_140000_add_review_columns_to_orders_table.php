<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin approval of orders with exceptions before they are sent to 1C. review_status: null (no review needed
     * or not reviewed) | pending (operator's order waits for an admin; status pending_approval) | approved |
     * rejected (sent back to draft with review_note). review_exceptions = the reasons (JSON text, MariaDB-safe).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('review_status', 12)->nullable();
            $table->text('review_note')->nullable();
            $table->text('review_exceptions')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'review_note', 'review_exceptions', 'reviewed_at']);
        });
    }
};
