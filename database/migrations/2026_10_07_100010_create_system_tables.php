<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Document numbers (ORD, SLIP, RET, …). Rows are locked FOR UPDATE inside the
        // business transaction, so a rolled-back payment never leaves a gap.
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('prefix', 20)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();
        });

        $now = now();
        DB::table('sequences')->insert(array_map(fn (array $row) => [...$row, 'next_number' => 1, 'created_at' => $now, 'updated_at' => $now], [
            ['key' => 'order', 'prefix' => 'ORD-', 'padding' => 6],
            ['key' => 'slip', 'prefix' => 'OS-', 'padding' => 6],
            ['key' => 'return', 'prefix' => 'RET-', 'padding' => 6],
            ['key' => 'sku', 'prefix' => 'P-', 'padding' => 6],
            ['key' => 'receiving', 'prefix' => 'RCV-', 'padding' => 6],
            ['key' => 'adjustment', 'prefix' => 'ADJ-', 'padding' => 6],
            ['key' => 'count', 'prefix' => 'CNT-', 'padding' => 6],
        ]));

        Schema::create('manager_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30)->index();
            $table->foreignId('approver_id')->constrained('users');
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->nullableMorphs('subject');
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('reprint_logs', function (Blueprint $table) {
            $table->id();
            $table->string('document', 30); // slip, job_ticket, return_slip
            $table->morphs('printable');
            $table->foreignId('user_id')->constrained('users');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reprint_logs');
        Schema::dropIfExists('manager_approvals');
        Schema::dropIfExists('sequences');
    }
};
