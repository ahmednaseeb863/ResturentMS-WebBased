<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PLAN §4.13. `paid_total` is the money kept (payments − refunds); `refunded_total` the refunds.
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('refunded_total', 12, 2)->default(0)->after('paid_total');
            $table->string('split_mode', 10)->nullable()->after('payment_status'); // equal / items
            $table->timestamp('paid_at')->nullable()->after('placed_at');
        });

        // Split bill: the order is paid in parts (equally, or by the items each guest had).
        // Replaced splits are trashed; a split with payments is never changed.
        Schema::create('bill_splits', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->unsignedSmallInteger('number');
            $table->string('label', 60);
            $table->decimal('amount', 12, 2);
            $table->json('items')->nullable(); // [{order_item_id, quantity}] when split by items
            $table->foreignId('created_by')->constrained('admins');
            $table->timestamps();
            $table->trashable();

            $table->index(['order_id', 'number']);
        });

        // Money received for an order, into the drawer of the shift that took it (cash) or a
        // bank account (transfer). Never deleted — refunds reverse them.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('bill_split_id')->nullable()->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->date('business_date');
            $table->string('method', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->decimal('amount', 12, 2); // applied to the bill
            $table->decimal('tendered', 12, 2)->nullable(); // cash handed over
            $table->decimal('change_given', 12, 2)->default(0);
            $table->string('reference_no', 80)->nullable();
            $table->string('proof_image')->nullable();
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->foreignId('received_by')->constrained('admins');
            $table->foreignId('collected_by_rider_id')->nullable()->constrained('employees'); // riders phase
            $table->timestamps();

            $table->index(['shift_id', 'method']);
            $table->index(['branch_id', 'bank_account_id', 'business_date']);
            $table->index(['branch_id', 'business_date']);
        });

        // Money given back (append-only). Cash comes out of the refunder's open shift.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('shift_id')->nullable()->constrained();
            $table->date('business_date');
            $table->string('method', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->string('reference_no', 80)->nullable();
            $table->foreignId('refunded_by')->constrained('admins');
            $table->foreignId('approved_by')->nullable()->constrained('admins');
            $table->timestamp('created_at')->nullable();

            $table->index(['shift_id', 'method']);
            $table->index(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bill_splits');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['refunded_total', 'split_mode', 'paid_at']));
    }
};
