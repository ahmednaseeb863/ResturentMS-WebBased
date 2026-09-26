<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kitchen timings of a line (KDS): started with its ticket, ready per item, served.
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->after('consumption_status');
            $table->timestamp('served_at')->nullable()->after('ready_at');
            $table->index(['kitchen_ticket_id', 'kitchen_status']);
            $table->index('consumption_status');
        });

        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->timestamp('served_at')->nullable()->after('completed_at');
        });

        // Raw materials an order line really used (PLAN §4.16), confirmed by the cook when the
        // line is ready — or auto-confirmed at recipe quantities. Quantities are in `unit_id`
        // (the recipe's unit); the stock movement holds the amount in the stock unit. Append-only.
        Schema::create('order_item_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('raw_material_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->date('business_date');
            $table->decimal('expected_qty', 12, 3);
            $table->decimal('actual_qty', 12, 3);
            $table->string('reason')->nullable();
            $table->boolean('auto')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('admins');
            $table->timestamp('confirmed_at');
            $table->foreignId('stock_movement_id')->nullable()->constrained();
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'business_date']);
            $table->index(['raw_material_id', 'business_date']);
        });

        // Documents waiting for a browser in the branch to print them (PLAN §8 "Thermal
        // printing"): pending → printing (claimed by a device) → printed / failed (retry).
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('printer_id')->constrained();
            $table->string('document_type', 20);
            $table->string('reference_type', 30);
            $table->unsignedBigInteger('reference_id');
            $table->string('title', 120);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins');
            $table->foreignId('printed_by')->nullable()->constrained('admins');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['printer_id', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('order_item_consumptions');

        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropColumn('served_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['kitchen_ticket_id', 'kitchen_status']);
            $table->dropIndex(['consumption_status']);
            $table->dropColumn(['ready_at', 'served_at']);
        });
    }
};
