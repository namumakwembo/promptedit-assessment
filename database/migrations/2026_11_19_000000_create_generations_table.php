<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->default('submitting')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->decimal('estimated_provider_min_usd', 20, 9);
            $table->decimal('estimated_provider_max_usd', 20, 9);
            $table->decimal('estimated_customer_min_usd', 22, 11);
            $table->decimal('estimated_customer_max_usd', 22, 11);
            $table->decimal('provider_cost_usd', 20, 9)->nullable();
            $table->decimal('customer_charge_usd', 22, 11)->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->boolean('submission_uncertain')->default(false);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
