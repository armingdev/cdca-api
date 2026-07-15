<?php

namespace App\Game\Http;

use App\Game\Exceptions\SessionCollisionException;
use App\Models\Character;
use App\Models\Rga;
use GuzzleHttp\Cookie\CookieJar;
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
    private const string BOOT_SENTINEL = 'Rampid Gaming Login';

    private function __construct(
        private readonly Rga $rga,
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
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     */
    public function post(string $path, array $data = [], array $query = []): Response
    {
        return $this->send('POST', $path, ['query' => $query, 'form_params' => $data]);
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
        $cookies = $this->rga->cookies ?? [];

        if ($this->character !== null) {
            $cookies['ow_userid'] = (string) $this->character->suid;
            $cookies['ow_serverid'] = (string) $this->character->server_id;
        }

        return CookieJar::fromArray($cookies, '.outwar.com');
    }

    /**
     * Sleep so consecutive requests for the same character keep a jittered
     * minimum gap — the per-character politeness throttle.
     */
    private function throttle(): void
    {
        $key = 'outwar:last_request:'.($this->character !== null ? 'char:'.$this->character->id : 'rga:'.$this->rga->id);
        $gapMs = random_int((int) config('outwar.http.throttle_min_ms'), (int) config('outwar.http.throttle_max_ms'));

        $last = Cache::get($key);

        if ($last !== null) {
            $elapsedMs = (microtime(true) - (float) $last) * 1000;

            if ($elapsedMs < $gapMs) {
                Sleep::usleep((int) (($gapMs - $elapsedMs) * 1000));
            }
        }

        Cache::put($key, microtime(true), 300);
    }

    /**
     * Every response is checked for the boot sentinel: the game returning its
     * login page mid-session means someone logged in elsewhere.
     */
    private function guard(Response $response): void
    {
        if (str_contains($response->body(), self::BOOT_SENTINEL)) {
            $this->rga->update(['status' => Rga::STATUS_INVALID]);

            throw SessionCollisionException::booted();
        }
    }
}
