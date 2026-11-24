@extends('layouts.app')

@section('title', 'CSV imports')

@section('content')
<div x-data="uploads" data-max-kb="{{ config('import.max_kb') }}" class="space-y-8">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">CSV imports</h1>
            <p class="text-sm text-slate-600">Upload a product CSV. Rows are imported in the background and update live.</p>
        </div>
        <p class="rounded-full border px-3 py-1 text-sm font-medium"
           :class="live ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-amber-300 bg-amber-50 text-amber-900'"
           data-testid="connection">
            <span x-text="live ? '● Live' : '○ Polling'">○ Polling</span>
        </p>
    </header>

    <section aria-labelledby="upload-heading">
        <h2 id="upload-heading" class="sr-only">Upload a file</h2>
        <div role="button" tabindex="0" id="drop-zone" data-testid="drop-zone"
             aria-describedby="drop-hint"
             @click="$refs.file.click()"
             @keydown.enter.prevent="$refs.file.click()"
             @keydown.space.prevent="$refs.file.click()"
             @dragover.prevent="dragging = true"
             @dragleave.prevent="dragging = false"
             @drop.prevent="dragging = false; pick($event.dataTransfer.files[0])"
             :class="dragging ? 'border-indigo-600 bg-indigo-50' : 'border-slate-400 bg-white hover:border-indigo-600'"
             class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-6 py-10 text-center focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600/50">
            <span class="font-medium">Drop a CSV here, or click to choose</span>
            <span id="drop-hint" class="text-sm text-slate-600">.csv or .txt, up to <span x-text="maxLabel"></span></span>
            <input type="file" x-ref="file" accept=".csv,.txt,text/csv,text/plain" class="sr-only" tabindex="-1"
                   @change="pick($event.target.files[0]); $event.target.value = ''" @click.stop>
        </div>

        <input type="text" name="website" x-ref="website" value="" autocomplete="off" tabindex="-1" aria-hidden="true"
               class="absolute -left-[9999px] h-0 w-0 overflow-hidden opacity-0">

        <template x-if="current">
            <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-sm">
                <div class="flex justify-between gap-3">
                    <span class="truncate font-medium" x-text="current.name" :title="current.name"></span>
                    <span class="shrink-0 text-slate-600" x-text="formatBytes(current.size)"></span>
                </div>
                <div class="mt-2">
                    <div class="h-2 overflow-hidden rounded-full bg-slate-200"
                         role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="current.percent">
                        <div class="h-full bg-indigo-700 transition-all" :style="`width:${current.percent}%`"></div>
                    </div>
                    <p class="mt-1 text-xs text-slate-600">Uploading <span x-text="current.percent"></span>%</p>
                </div>
            </div>
        </template>
        <p x-show="error" x-cloak x-text="error" role="alert" class="mt-3 text-sm font-medium text-red-700"></p>
        <p class="sr-only" aria-live="polite" x-text="announcement"></p>
    </section>

    <section aria-labelledby="list-heading">
        <h2 id="list-heading" class="mb-3 text-lg font-semibold">Recent uploads</h2>

        <div x-show="!loading && rows.length === 0" x-cloak data-testid="empty-state"
             class="rounded-xl border border-slate-200 bg-white px-6 py-12 text-center text-slate-600">
            No uploads yet. Drop a CSV above to get started.
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white" x-show="rows.length > 0" x-cloak>
            <table class="w-full text-left text-sm" data-testid="uploads-table">
                <caption class="sr-only">Uploads, newest first</caption>
                <thead class="bg-slate-100 text-xs uppercase tracking-wide text-slate-700">
                    <tr>
                        <th scope="col" class="px-4 py-3">Time</th>
                        <th scope="col" class="px-4 py-3">File</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Progress</th>
                        <th scope="col" class="px-4 py-3">Result</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <template x-for="row in rows" :key="row.id">
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">
                                <div x-text="absolute(row.created_at)"></div>
                                <div class="text-xs text-slate-600" x-text="relative(row.created_at, now)"></div>
                            </td>
                            <td class="max-w-56 px-4 py-3">
                                <div class="truncate font-medium" x-text="row.file_name" :title="row.file_name"></div>
                                <div class="text-xs text-slate-600" x-text="formatBytes(row.size_bytes)"></div>
                                <template x-if="row.duplicate_of">
                                    <span class="mt-1 inline-block rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800"
                                          x-text="`same file as #${shortId(row.duplicate_of)}`"></span>
                                </template>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                      :class="badgeClass(row.status)"
                                      :title="row.status === 'failed' && row.error_message ? row.error_message : null">
                                    <span class="size-1.5 rounded-full bg-current" :class="row.status === 'processing' && 'animate-pulse'" aria-hidden="true"></span>
                                    <span x-text="row.status_label"></span>
                                </span>
                                <p x-show="row.status === 'failed' && row.error_message" class="mt-1 max-w-48 truncate text-xs text-red-800"
                                   x-text="row.error_message" :title="row.error_message"></p>
                            </td>
                            <td class="min-w-40 px-4 py-3">
                                <div class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar"
                                     :aria-label="`Processing ${row.file_name}`" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="row.progress">
                                    <div class="h-full transition-all" :class="row.status === 'failed' ? 'bg-red-700' : 'bg-blue-700'" :style="`width:${row.progress}%`"></div>
                                </div>
                                <div class="mt-1 text-xs text-slate-600"><span x-text="row.progress"></span>% · <span x-text="row.processed_rows.toLocaleString()"></span> rows</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs">
                                <div x-text="`+${row.inserted_rows} · ~${row.updated_rows} · ⚠ ${row.skipped_rows}`"></div>
                                <div class="text-slate-600" x-text="formatDuration(row.duration_ms)"></div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
