<?php

use App\Support\PriceParser;

it('parses prices', function (?string $input, ?string $expected) {
    expect(PriceParser::parse($input))->toBe($expected);
})->with([
    '12.5' => ['12.5', '12.50'],
    'dollar' => ['$12.50', '12.50'],
    'thousands' => ['1,234.00', '1234.00'],
    'spaces' => [' 12.50 ', '12.50'],
    'integer' => ['7', '7.00'],
    'letters' => ['abc', null],
    'empty' => ['', null],
    'null' => [null, null],
]);
