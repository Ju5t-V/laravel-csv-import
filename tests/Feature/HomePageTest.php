<?php

it('renders the upload page with the drop zone and table', function () {
    $this->withoutVite()->get('/')
        ->assertOk()
        ->assertSee('data-testid="drop-zone"', false)
        ->assertSee('data-testid="uploads-table"', false)
        ->assertSee('name="csrf-token"', false)
        ->assertSee('data-max-kb="'.config('import.max_kb').'"', false);
});
