<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->longText('payload'); // Crypt-encrypted, including phone numbers and OTP.
            $table->char('recipient_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->unique(['notification_id', 'channel']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->char('event_key', 64)->nullable();
            $table->longText('payload')->nullable(); // Crypt-encrypted event snapshot.
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unique(['webhook_id', 'event_key'], 'webhook_event_once_unique');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique('webhook_event_once_unique');
            $table->dropColumn(['event_key', 'payload', 'sent_at', 'next_attempt_at']);
        });
        Schema::dropIfExists('notification_channel_payloads');
    }
};
