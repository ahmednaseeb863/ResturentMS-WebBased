<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PLAN §4.11: the waiter asks for the bill; the cashier sees it and takes the payment.
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('bill_requested_at')->nullable()->after('placed_at');
            $table->foreignId('bill_requested_by')->nullable()->after('bill_requested_at')->constrained('admins');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_requested_by');
            $table->dropColumn('bill_requested_at');
        });
    }
};
