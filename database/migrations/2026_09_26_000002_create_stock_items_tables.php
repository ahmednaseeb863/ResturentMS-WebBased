<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kitchen stations, POS categories, raw materials, ready items and the stock ledger
     * (PLAN §2.1, §4.7, §4.16). Quantities are DECIMAL(12,3) in the item's stock unit;
     * unit costs are DECIMAL(14,4) (a gram of saffron costs less than a paisa-precision
     * price can hold), money DECIMAL(12,2).
     */
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 60);
            $table->boolean('has_screen')->default(true);
            $table->foreignId('printer_id')->nullable()->constrained('printers');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        // POS categories — shared by menu items and ready items.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->string('image')->nullable();
            $table->foreignId('kitchen_station_id')->nullable()->constrained('kitchen_stations');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'slug']);
        });

        Schema::create('raw_material_categories', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 60);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained('raw_material_categories');
            $table->string('code', 30)->nullable();
            $table->string('name', 120);
            $table->foreignId('stock_unit_id')->constrained('units');
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units');
            $table->decimal('purchase_unit_factor', 12, 3)->nullable();
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('alert_level', 12, 3)->nullable();
            $table->decimal('avg_cost', 14, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('ready_items', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('kitchen_station_id')->nullable()->constrained('kitchen_stations');
            $table->string('code', 30)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('name', 120);
            $table->string('image')->nullable();
            $table->decimal('price', 12, 2);
            $table->foreignId('stock_unit_id')->constrained('units');
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units');
            $table->decimal('purchase_unit_factor', 12, 3)->nullable();
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('alert_level', 12, 3)->nullable();
            $table->decimal('avg_cost', 14, 4)->default(0);
            $table->json('available_for');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'code']);
            $table->unique(['branch_id', 'barcode']);
        });

        // Append-only stock ledger: every in/out of a raw material or ready item.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('stockable_type', 40);
            $table->unsignedBigInteger('stockable_id');
            $table->date('business_date');
            $table->string('type', 30);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('balance_after', 12, 3);
            $table->nullableMorphs('reference');
            $table->string('note', 255)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins');
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'stockable_type', 'stockable_id', 'business_date'], 'stock_movements_item_date');
            $table->index(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('ready_items');
        Schema::dropIfExists('raw_materials');
        Schema::dropIfExists('raw_material_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('kitchen_stations');
    }
};
