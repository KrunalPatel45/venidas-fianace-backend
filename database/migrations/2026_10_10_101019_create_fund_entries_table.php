<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_entries', function (Blueprint $table) {
            $table->id();

            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');

            $table->enum('direction', [
                'credit',
                'debit'
            ]);

            $table->decimal('amount', 14, 2);

            $table->date('transaction_date');

            $table->string('description', 255)->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique([
                'reference_type',
                'reference_id'
            ], 'fund_reference_unique');

            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_entries');
    }
};