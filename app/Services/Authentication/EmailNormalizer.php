<?php

declare(strict_types=1);

namespace App\Services\Authentication;

final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
