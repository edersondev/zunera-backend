<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetRequestLocale
{
    private const DEFAULT_LOCALE = 'pt-BR';

    /** @var array<string, string> */
    private const SUPPORTED_LOCALES = [
        'pt-br' => 'pt-BR',
        'en' => 'en',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::DEFAULT_LOCALE;

        foreach ($request->getLanguages() as $language) {
            $normalized = strtolower(str_replace('_', '-', $language));

            if (isset(self::SUPPORTED_LOCALES[$normalized])) {
                $locale = self::SUPPORTED_LOCALES[$normalized];
                break;
            }
        }

        App::setLocale($locale);

        return $next($request);
    }
}
