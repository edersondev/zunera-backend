<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });

        Schema::create('notification_preference_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->boolean('enabled');
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->index(['user_id', 'category', 'effective_at', 'id'], 'notification_preference_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preference_changes');
        Schema::dropIfExists('notification_preferences');
    }
};
