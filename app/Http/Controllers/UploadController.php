<?php

namespace App\Http\Controllers;

use App\Enums\UploadStatus;
use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Jobs\ProcessCsvUpload;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UploadController extends Controller
{
    public function store(StoreUploadRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->validated('file');
        $publicId = strtolower((string) Str::ulid());
        $disk = Storage::disk('local');
        $path = $disk->putFileAs('uploads', $file, "{$publicId}.csv");

        if ($path === false) {
            throw new RuntimeException('Could not store the upload.');
        }

        try {
            $sha256 = hash_file('sha256', $disk->path($path));
            if ($sha256 === false) {
                throw new RuntimeException('Could not hash the upload.');
            }

            $upload = Upload::query()->create([
                'public_id' => $publicId,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'sha256' => $sha256,
                'size_bytes' => $disk->size($path),
                'status' => UploadStatus::Pending,
            ]);
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        $upload->refresh();
        ProcessCsvUpload::dispatch($upload);

        return (new UploadResource($upload))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = $request->query('limit');
        $limit = is_numeric($limit) ? max(1, min(100, (int) $limit)) : 50;

        return UploadResource::collection(UploadResource::withDuplicateOf(Upload::query())
            ->latest()
            ->orderByDesc('id')
            ->limit($limit)
            ->get());
    }

    public function show(Upload $upload): UploadResource
    {
        return new UploadResource($upload);
    }
}
