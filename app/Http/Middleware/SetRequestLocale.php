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
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    /**
     * Missing and unsupported values default to PT-BR. The header is inspected
     * directly because Symfony fabricates an implicit "en" language list when
     * the request carries no Accept-Language header.
     */
    private function resolveLocale(Request $request): string
    {
        $header = $request->headers->get('Accept-Language');

        if ($header === null || trim($header) === '') {
            return self::DEFAULT_LOCALE;
        }

        foreach ($request->getLanguages() as $language) {
            $normalized = strtolower(str_replace('_', '-', $language));

            if (isset(self::SUPPORTED_LOCALES[$normalized])) {
                return self::SUPPORTED_LOCALES[$normalized];
            }
        }

        return self::DEFAULT_LOCALE;
    }
}
