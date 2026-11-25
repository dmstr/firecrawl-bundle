<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:21:25 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Logs in to a site with a plain HTML form (the Yii2 form login with a CSRF
 * token, or the Keycloak login page the site redirects to) and returns the
 * session as a `Cookie` header value for Firecrawl.
 * No browser: it does what the browser does, a GET of the form, a POST with
 * the credentials and the hidden `_csrf` field, and it keeps the cookies.
 *
 * Firecrawl never sees the password, only the cookies. A login that needs
 * JavaScript, MFA or a CAPTCHA is out of reach for this class.
 */
final class FormLogin
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @throws \RuntimeException when the profile has no usable `auth` block, a credential is missing or the login fails
     */
    public function login(CrawlProfile $profile): string
    {
        $auth = $profile->auth;
        if (null === $auth || !\in_array($auth['type'] ?? null, ['yii2-form', 'keycloak'], true)) {
            throw new \RuntimeException(\sprintf('Profile "%s": auth.type "%s" is not supported (yii2-form or keycloak).', $profile->name, $auth['type'] ?? 'none'));
        }

        $user = (string) ($auth['username'] ?? '');
        $password = (string) ($auth['password'] ?? '');
        $loginUrl = (string) ($auth['login_url'] ?? '');
        if ('' === $user || '' === $password || '' === $loginUrl) {
            throw new \RuntimeException(\sprintf('Profile "%s": set the login URL, the username and the password of the profile.', $profile->name));
        }
        if ('keycloak' === $auth['type']) {
            return $this->keycloak($loginUrl, $user, $password);
        }

        $cookies = [];
        $form = $this->httpClient->request('GET', $loginUrl, ['max_redirects' => 0]);
        $html = $form->getContent(false);
        $this->collect($form, $cookies);

        $page = new Crawler($html);
        $csrf = $page->filter('input[name="_csrf"]');
        if (0 === $csrf->count()) {
            throw new \RuntimeException('Login form has no _csrf field; is login_url the form page?');
        }
        $userField = self::fieldName($page, (string) ($auth['username_field'] ?? 'LoginForm[login]'));
        $passwordField = self::fieldName($page, (string) ($auth['password_field'] ?? 'LoginForm[password]'));

        $post = $this->httpClient->request('POST', $loginUrl, [
            'max_redirects' => 0,
            'headers' => ['Cookie' => self::header($cookies)],
            'body' => [
                '_csrf' => (string) $csrf->attr('value'),
                $userField => $user,
                $passwordField => $password,
            ],
        ]);
        $status = $post->getStatusCode();
        $this->collect($post, $cookies);
        $location = $post->getHeaders(false)['location'][0] ?? '';

        // A successful login redirects away from the form; a failed one shows the form again (200).
        if ($status < 300 || $status >= 400 || str_contains($location, 'login')) {
            throw new \RuntimeException(\sprintf('Login as "%s" failed (HTTP %d%s).', $user, $status, '' !== $location ? ', redirect to '.$location : ''));
        }

        return self::header($cookies);
    }

    /**
     * Keycloak (OpenID Connect): the login URL of the site redirects to the
     * Keycloak login page, its form is posted with `username` and `password`,
     * and Keycloak redirects back with a code the site exchanges for its own
     * session. Cookies are kept per host, so only the site's are returned.
     */
    private function keycloak(string $loginUrl, string $user, string $password): string
    {
        $jar = [];
        [$url, $html] = $this->follow('GET', $loginUrl, [], $jar);
        $form = (new Crawler($html, $url))->filter('form#kc-form-login');
        if (0 === $form->count()) {
            throw new \RuntimeException(\sprintf('No Keycloak login form at %s; is login_url the URL that starts the Keycloak login?', $url));
        }

        [$url, $html] = $this->follow('POST', self::resolve($url, (string) $form->attr('action')), ['body' => ['username' => $user, 'password' => $password, 'credentialId' => '']], $jar);
        $site = (string) parse_url($loginUrl, \PHP_URL_HOST);
        // A failed login shows the Keycloak form again.
        if (str_contains($html, 'kc-form-login') || $site !== parse_url($url, \PHP_URL_HOST) || [] === ($jar[$site] ?? [])) {
            throw new \RuntimeException(\sprintf('Keycloak login as "%s" failed (ended at %s).', $user, $url));
        }

        return self::header($jar[$site]);
    }

    /**
     * Sends the request and follows its redirects by hand, so each host gets
     * only its own cookies. Returns the final URL and body.
     *
     * @param array<string, mixed>                 $options
     * @param array<string, array<string, string>> $jar     cookies per host
     *
     * @return array{string, string}
     */
    private function follow(string $method, string $url, array $options, array &$jar): array
    {
        for ($hop = 0; $hop < 10; ++$hop) {
            $host = (string) parse_url($url, \PHP_URL_HOST);
            $jar[$host] ??= [];
            $headers = [] !== $jar[$host] ? ['Cookie' => self::header($jar[$host])] : [];
            $response = $this->httpClient->request($method, $url, $options + ['max_redirects' => 0, 'headers' => $headers]);
            $status = $response->getStatusCode();
            $this->collect($response, $jar[$host]);
            $location = $response->getHeaders(false)['location'][0] ?? null;
            if ($status < 300 || $status >= 400 || null === $location) {
                return [$url, $response->getContent(false)];
            }
            $url = self::resolve($url, $location);
            [$method, $options] = ['GET', []];
        }

        throw new \RuntimeException(\sprintf('Login: more than 10 redirects, last to %s.', $url));
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.rtrim(\dirname($p['path'] ?? '/').'/', '/').'/'.$location;
    }

    /**
     * The name a form field is posted under: $field is that name itself, or a
     * CSS selector of the input (e.g. `#login-form input[type=email]`).
     */
    private static function fieldName(Crawler $page, string $field): string
    {
        if ($page->filterXPath(\sprintf('//*[@name=%s]', self::xpathLiteral($field)))->count() > 0) {
            return $field;
        }
        try {
            $name = $page->filter($field)->first()->attr('name');
        } catch (\Exception) {
            $name = null;
        }
        if (null === $name || '' === $name) {
            throw new \RuntimeException(\sprintf('Login form has no field "%s" (a name or a CSS selector of an input with a name).', $field));
        }

        return $name;
    }

    private static function xpathLiteral(string $value): string
    {
        return str_contains($value, '"') ? "'".$value."'" : '"'.$value.'"';
    }

    /**
     * @param array<string, string> $cookies
     */
    private function collect(ResponseInterface $response, array &$cookies): void
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $line) {
            $pair = explode(';', $line, 2)[0];
            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $cookies[trim($name)] = trim($value);
            }
        }
    }

    /**
     * @param array<string, string> $cookies
     */
    private static function header(array $cookies): string
    {
        return implode('; ', array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($cookies), $cookies));
    }
}

// - revised 2026-11-18
// - revised 2026-11-23
