<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('society_cash_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamps();
        });

        DB::table('society_cash_locks')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('society_cash_locks');
    }
};
