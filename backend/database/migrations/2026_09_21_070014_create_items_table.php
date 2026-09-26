<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_category_id')->constrained('item_categories');
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('unit', 30);
            $table->integer('minimum_stock')->default(0);
            $table->integer('maximum_stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE items ADD CONSTRAINT items_minimum_stock_non_negative CHECK (minimum_stock >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
