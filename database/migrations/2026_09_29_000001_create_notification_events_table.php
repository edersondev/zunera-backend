<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 191);
            $table->string('type', 40);
            $table->string('category', 40);
            $table->string('severity', 20);
            $table->string('source_kind', 40);
            $table->unsignedBigInteger('source_id');
            $table->json('business_context')->nullable();
            $table->timestamp('event_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('visibility', 16)->default('visible');
            $table->timestamp('expired_at')->nullable();
            $table->json('event_snapshot')->nullable();
            $table->json('current_context')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
            $table->index(['user_id', 'visibility', 'created_at', 'id'], 'notification_events_cursor_idx');
            $table->index(['user_id', 'visibility', 'read_at'], 'notification_events_unread_idx');
            $table->index(['user_id', 'visibility', 'resolved_at'], 'notification_events_action_idx');
            $table->index(['user_id', 'source_kind', 'source_id'], 'notification_events_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
