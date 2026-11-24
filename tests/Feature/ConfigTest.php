<?php

it('runs on a PHP version that honors the SQLite transaction_mode', function () {
    expect(version_compare(PHP_VERSION, '8.4.0', '>='))->toBeTrue();
})->skip(fn () => version_compare(PHP_VERSION, '8.4.0', '<'), 'transaction_mode needs PHP 8.4+');

it('configures SQLite for WAL with an IMMEDIATE transaction mode', function () {
    expect(config('database.connections.sqlite'))
        ->journal_mode->toBe('WAL')
        ->synchronous->toBe('NORMAL')
        ->busy_timeout->toBe(5000)
        ->transaction_mode->toBe('IMMEDIATE');
});

it('keeps the redis retry_after above the job timeout', function () {
    expect(config('queue.connections.redis.retry_after'))->toBe(660);
});
