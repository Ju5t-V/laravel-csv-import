<?php

use App\Enums\UploadStatus;
use App\Events\UploadUpdated;
use App\Http\Resources\UploadResource;
use App\Jobs\ProcessCsvUpload;
use App\Models\Product;
use App\Models\Upload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait RefreshCsvImportDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
        ]);
    }
}

uses(RefreshCsvImportDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['broadcasting.default' => 'null', 'import.chunk_size' => 1000]);
});

function makeCsvUpload(string $fixture, ?string $contents = null): Upload
{
    $contents ??= file_get_contents(__DIR__.'/../Fixtures/'.$fixture);
    if ($contents === false) {
        throw new RuntimeException("Unable to read the {$fixture} fixture");
    }

    $path = 'uploads/'.Str::ulid().'.csv';
    Storage::disk('local')->put($path, $contents);

    return Upload::query()->create([
        'original_name' => $fixture,
        'stored_path' => $path,
        'sha256' => hash('sha256', $contents),
        'size_bytes' => strlen($contents),
        'status' => UploadStatus::Pending,
    ]);
}

/** @return array<string, int> */
function csvImportTotals(Upload $upload): array
{
    return $upload->only(['processed_rows', 'inserted_rows', 'updated_rows', 'skipped_rows']);
}

it('imports every clean row and maps normalized headers by name', function () {
    $upload = makeCsvUpload('clean.csv');

    (new ProcessCsvUpload($upload))->handle();

    $upload->refresh();
    expect($upload)
        ->status->toBe(UploadStatus::Completed)
        ->progress->toBe(100)
        ->processed_offset->toBe(3)
        ->started_at->not->toBeNull()
        ->finished_at->not->toBeNull();
    expect(csvImportTotals($upload))->toBe([
        'processed_rows' => 3,
        'inserted_rows' => 3,
        'updated_rows' => 0,
        'skipped_rows' => 0,
    ]);
    expect(Product::query()->count())->toBe(3)
        ->and($upload->rowErrors()->count())->toBe(0);
    $product = Product::query()->where('unique_key', 'A')->sole();
    expect($product)
        ->product_title->toBe('Alpha')
        ->product_description->toBe('<b>Soft cotton</b>')
        ->style_number->toBe('S1')
        ->sanmar_mainframe_color->toBe('NAVY')
        ->size->toBe('M')
        ->color_name->toBe('Navy')
        ->last_upload_id->toBe($upload->id);
    expect((float) $product->piece_price)->toBe(1234.0);
});

it('counts every existing product as updated on re-upload', function () {
    config(['import.chunk_size' => 3]);
    (new ProcessCsvUpload(makeCsvUpload('four-records.csv')))->handle();
    $reupload = makeCsvUpload('four-records.csv');

    (new ProcessCsvUpload($reupload))->handle();

    expect(csvImportTotals($reupload->refresh()))->toBe([
        'processed_rows' => 4,
        'inserted_rows' => 0,
        'updated_rows' => 3,
        'skipped_rows' => 1,
    ]);
    expect(Product::query()->count())->toBe(2)
        ->and(Product::query()->where('last_upload_id', $reupload->id)->count())->toBe(2)
        ->and($reupload->rowErrors()->count())->toBe(1);
});

it('updates changed product values on a later upload', function () {
    (new ProcessCsvUpload(makeCsvUpload('clean.csv')))->handle();
    $upload = makeCsvUpload('changed.csv', "UNIQUE_KEY,PRODUCT_TITLE\nA,Changed title\n");

    (new ProcessCsvUpload($upload))->handle();

    expect(Product::query()->where('unique_key', 'A')->sole()->product_title)->toBe('Changed title')
        ->and($upload->refresh()->updated_rows)->toBe(1);
});

it('maps a BOM header, repairs CP1252 bytes and samples invalid rows', function () {
    $upload = makeCsvUpload('dirty.csv');

    (new ProcessCsvUpload($upload))->handle();

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Completed)
        ->and(csvImportTotals($upload))->toBe([
            'processed_rows' => 2,
            'inserted_rows' => 1,
            'updated_rows' => 0,
            'skipped_rows' => 1,
        ]);
    $product = Product::query()->sole();
    expect($product->unique_key)->toBe('DIRTY')
        ->and(bin2hex($product->product_title))->toBe('54656520c2ae')
        ->and($product->piece_price)->toBeNull();
    expect($upload->rowErrors()->count())->toBe(2);
    $blankKeyError = $upload->rowErrors()->where('line_number', 3)->sole();
    expect($blankKeyError->reason)->toContain('UNIQUE_KEY');
});

