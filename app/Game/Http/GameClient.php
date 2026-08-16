<?php

namespace App\Game\Http;

use App\Game\Exceptions\SessionCollisionException;
use App\Models\Character;
use App\Models\Rga;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * One throttled HTTP client per character (or per RGA for account-level
 * pages). Composes the shared RGA cookies with the character's own
 * ow_userid/ow_serverid so characters run fully in parallel without
 * re-authenticating. Redirects are never followed automatically — the 302
 * Location is a load-bearing signal (attack success, login target).
 */
class GameClient
{
    public const string BOOT_SENTINEL = 'Rampid Gaming Login';

    /**
     * Dead-session sentinel. Deliberately the bare prefix: the game phrases
     * the rest per endpoint — "…to view this page", "…to use this page"
     * (userstats.php), "…to do that" (world_questHelper.php) — and pinning
     * the full 'view this page' wording silently matched none of them
     * (re-captured 2026-08-16 against a genuinely booted session).
     */
    public const string LOGGED_OUT_SENTINEL = 'You must be logged in';

    /**
     * accounts.php is the odd one out: a dead session gets 200 with this
     * 13-byte body instead of any "logged in" wording.
     */
    public const string NO_ACCOUNT_SENTINEL = 'No account id';

    private function __construct(
        private readonly ?Rga $rga,
        private readonly ?Character $character,
        private readonly string $baseUrl,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character->rga, $character, $character->serverHost());
    }

    public static function forRga(Rga $rga, ?int $serverId = null): self
    {
        $baseUrl = $serverId !== null
            ? config("outwar.servers.{$serverId}.host")
            : config('outwar.login_host');

        return new self($rga, null, $baseUrl);
    }

    /**
     * Cookieless client for public pages (e.g. show_quest.php) — throttled
     * like every other game client, but risks no character session.
     */
    public static function forServer(int $serverId): self
    {
        return new self(null, null, config("outwar.servers.{$serverId}.host"));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        // Only send the query option when non-empty: an empty `query` array
        // makes Guzzle overwrite a query string already present in $path
        // (e.g. a full mob_talk.php?...&finish=1 href).
        return $this->send('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     */
    public function post(string $path, array $data = [], array $query = []): Response
    {
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($data !== []) {
            $options['form_params'] = $data;
        }

        return $this->send('POST', $path, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(string $method, string $path, array $options): Response
    {
        $this->throttle();

        $response = $this->pendingRequest()->send($method, ltrim($path, '/'), $options);

        $this->guard($response);

        return $response;
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withUserAgent(config('outwar.http.user_agent'))
            ->timeout((int) config('outwar.http.timeout'))
            ->connectTimeout((int) config('outwar.http.connect_timeout'))
            ->retry(
                (int) config('outwar.http.retry_times'),
                (int) config('outwar.http.retry_sleep_ms'),
                fn (?Throwable $exception): bool => $exception instanceof ConnectionException,
            )
            ->withOptions([
                'allow_redirects' => false,
                'cookies' => $this->cookieJar(),
            ]);
    }

    private function cookieJar(): CookieJar
    {
        $cookies = $this->rga?->cookies ?? [];

        if ($this->character !== null) {
            $cookies['ow_userid'] = (string) $this->character->suid;
            $cookies['ow_serverid'] = (string) $this->character->server_id;
        }

        return CookieJar::fromArray($cookies, '.outwar.com');
    }

    /**
     * Sleep so consecutive requests for the same character keep a jittered
     * minimum gap — the per-character politeness throttle. Serialized by an
     * atomic lock (held through the sleep) so concurrent workers on the same
     * key cannot race the read-then-write and burst; if the lock cannot be
     * acquired in time, fall through with a plain full-gap sleep.
     */
    private function throttle(): void
    {
        $gapMs = random_int((int) config('outwar.http.throttle_min_ms'), (int) config('outwar.http.throttle_max_ms'));

        if ($gapMs <= 0) {
            return;
        }

        $key = 'outwar:last_request:'.match (true) {
            $this->character !== null => 'char:'.$this->character->id,
            $this->rga !== null => 'rga:'.$this->rga->id,
            default => 'anon:'.$this->baseUrl,
        };

        try {
            Cache::lock("{$key}:lock", 10)->block(15, function () use ($key, $gapMs): void {
                $last = Cache::get($key);

                if ($last !== null) {
                    $elapsedMs = (microtime(true) - (float) $last) * 1000;

                    if ($elapsedMs < $gapMs) {
                        Sleep::usleep((int) (($gapMs - $elapsedMs) * 1000));
                    }
                }

                Cache::put($key, microtime(true), 300);
            });
        } catch (LockTimeoutException) {
            Sleep::usleep($gapMs * 1000);
            Cache::put($key, microtime(true), 300);
        }
    }

    /**
     * Every response is checked for the session-dead sentinels: the login
     * page mid-session (someone logged in elsewhere) or the ajax logged-out
     * error box (expired session).
     */
    private function guard(Response $response): void
    {
        if ($this->rga === null) {
            return;
        }

        $body = $response->body();

        $booted = str_contains($body, self::BOOT_SENTINEL)
            || str_contains($body, self::LOGGED_OUT_SENTINEL)
            || trim($body) === self::NO_ACCOUNT_SENTINEL
            // Full pages (e.g. Navigator's /world reset hatch) bounce to the
            // login screen rather than rendering a sentinel.
            || $this->isLoginRedirect($response);

        if ($booted) {
            $this->rga->update(['status' => Rga::STATUS_INVALID]);

            throw SessionCollisionException::booted();
        }
    }

    /**
     * A 3xx whose Location points at the login page. Note a dead session also
     * makes some ajax endpoints answer 500 with an empty body — deliberately
     * NOT treated as booted, because a genuine server blip is indistinguishable
     * and invalidating on it would trigger pointless re-login storms.
     */
    private function isLoginRedirect(Response $response): bool
    {
        if ($response->status() < 300 || $response->status() >= 400) {
            return false;
        }

        return str_contains($response->header('Location'), '/login');
    }
}
