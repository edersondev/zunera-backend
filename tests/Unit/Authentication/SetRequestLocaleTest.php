<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Http\Middleware\SetRequestLocale;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SetRequestLocaleTest extends TestCase
{
    /**
     * The Laravel test client always sends Symfony's implicit
     * "en-us,en;q=0.5" header, so the absent-header case is exercised directly.
     */
    #[Test]
    public function it_defaults_to_brazilian_portuguese_without_an_accept_language_header(): void
    {
        $request = Request::create('/api/v1/auth/session', 'GET');
        $request->headers->remove('Accept-Language');

        app(SetRequestLocale::class)->handle($request, fn () => response(''));

        $this->assertSame('pt-BR', app()->getLocale());
    }

    #[Test]
    public function it_honours_supported_requested_languages(): void
    {
        $request = Request::create('/api/v1/auth/session', 'GET');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');

        app(SetRequestLocale::class)->handle($request, fn () => response(''));

        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function it_defaults_unsupported_languages_to_brazilian_portuguese(): void
    {
        $request = Request::create('/api/v1/auth/session', 'GET');
        $request->headers->set('Accept-Language', 'fr-FR,fr;q=0.9');

        app(SetRequestLocale::class)->handle($request, fn () => response(''));

        $this->assertSame('pt-BR', app()->getLocale());
    }

    #[Test]
    public function it_defaults_an_empty_header_to_brazilian_portuguese(): void
    {
        $request = Request::create('/api/v1/auth/session', 'GET');
        $request->headers->set('Accept-Language', '   ');

        app(SetRequestLocale::class)->handle($request, fn () => response(''));

        $this->assertSame('pt-BR', app()->getLocale());
    }
}
