<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riders & delivery (PLAN §4.14): delivery zones (fee + minimum per branch), rider
 * assignment / trip times on deliveries, cash collected by riders (a payment with no
 * shift until it is settled at a counter), and rider cash carried over at shift close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('min_order_amount', 12, 2)->nullable(); // null = the Delivery setting's minimum
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->nullable()->after('user_address_id')->constrained();
            $table->foreignId('assigned_by')->nullable()->after('assigned_at')->constrained('admins');
            $table->timestamp('failed_at')->nullable()->after('delivered_at');
            $table->string('failed_reason')->nullable()->after('failed_at');
            $table->timestamp('returned_at')->nullable()->after('failed_reason');
            $table->foreignId('settlement_movement_id')->nullable()->after('settled_at')->constrained('cash_movements');

            $table->index(['rider_id', 'status']);
        });

        // cash a rider collected is in no drawer until it is settled (a rider settlement movement)
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->change();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('rider_cash_carried', 12, 2)->nullable()->after('handed_over_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn('rider_cash_carried'));

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['rider_id', 'status']);
            $table->dropConstrainedForeignId('settlement_movement_id');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn(['failed_at', 'failed_reason', 'returned_at']);
        });

        Schema::dropIfExists('delivery_zones');
    }
};
