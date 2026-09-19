<?php

namespace App\Game\Http;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * The one cURL handler every game request in this process goes through.
 *
 * Laravel builds a fresh Guzzle client — and with it a fresh handler — for
 * every pending request, so without this each game request opened a new TCP
 * connection and redid the TLS handshake (measured ~55ms per request against
 * sigil). A run makes thousands of requests; holding one handler for the life
 * of the worker lets them ride a kept-alive connection instead.
 *
 * Only transport state is shared (DNS cache, TLS sessions, connections).
 * Cookies never live in cURL here — Guzzle's cookie middleware writes them as
 * a header per request — so characters sharing a handler cannot see each
 * other's session. Bound as a singleton in AppServiceProvider.
 */
class GameTransport
{
    /** @var (callable(RequestInterface, array<string, mixed>): PromiseInterface)|null */
    private $handler = null;

    /**
     * The shared handler, or null to let Laravel build a throwaway one per
     * request (the pre-sharing behaviour, kept as an off switch).
     *
     * @return (callable(RequestInterface, array<string, mixed>): PromiseInterface)|null
     */
    public function handler(): ?callable
    {
        if (! config('outwar.http.reuse_connections')) {
            return null;
        }

        // PERSISTENT_PREFER also keeps the pool across PHP-FPM requests where
        // the runtime supports it, and quietly falls back where it does not.
        return $this->handler ??= Utils::chooseHandler([
            'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
        ]);
    }
}
