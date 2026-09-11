<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('effective');
            $table->string('description', 200);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('amount_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->date('transaction_date');
            $table->text('search_text');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'removed_at', 'transaction_date', 'id'], 'transactions_owner_history_index');
            $table->index(['user_id', 'financial_account_id'], 'transactions_owner_account_index');
            $table->index(['user_id', 'category_id'], 'transactions_owner_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
