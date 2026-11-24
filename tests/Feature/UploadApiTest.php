<?php

use App\Enums\UploadStatus;
use App\Jobs\ProcessCsvUpload;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

trait RefreshUploadApiDatabase
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

uses(RefreshUploadApiDatabase::class);

it('rejects a missing file', function () {
    $this->post('/uploads')->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('rejects a non-CSV file', function () {
    $file = UploadedFile::fake()->create('document.pdf', 1, 'application/pdf');

    $this->postJson('/uploads', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('rejects files whose extension is not csv or txt', function (string $name) {
    $file = UploadedFile::fake()->createWithContent($name, "key,name\nA,Widget\n");

    $this->postJson('/uploads', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
})->with(['image.png', 'malware.exe']);

it('accepts a dirty csv containing a 0x01 byte', function () {
    Queue::fake();
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('dirty.csv', "UNIQUE_KEY,PRODUCT_TITLE\nA,Wid\x01get\n");

    $this->postJson('/uploads', ['file' => $file])->assertAccepted();

    Queue::assertPushed(ProcessCsvUpload::class);
});

it('accepts a GIF renamed to csv at upload but the job marks it failed', function () {
    config(['broadcasting.default' => 'null', 'queue.default' => 'sync']);
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('image.csv', "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00;");

    $this->postJson('/uploads', ['file' => $file])->assertAccepted();

    expect(Upload::query()->sole())
        ->status->toBe(UploadStatus::Failed)
        ->error_message->toContain('UNIQUE_KEY');
});

it('rejects a file above the configured limit', function () {
    config(['import.max_kb' => 1]);
    $file = UploadedFile::fake()->create('products.csv', 2, 'text/csv');

    $this->postJson('/uploads', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('stores an upload and queues a pending import', function () {
    Queue::fake();
    Storage::fake('local');
    $contents = "key,name\nA,Widget\n";
    $file = UploadedFile::fake()->createWithContent('products.csv', $contents);

    $response = $this->post('/uploads', ['file' => $file], ['Accept' => 'application/json'])->assertAccepted();

    $upload = Upload::query()->sole();
    $path = "uploads/{$upload->public_id}.csv";
    expect($upload->status)->toBe(UploadStatus::Pending)
        ->and($upload->sha256)->toBe(hash('sha256', $contents))
        ->and($upload->stored_path)->toBe($path);

    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe($contents);
    Queue::assertPushedOn('imports', ProcessCsvUpload::class, fn (ProcessCsvUpload $job) => $job->upload->is($upload));

    $response->assertExactJson(['data' => [
        'id' => $upload->public_id,
        'file_name' => 'products.csv',
        'size_bytes' => strlen($contents),
        'status' => 'pending',
        'status_label' => 'Pending',
        'progress' => 0,
        'processed_rows' => 0,
        'inserted_rows' => 0,
        'updated_rows' => 0,
        'skipped_rows' => 0,
        'error_message' => null,
        'duplicate_of' => null,
        'created_at' => $upload->created_at->toIso8601String(),
        'started_at' => null,
        'finished_at' => null,
        'duration_ms' => null,
    ]]);
});

it('lists newest uploads first and clamps the limit', function () {
    $uploads = collect(range(0, 100))->map(function (int $offset) {
        $upload = new Upload([
            'original_name' => "products-{$offset}.csv",
            'stored_path' => "uploads/products-{$offset}.csv",
            'sha256' => hash('sha256', (string) $offset),
            'size_bytes' => 10,
            'status' => UploadStatus::Pending,
        ]);
        $upload->forceFill(['created_at' => now()->subSeconds($offset)])->save();

        return $upload;
    });

    $this->getJson('/uploads')->assertOk()->assertJsonCount(50, 'data');
    $this->getJson('/uploads?limit=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $uploads[0]->public_id)
        ->assertJsonPath('data.1.id', $uploads[1]->public_id);
    $this->getJson('/uploads?limit=0')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/uploads?limit=101')->assertOk()->assertJsonCount(100, 'data');
});

it('shows an upload by its public id', function () {
    Queue::fake();
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('products.csv', "key,name\nA,Widget\n");
    $publicId = $this->postJson('/uploads', ['file' => $file])->assertAccepted()->json('data.id');
    $upload = Upload::query()->sole();

    $this->getJson("/uploads/{$publicId}")->assertOk()->assertJsonPath('data.id', $publicId);
    $this->getJson("/uploads/{$upload->id}")->assertNotFound();
});

it('links duplicates to the earliest other upload with the same bytes', function () {
    Queue::fake();
    Storage::fake('local');
    $contents = "key,name\nA,Widget\n";
    $first = $this->postJson('/uploads', ['file' => UploadedFile::fake()->createWithContent('first.csv', $contents)])
        ->assertAccepted()->assertJsonPath('data.duplicate_of', null)->json('data.id');
    $second = $this->postJson('/uploads', ['file' => UploadedFile::fake()->createWithContent('second.csv', $contents)])
        ->assertAccepted()->assertJsonPath('data.duplicate_of', $first)->json('data.id');

    $this->postJson('/uploads', ['file' => UploadedFile::fake()->createWithContent('third.csv', $contents)])
        ->assertAccepted()->assertJsonPath('data.duplicate_of', $first);
    $this->getJson("/uploads/{$first}")->assertOk()->assertJsonPath('data.duplicate_of', $second);
    $this->getJson('/uploads?limit=1')->assertOk()->assertJsonPath('data.0.duplicate_of', $first);
});
