<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_purchase_id')->constrained('credit_card_purchases')->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignId('credit_card_statement_id')->nullable()->constrained('credit_card_statements')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->unsignedBigInteger('amount_centavos');
            $table->unsignedBigInteger('credit_adjustment_centavos')->default(0);
            $table->date('recognition_date')->nullable();
            $table->timestamps();

            $table->unique(['credit_card_purchase_id', 'sequence'], 'credit_card_installments_purchase_sequence_unique');
            $table->index(['user_id', 'credit_card_statement_id'], 'credit_card_installments_owner_statement_index');
            $table->index(['user_id', 'credit_card_purchase_id'], 'credit_card_installments_owner_purchase_index');
            $table->index(['credit_card_id', 'credit_card_statement_id'], 'credit_card_installments_card_statement_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_installments');
    }
};
