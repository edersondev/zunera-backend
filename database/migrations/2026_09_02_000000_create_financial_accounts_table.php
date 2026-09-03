<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->string('account_type', 24);
            $table->string('institution_name', 120)->nullable();
            $table->string('color', 24);
            $table->string('icon', 32);
            $table->bigInteger('initial_balance_centavos');
            $table->bigInteger('current_balance_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->string('status', 16)->default('active');
            $table->string('active_normalized_name', 120)
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN normalized_name ELSE NULL END");
            $table->unique(['user_id', 'active_normalized_name']);
            $table->timestamp('archived_at')->nullable();
            $table->boolean('has_financial_movements')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
