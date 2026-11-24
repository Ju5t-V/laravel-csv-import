<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<Upload, $this> */
    public function lastUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'last_upload_id');
    }
}
