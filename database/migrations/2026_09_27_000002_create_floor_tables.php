<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dining areas and tables with their spot on the floor plan (PLAN §4.9). The floor
     * plan is a grid (see DiningTable::GRID_COLS / GRID_ROWS); pos_x / pos_y are the
     * table's top-left cell, so the layout scales with the screen.
     */
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 60);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('area_id')->constrained('areas');
            $table->string('name', 30);
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('shape', 20)->default('square'); // TableShape
            $table->string('status', 20)->default('available'); // TableStatus
            $table->unsignedTinyInteger('pos_x')->nullable();
            $table->unsignedTinyInteger('pos_y')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
        Schema::dropIfExists('areas');
    }
};
