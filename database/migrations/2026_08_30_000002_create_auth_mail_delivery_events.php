<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_mail_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->uuid('message_id')->index();
            $table->string('status', 24)->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_mail_delivery_events');
    }
};
