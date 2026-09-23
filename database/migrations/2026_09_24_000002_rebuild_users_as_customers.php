<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| `users` = customers, shared by every branch (PLAN §3). The Laravel starter table
| is reshaped: phone is the main lookup, email and password become optional.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('phone', 20)->nullable()->after('name');
            $table->string('email', 150)->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->date('birthday')->nullable()->after('password');
            $table->text('notes')->nullable()->after('birthday');
            $table->decimal('total_spent', 12, 2)->default(0)->after('notes');
            $table->unsignedInteger('visits_count')->default(0)->after('total_spent');
            $table->timestamp('last_visit_at')->nullable()->after('visits_count');
            $table->trashable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
            $table->string('phone', 20)->nullable(false)->unique()->change();
            $table->string('name', 120)->change();
        });

        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('user_id')->constrained();
            $table->string('label', 40)->nullable();
            $table->string('address', 255);
            $table->string('area', 100)->nullable();
            $table->string('landmark', 150)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->trashable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['phone']);
            $table->dropIndex(['deleted_at']);
            $table->dropIndex(['trash_batch']);
            $table->dropColumn([
                'uuid', 'phone', 'birthday', 'notes', 'total_spent', 'visits_count', 'last_visit_at',
                'deleted_at', 'deleted_by', 'delete_reason', 'trash_batch',
            ]);
        });
    }
};
