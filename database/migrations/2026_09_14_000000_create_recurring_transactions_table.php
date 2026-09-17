<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedBigInteger('amount_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->string('description', 200);
            $table->text('notes')->nullable();
            $table->string('frequency', 16);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('state', 16)->default('active');
            $table->string('paused_reason', 32)->nullable();
            $table->date('eligibility_starts_on');
            $table->date('schedule_cursor');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'state', 'schedule_cursor', 'id'], 'recurring_transactions_owner_schedule_index');
            $table->index(['user_id', 'financial_account_id'], 'recurring_transactions_owner_account_index');
            $table->index(['user_id', 'category_id'], 'recurring_transactions_owner_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
