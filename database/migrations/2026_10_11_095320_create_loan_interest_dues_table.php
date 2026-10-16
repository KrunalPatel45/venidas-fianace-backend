<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_interest_dues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_id')
                ->constrained('loans')
                ->restrictOnDelete();

            $table->date('interest_month');

            $table->decimal('principal_snapshot', 14, 2);
            $table->decimal('interest_rate_snapshot', 6, 3);
            $table->decimal('amount', 14, 2);

            $table->enum('status', ['pending', 'paid'])
                ->default('pending');

            $table->enum('payment_method', ['cash'])
                ->nullable();

            $table->date('paid_at')->nullable();
            $table->string('receipt_number', 50)
                ->nullable()
                ->unique();

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['loan_id', 'interest_month'],
                'loan_interest_month_unique'
            );

            $table->index(['interest_month', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_interest_dues');
    }
};
