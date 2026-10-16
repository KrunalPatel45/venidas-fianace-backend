<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('society_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('account_number', 30)->unique();

            $table->decimal('monthly_amount', 12, 2)
                ->default(1000);

            $table->decimal('opening_fee_amount', 12, 2)
                ->default(100);

            $table->date('opened_at');

            $table->enum('status', [
                'active',
                'inactive',
                'closed'
            ])->default('active');

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('society_accounts');
    }
};