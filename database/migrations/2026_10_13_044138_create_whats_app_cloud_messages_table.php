<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_cloud_messages', function (Blueprint $table) {
            $table->id();

            $table->string('event_key')->unique();
            $table->foreignId('user_id')->nullable()->index();

            $table->string('recipient', 30);
            $table->string('type', 30);
            $table->string('member_name');
            $table->string('amount', 40);

            $table->string('status', 30)->default('pending');
            $table->string('meta_message_id')->nullable()->unique();

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_cloud_messages');
    }
};