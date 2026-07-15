<?php

namespace App\Game\Auth;

use App\Game\Exceptions\LoginFailedException;
use App\Models\Rga;
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
        $response = Http::asForm()
            ->withUserAgent(config('outwar.http.user_agent'))
            ->timeout((int) config('outwar.http.timeout'))
            ->withOptions(['allow_redirects' => false])
            ->post(config('outwar.login_host').'/index.php', [
                'serverid' => 1,
                'login_username' => $rga->username,
                'login_password' => $rga->password,
                'submitit' => '',
            ]);

        if ($response->status() !== 302) {
            throw LoginFailedException::unexpectedStatus($response->status());
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
     * @return array<string, string>
     */
    private function extractSessionCookies(Response $response): array
    {
        $cookies = [];

        foreach ($response->headers()['Set-Cookie'] ?? [] as $header) {
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
