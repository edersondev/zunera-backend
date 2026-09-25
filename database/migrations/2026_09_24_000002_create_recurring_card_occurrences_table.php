<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_card_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_transaction_id')->constrained('recurring_transactions')->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->string('generation_mode_snapshot', 16);
            $table->unsignedBigInteger('scheduled_amount_centavos');
            $table->string('description_snapshot', 200);
            $table->text('notes_snapshot')->nullable();
            $table->foreignId('category_id_original')->constrained('categories')->restrictOnDelete();
            $table->foreignId('credit_card_id_original')->constrained('credit_cards')->restrictOnDelete();
            $table->json('card_identity_snapshot');
            $table->string('category_name_snapshot', 120);
            $table->string('state', 24)->default('expected');
            $table->unsignedBigInteger('actual_amount_centavos')->nullable();
            $table->date('actual_purchase_date')->nullable();
            $table->unsignedBigInteger('credit_card_id_override')->nullable();
            $table->unsignedBigInteger('category_id_override')->nullable();
            $table->string('action_claim_key', 64)->nullable();
            $table->unsignedInteger('action_choice_version')->default(0);
            $table->timestamp('action_claimed_at')->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['recurring_transaction_id', 'scheduled_date'], 'recurring_card_occurrences_rule_date_unique');
            $table->index(['user_id', 'state', 'scheduled_date'], 'recurring_card_occurrences_owner_state_index');
            $table->index(['user_id', 'recurring_transaction_id'], 'recurring_card_occurrences_owner_rule_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_card_occurrences');
    }
};
