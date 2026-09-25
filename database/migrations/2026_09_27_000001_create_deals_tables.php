<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deals (fixed-price bundles) and predefined discounts, per branch (PLAN §4.8).
     * A deal has slots ("Burger", "Drink" × 2); a slot with one option is a fixed item,
     * with more it is "choose any 1 from…". Options are menu items (optionally one size)
     * or ready items.
     */
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price', 12, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('days_of_week')->nullable(); // ISO 1 (Mon) … 7 (Sun); null = every day
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();     // may be past midnight (22:00 → 02:00)
            $table->json('available_for');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });

        Schema::create('deal_slots', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('deal_id')->constrained();
            $table->string('name', 80);
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();
        });

        Schema::create('deal_slot_options', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('deal_slot_id')->constrained();
            $table->string('sellable_type', 40);
            $table->unsignedBigInteger('sellable_id');
            $table->foreignId('variant_id')->nullable()->constrained('menu_item_variants');
            $table->decimal('extra_price', 12, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->trashable();

            $table->index(['sellable_type', 'sellable_id']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->string('type', 20);        // DiscountType
            $table->decimal('value', 12, 2);
            $table->string('applies_to', 20);  // DiscountScope
            $table->decimal('max_amount', 12, 2)->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->trashable();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('deal_slot_options');
        Schema::dropIfExists('deal_slots');
        Schema::dropIfExists('deals');
    }
};
