<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->char('endpoint_hash', 64)->unique();
            $table->longText('subscription'); // Encrypted PushSubscription JSON.
            $table->string('device_name', 100)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('platform', 50)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('web_push_messages', function (Blueprint $table) {
            $table->foreignId('notification_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('icon', 255)->nullable();
            $table->string('target_url', 1024)->nullable();
            $table->char('request_hash', 64)->nullable();
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreignId('push_subscription_id')->nullable()
                ->after('connection_id')->constrained('push_subscriptions')->nullOnDelete();
            $table->unique(
                ['notification_id', 'push_subscription_id'],
                'notification_push_device_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('notification_push_device_unique');
            $table->dropConstrainedForeignId('push_subscription_id');
        });

        Schema::dropIfExists('web_push_messages');
        Schema::dropIfExists('push_subscriptions');
    }
};
