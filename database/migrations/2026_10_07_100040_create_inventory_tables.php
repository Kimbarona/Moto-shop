<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stock ledger: single source of truth for sellable stock.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('type', 30)->index();
            $table->decimal('qty', 10, 2); // signed: + in, - out
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('balance_after', 10, 2);
            $table->nullableMorphs('reference');
            $table->string('remarks')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('stock_receivings', function (Blueprint $table) {
            $table->id();
            $table->string('receiving_no', 30)->unique();
            $table->date('date');
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->string('reference_no', 100)->nullable(); // supplier DR / invoice
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('stock_receiving_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receiving_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('qty', 10, 2);
            $table->decimal('unit_cost', 12, 4);
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 30)->unique();
            $table->date('date');
            $table->string('reason', 20);
            $table->text('remarks');
            $table->string('status', 10)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('qty', 10, 2); // signed: + adds stock, - removes stock
            $table->decimal('unit_cost', 12, 4)->nullable(); // snapshot at posting
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_no', 30)->unique();
            $table->date('date');
            $table->foreignId('category_id')->nullable()->constrained(); // null = all products
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('system_qty', 10, 2);
            $table->decimal('counted_qty', 10, 2)->nullable();
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_id']);
        });

        // Returned-defective items: outside sellable stock until sent back or written off.
        Schema::create('defective_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->decimal('qty', 10, 2);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->unsignedBigInteger('return_item_id')->nullable()->index(); // FK added with sales tables
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->string('supplier_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defective_stocks');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_receiving_items');
        Schema::dropIfExists('stock_receivings');
        Schema::dropIfExists('stock_movements');
    }
};
