<?php

use Illuminate\Support\Facades\Gate;

it('denies the Horizon dashboard outside the local environment', function () {
    app()['env'] = 'production';

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('allows the Horizon dashboard in the local environment', function () {
    app()['env'] = 'local';

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeTrue();
});
