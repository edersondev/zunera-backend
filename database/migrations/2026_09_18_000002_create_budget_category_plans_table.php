<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_category_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monthly_budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('planned_amount_centavos');
            $table->string('currency_code', 3)->default('BRL');
            $table->string('category_name_snapshot', 120);
            $table->string('category_classification_snapshot', 16);
            $table->string('category_origin_snapshot', 16);
            $table->string('category_color_snapshot', 32)->nullable();
            $table->string('category_icon_snapshot', 32)->nullable();
            $table->timestamps();

            $table->unique(['monthly_budget_id', 'category_id'], 'budget_category_plans_month_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_category_plans');
    }
};
