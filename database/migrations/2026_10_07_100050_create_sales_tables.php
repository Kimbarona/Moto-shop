<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->string('slip_no', 30)->nullable()->unique(); // assigned only when paid
            $table->string('type', 10); // walk_in, service
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('customer_motorcycle_id')->nullable()->constrained();
            $table->unsignedInteger('odometer_in')->nullable();
            $table->text('complaint')->nullable();
            $table->string('status', 10)->default('pending')->index();
            $table->string('service_status', 20)->nullable()->index();
            $table->string('refund_status', 10)->default('none');

            // Order-level discount as entered (peso or %); the computed peso amount
            // is spread over the lines (order_items.discount_share) at payment.
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('vatable', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Exchange: credit from a return applied to this sale.
            $table->unsignedBigInteger('exchange_return_id')->nullable()->index();
            $table->decimal('return_credit', 12, 2)->default(0);

            $table->timestamp('done_at')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->foreignId('cashier_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('order_mechanic', function (Blueprint $table) {
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_id')->constrained();
            $table->primary(['order_id', 'mechanic_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('line_type', 10); // part, labor
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('service_id')->nullable()->constrained();
            $table->foreignId('service_package_id')->nullable()->constrained();
            $table->string('description');
            $table->decimal('qty', 10, 2);
            $table->decimal('original_price', 12, 2); // SRP / labor fee when added
            $table->decimal('unit_price', 12, 2);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0); // line discount in pesos
            $table->decimal('line_total', 12, 2)->default(0); // qty × price − line discount
            $table->decimal('discount_share', 12, 2)->default(0); // allocated order discount
            $table->decimal('unit_cost', 12, 4)->nullable(); // avg_cost snapshot at payment
            $table->foreignId('mechanic_id')->nullable()->constrained();
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('qty_returned', 10, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'order_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('payment_method_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->default(0);
            $table->string('reference_no', 100)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->index();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('order_id')->constrained();
            $table->timestamp('date')->index();
            $table->string('reason', 20);
            $table->text('remarks')->nullable();
            $table->string('resolution', 10); // refund, exchange
            $table->decimal('return_total', 12, 2)->default(0); // value of everything returned
            $table->foreignId('refund_method_id')->nullable()->constrained('payment_methods');
            $table->string('refund_reference', 100)->nullable();
            $table->decimal('refund_amount', 12, 2)->default(0); // money actually paid out
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained();
            $table->decimal('qty', 10, 2);
            $table->string('disposition', 20)->nullable(); // null for labor lines
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('amount', 12, 2);
            $table->decimal('commission_reversed', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('exchange_return_id')->references('id')->on('returns');
        });

        Schema::table('defective_stocks', function (Blueprint $table) {
            $table->foreign('return_item_id')->references('id')->on('return_items');
        });
    }

    public function down(): void
    {
        Schema::table('defective_stocks', fn (Blueprint $table) => $table->dropForeign(['return_item_id']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropForeign(['exchange_return_id']));
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('order_mechanic');
        Schema::dropIfExists('orders');
    }
};
