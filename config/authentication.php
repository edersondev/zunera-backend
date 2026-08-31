<?php

return [
    'session' => [
        'idle_minutes' => (int) env('SESSION_LIFETIME', 15),
        'absolute_minutes' => (int) env('SESSION_ABSOLUTE_LIFETIME_MINUTES', 480),
        'warning_seconds' => 60,
    ],
    'mail' => [
        'queue' => env('AUTH_MAIL_QUEUE', 'auth-mail'),
        'delivery_secret' => env('AUTH_MAIL_DELIVERY_EVENT_SECRET'),
        'delivery_replay_seconds' => (int) env('AUTH_MAIL_DELIVERY_REPLAY_SECONDS', 300),
    ],
    'retention' => [
        'delivery_events_days' => (int) env('AUTH_MAIL_DELIVERY_RETENTION_DAYS', 30),
        'security_logs_days' => (int) env('AUTH_SECURITY_LOG_RETENTION_DAYS', 365),
        'failed_jobs_days' => (int) env('AUTH_FAILED_JOB_RETENTION_DAYS', 14),
    ],
];
