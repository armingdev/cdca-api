<?php

use App\Game\Exceptions\ParseException;

it('quotes an unexpected HTML page as the text a person would see', function () {
    $page = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<style type=\"text/css\">\n#outerdiv\n{\nwidth:160px;\n}\n</style>"
        ."<script>var a = '<b>';</script></head><body><h1>Server&nbsp;maintenance</h1>\n\n<p>Back   soon.</p></body></html>";

    expect(ParseException::unexpected('Not a questHelper response', $page)->getMessage())
        ->toBe('Not a questHelper response: Server maintenance Back soon.');
});

it('keeps the quoted response short enough for the activity line', function () {
    $message = ParseException::unexpected('Room blob is not valid JSON', str_repeat('garbage ', 100))->getMessage();

    expect(mb_strlen($message))->toBeLessThanOrEqual(160);
});

it('says so when the game answered with nothing at all', function () {
    expect(ParseException::unexpected('Not a mob_talk step page', " \r\n")->getMessage())
        ->toBe('Not a mob_talk step page: (empty response)');
});
