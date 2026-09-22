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
            $table->foreignId('user_id')->constrained(indexName: 'cc_credit_apps_user_fk')->cascadeOnDelete();
            $table->foreignId('credit_card_credit_event_id')->constrained(
                table: 'credit_card_credit_events',
                indexName: 'cc_credit_apps_event_fk',
            )->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained(
                table: 'credit_cards',
                indexName: 'cc_credit_apps_card_fk',
            )->cascadeOnDelete();
            $table->foreignId('credit_card_installment_id')->nullable()->constrained(
                table: 'credit_card_installments',
                indexName: 'cc_credit_apps_installment_fk',
            )->cascadeOnDelete();
            $table->foreignId('credit_card_statement_id')->nullable()->constrained(
                table: 'credit_card_statements',
                indexName: 'cc_credit_apps_statement_fk',
            )->cascadeOnDelete();
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