it('keeps the last value for a duplicate key within a chunk', function () {
    $upload = makeCsvUpload('duplicate-keys.csv');

    (new ProcessCsvUpload($upload))->handle();

    expect(Product::query()->sole()->product_title)->toBe('Last title')
        ->and(csvImportTotals($upload->refresh()))->toBe([
            'processed_rows' => 2,
            'inserted_rows' => 1,
            'updated_rows' => 1,
            'skipped_rows' => 0,
        ]);
});

it('fails with a clear message when the key header is missing', function () {
    $upload = makeCsvUpload('missing-header.csv');

    (new ProcessCsvUpload($upload))->handle();

    expect($upload->refresh())
        ->status->toBe(UploadStatus::Failed)
        ->error_message->toContain('UNIQUE_KEY')
        ->finished_at->not->toBeNull()
        ->processed_offset->toBe(0);
    expect(Product::query()->count())->toBe(0);
});

it('counts the four-record fixture exactly for each chunk size', function (int $chunkSize) {
    config(['import.chunk_size' => $chunkSize]);
    $upload = makeCsvUpload('four-records.csv');

    (new ProcessCsvUpload($upload))->handle();

    $upload->refresh();
    expect(csvImportTotals($upload))->toBe([
        'processed_rows' => 4,
        'inserted_rows' => 2,
        'updated_rows' => 1,
        'skipped_rows' => 1,
    ]);
    expect($upload->processed_offset)->toBe(4)
        ->and($upload->rowErrors()->count())->toBe(1)
        ->and(Product::query()->count())->toBe(2)
        ->and(Product::query()->where('unique_key', 'A')->sole()->product_title)->toBe('Alpha revised');
})->with([
    'duplicate across chunks' => [1],
    'duplicate in one chunk' => [3],
]);

it('resumes after the first committed chunk when the next flush crashes', function (int $chunkSize) {
    config(['import.chunk_size' => $chunkSize]);
    $upload = makeCsvUpload('four-records.csv');
    [$writePrefix, $failOnWrite, $nextRecord, $inserted, $updated] = match ($chunkSize) {
        2 => ['insert into "products"', 2, 'A,Alpha revised,', 2, 0],
        3 => ['insert into "upload_row_errors"', 1, ',Blank key,', 2, 1],
    };
    $writes = 0;
    DB::connection()->beforeExecuting(function (string $query) use ($writePrefix, $failOnWrite, &$writes): void {
        if (! str_starts_with($query, $writePrefix)) {
            return;
        }

        if (++$writes === $failOnWrite) {
            throw new RuntimeException('Simulated import crash');
        }
    });

    expect(fn () => (new ProcessCsvUpload($upload))->handle())
        ->toThrow(RuntimeException::class, 'Simulated import crash');

    $upload->refresh();
    $contents = Storage::disk('local')->get($upload->stored_path);
    $committedBytes = strpos($contents, $nextRecord);
    expect($committedBytes)->not->toBeFalse();
    expect($upload)
        ->status->toBe(UploadStatus::Processing)
        ->processed_offset->toBe($chunkSize)
        ->progress->toBe(intdiv($committedBytes * 100, strlen($contents)));
    expect(csvImportTotals($upload))->toBe([
        'processed_rows' => $chunkSize,
        'inserted_rows' => $inserted,
        'updated_rows' => $updated,
        'skipped_rows' => 0,
    ]);
    expect(Product::query()->count())->toBe($inserted)
        ->and($upload->rowErrors()->count())->toBe(0);

    (new ProcessCsvUpload(Upload::query()->findOrFail($upload->id)))->handle();

    $upload->refresh();
    expect($upload)
        ->status->toBe(UploadStatus::Completed)
        ->processed_offset->toBe(4);
    expect(csvImportTotals($upload))->toBe([
        'processed_rows' => 4,
        'inserted_rows' => 2,
        'updated_rows' => 1,
        'skipped_rows' => 1,
    ]);
    expect(Product::query()->count())->toBe(2)
        ->and($upload->rowErrors()->count())->toBe(1)
        ->and(Product::query()->where('unique_key', 'A')->sole()->product_title)->toBe('Alpha revised')
        ->and(Product::query()->where('unique_key', 'B')->sole()->product_description)->toBe("This record contains a\nquoted newline.");
})->with([
    'first commit includes quoted multiline record' => [2],
    'first commit includes quoted multiline record and duplicate' => [3],
]);

