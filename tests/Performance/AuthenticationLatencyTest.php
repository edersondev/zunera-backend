<?php

declare(strict_types=1);

namespace Tests\Performance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticationLatencyTest extends TestCase
{
    #[Test]
    public function release_profile_fixture_defines_one_hundred_measured_actions(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/Fixtures/auth-release-profile.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(10, $fixture['warmups']);
        $this->assertSame(5, $fixture['concurrency']);
        $this->assertSame(100, array_sum($fixture['measured_mix']));
        $this->assertSame('client_monotonic_complete_response_body', $fixture['timing']);
    }
}
