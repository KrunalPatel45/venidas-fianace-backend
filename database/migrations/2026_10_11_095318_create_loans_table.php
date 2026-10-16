<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();

            $table->string('loan_number', 50)->unique();

            $table->foreignId('borrower_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('loan_type', ['member', 'external']);

            $table->decimal('principal_amount', 14, 2);
            $table->decimal('outstanding_principal', 14, 2);

            // 1.00 means 1% per month.
            $table->decimal('monthly_interest_rate', 6, 3);

            $table->enum('status', ['active', 'closed'])
                ->default('active');

            $table->date('disbursed_at');
            $table->enum('disbursement_method', ['cash'])
                ->default('cash');

            $table->string('purpose', 500)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['borrower_id', 'status']);
            $table->index(['loan_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
