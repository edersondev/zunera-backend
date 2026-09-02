<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Services\Authentication\EmailNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailNormalizerTest extends TestCase
{
    #[Test]
    public function it_trims_and_lowercases_email_addresses(): void
    {
        $normalizer = new EmailNormalizer;

        $this->assertSame('person@example.com', $normalizer->normalize(' Person@Example.COM '));
    }
}