it('completes from the final checkpoint after a crash before completion', function () {
    $upload = makeCsvUpload('four-records.csv');
    $finishAttempts = 0;
    DB::connection()->beforeExecuting(function (string $query) use (&$finishAttempts): void {
        if (str_starts_with($query, 'update "uploads"') && str_contains($query, '"finished_at"') && ++$finishAttempts === 1) {
            throw new RuntimeException('Simulated crash before completion');
        }
    });

    expect(fn () => (new ProcessCsvUpload($upload))->handle())
        ->toThrow(RuntimeException::class, 'Simulated crash before completion');
    expect($upload->refresh())
        ->status->toBe(UploadStatus::Processing)
        ->processed_offset->toBe(4)
        ->progress->toBe(99);
    $totals = csvImportTotals($upload);

    (new ProcessCsvUpload($upload))->handle();

    expect($upload->refresh())
        ->status->toBe(UploadStatus::Completed)
        ->processed_offset->toBe(4)
        ->progress->toBe(100);
    expect(csvImportTotals($upload))->toBe($totals)
        ->and($upload->rowErrors()->count())->toBe(1);
});

it('caps row error samples across chunks while counting every skipped row', function () {
    config(['import.chunk_size' => 30]);
    $row = ','.str_repeat('x', 1500)."\n";
    $upload = makeCsvUpload('many-errors.csv', "UNIQUE_KEY,PRODUCT_TITLE\n".str_repeat($row, 105));

    (new ProcessCsvUpload($upload))->handle();

    expect($upload->refresh())
        ->status->toBe(UploadStatus::Completed)
        ->processed_offset->toBe(105)
        ->skipped_rows->toBe(105);
    expect($upload->rowErrors()->count())->toBe(100);
    $sample = $upload->rowErrors()->orderBy('line_number')->firstOrFail();
    expect(strlen((string) $sample->getRawOriginal('raw')))->toBeLessThanOrEqual(1024);
});

it('broadcasts the public upload resource on start and completion', function () {
    Event::fake([UploadUpdated::class]);
    $upload = makeCsvUpload('clean.csv');

    (new ProcessCsvUpload($upload))->handle();

    Event::assertDispatched(UploadUpdated::class);
    $updates = Event::dispatched(UploadUpdated::class);
    /** @var UploadUpdated $started */
    [$started] = $updates->first();
    /** @var UploadUpdated $completed */
    [$completed] = $updates->last();

    expect($started->broadcastWith())
        ->id->toBe($upload->public_id)
        ->status->toBe(UploadStatus::Processing->value)
        ->progress->toBe(0);
    expect($completed->broadcastWith())
        ->id->toBe($upload->public_id)
        ->status->toBe(UploadStatus::Completed->value)
        ->progress->toBe(100);
    $channel = $completed->broadcastOn();
    expect($completed->broadcastAs())->toBe('upload.updated')
        ->and($channel::class)->toBe(Channel::class)
        ->and($channel->name)->toBe('uploads')
        ->and($completed->broadcastWith())->toBe((new UploadResource($upload->refresh()))->toArray(request()));
});

it('completes an import when the broadcaster throws', function () {
    Exceptions::fake();
    config([
        'broadcasting.default' => 'throwing-test',
        'broadcasting.connections.throwing-test' => ['driver' => 'throwing-test'],
    ]);
    $exception = new RuntimeException('Test broadcaster is unavailable');
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->atLeast()->twice()
        ->andThrow($exception);
    Broadcast::extend('throwing-test', fn () => $broadcaster);
    $upload = makeCsvUpload('clean.csv');

    (new ProcessCsvUpload($upload))->handle();

    expect($upload->refresh())
        ->status->toBe(UploadStatus::Completed)
        ->progress->toBe(100);
    expect(csvImportTotals($upload))->toBe([
        'processed_rows' => 3,
        'inserted_rows' => 3,
        'updated_rows' => 0,
        'skipped_rows' => 0,
    ]);
    expect(Product::query()->count())->toBe(3);
    Exceptions::assertReported(fn (RuntimeException $reported) => $reported->getMessage() === $exception->getMessage());
});

it('records a user-safe failure, broadcasts it and reports the original exception', function () {
    Event::fake([UploadUpdated::class]);
    Exceptions::fake();
    $upload = makeCsvUpload('clean.csv');
    $exception = new RuntimeException('Internal database path and failure details');

    (new ProcessCsvUpload($upload))->failed($exception);

    expect($upload->refresh())
        ->status->toBe(UploadStatus::Failed)
        ->error_message->not->toBeEmpty()->not->toContain($exception->getMessage())
        ->finished_at->not->toBeNull();
    Event::assertDispatched(UploadUpdated::class, fn (UploadUpdated $event) => $event->broadcastWith()['status'] === UploadStatus::Failed->value);
    Exceptions::assertReported(fn (RuntimeException $reported) => $reported === $exception);
});
