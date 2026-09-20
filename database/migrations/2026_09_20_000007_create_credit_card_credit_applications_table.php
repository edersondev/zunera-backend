<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_credit_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_credit_event_id')->constrained('credit_card_credit_events')->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignId('credit_card_installment_id')->nullable()->constrained('credit_card_installments')->cascadeOnDelete();
            $table->foreignId('credit_card_statement_id')->nullable()->constrained('credit_card_statements')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_centavos');
            $table->date('applied_at');
            $table->timestamps();

            $table->index(['user_id', 'credit_card_statement_id'], 'credit_card_credit_applications_owner_statement_index');
            $table->index(['user_id', 'credit_card_credit_event_id'], 'credit_card_credit_applications_owner_event_index');
            $table->index('credit_card_id', 'credit_card_credit_applications_card_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_credit_applications');
    }
};
