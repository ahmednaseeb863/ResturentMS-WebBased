<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared by all branches; `bank_account_branch` says where each account can be used.
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('bank_name', 100);
            $table->string('account_title', 120);
            $table->string('account_number', 40)->unique();
            $table->string('iban', 34)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_receipt')->default(false);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('bank_account_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->timestamps();
            $table->trashable();

            $table->unique(['bank_account_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_branch');
        Schema::dropIfExists('bank_accounts');
    }
};
