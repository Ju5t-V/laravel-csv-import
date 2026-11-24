<?php

use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('uploads.index');
});

Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:10,1');
Route::get('/uploads', [UploadController::class, 'index']);
Route::get('/uploads/{upload:public_id}', [UploadController::class, 'show']);
