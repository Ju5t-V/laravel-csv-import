<?php

it('exposes the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});
