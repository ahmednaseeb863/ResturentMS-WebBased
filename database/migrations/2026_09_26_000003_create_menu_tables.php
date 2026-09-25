<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu items (prepared, no stock), their variants, modifier groups and recipes
     * (PLAN §4.7, §7 Menu). A recipe row says how much of a raw material one serving
     * of a menu item / variant / modifier uses.
     */
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('kitchen_station_id')->nullable()->constrained('kitchen_stations');
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('prep_time_minutes')->nullable();
            $table->json('available_for');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_sold_out')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'slug']);
        });

        Schema::create('menu_item_variants', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('menu_item_id')->constrained();
            $table->string('name', 60);
            $table->decimal('price', 12, 2);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('modifier_group_id')->constrained();
            $table->string('name', 80);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('menu_item_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained();
            $table->foreignId('modifier_group_id')->constrained();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->unique(['menu_item_id', 'modifier_group_id']);
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->string('recipeable_type', 40);
            $table->unsignedBigInteger('recipeable_id');
            $table->foreignId('raw_material_id')->constrained();
            $table->decimal('quantity', 12, 3);
            $table->foreignId('unit_id')->constrained('units');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->unique(['recipeable_type', 'recipeable_id', 'raw_material_id'], 'recipe_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('menu_item_modifier_group');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('menu_item_variants');
        Schema::dropIfExists('menu_items');
    }
};
