<?php

namespace App\Http\Controllers;

use App\Models\RoomMetadata;
use App\Models\User;
use App\Services\MatrixService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Str;

class RoomController extends Controller
{
    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

    /**
     * Получить список комнат текущего пользователя (с фильтрацией по роли).
     */
    public function index(Request $request): JsonResponse
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'User matrix token not found.'], 403);
        }

        $rooms = $this->matrixService->getAllRoomsWithDetailsFresh($userAccessToken);
        if (!is_array($rooms)) {
            return response()->json(['rooms' => []]);
        }

        $currentUser = $request->user();
        $serverDomain = $this->matrixService->getServerDomain();
        $currentMatrixUserId = '@' . $currentUser->name . ':' . $serverDomain;

        // Точный список DM из account_data
        $directRoomIds = $this->matrixService->getDirectRoomIds($userAccessToken);
        $directRoomMap = array_flip($directRoomIds);

        $allRoomMetadata = RoomMetadata::all()->keyBy('matrix_room_id');
        $formatted = [];
        $seenRoomIds = [];

        foreach ($rooms as $room) {
            $roomId = $room['id'];

            if (in_array($roomId, $seenRoomIds)) {
                continue;
            }
            $seenRoomIds[] = $roomId;

            $members = $room['members'] ?? [];

            // СТРОГО по m.direct
            $isDirect = isset($directRoomMap[$roomId]);

            // Если комната не в m.direct, но имеет 2 участников и НЕТ метаданных группы — синхронизируем
            if (!$isDirect && count($members) === 2 && !isset($allRoomMetadata[$roomId])) {
                $companionId = null;
                foreach ($members as $memberId) {
                    if ($memberId !== $currentMatrixUserId) {
                        $companionId = $memberId;
                        break;
                    }
                }
                if ($companionId && $this->matrixService->setDirectRoom($userAccessToken, $roomId, $companionId)) {
                    $isDirect = true;
                    $directRoomMap[$roomId] = true;
                }
            }

            // === ЛИЧНЫЙ ДИАЛОГ ===
            if ($isDirect) {
                $companionId = null;
                foreach ($members as $memberId) {
                    if ($memberId !== $currentMatrixUserId) {
                        $companionId = $memberId;
                        break;
                    }
                }

                $roomTitle = 'Direct Message';
                $avatar = '/default-avatar.png';

                if ($companionId) {
                    // Всегда получаем профиль собеседника из Matrix
                    $profile = $this->matrixService->getUserProfile($companionId, $userAccessToken);
                    $roomTitle = $profile['displayname'] ?? (explode(':', $companionId)[0] ?? $companionId);
                    $roomTitle = ltrim($roomTitle, '@');
                    $avatarMxc = $profile['avatar_url'] ?? null;
                    if ($avatarMxc && preg_match('/^mxc:\/\/([^\/]+)\/(.+)$/', $avatarMxc, $matches)) {
                        $avatar = "/api/user/avatar/{$matches[1]}/{$matches[2]}";
                    }
                } else {
                    $roomTitle = $room['name'] ?: 'Direct Message';
                }

                $formatted[] = [
                    'id'        => $roomId,
                    'title'     => $roomTitle,
                    'avatar'    => $avatar,
                    'subtitle'  => $room['last_message'] ?? '',
                    'date'      => $room['last_message_time'] ? date('d.m.Y H:i', strtotime($room['last_message_time'])) : '',
                    'unread'    => $room['unread_count'] ?? 0,
                    'is_direct' => true,
                ];
                continue;
            }

            // === ГРУППОВАЯ КОМНАТА ===
            $meta = $allRoomMetadata->get($roomId);
            if (!$meta) {
                try {
                    $meta = RoomMetadata::create([
                        'matrix_room_id' => $roomId,
                        'creator_id'     => $currentUser->id,
                        'room_type'      => 'developer',
                    ]);
                    $allRoomMetadata[$roomId] = $meta;
                } catch (\Exception $e) {
                    Log::error('Failed to auto-create metadata', ['room_id' => $roomId]);
                    continue;
                }
            }

            // Получаем актуальные имя и аватар
            $roomName = null;
            $roomAvatar = null;

            $nameState = $this->matrixService->getRoomStateEvent($roomId, 'm.room.name', $userAccessToken);
            if ($nameState && isset($nameState['name'])) {
                $roomName = $nameState['name'];
            }

            $avatarState = $this->matrixService->getRoomStateEvent($roomId, 'm.room.avatar', $userAccessToken);
            if ($avatarState && isset($avatarState['url'])) {
                $roomAvatar = $avatarState['url'];
            }

            if (!$roomName) $roomName = $room['name'] ?? 'Групповой чат';
            if ($roomAvatar && preg_match('/^mxc:\/\/([^\/]+)\/(.+)$/', $roomAvatar, $matches)) {
                $roomAvatar = "/api/user/avatar/{$matches[1]}/{$matches[2]}";
            } else {
                $roomAvatar = '/default-avatar.png';
            }

            $formatted[] = [
                'id'        => $roomId,
                'title'     => $roomName,
                'avatar'    => $roomAvatar,
                'subtitle'  => $room['last_message'] ?? '',
                'date'      => $room['last_message_time'] ? date('d.m.Y H:i', strtotime($room['last_message_time'])) : '',
                'unread'    => $room['unread_count'] ?? 0,
                'is_direct' => false,
                'is_creator'=> $meta && $meta->creator_id === $currentUser->id,
            ];
        }

        return response()->json(['rooms' => $formatted]);
    }

    /**
     * Создать новую групповую комнату (с указанием типа).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'alias'     => 'nullable|string|max:255|regex:/^[a-z0-9\-_]+$/i',
            'invite'    => 'nullable|array',
            'invite.*'  => 'string',
            'public'    => 'boolean',
            'room_type' => 'required|string|in:developer_frontend,developer_backend,project_manager,admin,customer',
        ]);

        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $currentUser = $request->user();
        $roomType = $request->input('room_type');

        if (!$currentUser->isAdmin()) {
            if ($roomType !== $currentUser->role) {
                return response()->json(['error' => 'You can only create rooms for your own role.'], 403);
            }
        }

        $aliasName = $request->input('alias');
        if (!$aliasName) {
            $aliasName = Str::slug($request->name) . '_' . Str::random(6);
        }

        $roomId = $this->matrixService->createRoom(
            $aliasName,
            $request->name,
            $request->input('invite', []),
            $userAccessToken,
            $request->input('public', false),
            false
        );

        if (!$roomId) {
            return response()->json(['error' => 'Failed to create room.'], 500);
        }

        $this->matrixService->waitForRoomToAppear($roomId, $userAccessToken);

        try {
            RoomMetadata::create([
                'matrix_room_id' => $roomId,
                'creator_id'     => $currentUser->id,
                'room_type'      => $roomType,
            ]);
            Log::info('Room metadata saved', ['room_id' => $roomId]);
        } catch (\Exception $e) {
            Log::error('Failed to save room metadata', ['error' => $e->getMessage(), 'room_id' => $roomId]);
        }

        // Установка начального аватара комнаты (первая буква названия)
        $this->matrixService->setInitialRoomAvatar($roomId, $request->name, $userAccessToken);

        $details = $this->matrixService->getRoomDetails($roomId, $userAccessToken);

        return response()->json([
            'message' => 'Room created successfully',
            'room_id' => $roomId,
            'room'    => $details,
        ], 201);
    }

    /**
     * Покинуть комнату.
     */
    public function leave(Request $request, string $roomId): JsonResponse
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $success = $this->matrixService->leaveRoom($roomId, $userAccessToken);
        if (!$success) {
            return response()->json(['error' => 'Failed to leave room.'], 500);
        }

        $meta = RoomMetadata::where('matrix_room_id', $roomId)->first();
        if ($meta) {
            $members = $this->matrixService->getRoomMembers($roomId, $userAccessToken);
            if (empty($members) || count($members) === 0) {
                $meta->delete();
            }
        }

        return response()->json(['message' => 'Left room successfully.']);
    }

    /**
     * Удалить комнату (администратор может принудительно).
     */
    public function destroy(Request $request, string $roomId): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['error' => 'Admin access required.'], 403);
        }

        $forceDelete = $request->input('force', false);
        if ($forceDelete) {
            $newRoomUserId = $request->input('new_room_user_id');
            $deleted = $this->matrixService->deleteRoomAdmin($roomId, $newRoomUserId);
            if (!$deleted) {
                return response()->json(['error' => 'Failed to delete room via admin API.'], 500);
            }

            $userAccessToken = $request->attributes->get('matrix_access_token');
            if ($userAccessToken) {
                $this->matrixService->leaveRoom($roomId, $userAccessToken);
            }

            RoomMetadata::where('matrix_room_id', $roomId)->delete();
            return response()->json(['message' => 'Room deleted permanently.']);
        }

        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $success = $this->matrixService->leaveRoom($roomId, $userAccessToken);
        if (!$success) {
            return response()->json(['error' => 'Failed to leave room.'], 500);
        }
        return response()->json(['message' => 'Left room successfully.']);
    }

    /**
     * Пригласить пользователя в комнату.
     */
    public function invite(Request $request, string $roomId): JsonResponse
    {
        $request->validate(['user_id' => 'required|string']);

        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $inviteeMatrixId = $request->input('user_id');

        $invitee = User::where('matrix_user_id', $inviteeMatrixId)->first();
        if (!$invitee) {
            $invitee = User::whereHas('matrixToken', function ($q) use ($inviteeMatrixId) {
                $q->where('matrix_user_id', $inviteeMatrixId);
            })->first();
        }

        if (!$invitee) {
            Log::warning('Invite: user not found', ['matrix_user_id' => $inviteeMatrixId]);
            return response()->json(['error' => 'User not found'], 404);
        }

        $success = $this->matrixService->inviteUser($roomId, $inviteeMatrixId, $userAccessToken);
        if (!$success) {
            return response()->json(['error' => 'Failed to invite user.'], 500);
        }

        return response()->json(['message' => 'User invited successfully.']);
    }

    /**
     * Исключить пользователя из комнаты.
     */
    public function kick(Request $request, string $roomId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|string',
            'reason'  => 'nullable|string',
        ]);

        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $userId = $request->input('user_id');
        $reason = $request->input('reason', '');
        if ($reason === null) {
            $reason = '';
        }

        $success = $this->matrixService->kickUser($roomId, $userId, $userAccessToken, $reason);
        if (!$success) {
            return response()->json(['error' => 'Failed to kick user.'], 500);
        }

        return response()->json(['message' => 'User kicked successfully.']);
    }

    /**
     * Получить или создать DM-комнату.
     */
    public function storeDm(Request $request): JsonResponse
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'User matrix token not found.'], 403);
        }

        $validated = $request->validate(['user_id' => 'required|string']);
        $companionMatrixId = $validated['user_id'];

        $currentUser = $request->user();
        $serverDomain = $this->matrixService->getServerDomain();
        $currentMatrixUserId = '@' . $currentUser->name . ':' . $serverDomain;

        if ($companionMatrixId === $currentMatrixUserId) {
            return response()->json(['error' => 'Cannot create chat with yourself.'], 400);
        }

        // Ищем существующую DM через m.direct и другие методы
        $otherUser = User::where('name', explode(':', $companionMatrixId)[0] ?? '')->first();
        if ($otherUser) {
            $existingRoomId = $this->matrixService->findDirectMessageRoom($currentUser->name, $otherUser->name, $userAccessToken);
            if ($existingRoomId) {
                return response()->json(['room_id' => $existingRoomId]);
            }
        }

        // Fallback: перебор joined_rooms
        $joinedRooms = $this->matrixService->getUserRooms($userAccessToken);
        if ($joinedRooms) {
            foreach ($joinedRooms as $roomId) {
                $members = $this->matrixService->getRoomMembers($roomId, $userAccessToken);
                if (is_array($members) && count($members) === 2) {
                    if (in_array($currentMatrixUserId, $members) && in_array($companionMatrixId, $members)) {
                        $this->matrixService->setDirectRoom($userAccessToken, $roomId, $companionMatrixId);
                        return response()->json(['room_id' => $roomId]);
                    }
                }
            }
        }

        // Создаём новую
        $roomId = $this->matrixService->createDirectMessageRoom($userAccessToken, $companionMatrixId);
        if (!$roomId) {
            return response()->json(['error' => 'Failed to create DM room.'], 500);
        }

        $this->matrixService->waitForRoomToAppear($roomId, $userAccessToken);
        $this->matrixService->setDirectRoom($userAccessToken, $roomId, $companionMatrixId);

        return response()->json(['room_id' => $roomId], 201);
    }

    /**
     * Обновить название комнаты.
     */
    public function updateName(Request $request, string $roomId): JsonResponse
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $success = $this->matrixService->sendStateEvent($roomId, 'm.room.name', ['name' => $validated['name']], $userAccessToken);
        if (!$success) {
            return response()->json(['error' => 'Failed to update room name'], 500);
        }

        return response()->json(['message' => 'Room name updated']);
    }

    /**
     * Обновить аватар комнаты.
     */
    public function updateAvatar(Request $request, string $roomId): JsonResponse
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'avatar_url' => 'required|string',
        ]);

        $success = $this->matrixService->sendStateEvent($roomId, 'm.room.avatar', ['url' => $validated['avatar_url']], $userAccessToken);
        if (!$success) {
            return response()->json(['error' => 'Failed to update room avatar'], 500);
        }

        return response()->json(['message' => 'Room avatar updated']);
    }

    /**
     * Получить детальную информацию о комнате (включая is_creator).
     */
    public function show(Request $request, string $roomId): JsonResponse
    {
        $userAccessToken = $request->attributes->get('matrix_access_token');
        if (!$userAccessToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $details = $this->matrixService->getRoomDetails($roomId, $userAccessToken);
        if (!$details) {
            return response()->json(['error' => 'Room not found or inaccessible.'], 404);
        }

        $members = $this->matrixService->getRoomMembers($roomId, $userAccessToken);
        $meta = RoomMetadata::where('matrix_room_id', $roomId)->first();
        $isCreator = $meta && $meta->creator_id === $request->user()->id;

        $directRoomIds = $this->matrixService->getDirectRoomIds($userAccessToken);
        $isDirect = in_array($roomId, $directRoomIds);

        $avatarUrl = $details['avatar_url'] ?? null;
        $roomName = $details['name'] ?? null;

        return response()->json([
            'room'       => $details,
            'members'    => $members,
            'id'         => $roomId,
            'is_creator' => $isCreator,
            'is_direct'  => $isDirect,
            'avatar_url' => $avatarUrl,
            'name'       => $roomName,
        ]);
    }
}