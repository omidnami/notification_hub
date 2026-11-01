<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary(); // Always use id = 1.
            $table->string('site_name');
            $table->string('contact_email')->nullable();
            $table->timestamps();
        });

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 32)->default('global');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->string('key', 128);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'key']);
        });

        Schema::create('user_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->json('value')->nullable();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('post_type', 32)->default('post');
            $table->string('title');
            $table->string('slug', 191);
            $table->longText('content')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->unique(['post_type', 'slug']);
            $table->index(['post_type', 'status']);
        });

        Schema::create('post_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->json('value')->nullable();

            $table->unique(['post_id', 'key']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('content_type', 32);
            $table->string('name');
            $table->string('slug', 191);
            $table->timestamps();

            $table->unique(['content_type', 'slug']);
        });

        Schema::create('post_categories', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['post_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('post_meta');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('user_meta');
        Schema::dropIfExists('options');
        Schema::dropIfExists('settings');
    }
};
