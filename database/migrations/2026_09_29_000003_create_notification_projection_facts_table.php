<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_projection_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedSmallInteger('affected_year')->nullable();
            $table->unsignedTinyInteger('affected_month')->nullable();
            $table->string('qualified_type', 40)->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'available_at', 'id'], 'notification_facts_pending_idx');
            $table->index(['user_id', 'processed_at', 'id'], 'notification_facts_owner_idx');
            $table->index(['source_kind', 'source_id', 'processed_at'], 'notification_facts_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_projection_facts');
    }
};
