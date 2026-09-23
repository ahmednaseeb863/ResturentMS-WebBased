<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Everyone who logs in: the super admin and every employee's login account. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('role_id')->nullable()->constrained();
            $table->string('name', 120);
            $table->string('username', 60)->unique();
            $table->string('email', 150)->nullable()->unique();
            $table->string('password');
            $table->string('pin')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->trashable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
