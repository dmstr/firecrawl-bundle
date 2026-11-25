<?php

// file generated with AI assistance: Claude Code - 2026-11-06 09:10:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\Firecrawl\Crawl\CrawlProfile;
use Dmstr\Firecrawl\Crawl\FormLogin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The form login: the username and password fields are given by their name
 * or by a CSS selector of the input, and are posted under the input's name.
 */
final class FormLoginTest extends TestCase
{
    private const string FORM = '<form id="login-form"><input type="hidden" name="_csrf" value="t0ken">'
        .'<input type="email" name="LoginForm[login]"><input type="password" name="LoginForm[password]"></form>';

    /** @return iterable<string, array{string, string}> */
    public static function fields(): iterable
    {
        yield 'names' => ['LoginForm[login]', 'LoginForm[password]'];
        yield 'css selectors' => ['#login-form input[type=email]', 'input[type=password]'];
    }

    #[DataProvider('fields')]
    public function testPostsTheCredentialsUnderTheNamesOfTheFields(string $userField, string $passwordField): void
    {
        $posted = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$posted): MockResponse {
            if ('GET' === $method) {
                return new MockResponse(self::FORM, ['response_headers' => ['set-cookie' => 'session=a; path=/']]);
            }
            $posted = $options['body'];

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/home', 'set-cookie' => 'session=b; path=/']]);
        });

        $cookie = (new FormLogin($client))->login(self::profile($userField, $passwordField));

        self::assertSame('session=b', $cookie);
        self::assertSame(http_build_query(['_csrf' => 't0ken', 'LoginForm[login]' => 'bot', 'LoginForm[password]' => 'geheim']), $posted);
    }

    public function testAFieldThatIsNotInTheFormIsReported(): void
    {
        $client = new MockHttpClient([new MockResponse(self::FORM)]);

        $this->expectExceptionMessage('Login form has no field "input[name=user]"');
        (new FormLogin($client))->login(self::profile('input[name=user]', 'LoginForm[password]'));
    }

    public function testKeycloakFollowsTheRedirectsAndReturnsOnlyTheCookiesOfTheSite(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $cookie = implode('', array_filter($options['headers'] ?? [], static fn (string $h): bool => str_starts_with($h, 'Cookie:')));
            $requests[] = $method.' '.$url.' '.$cookie;

            return match (true) {
                str_starts_with($url, 'https://site.example/auth') => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '//kc.example/realms/r/auth?client_id=site', 'set-cookie' => 'PHPSESSID=pre; path=/']]),
                'GET' === $method && str_starts_with($url, 'https://kc.example/') => new MockResponse('<form id="kc-form-login" action="/login-actions/authenticate?session_code=x&amp;tab_id=y"><input name="username"><input name="password"></form>', ['response_headers' => ['set-cookie' => 'AUTH_SESSION_ID=kc1; path=/']]),
                'POST' === $method => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://site.example/callback?code=c', 'set-cookie' => 'KEYCLOAK_SESSION=kc2; path=/']]),
                str_starts_with($url, 'https://site.example/callback') => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/de', 'set-cookie' => 'PHPSESSID=post; path=/']]),
                default => new MockResponse('<h1>Intranet</h1>'),
            };
        });

        $cookie = (new FormLogin($client))->login(self::keycloakProfile());

        self::assertSame('PHPSESSID=post', $cookie);
        self::assertSame('POST https://kc.example/login-actions/authenticate?session_code=x&tab_id=y Cookie: AUTH_SESSION_ID=kc1', $requests[2]);
        self::assertSame('GET https://site.example/callback?code=c Cookie: PHPSESSID=pre', $requests[3]);
        self::assertSame('GET https://site.example/de Cookie: PHPSESSID=post', $requests[4]);
    }

    public function testAKeycloakLoginThatShowsTheFormAgainFails(): void
    {
        $form = '<form id="kc-form-login" action="https://kc.example/login-actions/authenticate"></form>';
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_starts_with($url, 'https://site.example/')
            ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://kc.example/realms/r/auth']])
            : new MockResponse($form));

        $this->expectExceptionMessage('Keycloak login as "bot" failed');
        (new FormLogin($client))->login(self::keycloakProfile());
    }

    private static function keycloakProfile(): CrawlProfile
    {
        return CrawlProfile::fromArray('site', [
            'discover' => ['seeds' => ['https://site.example/'], 'allowed_domains' => ['site.example']],
            'auth' => ['type' => 'keycloak', 'login_url' => 'https://site.example/auth?authclient=keycloak', 'username' => 'bot', 'password' => 'geheim'],
        ]);
    }

    private static function profile(string $userField, string $passwordField): CrawlProfile
    {
        return CrawlProfile::fromArray('site', [
            'discover' => ['seeds' => ['https://site.example/'], 'allowed_domains' => ['site.example']],
            'auth' => ['type' => 'yii2-form', 'login_url' => 'https://site.example/login', 'username_field' => $userField, 'password_field' => $passwordField, 'username' => 'bot', 'password' => 'geheim'],
        ]);
    }
}

// - revised 2026-11-23
