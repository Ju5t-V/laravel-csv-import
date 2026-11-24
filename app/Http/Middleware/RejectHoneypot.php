<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bots fill every input. A filled "website" field gets a fake 202 (so the bot learns nothing)
 * and nothing is stored or queued.
 */
class RejectHoneypot
{
    public const FIELD = 'website';

    public function handle(Request $request, Closure $next): Response
    {
        if (! filled($request->input(self::FIELD))) {
            return $next($request);
        }

        Log::info('Honeypot triggered on upload.', ['ip' => $request->ip()]);

        $now = now()->toIso8601String();

        return new JsonResponse(['data' => [
            'id' => strtolower((string) Str::ulid()),
            'file_name' => 'upload.csv',
            'size_bytes' => 0,
            'status' => 'pending',
            'status_label' => 'Pending',
            'progress' => 0,
            'processed_rows' => 0,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
            'error_message' => null,
            'duplicate_of' => null,
            'created_at' => $now,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
        ]], Response::HTTP_ACCEPTED);
    }
}
