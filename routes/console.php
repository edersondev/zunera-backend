<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    DB::table('password_reset_tokens')
        ->where('created_at', '<', now()->subMinutes((int) env('AUTH_RECOVERY_TOKEN_RETENTION_MINUTES', 60)))
        ->delete();
})->hourly();

Schedule::call(function (): void {
    DB::table('sessions')
        ->where('last_activity', '<', now()->subMinutes((int) config('authentication.session.absolute_minutes', 480))->timestamp)
        ->delete();
})->daily();

Schedule::call(function (): void {
    DB::table('auth_mail_delivery_events')
        ->where('received_at', '<', now()->subDays((int) config('authentication.retention.delivery_events_days', 30)))
        ->delete();
})->daily();

Schedule::call(function (): void {
    DB::table('failed_jobs')
        ->where('failed_at', '<', now()->subDays((int) config('authentication.retention.failed_jobs_days', 14)))
        ->delete();
})->daily();
