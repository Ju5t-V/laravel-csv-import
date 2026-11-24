<?php

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Events\UploadUpdated;
use App\Imports\ProductCsv;
use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Iterator;
use League\Csv\Reader;
use League\Csv\Statement;
use RuntimeException;
use Throwable;

class ProcessCsvUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Unique lock expires so a lost job (e.g. horizon:clear) can be re-dispatched.
    public int $uniqueFor = 3600;

    private const MAX_ERROR_SAMPLES = 100;

    // SQLite allows 32,766 bind parameters. Each product binds 11 including timestamps.
    private const MAX_CHUNK_SIZE = 2978;

    private const BROADCAST_INTERVAL_NS = 500_000_000;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public Upload $upload)
    {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return (string) $this->upload->id;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        // A killed worker's lock must expire before Redis redelivers the job at 660s.
        $lockSeconds = $this->timeout + 10;

        return [new WithoutOverlapping($this->uniqueId(), releaseAfter: $lockSeconds, expiresAfter: $lockSeconds)];
    }

    public function handle(): void
    {
        $started = Upload::query()->whereKey($this->upload->id)->where('status', UploadStatus::Pending)
            ->update(['status' => UploadStatus::Processing, 'started_at' => now()]);
        $upload = Upload::query()->findOrFail($this->upload->id);
        if ($upload->status !== UploadStatus::Processing) {
            return;
        }
        if ($started === 1) {
            UploadUpdated::dispatch($upload);
        }

        $stream = Storage::disk('local')->readStream($upload->stored_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The stored CSV could not be opened.');
        }

        try {
            $this->import($upload, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function import(Upload $upload, $stream): void
    {
        $lastBroadcast = hrtime(true);
        /** @var Reader<list<string|null>> $reader */
        $reader = Reader::from($stream)->setEscape('')->includeEmptyRecords();
        $format = ProductCsv::fromHeader($reader->first());
        if ($format === null) {
            $this->finish(UploadStatus::Failed, 'The CSV header is missing UNIQUE_KEY.');

            return;
        }

        $offset = $upload->processed_offset;
        // The positional reader includes the header. Skip it plus committed CSV
        // records, so quoted newlines and skipped rows resume at the same boundary.
        /** @var Iterator<int, list<string|null>> $records */
        $records = (new Statement)->offset(1 + $offset)->process($reader)->getRecords();
        $size = max(1, min(self::MAX_CHUNK_SIZE, (int) config('import.chunk_size')));
        $samplesLeft = self::MAX_ERROR_SAMPLES - $upload->rowErrors()->count();
        foreach ($format->chunks($records, $size, $upload->id, $samplesLeft) as $chunk) {
            $position = ftell($stream);
            if ($position === false) {
                throw new RuntimeException('The CSV byte position could not be read.');
            }
            $progress = min(99, intdiv($position * 100, max(1, $upload->size_bytes)));
            $chunk->commit($upload->id, $offset, $progress);
            $offset += $chunk->processed;

            if (hrtime(true) - $lastBroadcast >= self::BROADCAST_INTERVAL_NS) {
                $this->broadcastUpdate();
                $lastBroadcast = hrtime(true);
            }
        }

        $this->finish(UploadStatus::Completed);
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->finish(UploadStatus::Failed, 'The import could not be completed. Please try uploading the file again.');
        } finally {
            report($exception);
        }
    }

    private function finish(UploadStatus $status, ?string $message = null): void
    {
        $attributes = ['status' => $status, 'finished_at' => now(), 'error_message' => $message];
        if ($status === UploadStatus::Completed) {
            $attributes['progress'] = 100;
        }
        $changed = Upload::query()->whereKey($this->upload->id)
            ->whereIn('status', [UploadStatus::Pending, UploadStatus::Processing])->update($attributes);
        if ($changed === 1) {
            $this->broadcastUpdate();
        }
    }

    private function broadcastUpdate(): void
    {
        UploadUpdated::dispatch(Upload::query()->findOrFail($this->upload->id));
    }
}
