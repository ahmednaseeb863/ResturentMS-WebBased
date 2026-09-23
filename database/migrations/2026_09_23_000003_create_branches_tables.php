<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // manager_id (→ employees) is added in Phase 2 with the employees table.
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();
        });

        // Which branches an admin may work in (super admins see all).
        Schema::create('admin_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->timestamps();
            $table->trashable();

            $table->unique(['admin_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_branch');
        Schema::dropIfExists('branches');
    }
};
