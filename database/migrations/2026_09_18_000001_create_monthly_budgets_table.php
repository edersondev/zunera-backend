<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('budget_year');
            $table->unsignedTinyInteger('budget_month');
            $table->timestamps();

            $table->unique(['user_id', 'budget_year', 'budget_month'], 'monthly_budgets_owner_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_budgets');
    }
};
