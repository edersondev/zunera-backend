<?php

declare(strict_types=1);

namespace App\Services\FinancialAccounts;

final class FinancialAccountNameNormalizer
{
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $name = (string) preg_replace('/\p{M}+/u', '', $decomposed);
            }
        }

        return mb_strtolower($name, 'UTF-8');
    }
}
