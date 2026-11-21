<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixelforge_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('status')->default('received');
            $table->timestampTz('provider_created_at');
            $table->timestampTz('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixelforge_webhook_events');
    }
};
