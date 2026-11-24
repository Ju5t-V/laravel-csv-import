<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadRowError extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'upload_id',
        'line_number',
        'reason',
        'raw',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['raw' => 'array'];
    }

    /** @return BelongsTo<Upload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
