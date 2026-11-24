<?php

use App\Support\Utf8Sanitizer;

it('cleans values', function (?string $input, ?string $expected) {
    expect(Utf8Sanitizer::clean($input))->toBe($expected);
})->with([
    'null' => [null, null],
    'plain' => ['hello', 'hello'],
    'bom' => ["\xEF\xBB\xBFhello", 'hello'],
    'control chars' => ["a\x00b\x07c\x1Fd\x7Fe", 'abcde'],
    'tab and CR kept' => ["a\tb\rc", "a\tb\rc"],
    'newlines kept' => ["line1\nline2", "line1\nline2"],
    'nbsp' => ["a\u{00A0}b", 'a b'],
    'zwsp and zwnj' => ["a\u{200B}b\u{200C}c", 'abc'],
    'zwj emoji kept' => ["\u{1F468}\u{200D}\u{1F469}", "\u{1F468}\u{200D}\u{1F469}"],
    'stray zwj stripped' => ["\u{200D}ab\u{200D}", 'ab'],
    'zwj next to space stripped' => ["a \u{200D}b", 'a b'],
    'fffd' => ["a\u{FFFD}b", 'ab'],
    'nfc' => ["e\u{0301}", "\u{00E9}"],
    'trim' => ["  x \n", 'x'],
]);

it('converts a CP1252 registered sign to UTF-8 by byte value', function () {
    $out = Utf8Sanitizer::clean("Brand\xAE");

    expect(bin2hex((string) $out))->toBe(bin2hex("Brand\xC2\xAE"))
        ->and(mb_check_encoding((string) $out, 'UTF-8'))->toBeTrue();
});

it('normalizes headers', function (string $input, string $expected) {
    expect(Utf8Sanitizer::normalizeHeader($input))->toBe($expected);
})->with([
    'bom' => ["\xEF\xBB\xBFunique_key", 'UNIQUE_KEY'],
    'zero width' => ["style\u{200B}#", 'STYLE#'],
    'trim' => ['  Size ', 'SIZE'],
]);
