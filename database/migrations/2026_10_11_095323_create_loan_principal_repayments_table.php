<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_principal_repayments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_id')
                ->constrained('loans')
                ->restrictOnDelete();

            $table->decimal('amount', 14, 2);
            $table->date('paid_at');

            $table->enum('payment_method', ['cash'])
                ->default('cash');

            $table->string('receipt_number', 50)->unique();

            // Unique per submitted request, prevents retry duplicates.
            $table->uuid('idempotency_key')->unique();

            $table->foreignId('received_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['loan_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_principal_repayments');
    }
};
