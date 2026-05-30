<?php

namespace App\Http\Controllers;

use App\Models\DmRoom;
use App\Models\User;
use App\Models\UserMatrixToken;
use App\Services\MatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactsController extends Controller
{
    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

    public function index(Request $request)
{
    $currentUser = $request->user();

    if ($currentUser->is_admin) {
        $users = User::all();
    } else {
        $users = User::where('role', $currentUser->role)
                     ->orWhere('is_admin', true)
                     ->get();
    }

    $serverDomain = $this->matrixService->getServerDomain();

    $contacts = $users->map(function ($user) use ($serverDomain, $currentUser) {
        $data = [
            'id'             => $user->id,
            'matrix_user_id' => '@' . $user->name . ':' . $serverDomain,
            'display_name'   => $user->name,
            'avatar_url'     => $user->avatar_url,   // ← реальный аватар из БД
            'position'       => $user->position,
            'role'           => $user->role,         // ← роль для отображения
        ];

        // email показываем только администратору или самому себе
        if ($currentUser->is_admin || $currentUser->id === $user->id) {
            $data['email'] = $user->email;
        }
        return $data;
    });

    return response()->json($contacts);
}

    public function store(Request $request): JsonResponse
    {
        return response()->json(['error' => 'Method not allowed'], 405);
    }

    public function destroy(Request $request, $userId): JsonResponse
    {
        return response()->json(['error' => 'Method not allowed'], 405);
    }

public function getOrCreateDmRoom(Request $request, $userId)
{
    $currentUser = $request->user();
    $otherUser = User::find($userId);
    if (!$otherUser) {
        return response()->json(['error' => 'User not found'], 404);
    }
    if ($currentUser->id == $otherUser->id) {
        return response()->json(['error' => 'Cannot create DM with yourself'], 400);
    }

    $token = UserMatrixToken::where('user_id', $currentUser->id)->value('access_token');
    if (!$token) {
        return response()->json(['error' => 'Matrix token not found'], 403);
    }

    $serverDomain = $this->matrixService->getServerDomain();
    $companionMatrixId = '@' . $otherUser->name . ':' . $serverDomain;

    // 1. Поиск через улучшенный findDirectMessageRoom (m.direct + перебор + DmRoom)
    $existingRoomId = $this->matrixService->findDirectMessageRoom($currentUser->name, $otherUser->name, $token);
    if ($existingRoomId) {
        // Обновим локальную таблицу DmRoom, если ещё не записана
        $user1 = min($currentUser->id, $otherUser->id);
        $user2 = max($currentUser->id, $otherUser->id);
        \App\Models\DmRoom::firstOrCreate([
            'user1_id' => $user1,
            'user2_id' => $user2,
        ], [
            'matrix_room_id' => $existingRoomId,
        ]);
        return response()->json(['room_id' => $existingRoomId]);
    }

    // 2. Дополнительная проверка перебором (на случай гонки)
    $joinedRooms = $this->matrixService->getUserRooms($token);
    if ($joinedRooms) {
        $currentMatrixUserId = '@' . $currentUser->name . ':' . $serverDomain;
        foreach ($joinedRooms as $roomId) {
            $members = $this->matrixService->getRoomMembers($roomId, $token);
            if (is_array($members) && count($members) === 2) {
                if (in_array($currentMatrixUserId, $members) && in_array($companionMatrixId, $members)) {
                    $this->matrixService->setDirectRoom($token, $roomId, $companionMatrixId);
                    return response()->json(['room_id' => $roomId]);
                }
            }
        }
    }

    // 3. Создаём новую
    $roomName = $currentUser->name . ' and ' . $otherUser->name;
    $roomId = $this->matrixService->createDirectMessageRoom($token, $companionMatrixId, $roomName);
    if (!$roomId) {
        return response()->json(['error' => 'Failed to create DM room'], 500);
    }

    $this->matrixService->waitForRoomToAppear($roomId, $token);
    $this->matrixService->setDirectRoom($token, $roomId, $companionMatrixId);

    $user1 = min($currentUser->id, $otherUser->id);
    $user2 = max($currentUser->id, $otherUser->id);
    \App\Models\DmRoom::create([
        'user1_id'       => $user1,
        'user2_id'       => $user2,
        'matrix_room_id' => $roomId,
    ]);

    return response()->json(['room_id' => $roomId]);
}
}