<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_purchase_moves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignId('credit_card_purchase_id')->constrained('credit_card_purchases')->cascadeOnDelete();
            $table->foreignId('credit_card_installment_id')->constrained('credit_card_installments')->cascadeOnDelete();
            $table->foreignId('source_statement_id')->constrained('credit_card_statements')->restrictOnDelete();
            $table->foreignId('destination_statement_id')->constrained('credit_card_statements')->restrictOnDelete();
            $table->string('mode', 24);
            $table->unsignedSmallInteger('sequence');
            $table->date('source_closing_date');
            $table->date('source_period_from');
            $table->date('source_period_to');
            $table->date('source_due_date');
            $table->date('destination_closing_date');
            $table->date('destination_period_from');
            $table->date('destination_period_to');
            $table->date('destination_due_date');
            $table->string('operation_id', 64);
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->unique(['operation_id', 'credit_card_installment_id'], 'cc_purchase_moves_operation_installment_unique');
            $table->index(['user_id', 'credit_card_purchase_id', 'moved_at'], 'cc_purchase_moves_owner_purchase_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_purchase_moves');
    }
};
