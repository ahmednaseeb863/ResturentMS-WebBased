<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservations (PLAN §4.17) and expenses (PLAN §4.18). Reservations are never trashed —
 * they are cancelled / marked no-show. Expenses are money: never trashed, voided instead
 * (a cash expense puts the money back into a drawer). Expense categories are shared by
 * every branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->foreignId('user_id')->nullable()->constrained(); // customer, when known
            $table->string('guest_name', 120);
            $table->string('guest_phone', 30)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->dateTime('reserved_at'); // branch local time
            $table->unsignedSmallInteger('duration_minutes');
            $table->foreignId('table_id')->nullable()->constrained('tables');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('admins');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('closed_at')->nullable(); // cancelled / no-show
            $table->foreignId('closed_by')->nullable()->constrained('admins');
            $table->string('close_reason')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'reserved_at']);
            $table->index(['table_id', 'status']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->foreignId('expense_category_id')->constrained();
            $table->date('business_date');
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->string('reference_no', 80)->nullable();
            $table->string('paid_from', 20); // cash / bank_transfer
            $table->foreignId('shift_id')->nullable()->constrained();
            $table->foreignId('cash_movement_id')->nullable()->constrained();
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->string('attachment')->nullable();
            $table->foreignId('created_by')->constrained('admins');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins');
            $table->string('void_reason')->nullable();
            $table->foreignId('void_movement_id')->nullable()->constrained('cash_movements');
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('reservations');
    }
};
