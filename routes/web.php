<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'index'])->name('chat.index');

Route::post('/chat/messages', [ChatController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('chat.messages.store');
