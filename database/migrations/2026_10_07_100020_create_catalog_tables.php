<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('abbreviation', 10);
            $table->boolean('allows_decimal')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('motorcycle_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('motorcycle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorcycle_make_id')->constrained();
            $table->string('name');
            $table->unsignedSmallInteger('engine_cc')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 30)->unique();
            $table->string('name')->index();
            $table->string('part_number', 100)->nullable()->index();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->text('description')->nullable();
            $table->decimal('avg_cost', 12, 4)->default(0);
            $table->decimal('srp', 12, 2)->default(0);
            $table->decimal('reorder_level', 10, 2)->default(0);
            $table->string('bin_location', 50)->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_universal')->default(false);
            $table->boolean('is_active')->default(true);
            // Cache of the stock ledger. Only StockService may write this column.
            $table->decimal('qty_on_hand', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('motorcycle_model_id')->constrained()->cascadeOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'motorcycle_model_id']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->decimal('labor_fee', 12, 2)->default(0);
            $table->unsignedSmallInteger('est_minutes')->nullable();
            // null = use the default commission % from Settings
            $table->string('commission_type', 10)->nullable();
            $table->decimal('commission_value', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // null = lines keep their normal prices
            $table->decimal('package_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->decimal('qty', 10, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_items');
        Schema::dropIfExists('service_packages');
        Schema::dropIfExists('services');
        Schema::dropIfExists('product_fitments');
        Schema::dropIfExists('products');
        Schema::dropIfExists('motorcycle_models');
        Schema::dropIfExists('motorcycle_makes');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
