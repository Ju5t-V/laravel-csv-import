<?php

use App\Models\Product;
use App\Models\Upload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RefreshMemoryDatabase;

uses(RefreshMemoryDatabase::class);

afterEach(function () {
    File::delete([
        storage_path('app/samples/products-40.csv'),
        storage_path('app/samples/products-250-dirty.csv'),
    ]);
});

it('streams a clean sample CSV with the requested number of rows', function () {
    $this->artisan('products:generate-csv', ['rows' => 40])->assertSuccessful();

    $lines = file(storage_path('app/samples/products-40.csv'), FILE_IGNORE_NEW_LINES);
    expect($lines)->toHaveCount(41)
        ->and($lines[0])->toBe('UNIQUE_KEY,PRODUCT_TITLE,PRODUCT_DESCRIPTION,STYLE#,SANMAR_MAINFRAME_COLOR,SIZE,COLOR_NAME,PIECE_PRICE');
});

it('rejects a row count below one', function () {
    $this->artisan('products:generate-csv', ['rows' => 0])->assertFailed();
});

it('writes a dirty file with a BOM, raw CP1252 bytes, blank and duplicate keys and control characters', function () {
    $this->artisan('products:generate-csv', ['rows' => 250, '--dirty' => true])->assertSuccessful();

    $bytes = (string) file_get_contents(storage_path('app/samples/products-250-dirty.csv'));
    expect(str_starts_with($bytes, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(mb_check_encoding($bytes, 'UTF-8'))->toBeFalse()
        ->and($bytes)->toContain("\xAE")
        ->and($bytes)->toContain("\x99")
        ->and($bytes)->toContain("\xE9")
        ->and($bytes)->toContain('"$1,')
        ->and($bytes)->toContain("\x01")
        ->and($bytes)->toContain("\x07")
        ->and($bytes)->toMatch('/^,/m');
    $keys = array_column(array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_slice(explode("\n", trim($bytes)), 1)), 0);
    expect(count($keys) - count(array_unique($keys)))->toBeGreaterThan(0);
});

it('pads descriptions so a file can reach a target size', function () {
    $this->artisan('products:generate-csv', ['rows' => 40, '--pad' => 1000])->assertSuccessful();

    expect(filesize(storage_path('app/samples/products-40.csv')))->toBeGreaterThan(40 * 1000);
});

it('imports a dirty file twice with second-run inserted 0', function () {
    Storage::fake('local');
    $this->artisan('products:generate-csv', ['rows' => 250, '--dirty' => true])->assertSuccessful();

    $this->artisan('imports:demo', ['file' => storage_path('app/samples/products-250-dirty.csv')])
        ->assertSuccessful();

    [$first, $second] = Upload::query()->orderBy('id')->get()->all();
    expect($first->inserted_rows)->toBeGreaterThan(0)
        ->and($first->skipped_rows)->toBeGreaterThan(0)
        ->and($second->inserted_rows)->toBe(0)
        ->and($second->updated_rows)->toBe($first->inserted_rows + $first->updated_rows)
        ->and($second->skipped_rows)->toBe($first->skipped_rows)
        ->and(Product::query()->count())->toBe($first->inserted_rows);
});
