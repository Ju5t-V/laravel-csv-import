<?php

namespace App\Events;

use App\Http\Resources\UploadResource;
use App\Models\Upload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

final class UploadUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public readonly Upload $upload) {}

    public function broadcastOn(): Channel
    {
        return new Channel('uploads');
    }

    public function broadcastAs(): string
    {
        return 'upload.updated';
    }

    /** @return array<string, int|string|null> */
    public function broadcastWith(): array
    {
        return (new UploadResource($this->upload))->toArray(request());
    }
}
