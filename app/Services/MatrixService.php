<?php

namespace App\Services;

use App\Models\ArchivedMessage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MatrixService
{
    protected Client $httpClient;
    protected string $homeserverUrl;
    protected string $adminAccessToken;
    protected int $txnIdTtl;

    public function __construct()
    {
        $this->homeserverUrl = rtrim(config('matrix.homeserver_url'), '/');
        $this->adminAccessToken = config('matrix.admin_access_token');
        $this->txnIdTtl = config('matrix.txn_id_ttl', 300);

        $this->httpClient = new Client([
            'base_uri' => $this->homeserverUrl,
            'timeout'  => 10,
        ]);
    }

    public function getServerDomain(): string
    {
        $parsed = parse_url($this->homeserverUrl);
        return $parsed['host'] ?? 'localhost';
    }

public function loginUser(string $username, string $password): ?string
{
    $serverDomain = $this->getServerDomain();
    $userId = '@' . $username . ':' . $serverDomain;

    try {
        $response = $this->httpClient->post('/_matrix/client/v3/login', [
            'json' => [
                'type'     => 'm.login.password',
                'user'     => $userId,
                'password' => $password,
            ],
        ]);

        $body = json_decode($response->getBody(), true);
        $accessToken = $body['access_token'] ?? null;

        if ($accessToken) {
            Log::info('Matrix user logged in', ['user_id' => $userId]);
            return $accessToken;
        }

        Log::warning('Matrix login response missing access_token', ['response' => $body]);
        return null;
    } catch (GuzzleException $e) {
        Log::error('Matrix login failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return null;
    }
}
public function createUser(string $username, string $password, ?string $displayName = null): ?array
{
    $serverDomain = $this->getServerDomain();
    $userId = '@' . $username . ':' . $serverDomain;
    $encodedUserId = urlencode($userId);

    $payload = [
        'password' => $password,
        'admin'    => false,
    ];
    if ($displayName) {
        $payload['displayname'] = $displayName;
    }

    try {
        $response = $this->httpClient->put("/_synapse/admin/v2/users/{$encodedUserId}", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->adminAccessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody(), true);
        Log::info('Matrix user created', ['user_id' => $userId]);
        return $body;
    } catch (GuzzleException $e) {
        Log::error('Failed to create Matrix user', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return null;
    }
}

    /**
     * Проверить существование пользователя в Matrix (через Admin API).
     */
public function userExists(string $matrixUserId): bool
{
    $encodedUserId = urlencode($matrixUserId);
    try {
        $response = $this->httpClient->get("/_matrix/client/v3/profile/{$encodedUserId}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminAccessToken],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        if ($e->getCode() === 404) {
            return false;
        }
        Log::warning('Failed to check user existence', [
            'user_id' => $matrixUserId,
            'error'   => $e->getMessage(),
        ]);
        return false;
    }
}
    public function getCurrentUserId(string $userAccessToken): ?string
    {
        try {
            $response = $this->httpClient->get('/_matrix/client/v3/account/whoami', [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            $data = json_decode($response->getBody(), true);
            return $data['user_id'] ?? null;
        } catch (GuzzleException $e) {
            Log::error('whoami failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ==================== УПРАВЛЕНИЕ КОМНАТАМИ ====================


public function getAllRoomsWithDetails(string $userAccessToken): array
{
    try {
        $filter = json_encode([
            'room' => [
                'timeline' => ['limit' => 1],
                'state'    => ['lazy_load_members' => false],
            ],
        ]);

        $response = $this->httpClient->get('/_matrix/client/v3/sync', [
            'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            'query'   => [
                'filter'       => $filter,
                'timeout'      => 0,
                'set_presence' => 'offline',
                '_cacheBuster' => time(),  
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        $rooms = [];

        foreach ($data['rooms']['join'] ?? [] as $roomId => $roomData) {
            $name = null;
            $avatar = null;
            $topic = null;
            $members = [];
            $unreadCount = $roomData['unread_notifications']['notification_count'] ?? 0;

            foreach ($roomData['state']['events'] ?? [] as $event) {
                $type = $event['type'];
                if ($type === 'm.room.name') {
                    $name = $event['content']['name'] ?? null;
                } elseif ($type === 'm.room.avatar') {
                    $avatar = $event['content']['url'] ?? null;
                } elseif ($type === 'm.room.topic') {
                    $topic = $event['content']['topic'] ?? null;
                } elseif ($type === 'm.room.member') {
                    $membership = $event['content']['membership'] ?? '';
                    if (!in_array($membership, ['leave', 'ban'])) {
                        $members[] = $event['state_key'];
                    }
                }
            }

            if (!$name && isset($roomData['name'])) {
                $name = $roomData['name'];
            }

            if (!$name && !empty($roomData['timeline']['events'])) {
                foreach ($roomData['timeline']['events'] as $event) {
                    if ($event['type'] === 'm.room.name') {
                        $name = $event['content']['name'] ?? null;
                        break;
                    }
                }
            }

            $lastMessage = null;
            $lastMessageTime = null;
            if (!empty($roomData['timeline']['events'])) {
                $lastEvent = $roomData['timeline']['events'][0];
                $lastMessage = $lastEvent['content']['body'] ?? null;
                if (isset($lastEvent['origin_server_ts'])) {
                    $lastMessageTime = date('Y-m-d H:i:s', $lastEvent['origin_server_ts'] / 1000);
                }
            }

            $isDirect = (count($members) === 2);

            $rooms[] = [
                'id'                => $roomId,
                'name'              => $name,
                'avatar_url'        => $avatar,
                'topic'             => $topic,
                'last_message'      => $lastMessage ?? 'Нет сообщений',
                'last_message_time' => $lastMessageTime,
                'unread_count'      => $unreadCount,
                'is_direct'         => $isDirect,
                'members'           => $members,
            ];
        }

        return $rooms;
    } catch (\Exception $e) {
        Log::error('Sync request failed', ['error' => $e->getMessage()]);
        return [];
    }
}
    /**
     * Получить список ID комнат, в которых состоит пользователь.
     */
    public function getUserRooms(string $userAccessToken): ?array
    {
        try {
            $response = $this->httpClient->get('/_matrix/client/v3/joined_rooms', [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody(), true);
                $joinedRooms = $body['joined_rooms'] ?? [];
                Log::info('Fetched user rooms successfully', ['room_count' => count($joinedRooms)]);
                return $joinedRooms;
            }

            Log::error('Failed to fetch user rooms', ['status_code' => $response->getStatusCode()]);
            return null;
        } catch (GuzzleException $e) {
            Log::error('Matrix API connection error: ' . $e->getMessage());
            return null;
        }
    }

    public function getRoomDetails(string $roomId, string $userAccessToken): ?array
    {
        try {
            $stateResponse = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/state", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            $state = json_decode($stateResponse->getBody(), true);

            $roomName = null;
            $roomAvatar = null;
            $roomTopic = null;
            foreach ($state as $event) {
                if ($event['type'] === 'm.room.name') {
                    $roomName = $event['content']['name'] ?? null;
                }
                if ($event['type'] === 'm.room.avatar') {
                    $roomAvatar = $event['content']['url'] ?? null;
                }
                if ($event['type'] === 'm.room.topic') {
                    $roomTopic = $event['content']['topic'] ?? null;
                }
            }

            $messagesResponse = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/messages", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
                'query'   => ['dir' => 'b', 'limit' => 1],
            ]);
            $messages = json_decode($messagesResponse->getBody(), true);
            $lastMessage = null;
            $lastMessageDate = null;
            if (!empty($messages['chunk'])) {
                $lastEvent = $messages['chunk'][0];
                $lastMessage = $lastEvent['content']['body'] ?? null;
                $lastMessageDate = isset($lastEvent['origin_server_ts'])
                    ? date('Y-m-d H:i:s', $lastEvent['origin_server_ts'] / 1000)
                    : null;
            }

            $syncResponse = $this->httpClient->get('/_matrix/client/v3/sync', [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
                'query'   => [
                    'filter'  => json_encode(['room' => ['rooms' => [$roomId]]]),
                    'timeout' => 0,
                ],
            ]);
            $sync = json_decode($syncResponse->getBody(), true);
            $unreadCount = $sync['rooms']['join'][$roomId]['unread_notifications']['notification_count'] ?? 0;

            return [
                'name'             => $roomName ?? 'Без названия',
                'avatar_url'       => $roomAvatar,
                'topic'            => $roomTopic,
                'last_message'     => $lastMessage ?? 'Нет сообщений',
                'last_message_time'=> $lastMessageDate,
                'unread_count'     => $unreadCount,
            ];
        } catch (GuzzleException $e) {
            Log::error('Failed to fetch room details', [
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

public function createRoom($aliasName, $roomName, $inviteUserIds, $accessToken, $isPublic = false, $isDirect = false)
{
    $payload = [
        'room_alias_name' => $aliasName,
        'name'            => $roomName,
        'invite'          => $inviteUserIds,
        'preset'          => $isPublic ? 'public_chat' : 'private_chat',
        'visibility'      => $isPublic ? 'public' : 'private',
        'initial_state'   => [
            [
                'type'    => 'm.room.name',
                'content' => ['name' => $roomName],
            ],
        ],
    ];
    if ($isDirect) {
        $payload['is_direct'] = true;
    }

    try {
        $response = $this->httpClient->post($this->homeserverUrl . '/_matrix/client/v3/createRoom', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'json'    => $payload,
        ]);
        $data = json_decode($response->getBody(), true);
        return $data['room_id'] ?? null;
    } catch (GuzzleException $e) {
        Log::error('Failed to create room', ['error' => $e->getMessage()]);
        return null;
    }
}

    public function createDirectMessageRoom(string $creatorToken, string $inviteeMatrixId, string $roomName = ''): ?string
    {
        $serverDomain = $this->getServerDomain();
        $aliasName = 'dm_' . Str::slug($roomName ?: $inviteeMatrixId) . '_' . Str::random(6);
        try {
            $payload = [
                'room_alias_name' => $aliasName,
                'name'            => $roomName ?: 'Direct Message',
                'preset'          => 'private_chat',
                'invite'          => [$inviteeMatrixId],
                'is_direct'       => true,
                'visibility'      => 'private',
            ];

            $response = $this->httpClient->post('/_matrix/client/v3/createRoom', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $creatorToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody(), true);
            $roomId = $body['room_id'] ?? null;
            if ($roomId) {
                Log::info('DM room created', ['room_id' => $roomId, 'invitee' => $inviteeMatrixId]);
                return $roomId;
            }
            Log::error('DM room creation failed', ['response' => $body]);
            return null;
        } catch (GuzzleException $e) {
            Log::error('Exception while creating DM room', [
                'invitee' => $inviteeMatrixId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

public function findDirectMessageRoom(string $user1Name, string $user2Name, string $userAccessToken): ?string
{
    $serverDomain = $this->getServerDomain();
    $user1Id = '@' . $user1Name . ':' . $serverDomain;
    $user2Id = '@' . $user2Name . ':' . $serverDomain;

    // 1. Проверяем m.direct текущего пользователя
    try {
        $currentUserId = $this->getCurrentUserId($userAccessToken);
        if ($currentUserId) {
            $response = $this->httpClient->get(
                "/_matrix/client/v3/user/{$currentUserId}/account_data/m.direct",
                ['headers' => ['Authorization' => 'Bearer ' . $userAccessToken]]
            );
            $mDirect = json_decode($response->getBody(), true);
            if (isset($mDirect[$user2Id]) && is_array($mDirect[$user2Id])) {
                $roomId = $mDirect[$user2Id][0] ?? null;
                if ($roomId && $this->isUserInRoom($roomId, $userAccessToken)) {
                    return $roomId;
                }
            }
        }
    } catch (\Exception $e) {
        // ignore
    }

    // 2. Перебор всех комнат и поиск по участникам
    $rooms = $this->getUserRooms($userAccessToken);
    if ($rooms) {
        foreach ($rooms as $roomId) {
            try {
                $response = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/state", [
                    'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
                ]);
                $stateEvents = json_decode($response->getBody(), true);
                $members = [];
                foreach ($stateEvents as $event) {
                    if ($event['type'] === 'm.room.member') {
                        $members[] = $event['state_key'];
                    }
                }
                if (in_array($user1Id, $members) && in_array($user2Id, $members) && count($members) === 2) {
                    // Синхронизируем m.direct
                    $this->setDirectRoom($userAccessToken, $roomId, $user2Id);
                    return $roomId;
                }
            } catch (GuzzleException $e) {
                continue;
            }
        }
    }

    // 3. Fallback: ищем в локальной таблице DmRoom
    $user1 = User::where('name', $user1Name)->first();
    $user2 = User::where('name', $user2Name)->first();
    if ($user1 && $user2) {
        $dm = \App\Models\DmRoom::where(function ($q) use ($user1, $user2) {
            $q->where('user1_id', $user1->id)->where('user2_id', $user2->id);
        })->orWhere(function ($q) use ($user1, $user2) {
            $q->where('user1_id', $user2->id)->where('user2_id', $user1->id);
        })->first();

        if ($dm && $this->isUserInRoom($dm->matrix_room_id, $userAccessToken)) {
            // Синхронизируем m.direct, если отсутствует
            $this->setDirectRoom($userAccessToken, $dm->matrix_room_id, $user2Id);
            return $dm->matrix_room_id;
        }
    }

    return null;
}

public function getRoomMembers(string $roomId, string $userAccessToken): ?array
{
    try {
        $response = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/members", [
            'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
        ]);
        $data = json_decode($response->getBody(), true);
        $members = [];
        foreach ($data['chunk'] ?? [] as $member) {

            if (!in_array($member['content']['membership'], ['leave', 'ban'])) {
                $members[] = $member['state_key'];
            }
        }
        return $members;
    } catch (GuzzleException $e) {
        Log::error('Failed to get room members', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        return null;
    }
}

    public function isUserInRoom(string $roomId, string $userAccessToken): bool
    {
        try {
            $response = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/members", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
                'query'   => ['not_membership' => 'leave'],
            ]);
            $data = json_decode($response->getBody(), true);
            $currentUserId = $this->getCurrentUserId($userAccessToken);
            if (!$currentUserId) {
                return false;
            }
            foreach ($data['chunk'] ?? [] as $member) {
                if ($member['state_key'] === $currentUserId && $member['content']['membership'] === 'join') {
                    return true;
                }
            }
            return false;
        } catch (GuzzleException $e) {
            Log::error('isUserInRoom failed', [
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function findRoomByMediaId(string $server, string $mediaId, string $userAccessToken): ?string
    {
        $mxcUri = "mxc://{$server}/{$mediaId}";
        $rooms = $this->getUserRooms($userAccessToken);
        if (!$rooms) {
            return null;
        }

        foreach ($rooms as $roomId) {
            try {
                $response = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/messages", [
                    'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
                    'query'   => ['dir' => 'b', 'limit' => 100],
                ]);
                $data = json_decode($response->getBody(), true);
                foreach ($data['chunk'] ?? [] as $event) {
                    if ($event['type'] === 'm.room.message') {
                        $content = $event['content'];
                        $url = $content['url'] ?? $content['thumbnail_url'] ?? null;
                        if ($url === $mxcUri) {
                            Log::info('Found room for media', ['room_id' => $roomId, 'media_id' => $mediaId]);
                            return $roomId;
                        }
                    }
                }
            } catch (GuzzleException $e) {
                Log::warning("Failed to search messages in room $roomId", ['error' => $e->getMessage()]);
                continue;
            }
        }
        return null;
    }
    public function sendMessage(string $roomId, string $message, string $userAccessToken): bool
    {
        $txnId = Str::random(32);
        $cacheKey = "matrix_txn_{$txnId}";
        if (Cache::has($cacheKey)) {
            Log::warning('Duplicate txnId detected, skipping', ['txnId' => $txnId]);
            return false;
        }
        Cache::put($cacheKey, true, $this->txnIdTtl);

        try {
            $response = $this->httpClient->put("/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txnId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'msgtype' => 'm.text',
                    'body'    => $message,
                ],
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (GuzzleException $e) {
            Log::error('Exception while sending message', [
                'roomId' => $roomId,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ==================== УПРАВЛЕНИЕ УЧАСТНИКАМИ ====================

    public function inviteUser(string $roomId, string $userId, string $userAccessToken): bool
    {
        try {
            $response = $this->httpClient->post("/_matrix/client/v3/rooms/{$roomId}/invite", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['user_id' => $userId],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to invite user', [
                'room_id' => $roomId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function kickUser(string $roomId, string $userId, string $userAccessToken, string $reason = ''): bool
    {
        try {
            $response = $this->httpClient->post("/_matrix/client/v3/rooms/{$roomId}/kick", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'user_id' => $userId,
                    'reason'  => $reason,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to kick user', [
                'room_id' => $roomId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function banUser(string $roomId, string $userId, string $userAccessToken, string $reason = ''): bool
    {
        try {
            $response = $this->httpClient->post("/_matrix/client/v3/rooms/{$roomId}/ban", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'user_id' => $userId,
                    'reason'  => $reason,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to ban user', [
                'room_id' => $roomId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function unbanUser(string $roomId, string $userId, string $userAccessToken): bool
    {
        try {
            $response = $this->httpClient->post("/_matrix/client/v3/rooms/{$roomId}/unban", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['user_id' => $userId],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to unban user', [
                'room_id' => $roomId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function leaveRoom(string $roomId, string $userAccessToken): bool
    {
        try {
            $response = $this->httpClient->post("/_matrix/client/v3/rooms/{$roomId}/leave", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to leave room', [
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function joinRoom(string $roomIdentifier, string $userAccessToken): ?string
    {
        try {
            $response = $this->httpClient->post("/_matrix/client/v3/join/{$roomIdentifier}", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            $body = json_decode($response->getBody(), true);
            return $body['room_id'] ?? null;
        } catch (GuzzleException $e) {
            Log::error('Failed to join room', [
                'room_identifier' => $roomIdentifier,
                'error'           => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ==================== АДМИНИСТРАТИВНОЕ УДАЛЕНИЕ КОМНАТ ====================

public function deleteRoomAdmin(string $roomId, ?string $newRoomUserId = null): bool
{
    if (!$this->adminAccessToken) {
        Log::error('Admin access token missing for room deletion');
        return false;
    }

    $payload = [];
    if ($newRoomUserId) {
        $payload['new_room_user_id'] = $newRoomUserId;
    }

    try {

        $response = $this->httpClient->delete("/_synapse/admin/v2/rooms/{$roomId}", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->adminAccessToken,
                'Content-Type'  => 'application/json',
            ],

            'json' => (object) $payload,
        ]);

        $success = $response->getStatusCode() === 200;
        if ($success) {
            Log::info('Room deleted by admin', ['room_id' => $roomId]);
        } else {
            Log::error('Failed to delete room by admin', [
                'room_id' => $roomId,
                'status'  => $response->getStatusCode(),
            ]);
        }
        return $success;
    } catch (GuzzleException $e) {
        Log::error('Exception while deleting room by admin', [
            'room_id' => $roomId,
            'error'   => $e->getMessage(),
        ]);
        return false;
    }
}
    public function blockRoom(string $roomId): bool
    {
        if (!$this->adminAccessToken) {
            return false;
        }
        try {
            $response = $this->httpClient->put("/_synapse/admin/v1/rooms/{$roomId}/block", [
                'headers' => ['Authorization' => 'Bearer ' . $this->adminAccessToken],
                'json'    => ['block' => true],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to block room', [
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ==================== АВАТАРЫ И ПРОФИЛИ ====================

    public function generateAvatarSvg(string $displayName): string
    {
        $name = trim($displayName);
        if (empty($name)) {
            $name = 'User';
        }

        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            if (mb_strlen($word) > 0) {
                $initials .= mb_substr($word, 0, 1);
            }
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }
        if (empty($initials)) {
            $initials = 'U';
        }
        $initials = mb_strtoupper($initials);

        $hue = rand(0, 360);
        $backgroundColor = "hsl($hue, 45%, 55%)";

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
  <rect width="100" height="100" fill="{$backgroundColor}" />
  <text x="50" y="50" fill="white" font-size="50" font-family="Arial, sans-serif"
        text-anchor="middle" dominant-baseline="central" font-weight="bold">{$initials}</text>
</svg>
SVG;
    }


    public function setUserAvatar(string $userId, string $avatarUrl, string $userAccessToken): bool
    {
        try {
            $response = $this->httpClient->put("/_matrix/client/v3/profile/{$userId}/avatar_url", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['avatar_url' => $avatarUrl],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Failed to set user avatar', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

/**
 * Обновить аватар текущего пользователя.
 *
 * @param string $userAccessToken
 * @param \Illuminate\Http\UploadedFile $file
 * @return string|null Content URI (mxc://...)
 */
public function updateUserAvatar(string $userAccessToken, $file): ?string
{
    // Загружаем файл в медиа-репозиторий
    $content = file_get_contents($file->getRealPath());
    $mime = $file->getMimeType();
    $filename = urlencode($file->getClientOriginalName());
    $uploadUrl = $this->homeserverUrl . "/_matrix/media/v3/upload?filename={$filename}";

    try {
        $uploadResponse = $this->httpClient->post($uploadUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $userAccessToken,
                'Content-Type'  => $mime,
            ],
            'body' => $content,
        ]);

        if ($uploadResponse->getStatusCode() !== 200) {
            Log::error('Avatar upload failed', ['status' => $uploadResponse->getStatusCode()]);
            return null;
        }

        $uploadData = json_decode($uploadResponse->getBody(), true);
        $contentUri = $uploadData['content_uri'] ?? null;
        if (!$contentUri) {
            Log::error('Avatar upload response missing content_uri');
            return null;
        }

        // Получаем ID текущего пользователя 
        $currentUserId = $this->getCurrentUserId($userAccessToken);
        if (!$currentUserId) {
            Log::error('Unable to get current user ID from token');
            return null;
        }

        // Устанавливаем avatar_url в профиль этого пользователя
        $profileUrl = "/_matrix/client/v3/profile/{$currentUserId}/avatar_url";
        $setResponse = $this->httpClient->put($profileUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $userAccessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => ['avatar_url' => $contentUri],
        ]);

        if ($setResponse->getStatusCode() !== 200) {
            Log::error('Failed to set avatar_url', ['status' => $setResponse->getStatusCode()]);
            return null;
        }

        return $contentUri;
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        Log::error('Exception while updating avatar', ['error' => $e->getMessage()]);
        return null;
    }
}
    public function getUserProfile(string $userId, string $userAccessToken): ?array
    {
        try {
            $response = $this->httpClient->get("/_matrix/client/v3/profile/{$userId}", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to get user profile', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

public function deactivateMatrixUser(string $matrixUserId): bool
{
    if (!$this->adminAccessToken) {
        Log::error('Matrix admin token missing, cannot deactivate user');
        return false;
    }

    $encodedUserId = urlencode($matrixUserId);
    $url = $this->homeserverUrl . "/_synapse/admin/v2/users/{$encodedUserId}/deactivate";

    try {
        $response = $this->httpClient->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->adminAccessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => ['erase' => true],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to deactivate Matrix user', [
            'user_id' => $matrixUserId,
            'error'   => $e->getMessage(),
        ]);
        return false;
    }
}
public function setUserBlocked(string $matrixUserId, bool $blocked): bool
{
    $encodedUserId = urlencode($matrixUserId);
    $url = $this->homeserverUrl . "/_synapse/admin/v1/users/{$encodedUserId}/block";
    try {
        $response = $this->httpClient->put($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->adminAccessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => ['block' => $blocked],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to set user block status', [
            'user_id' => $matrixUserId,
            'blocked' => $blocked,
            'error'   => $e->getMessage(),
        ]);
        return false;
    }
}

public function setUserPassword(string $matrixUserId, string $newPassword): bool
{
    $encodedUserId = urlencode($matrixUserId);
    $url = $this->homeserverUrl . "/_synapse/admin/v2/users/{$encodedUserId}";
    try {
        $response = $this->httpClient->put($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->adminAccessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'password' => $newPassword,
                'logout_devices' => true,
            ],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to set user password', [
            'user_id' => $matrixUserId,
            'error'   => $e->getMessage(),
        ]);
        return false;
    }
}
public function resetUserPassword(string $matrixUserId, string $newPassword): bool
{
    $encodedUserId = urlencode($matrixUserId);
    $url = $this->homeserverUrl . "/_synapse/admin/v2/users/{$encodedUserId}";
    try {
        $response = $this->httpClient->put($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->adminAccessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'password' => $newPassword,
                'logout_devices' => true,
            ],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to reset user password', ['user_id' => $matrixUserId, 'error' => $e->getMessage()]);
        return false;
    }
}
public function setRoomName(string $roomId, string $name, string $userAccessToken): bool
{
    try {
        $response = $this->httpClient->put("/_matrix/client/v3/rooms/{$roomId}/state/m.room.name", [
            'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            'json' => ['name' => $name],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to set room name', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        return false;
    }
}

public function setRoomAvatar(string $roomId, string $avatarUrl, string $userAccessToken): bool
{
    try {
        $response = $this->httpClient->put("/_matrix/client/v3/rooms/{$roomId}/state/m.room.avatar", [
            'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            'json' => ['url' => $avatarUrl],
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to set room avatar', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        return false;
    }
}
public function sendStateEvent(string $roomId, string $eventType, array $content, string $userAccessToken): bool
{
    try {
        $url = $this->homeserverUrl . "/_matrix/client/v3/rooms/{$roomId}/state/{$eventType}";
        $response = $this->httpClient->put($url, [
            'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            'json'    => $content,
        ]);
        return $response->getStatusCode() === 200;
    } catch (GuzzleException $e) {
        Log::error('Failed to send state event', [
            'room_id'    => $roomId,
            'event_type' => $eventType,
            'error'      => $e->getMessage(),
        ]);
        return false;
    }
}
public function getRoomStateEvent(string $roomId, string $eventType, string $userAccessToken): ?array
{
    try {
        $response = $this->httpClient->get(
            "/_matrix/client/v3/rooms/{$roomId}/state/{$eventType}",
            ['headers' => ['Authorization' => 'Bearer ' . $userAccessToken]]
        );
        return json_decode($response->getBody(), true);
    } catch (GuzzleException $e) {

        if ($e->getCode() !== 404) {
            Log::error("Failed to get state event {$eventType} for room {$roomId}", ['error' => $e->getMessage()]);
        }
        return null;
    }
}

public function getDirectRoomIds(string $userAccessToken): array
{
    try {
        $userId = $this->getCurrentUserId($userAccessToken);
        if (!$userId) return [];

        $response = $this->httpClient->get(
            "/_matrix/client/v3/user/{$userId}/account_data/m.direct",
            ['headers' => ['Authorization' => 'Bearer ' . $userAccessToken]]
        );
        $data = json_decode($response->getBody(), true);
        $directRoomIds = [];
        foreach ($data as $rooms) {
            $directRoomIds = array_merge($directRoomIds, $rooms);
        }
        return array_unique($directRoomIds);
    } catch (GuzzleException $e) {

        if ($e->getCode() !== 404) {
            Log::error('Failed to get direct rooms', ['error' => $e->getMessage()]);
        }
        return [];
    }
}
public function waitForRoomToAppear(string $roomId, string $userAccessToken, int $timeoutSeconds = 5): bool
{
    $start = time();
    while (time() - $start < $timeoutSeconds) {
        $rooms = $this->getUserRooms($userAccessToken);
        if (is_array($rooms) && in_array($roomId, $rooms)) {
            return true;
        }
        usleep(500000); // 0.5 сек
    }
    return false;
}

public function setDirectRoom(string $userAccessToken, string $roomId, string $companionUserId): bool
{
    $currentUserId = $this->getCurrentUserId($userAccessToken);
    if (!$currentUserId) {
        Log::warning('setDirectRoom: unable to get current user ID');
        return false;
    }

    $data = [];
    try {
        $response = $this->httpClient->get(
            "/_matrix/client/v3/user/{$currentUserId}/account_data/m.direct",
            ['headers' => ['Authorization' => 'Bearer ' . $userAccessToken]]
        );
        $data = json_decode($response->getBody(), true);
    } catch (GuzzleException $e) {

        if ($e->getCode() !== 404) {
            Log::error('setDirectRoom: failed to fetch m.direct', ['error' => $e->getMessage()]);
            return false;
        }
        $data = [];
    }

    $data[$companionUserId] = array_unique(array_merge($data[$companionUserId] ?? [], [$roomId]));

    try {
        $this->httpClient->put(
            "/_matrix/client/v3/user/{$currentUserId}/account_data/m.direct",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $userAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $data,
            ]
        );
        Log::info('m.direct updated successfully', ['user_id' => $currentUserId, 'room_id' => $roomId]);
        return true;
    } catch (GuzzleException $e) {
        Log::error('setDirectRoom: failed to update m.direct', ['error' => $e->getMessage()]);
        return false;
    }
}

public function getAllRoomsWithDetailsFresh(string $userAccessToken): array
{

    $roomIds = $this->getUserRooms($userAccessToken);
    if (empty($roomIds)) {
        return [];
    }

    $rooms = [];
    foreach ($roomIds as $roomId) {
        $name = null;
        $avatar = null;
        $lastMessage = null;
        $lastMessageTime = null;
        $members = [];

        try {
            $nameResp = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/state/m.room.name", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            $nameData = json_decode($nameResp->getBody(), true);
            $name = $nameData['name'] ?? null;
        } catch (\Exception $e) {

        }

        try {
            $avatarResp = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/state/m.room.avatar", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            $avatarData = json_decode($avatarResp->getBody(), true);
            $avatar = $avatarData['url'] ?? null;
        } catch (\Exception $e) {

        }

        try {
            $msgResp = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/messages", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
                'query'   => ['dir' => 'b', 'limit' => 1],
            ]);
            $msgData = json_decode($msgResp->getBody(), true);
            if (!empty($msgData['chunk'])) {
                $lastEvent = $msgData['chunk'][0];
                $lastMessage = $lastEvent['content']['body'] ?? null;
                if (isset($lastEvent['origin_server_ts'])) {
                    $lastMessageTime = date('Y-m-d H:i:s', $lastEvent['origin_server_ts'] / 1000);
                }
            }
        } catch (\Exception $e) {
        }

        try {
            $membersResp = $this->httpClient->get("/_matrix/client/v3/rooms/{$roomId}/members", [
                'headers' => ['Authorization' => 'Bearer ' . $userAccessToken],
            ]);
            $membersData = json_decode($membersResp->getBody(), true);
            if (!empty($membersData['chunk'])) {
                foreach ($membersData['chunk'] as $m) {
                    if (!in_array($m['content']['membership'], ['leave', 'ban'])) {
                        $members[] = $m['state_key'];
                    }
                }
            }
        } catch (\Exception $e) {
        }

        $isDirect = (count($members) === 2);

        $rooms[] = [
            'id'                => $roomId,
            'name'              => $name,
            'avatar_url'        => $avatar,
            'last_message'      => $lastMessage ?? 'Нет сообщений',
            'last_message_time' => $lastMessageTime,
            'unread_count'      => 0,
            'is_direct'         => $isDirect,
            'members'           => $members,
        ];
    }

    return $rooms;
}
public function archiveMessage(string $roomId, string $sender, string $body, string $msgtype = 'm.text', ?string $eventId = null, ?int $originServerTs = null): void
{
    ArchivedMessage::create([
        'room_id'          => $roomId,
        'sender'           => $sender,
        'body'             => $body,
        'msgtype'          => $msgtype,
        'event_id'         => $eventId,
        'origin_server_ts' => $originServerTs,
    ]);
}

public function setInitialRoomAvatar(string $roomId, string $roomName, string $userAccessToken): void
{
    $firstLetter = mb_strtoupper(mb_substr($roomName, 0, 1));
    $svg = $this->generateAvatarSvg($firstLetter);
    $contentUri = $this->uploadContentAsMedia($svg, "room_avatar_{$roomId}.svg", $userAccessToken);
    if ($contentUri) {
        $this->sendStateEvent($roomId, 'm.room.avatar', ['url' => $contentUri], $userAccessToken);
    }
}

public function uploadContentAsMedia(string $content, string $filename, string $userAccessToken): ?string
{
    try {
        $url = $this->homeserverUrl . '/_matrix/media/v3/upload?filename=' . urlencode($filename);
        $response = $this->httpClient->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $userAccessToken,
                'Content-Type'  => 'application/octet-stream',  // универсальный бинарный тип
            ],
            'body' => $content,
        ]);
        if ($response->getStatusCode() !== 200) {
            Log::error('Failed to upload avatar media', ['status' => $response->getStatusCode()]);
            return null;
        }
        $data = json_decode($response->getBody(), true);
        return $data['content_uri'] ?? null;
    } catch (GuzzleException $e) {
        Log::error('Exception while uploading avatar', ['error' => $e->getMessage()]);
        return null;
    }
}

public function setInitialAvatar(string $username, string $userAccessToken): void
{
    $firstLetter = mb_strtoupper(mb_substr($username, 0, 1));
    $svg = $this->generateAvatarSvg($firstLetter);
    $contentUri = $this->uploadContentAsMedia($svg, "avatar_{$username}.svg", $userAccessToken);
    if ($contentUri) {
        $serverDomain = $this->getServerDomain();
        $userId = '@' . $username . ':' . $serverDomain;
        $this->setUserAvatar($userId, $contentUri, $userAccessToken);
    }
}

}