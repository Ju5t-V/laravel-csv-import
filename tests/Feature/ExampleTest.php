<?php

it('serves the home page', function () {
    $this->withoutVite()->get('/')->assertOk();
});

it('exposes the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});
