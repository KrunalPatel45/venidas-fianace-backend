<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_opening_fees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('society_account_id')
                ->unique()
                ->constrained('society_accounts')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->enum('status', [
                'pending',
                'paid'
            ])->default('pending');

            $table->date('paid_at')->nullable();

            $table->string('receipt_number', 40)
                ->nullable()
                ->unique();

            $table->enum('payment_method', [
                'cash',
                'upi',
                'bank'
            ])->nullable();

            $table->string('payment_reference', 100)
                ->nullable();

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_opening_fees');
    }
};