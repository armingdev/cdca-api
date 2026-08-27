<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('keeps query bindings out of database exception messages', function () {
    $insert = fn () => DB::insert(
        'insert into users (name, email, password, created_at, updated_at) values (?, ?, ?, ?, ?)',
        ['Armin', 'armin@example.com', 'super-secret-password', now(), now()],
    );

    $insert();

    // The second insert violates the unique email index. Its message travels
    // into the logs, so the SQL must still carry placeholders rather than the
    // values bound to them — RGA passwords and session cookies bind the same way.
    expect($insert)->toThrow(function (QueryException $exception) {
        expect($exception->getMessage())
            ->not->toContain('super-secret-password')
            ->toContain('values (?, ?, ?, ?, ?)');
    });
});
