<?php

namespace App\Imports;

use App\Enums\UploadStatus;
use App\Models\Product;
use App\Models\Upload;
use App\Models\UploadRowError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ProductImportChunk
{
    /**
     * @param  list<array<string, int|string|null>>  $products
     * @param  list<array<string, mixed>>  $errors
     */
    public function __construct(
        public int $processed,
        private int $skipped,
        private array $products,
        private array $errors,
    ) {}

    public function commit(int $uploadId, int $offset, int $progress): void
    {
        DB::transaction(function () use ($uploadId, $offset, $progress): void {
            // Take the SQLite write lock before counting keys. A rollback restores
            // this checkpoint along with its samples, totals and product writes.
            $checkpoint = Upload::query()->whereKey($uploadId)
                ->where('status', UploadStatus::Processing)
                ->where('processed_offset', $offset)
                ->incrementEach(['processed_rows' => $this->processed, 'skipped_rows' => $this->skipped], [
                    'processed_offset' => $offset + $this->processed,
                    'progress' => $progress,
                ]);
            if ($checkpoint !== 1) {
                throw new RuntimeException('The upload checkpoint changed during import.');
            }

            if ($this->errors !== []) {
                UploadRowError::query()->insert($this->errors);
            }
            if ($this->products === []) {
                return;
            }

            $keys = array_column($this->products, 'unique_key');
            $existing = Product::query()->whereIn('unique_key', $keys)->count();
            $inserted = count($keys) - $existing;
            // A repeated CSV key counts as an update even when folded into this upsert.
            Upload::query()->whereKey($uploadId)->incrementEach([
                'inserted_rows' => $inserted,
                'updated_rows' => $this->processed - $this->skipped - $inserted,
            ]);
            $columns = array_values(array_diff(array_keys($this->products[0]), ['unique_key']));
            Product::query()->upsert($this->products, ['unique_key'], $columns);
        });
    }
}
