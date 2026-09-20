<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_statement_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_statement_id')->constrained('credit_card_statements')->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('effective');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'credit_card_statement_id', 'status'], 'credit_card_payments_owner_statement_index');
            $table->index(['user_id', 'payment_date', 'id'], 'credit_card_payments_owner_date_index');
            $table->index(['user_id', 'financial_account_id'], 'credit_card_payments_owner_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_statement_payments');
    }
};
