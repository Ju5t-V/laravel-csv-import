<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'unique_key',
        'product_title',
        'product_description',
        'style_number',
        'sanmar_mainframe_color',
        'size',
        'color_name',
        'piece_price',
        'last_upload_id',
    ];

    /** @return BelongsTo<Upload, $this> */
    public function lastUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'last_upload_id');
    }
}
