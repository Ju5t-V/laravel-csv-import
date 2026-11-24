<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCsvUpload implements ShouldQueue
{
    use Queueable;

    public function __construct(public Upload $upload)
    {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        // T3 implements
    }
}
