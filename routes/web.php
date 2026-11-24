<?php

use App\Http\Controllers\UploadController;
use App\Http\Middleware\RejectHoneypot;
use Illuminate\Support\Facades\Route;

Route::view('/', 'uploads.index');

Route::post('/uploads', [UploadController::class, 'store'])->middleware(['throttle:10,1', RejectHoneypot::class]);
Route::get('/uploads', [UploadController::class, 'index']);
Route::get('/uploads/{upload:public_id}', [UploadController::class, 'show']);
