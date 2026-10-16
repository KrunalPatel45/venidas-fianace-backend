<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('member_code')
                ->nullable()
                ->unique();

            $table->string('phone', 15)
                ->nullable()
                ->unique();

            $table->string('address')
                ->nullable();

            $table->enum('role', [
                'admin',
                'member',
                'external'
            ])->default('member');

            $table->enum('preferred_language', [
                'gu',
                'en'
            ])->default('gu');

            $table->boolean('is_active')
                ->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'member_code',
                'phone',
                'address',
                'role',
                'preferred_language',
                'is_active'
            ]);
        });
    }
};