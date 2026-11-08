<?php

namespace App\Game\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The game — or Cloudflare in front of it — answered with a 5xx.
 *
 * Deliberately NOT a GameException. A dozen engine sites catch GameException
 * to mean "this room, mob or quest is a dead end" and carry on: an outage read
 * that way marks rooms exhausted and skips quests the character could finish.
 * Like a ConnectionException, this passes straight through them to the run
 * job, which parks the participant and retries (see TransientFailure).
 *
 * Not every 5xx is an outage, though. The game answers some refusals with a
 * 500 whose whole body is its own error alert — attacking a mob that is
 * already gone gets `showErrorAlert('Invalid mob!')` (seen 150 times in prod
 * failures up to 2026-11-07). gameAlert() exposes that text, so a caller that
 * expects the refusal can treat it as one.
 */
class GameServerException extends RuntimeException
{
    public function __construct(
        public readonly Response $response,
        public readonly string $path,
    ) {
        parent::__construct("Game server answered HTTP {$response->status()} for {$path}: {$this->summary()}");
    }

    /**
     * The game's own `showErrorAlert('…')` text, when the body carries one:
     * a deliberate answer rather than a server falling over.
     */
    public function gameAlert(): ?string
    {
        return preg_match('/showErrorAlert\(\s*([\'"])(.*?)\1\s*\)/s', $this->response->body(), $matches) === 1
            ? $matches[2]
            : null;
    }

    /**
     * @return array{status: int, path: string}
     */
    public function context(): array
    {
        return ['status' => $this->response->status(), 'path' => $this->path];
    }

    /**
     * What a person would read on the error page. Cloudflare serves whole HTML
     * pages (style block first) and the game a bare script, so neither is
     * fit for last_activity as it stands.
     */
    private function summary(): string
    {
        $alert = $this->gameAlert();

        if ($alert !== null) {
            return $alert;
        }

        $body = $this->response->body();
        $text = preg_replace(['#<(style|script)\b[^>]*>.*?</\1>#is', '#<[^>]*>#'], ' ', $body) ?? $body;
        $text = Str::squish(html_entity_decode($text));

        return $text === '' ? '(empty response)' : Str::limit($text, 120);
    }
}
