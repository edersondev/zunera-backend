<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('description', 200);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('total_amount_centavos');
            $table->unsignedSmallInteger('installment_count');
            $table->date('purchase_date');
            $table->string('currency_code', 3)->default('BRL');
            $table->string('card_name_snapshot', 120);
            $table->string('category_name_snapshot', 120);
            $table->string('category_status_snapshot', 16);
            $table->timestamps();

            $table->index(['user_id', 'purchase_date', 'id'], 'credit_card_purchases_owner_date_index');
            $table->index(['user_id', 'credit_card_id'], 'credit_card_purchases_owner_card_index');
            $table->index(['user_id', 'category_id'], 'credit_card_purchases_owner_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_purchases');
    }
};
