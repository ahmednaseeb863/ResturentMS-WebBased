<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only tables for the Trashable / HasPublicUuid / BelongsToBranch trait tests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_parents', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('fixture_children', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('fixture_parent_id')->constrained();
            $table->string('name');
            $table->timestamps();
            $table->trashable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_children');
        Schema::dropIfExists('fixture_parents');
    }
};
