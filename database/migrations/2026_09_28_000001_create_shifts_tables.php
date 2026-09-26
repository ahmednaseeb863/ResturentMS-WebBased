<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cashier's session on one cash counter (PLAN §6). Never trashed — closed / reopened only.
        // business_date is fixed when the shift opens (a Night shift's 01:30 sale stays on that day).
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedInteger('number');
            $table->foreignId('cash_counter_id')->constrained();
            $table->foreignId('shift_type_id')->nullable()->constrained();
            $table->date('business_date');
            $table->string('status', 20);
            $table->foreignId('opened_by')->constrained('admins');
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('admins');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->decimal('float_left', 12, 2)->nullable();
            $table->decimal('handed_over_amount', 12, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins');
            $table->foreignId('reopened_by')->nullable()->constrained('admins');
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedTinyInteger('reopen_count')->default(0);
            $table->timestamps();

            // at most one open shift per counter, and per cashier in a branch — enforced by MySQL too
            $table->unsignedBigInteger('open_counter_id')->nullable()
                ->storedAs("if(`status` = 'open', `cash_counter_id`, null)")->unique();
            $table->string('open_cashier_key', 41)->nullable()
                ->storedAs("if(`status` = 'open', concat(`branch_id`, ':', `opened_by`), null)")->unique();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'business_date']);
            $table->index(['branch_id', 'status']);
        });

        // Notes & coins counted at opening / closing. Closing rows are trashed when a shift is reopened.
        Schema::create('shift_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained();
            $table->string('type', 10);
            $table->decimal('denomination', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->trashable();
        });

        // Cash in / out of a drawer other than sales (append-only). `amount` is positive; the type
        // gives the direction. Rider settlements, expenses and supplier payments arrive later.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->date('business_date');
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('rider_id')->nullable()->constrained('employees');
            $table->nullableMorphs('reference');
            $table->foreignId('admin_id')->nullable()->constrained('admins');
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'business_date']);
        });

        // Staff on duty during a shift.
        Schema::create('shift_employees', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('shift_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();
            $table->trashable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_employees');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shift_cash_counts');
        Schema::dropIfExists('shifts');
    }
};
