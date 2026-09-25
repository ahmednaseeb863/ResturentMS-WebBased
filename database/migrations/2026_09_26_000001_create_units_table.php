<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Units of measure, shared by every branch (PLAN §7 Inventory). A unit is either a
     * base unit (kg, L, pcs, carton…) or part of one: `factor` = how many base units one
     * of it is (g → kg = 0.001, dozen → pcs = 12). Units of one family convert freely.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->publicUuid();
            $table->string('name', 40);
            $table->string('short_name', 12)->unique();
            $table->foreignId('base_unit_id')->nullable()->constrained('units');
            $table->decimal('factor', 16, 6)->default(1);
            $table->timestamps();
            $table->trashable();
        });

        $now = now();
        $insert = function (string $name, string $short, ?int $base = null, float $factor = 1) use ($now): int {
            return DB::table('units')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'name' => $name,
                'short_name' => $short,
                'base_unit_id' => $base,
                'factor' => $factor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        $kg = $insert('Kilogram', 'kg');
        $insert('Gram', 'g', $kg, 0.001);
        $l = $insert('Litre', 'L');
        $insert('Millilitre', 'ml', $l, 0.001);
        $pcs = $insert('Piece', 'pcs');
        $insert('Dozen', 'dozen', $pcs, 12);
        $insert('Carton', 'carton');
        $insert('Crate', 'crate');
        $insert('Packet', 'pkt');
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
