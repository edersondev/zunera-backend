<?php

declare(strict_types=1);

namespace App\Data\Authentication;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        #[\SensitiveParameter]
        public string $password,
        public string $ipAddress,
    ) {}
}
