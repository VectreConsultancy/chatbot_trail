<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/chat/stream', [ChatController::class, 'stream'])->name('chat.stream');
Route::post('/chat/feedback', [ChatController::class, 'feedback'])->name('chat.feedback');
Route::get('/chat/suggestions', [ChatController::class, 'suggestions'])->name('chat.suggestions');
