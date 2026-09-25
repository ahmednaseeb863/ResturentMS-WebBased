<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two-level settings (PLAN §4.5): branch_id NULL = global value, else a branch override.
        // A branch row exists only for overridden fields; "use global" trashes it (history kept).
        // Field definitions and defaults live in App\Support\Settings\SettingsRegistry.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('group', 40);
            $table->string('key', 60);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins');
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
