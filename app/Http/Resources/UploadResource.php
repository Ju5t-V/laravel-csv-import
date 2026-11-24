<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        /** @var Upload $upload */
        $upload = $this->resource;
        $startedAt = $upload->started_at;
        $finishedAt = $upload->finished_at;

        return [
            'id' => $upload->public_id,
            'file_name' => $upload->original_name,
            'size_bytes' => $upload->size_bytes,
            'status' => $upload->status->value,
            'status_label' => $upload->status->label(),
            'progress' => $upload->progress,
            'processed_rows' => $upload->processed_rows,
            'inserted_rows' => $upload->inserted_rows,
            'updated_rows' => $upload->updated_rows,
            'skipped_rows' => $upload->skipped_rows,
            'error_message' => $upload->error_message,
            'duplicate_of' => $this->duplicateOf($upload),
            'created_at' => $upload->created_at?->toIso8601String(),
            'started_at' => $startedAt?->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
            'duration_ms' => $startedAt !== null && $finishedAt !== null
                ? (int) $startedAt->diffInMilliseconds($finishedAt)
                : null,
        ];
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public static function withDuplicateOf(Builder $query): Builder
    {
        return $query->addSelect([
            'duplicate_of' => Upload::query()
                ->from('uploads as duplicate')
                ->select('duplicate.public_id')
                ->whereColumn('duplicate.sha256', 'uploads.sha256')
                ->whereColumn('duplicate.id', '!=', 'uploads.id')
                ->oldest('duplicate.created_at')
                ->orderBy('duplicate.id')
                ->limit(1),
        ]);
    }

    private function duplicateOf(Upload $upload): ?string
    {
        $attributes = $upload->getAttributes();
        if (! array_key_exists('duplicate_of', $attributes)) {
            $attributes = self::withDuplicateOf(Upload::query())
                ->whereKey($upload->getKey())
                ->first()?->getAttributes() ?? [];
        }

        return $attributes['duplicate_of'] ?? null;
    }
}
