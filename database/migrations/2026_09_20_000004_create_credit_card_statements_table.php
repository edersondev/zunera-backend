<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->date('closing_date');
            $table->date('due_date');
            $table->unsignedBigInteger('original_amount_centavos')->default(0);
            $table->unsignedBigInteger('credit_adjustment_centavos')->default(0);
            $table->unsignedBigInteger('paid_centavos')->default(0);
            $table->unsignedBigInteger('card_credit_applied_centavos')->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['credit_card_id', 'closing_date'], 'credit_card_statements_card_closing_unique');
            $table->index(['user_id', 'due_date', 'id'], 'credit_card_statements_owner_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_statements');
    }
};
