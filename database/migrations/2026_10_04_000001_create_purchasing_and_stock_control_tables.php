<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory, the rest (PLAN §4.16 / Phase 13): suppliers (shared by all branches),
 * purchases with their lines, supplier payments (cash from a shift drawer or a bank
 * account), purchase returns, waste / damage entries and stock counts. Everything that
 * moved stock or money is never deleted — mistakes are fixed with a return / correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('name', 120);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('ntn', 30)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique('name');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->foreignId('supplier_id')->constrained();
            $table->string('invoice_no', 60)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('business_date');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('returned_total', 12, 2)->default(0);
            $table->string('payment_status', 20);
            $table->string('notes', 500)->nullable();
            $table->foreignId('received_by')->constrained('admins');
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'supplier_id', 'business_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('purchase_id')->constrained();
            $table->string('stockable_type', 40);
            $table->unsignedBigInteger('stockable_id');
            $table->string('item_name', 120);
            $table->decimal('quantity', 12, 3); // as entered, in `unit_id`
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('stock_quantity', 12, 3); // in the item's stock unit
            $table->decimal('unit_cost', 14, 4); // per entered unit
            $table->decimal('line_total', 12, 2);
            $table->decimal('returned_stock_quantity', 12, 3)->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->timestamps();

            $table->index(['stockable_type', 'stockable_id']);
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->foreignId('purchase_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->date('business_date');
            $table->string('reason', 255);
            $table->decimal('total', 12, 2);
            $table->foreignId('admin_id')->constrained('admins');
            $table->timestamp('created_at')->nullable();

            $table->unique(['branch_id', 'number']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained();
            $table->foreignId('purchase_item_id')->constrained();
            $table->decimal('quantity', 12, 3); // in the purchase line's unit
            $table->decimal('stock_quantity', 12, 3);
            $table->decimal('line_total', 12, 2);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_id')->nullable()->constrained(); // null = on account (oldest bills first)
            $table->date('business_date');
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->foreignId('shift_id')->nullable()->constrained();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements');
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->string('reference_no', 80)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('paid_by')->constrained('admins');
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'supplier_id']);
            $table->index(['branch_id', 'business_date']);
        });

        // waste / damage entries (one or more lines)
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->string('type', 20);
            $table->date('business_date');
            $table->string('reason', 255);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->foreignId('admin_id')->constrained('admins');
            $table->timestamp('created_at')->nullable();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'business_date']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained();
            $table->string('stockable_type', 40);
            $table->unsignedBigInteger('stockable_id');
            $table->string('item_name', 120);
            $table->decimal('quantity', 12, 3);
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('stock_quantity', 12, 3);
            $table->decimal('unit_cost', 14, 4); // per stock unit, average cost at the time
            $table->decimal('line_cost', 12, 2);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->string('status', 20);
            $table->string('scope', 120);
            $table->date('business_date');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('admins');
            $table->foreignId('submitted_by')->nullable()->constrained('admins');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins');
            $table->timestamp('approved_at')->nullable();
            $table->decimal('variance_value', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('stock_count_id')->constrained();
            $table->string('stockable_type', 40);
            $table->unsignedBigInteger('stockable_id');
            $table->string('item_name', 120);
            $table->decimal('system_qty', 12, 3); // stock when the count started
            $table->decimal('counted_qty', 12, 3)->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
