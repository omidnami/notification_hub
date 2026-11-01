<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedTinyInteger('priority')->default(4); // 1 critical ... 5 low
            $table->string('status', 16)->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->softDeletes(); // API deletes are soft; preserve delivery history.
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at', 'priority']);
            $table->index(['user_id', 'read_at']);
            $table->unique(['project_id', 'idempotency_key']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'connection_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
    }
};
