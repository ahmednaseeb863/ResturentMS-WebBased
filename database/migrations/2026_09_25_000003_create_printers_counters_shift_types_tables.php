<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thermal printers of a branch (PLAN §4.5.2). USB printers are addressed by their
        // OS printer name (QZ Tray), network printers by IP + port. (Not `connection`: Eloquent
        // models already have a $connection property.)
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->string('type', 20);
            $table->string('connection_type', 20);
            $table->string('device_name', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->unsignedTinyInteger('paper_width')->default(80);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        // Cash counters (drawers) of a branch; each has at most one open shift (Phase 7).
        Schema::create('cash_counters', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->foreignId('receipt_printer_id')->nullable()->constrained('printers');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        // Shift templates, e.g. Morning 11:00–19:00, Night 19:00–04:00 (end ≤ start ⇒ overnight).
        Schema::create('shift_types', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_types');
        Schema::dropIfExists('cash_counters');
        Schema::dropIfExists('printers');
    }
};
