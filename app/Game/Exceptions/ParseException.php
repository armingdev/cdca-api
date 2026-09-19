<?php

namespace App\Game\Exceptions;

use Illuminate\Support\Str;

class ParseException extends GameException
{
    /**
     * A response that is not the page or payload a parser expected, quoted
     * readably. The message travels into last_activity and the run log, and
     * the game answers some failures with a whole HTML page — style block
     * first — so the raw body is reduced to the text a person would see.
     */
    public static function unexpected(string $expectation, string $body): self
    {
        $text = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        $text = Str::squish(html_entity_decode(strip_tags($text)));

        return new self($expectation.': '.($text === '' ? '(empty response)' : Str::limit($text, 120)));
    }
}
