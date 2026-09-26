<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_goal_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_goal_id')->constrained('financial_goals')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedBigInteger('amount_centavos')->nullable();
            $table->unsignedBigInteger('financial_account_id_at_time')->nullable();
            $table->string('account_name_at_time', 120)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->date('business_date');
            $table->timestamps();

            $table->index(['financial_goal_id', 'occurred_at', 'id']);
            $table->index(['user_id', 'financial_goal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_goal_activities');
    }
};
