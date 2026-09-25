<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table): void {
            $table->string('destination_type', 24)->default('financial_account');
            $table->unsignedBigInteger('credit_card_id')->nullable();
            $table->string('generation_mode', 16)->nullable();
            $table->unsignedBigInteger('financial_account_id')->nullable()->change();

            $table->foreign('credit_card_id', 'recurring_transactions_credit_card_foreign')
                ->references('id')
                ->on('credit_cards')
                ->restrictOnDelete();
            $table->index(['user_id', 'credit_card_id'], 'recurring_transactions_owner_card_index');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table): void {
            $table->dropIndex('recurring_transactions_owner_card_index');
            $table->dropForeign('recurring_transactions_credit_card_foreign');
            $table->dropColumn(['destination_type', 'credit_card_id', 'generation_mode']);
            $table->unsignedBigInteger('financial_account_id')->nullable(false)->change();
        });
    }
};
