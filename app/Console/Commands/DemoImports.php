<?php

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Jobs\ProcessCsvUpload;
use App\Models\Product;
use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DemoImports extends Command
{
    protected $signature = 'imports:demo
        {file? : CSV to import (default: storage/app/samples/products-10000-dirty.csv)}
        {--fresh : Delete all products first so run 1 shows inserts}';

    protected $description = 'Import one CSV twice, synchronously, and print the inserted/updated/skipped counts';

    public function handle(): int
    {
        $file = (string) ($this->argument('file') ?? storage_path('app/samples/products-10000-dirty.csv'));
        if (! is_file($file)) {
            $this->error("File not found: {$file}. Run php artisan products:generate-csv 10000 --dirty first.");

            return self::FAILURE;
        }

        // Nobody is watching a websocket here, so skip the broadcasts.
        config(['broadcasting.default' => 'null']);

        if ($this->option('fresh')) {
            Product::query()->delete();
        }

        $rows = [];
        foreach ([1, 2] as $run) {
            $upload = $this->importOnce($file);
            $rows[] = [
                $run,
                $upload->status->value,
                $upload->processed_rows,
                $upload->inserted_rows,
                $upload->updated_rows,
                $upload->skipped_rows,
                $this->duration($upload),
            ];
        }

        $this->table(['Run', 'Status', 'Rows', 'Inserted', 'Updated', 'Skipped', 'Duration ms'], $rows);
        $this->line('Products in table: '.Product::query()->count());

        return self::SUCCESS;
    }

    private function importOnce(string $file): Upload
    {
        $contents = fopen($file, 'rb');
        if ($contents === false) {
            throw new RuntimeException("Could not open {$file}.");
        }

        $publicId = strtolower((string) Str::ulid());
        $path = "uploads/{$publicId}.csv";
        try {
            Storage::disk('local')->put($path, $contents);
        } finally {
            fclose($contents);
        }

        $hash = hash_file('sha256', $file);
        $upload = Upload::query()->create([
            'public_id' => $publicId,
            'original_name' => basename($file),
            'stored_path' => $path,
            'sha256' => $hash === false ? '' : $hash,
            'size_bytes' => Storage::disk('local')->size($path),
            'status' => UploadStatus::Pending,
        ]);

        ProcessCsvUpload::dispatchSync($upload);

        return $upload->refresh();
    }

    private function duration(Upload $upload): string
    {
        $started = $upload->started_at;
        $finished = $upload->finished_at;

        return $started !== null && $finished !== null ? (string) (int) $started->diffInMilliseconds($finished) : '-';
    }
}
