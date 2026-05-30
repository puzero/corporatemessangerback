<?php

use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ContactsController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\UserAvatarController;
use App\Http\Controllers\SearchController;
use Illuminate\Http\Request;

Route::post('/login', [AuthController::class, 'login']);
Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/user', fn(Request $r) => $r->user());
    Route::post('/user/avatar', [ProfileController::class, 'updateAvatar']);
    Route::post('/user/phone', [ProfileController::class, 'updatePhone']);
    Route::get('/user/avatar/{server}/{mediaId}', [UserAvatarController::class, 'show']);
    Route::middleware(['matrix.token'])->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/matrix/rooms', [RoomController::class, 'index']);
        Route::get('/matrix/rooms/{roomId}', [RoomController::class, 'show']);
        Route::post('/matrix/rooms', [RoomController::class, 'store']);
        Route::post('/matrix/rooms/{roomId}/leave', [RoomController::class, 'leave']);
        Route::delete('/matrix/rooms/{roomId}', [RoomController::class, 'destroy']);
        Route::post('/matrix/rooms/{roomId}/invite', [RoomController::class, 'invite']);
        Route::post('/matrix/rooms/{roomId}/kick', [RoomController::class, 'kick']);

        Route::put('/matrix/rooms/{roomId}/name', [RoomController::class, 'updateName']);
        Route::put('/matrix/rooms/{roomId}/avatar', [RoomController::class, 'updateAvatar']);

        Route::post('/upload', [UploadController::class, 'upload']);

        Route::get('/contacts', [ContactsController::class, 'index']);
        Route::post('/contacts/{userId}/dm-room', [ContactsController::class, 'getOrCreateDmRoom']);
        Route::post('/rooms/dm', [RoomController::class, 'storeDm']);

        Route::post('/matrix/search', [SearchController::class, 'search']);

        Route::post('/archive/message', [ArchiveController::class, 'store']);
    });

    Route::prefix('admin')->middleware(['admin'])->group(function () {
        Route::get('/users', [AdminController::class, 'index']);
        Route::post('/users', [AdminController::class, 'store']);
        Route::delete('/users/{id}', [AdminController::class, 'destroyUser']);
        Route::put('/users/{id}/block', [AdminController::class, 'blockUser']);
        Route::put('/users/{id}/unblock', [AdminController::class, 'unblockUser']);
        Route::post('/users/{id}/reset-password', [AdminController::class, 'resetPassword']);

        Route::get('/archive/messages', [ArchiveController::class, 'index']);
        Route::get('/archive/rooms', [ArchiveController::class, 'rooms']);
        Route::get('/archive/messages/{roomId}', [ArchiveController::class, 'show']);
        Route::get('/archive/messages/{eventId}/download', [ArchiveController::class, 'download']); // ← скачивание файла
        Route::delete('/archive/messages/{id}', [ArchiveController::class, 'destroy']);
    });
});

Route::middleware(['auth.api'])->group(function () {
    Route::get('/download/{server}/{mediaId}', [DownloadController::class, 'download']);
});