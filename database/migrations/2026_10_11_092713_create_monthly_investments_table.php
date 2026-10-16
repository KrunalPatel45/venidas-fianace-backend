
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_investments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('society_account_id')
                ->constrained('society_accounts')
                ->restrictOnDelete();

            // First day of the investment month.
            $table->date('investment_month');

            $table->decimal('amount', 14, 2);

            $table->enum('status', [
                'pending',
                'paid',
            ])->default('pending');

            // Cash is the only supported payment mode.
            $table->enum('payment_method', [
                'cash',
            ])->nullable();

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
                ['society_account_id', 'investment_month'],
                'monthly_investment_account_month_unique'
            );

            $table->index([
                'investment_month',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_investments');
    }
};
