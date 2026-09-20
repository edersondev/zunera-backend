<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignId('credit_card_purchase_id')->constrained('credit_card_purchases')->cascadeOnDelete();
            $table->string('reason', 16);
            $table->unsignedBigInteger('amount_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->date('event_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'credit_card_purchase_id', 'id'], 'credit_card_credit_events_owner_purchase_index');
            $table->index(['user_id', 'event_date'], 'credit_card_credit_events_owner_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_credit_events');
    }
};
