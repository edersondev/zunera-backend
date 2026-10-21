<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_payment_surplus_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignId('source_statement_id')->constrained('credit_card_statements')->cascadeOnDelete();
            $table->foreignId('destination_statement_id')->constrained('credit_card_statements')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->date('applied_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['credit_card_id', 'superseded_at', 'source_statement_id'], 'cc_surplus_card_active_source_idx');
            $table->index(['credit_card_id', 'superseded_at', 'destination_statement_id'], 'cc_surplus_card_active_target_idx');
            $table->index(['user_id', 'credit_card_id'], 'cc_surplus_owner_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_payment_surplus_applications');
    }
};
