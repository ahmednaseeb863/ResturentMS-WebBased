<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our own roles & permissions (no package). A permission grants a list of route
 * names; the catalog lives in App\Support\Permissions\PermissionCatalog and is
 * synced with `php artisan permissions:sync`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('title', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('permission_group_id')->constrained();
            $table->string('title', 100);
            $table->json('routes');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->unique(['permission_group_id', 'title']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained();
            $table->foreignId('permission_id')->constrained();
            $table->timestamps();
            $table->trashable();

            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('permission_groups');
    }
};
