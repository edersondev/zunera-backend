<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('destination_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('status', 16)->default('effective');
            $table->string('description', 200)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('amount_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->date('transfer_date');
            $table->text('search_text');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'removed_at', 'transfer_date', 'id'], 'transfers_owner_history_index');
            $table->index(['user_id', 'source_financial_account_id'], 'transfers_owner_source_index');
            $table->index(['user_id', 'destination_financial_account_id'], 'transfers_owner_destination_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
