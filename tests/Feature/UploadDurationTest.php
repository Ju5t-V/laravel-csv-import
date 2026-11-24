<?php

use App\Enums\UploadStatus;
use App\Http\Resources\UploadResource;
use App\Jobs\ProcessCsvUpload;
use App\Models\Upload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RefreshMemoryDatabase;

uses(RefreshMemoryDatabase::class);

function makeDurationUpload(string $name, string $path): Upload
{
    return Upload::query()->create([
        'original_name' => $name, 'stored_path' => $path, 'sha256' => hash('sha256', $name),
        'size_bytes' => 30, 'status' => UploadStatus::Pending,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('stores the import timestamps with millisecond precision', function () {
    config(['broadcasting.default' => 'null']);
    Storage::fake('local');
    Storage::disk('local')->put('uploads/ms.csv', "UNIQUE_KEY,PRODUCT_TITLE\nA,One\n");
    $upload = makeDurationUpload('ms.csv', 'uploads/ms.csv');

    Carbon::setTestNow('2026-01-01 10:00:00.250');
    (new ProcessCsvUpload($upload))->handle();

    $raw = Upload::query()->toBase()->where('id', $upload->id)->first();
    expect($raw->started_at)->toBe('2026-01-01 10:00:00.250')
        ->and($raw->finished_at)->toBe('2026-01-01 10:00:00.250');
});

it('reports a sub-second import with a positive duration_ms', function () {
    $upload = makeDurationUpload('d.csv', 'uploads/d.csv');
    Upload::query()->whereKey($upload->id)->update([
        'started_at' => Carbon::parse('2026-01-01 10:00:00.100'),
        'finished_at' => Carbon::parse('2026-01-01 10:00:00.350'),
    ]);

    $payload = (new UploadResource($upload->refresh()))->toArray(request());

    expect($payload['duration_ms'])->toBe(250);
});
