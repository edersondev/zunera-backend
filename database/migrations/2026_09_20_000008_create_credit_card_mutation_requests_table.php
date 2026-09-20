<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_mutation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('operation', 64);
            $table->string('request_fingerprint', 64);
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'credit_card_mutations_owner_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_mutation_requests');
    }
};
