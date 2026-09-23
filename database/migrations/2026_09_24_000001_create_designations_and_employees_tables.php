<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Job titles, shared by every branch. `type` drives pickers (waiters, riders…).
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('name', 80)->unique();
            $table->string('type', 20)->default('other');
            $table->foreignId('default_role_id')->nullable()->constrained('roles');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();
        });

        // Staff records. `branch_id` = home branch; `admin_id` = their login (optional).
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('admin_id')->nullable()->unique()->constrained();
            $table->foreignId('designation_id')->constrained();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('cnic', 15)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('photo', 255)->nullable();
            $table->date('joining_date')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->trashable();

            $table->index(['branch_id', 'status']);
        });

        // Allocated manager of a branch (PLAN §2).
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('tax_number')->constrained('employees');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
        Schema::dropIfExists('employees');
        Schema::dropIfExists('designations');
    }
};
