<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PLAN §5 / §7. Orders are never trashed — voided / cancelled / refunded only.
        // A held order (status draft) keeps its cart in `held_items` until it is sent; only sent
        // lines become order_items. Totals are recalculated on the server (App\Support\OrderPricing).
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number')->nullable(); // given when the order is placed
            $table->string('order_number', 30)->nullable();
            $table->string('type', 20);
            $table->string('source', 20);
            $table->string('status', 20);
            $table->date('business_date');
            $table->foreignId('shift_id')->nullable()->constrained(); // the shift the order is paid in (billing)
            $table->foreignId('user_id')->nullable()->constrained(); // customer
            $table->foreignId('table_id')->nullable()->constrained('tables');
            $table->foreignId('waiter_id')->nullable()->constrained('employees');
            $table->foreignId('created_by')->constrained('admins');
            $table->unsignedSmallInteger('guests')->nullable();

            $table->decimal('items_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('net_total', 12, 2)->default(0);
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->boolean('service_charge_removed')->default(false);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->string('tax_name', 30)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('round_off', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid');

            $table->json('held_items')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('admins');
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            // one open order per table — enforced by MySQL too
            $table->unsignedBigInteger('open_table_id')->nullable()
                ->storedAs("if(`status` in ('draft','placed','preparing','ready','served'), `table_id`, null)")->unique();

            $table->unique(['branch_id', 'business_date', 'number']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'business_date']);
        });

        // One ticket per kitchen station per send (KOT). Shown on the KDS / printed in the kitchen phase.
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('kitchen_station_id')->nullable()->constrained();
            $table->date('business_date');
            $table->unsignedInteger('number');
            $table->string('status', 20);
            $table->timestamp('sent_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'number']);
            $table->index(['branch_id', 'status']);
        });

        // Sent lines. A deal is a parent line (the price) with its picks as child lines (price 0).
        // Voiding part of a line splits it: the voided quantity moves to a new voided row.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('parent_order_item_id')->nullable()->constrained('order_items');
            $table->foreignId('split_from_id')->nullable()->constrained('order_items');
            $table->foreignId('deal_id')->nullable()->constrained();
            $table->string('sellable_type', 30);
            $table->unsignedBigInteger('sellable_id');
            $table->foreignId('variant_id')->nullable()->constrained('menu_item_variants');
            $table->string('item_name');
            $table->string('variant_name')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('modifiers_total', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->foreignId('kitchen_station_id')->nullable()->constrained();
            $table->foreignId('kitchen_ticket_id')->nullable()->constrained();
            $table->string('kitchen_status', 20)->nullable(); // null = not for the kitchen
            $table->string('consumption_status', 20);
            $table->string('notes')->nullable();
            $table->foreignId('sent_by')->constrained('admins');
            $table->timestamp('sent_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins');
            $table->string('void_reason')->nullable();
            $table->boolean('void_wasted')->default(false);
            $table->foreignId('void_approved_by')->nullable()->constrained('admins');
            $table->timestamps();

            $table->index(['sellable_type', 'sellable_id']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('modifier_id')->nullable()->constrained();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->timestamp('created_at')->nullable();
        });

        // Discount on the whole order (order_item_id null) or on one line. Replaced ones are trashed.
        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('order_item_id')->nullable()->constrained();
            $table->foreignId('discount_id')->nullable()->constrained();
            $table->string('name', 120);
            $table->string('type', 10);
            $table->decimal('value', 12, 2);
            $table->decimal('max_amount', 12, 2)->nullable(); // cap / minimum copied from the discount
            $table->decimal('min_amount', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins');
            $table->foreignId('created_by')->constrained('admins');
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('admin_id')->nullable()->constrained();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Delivery details of a delivery order; riders, zones and COD settlement come with the riders phase.
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('user_address_id')->nullable()->constrained();
            $table->string('address', 500);
            $table->string('phone', 20)->nullable();
            $table->foreignId('rider_id')->nullable()->constrained('employees');
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('status', 20);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('cash_to_collect', 12, 2)->default(0);
            $table->decimal('cash_collected', 12, 2)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('orders');
    }
};
