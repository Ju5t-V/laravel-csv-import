<?php

namespace App\Models;

use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property UploadStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class Upload extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'original_name',
        'stored_path',
        'sha256',
        'size_bytes',
        'status',
        'progress',
        'processed_rows',
        'inserted_rows',
        'updated_rows',
        'skipped_rows',
        'processed_offset',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Upload $upload): void {
            if (empty($upload->public_id)) {
                $upload->public_id = strtolower((string) Str::ulid());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'last_upload_id');
    }

    /** @return HasMany<UploadRowError, $this> */
    public function rowErrors(): HasMany
    {
        return $this->hasMany(UploadRowError::class);
    }
}
