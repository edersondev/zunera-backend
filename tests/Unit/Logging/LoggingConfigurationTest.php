<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoggingConfigurationTest extends TestCase
{
    #[Test]
    public function routine_test_runs_use_the_existing_null_channel(): void
    {
        $this->assertSame('null', config('logging.default'));
        $this->assertArrayHasKey('null', config('logging.channels'));
    }

    #[Test]
    public function application_and_authentication_channels_have_daily_retention(): void
    {
        $logging = $this->loadLoggingConfiguration([
            'LOG_CHANNEL' => null,
            'LOG_STACK' => null,
            'LOG_LEVEL' => null,
            'LOG_DAILY_DAYS' => null,
            'AUTH_LOG_LEVEL' => null,
            'AUTH_LOG_DAILY_DAYS' => null,
        ]);

        $this->assertSame('daily', $logging['default']);
        $this->assertSame(['daily'], $logging['channels']['stack']['channels']);
        $this->assertSame('daily', $logging['channels']['daily']['driver']);
        $this->assertSame(storage_path('logs/laravel.log'), $logging['channels']['daily']['path']);
        $this->assertSame('debug', $logging['channels']['daily']['level']);
        $this->assertSame(14, $logging['channels']['daily']['max_files']);
        $this->assertSame('daily', $logging['channels']['auth']['driver']);
        $this->assertSame(storage_path('logs/authentication.log'), $logging['channels']['auth']['path']);
        $this->assertSame('info', $logging['channels']['auth']['level']);
        $this->assertSame(30, $logging['channels']['auth']['max_files']);
        $this->assertSame('auth', $logging['auth_channel']);
        $this->assertArrayHasKey('single', $logging['channels']);
        $this->assertArrayHasKey('monthly', $logging['channels']);
    }

    #[Test]
    public function environment_overrides_levels_retention_and_channel_selection(): void
    {
        $logging = $this->loadLoggingConfiguration([
            'LOG_CHANNEL' => 'stack',
            'LOG_STACK' => 'daily,stderr',
            'LOG_LEVEL' => 'warning',
            'LOG_DAILY_DAYS' => '5',
            'AUTH_LOG_CHANNEL' => 'auth',
            'AUTH_LOG_LEVEL' => 'error',
            'AUTH_LOG_DAILY_DAYS' => '9',
        ]);

        $this->assertSame('stack', $logging['default']);
        $this->assertSame(['daily', 'stderr'], $logging['channels']['stack']['channels']);
        $this->assertSame('warning', $logging['channels']['daily']['level']);
        $this->assertSame(5, $logging['channels']['daily']['max_files']);
        $this->assertSame('error', $logging['channels']['auth']['level']);
        $this->assertSame(9, $logging['channels']['auth']['max_files']);
    }

    #[Test]
    public function daily_driver_writes_a_dated_file_with_monolog_rotating_handler(): void
    {
        $directory = sys_get_temp_dir().'/zunera-logging-'.bin2hex(random_bytes(8));
        $path = $directory.'/laravel.log';

        try {
            $logger = Log::build([
                'driver' => 'daily',
                'path' => $path,
                'level' => 'info',
                'max_files' => 2,
            ]);
            $logger->info('rotation smoke test');

            $handler = $logger->getLogger()->getHandlers()[0];
            $this->assertInstanceOf(RotatingFileHandler::class, $handler);
            $this->assertMatchesRegularExpression('/^laravel-\\d{4}-\\d{2}-\\d{2}\\.log$/', basename($handler->getUrl()));
            $this->assertFileExists($handler->getUrl());
            $handler->close();
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** @param array<string, string|null> $variables
     * @return array<string, mixed>
     */
    private function loadLoggingConfiguration(array $variables): array
    {
        $previous = [];
        foreach ($variables as $key => $value) {
            $previous[$key] = [
                'environment' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
                'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
                'process' => getenv($key),
            ];

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }

        try {
            return require base_path('config/logging.php');
        } finally {
            foreach ($previous as $key => $values) {
                if ($values['environment'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $values['environment'];
                }
                if ($values['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $values['server'];
                }
                if ($values['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$values['process']);
                }
            }
        }
    }
}
