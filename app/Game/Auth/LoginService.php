<?php

namespace App\Game\Auth;

use App\Game\Exceptions\LoginFailedException;
use App\Game\Exceptions\ParseException;
use App\Game\Http\GameClient;
use App\Game\Parsers\TrusteeListParser;
use App\Models\Rga;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Performs the one-time RGA credential login and captures the account-level
 * session cookies. Characters then reuse these cookies with their own ow_*
 * pair (see GameClient) — no per-character re-auth.
 */
class LoginService
{
    /**
     * The account-level cookies minted by a successful login.
     */
    private const array SESSION_COOKIES = ['rg_sess_id', 'token', 'cuserid2', 'owip'];

    public function login(Rga $rga): Rga
    {
        $loginHost = config('outwar.login_host');

        $response = Http::asForm()
            ->withUserAgent(config('outwar.http.user_agent'))
            ->timeout((int) config('outwar.http.timeout'))
            ->withOptions(['allow_redirects' => false])
            ->post($loginHost.'/index.php', [
                'serverid' => 1,
                'login_username' => $rga->username,
                'login_password' => $rga->password,
                'submitit' => '',
            ]);

        $location = $response->header('Location');

        if ($response->status() === 301) {
            throw LoginFailedException::hostMoved($loginHost, $location);
        }

        if ($response->status() !== 302) {
            throw LoginFailedException::unexpectedStatus($response->status());
        }

        // A rejected login also 302s, but back to the login page with LE=1.
        if (str_contains($location, 'LE=1')) {
            throw LoginFailedException::badCredentials();
        }

        $cookies = $this->extractSessionCookies($response);

        if (empty($cookies['rg_sess_id'])) {
            throw LoginFailedException::missingSessionCookie();
        }

        $rga->update([
            'cookies' => $cookies,
            'status' => Rga::STATUS_ACTIVE,
            'last_login_at' => now(),
        ]);

        return $rga->refresh();
    }

    /**
     * Adopt a session pasted from the user's browser instead of minting a new
     * one — a fresh credential login would boot the browser session (and vice
     * versa), while sharing the browser's rg_sess_id keeps both alive. The
     * candidate cookies are verified against the game before anything is
     * persisted; a rejected paste leaves the RGA completely untouched.
     *
     * Deliberately probes with a direct Http call rather than GameClient:
     * GameClient reads the *stored* cookies and its guard() would mark the RGA
     * invalid on a dead probe. Also note we cannot cheaply verify the pasted
     * session belongs to *this* RGA's account — a wrong-account paste attaches
     * a working-but-wrong session (character sync would surface it).
     */
    public function attachSession(Rga $rga, string $rgSessId, ?string $token = null, ?string $cuserid2 = null): Rga
    {
        $cookies = array_filter([
            'rg_sess_id' => $rgSessId,
            'token' => $token,
            'cuserid2' => $cuserid2,
        ]);

        $this->verifySession($cookies);

        $rga->update([
            'cookies' => $cookies,
            'status' => Rga::STATUS_ACTIVE,
            'last_login_at' => now(),
        ]);

        return $rga->refresh();
    }

    /**
     * @param  array<string, string>  $cookies
     *
     * @throws LoginFailedException
     */
    private function verifySession(array $cookies): void
    {
        $response = Http::withUserAgent(config('outwar.http.user_agent'))
            ->timeout((int) config('outwar.http.timeout'))
            ->withOptions([
                'allow_redirects' => false,
                'cookies' => CookieJar::fromArray($cookies, '.outwar.com'),
            ])
            ->get(config('outwar.servers.1.host').'/ajax/trusteeList.php', ['dropdown' => 1]);

        $body = $response->body();

        if ($response->status() !== 200
            || str_contains($body, GameClient::BOOT_SENTINEL)
            || str_contains($body, GameClient::LOGGED_OUT_SENTINEL)) {
            throw LoginFailedException::sessionRejected();
        }

        try {
            (new TrusteeListParser)->parse($body);
        } catch (ParseException) {
            throw LoginFailedException::sessionRejected();
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractSessionCookies(Response $response): array
    {
        $cookies = [];

        // Case-insensitive lookup — HTTP/2 lowercases header names (set-cookie).
        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $header) {
            $pair = explode('=', explode(';', $header, 2)[0], 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$name, $value] = $pair;

            if (in_array($name, self::SESSION_COOKIES, true) && $value !== '' && $value !== 'deleted') {
                $cookies[$name] = urldecode($value);
            }
        }

        return $cookies;
    }
}
