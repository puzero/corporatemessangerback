<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArchivedMessage;
use Illuminate\Http\Request;

class ArchiveController extends Controller
{
public function index(Request $request)
{
    if (!$request->user() || !$request->user()->isAdmin()) {
        abort(403);
    }

    // Подзапрос: максимальный id для каждого event_id
    $subQuery = ArchivedMessage::selectRaw('MAX(id)')
        ->groupBy('event_id');

    $query = ArchivedMessage::whereIn('id', $subQuery);

    if ($roomId = $request->query('room_id')) {
        $query->where('room_id', $roomId);
    }
    if ($search = $request->query('search')) {
        $query->where('body', 'like', "%{$search}%");
    }

    $messages = $query->orderBy('origin_server_ts', 'desc')
        ->paginate(50)
        ->through(function ($msg) {
            return [
                'id'               => $msg->id,
                'room_id'          => $msg->room_id,
                'room_name'        => $msg->room_name ?? $msg->room_id,
                'sender'           => $msg->sender,
                'body'             => $msg->body,
                'msgtype'          => $msg->msgtype,
                'event_id'         => $msg->event_id,
                'origin_server_ts' => $msg->origin_server_ts,
                'is_room_deleted'  => $msg->is_room_deleted,
            ];
        });

    return response()->json($messages);
}

    public function rooms(Request $request)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403);
        }

        $rooms = ArchivedMessage::select('room_id', 'room_name')
                    ->selectRaw('MAX(origin_server_ts) as last_activity')
                    ->groupBy('room_id', 'room_name')
                    ->orderBy('last_activity', 'desc')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id'          => $item->room_id,
                            'name'        => $item->room_name ?? $item->room_id,
                            'is_deleted'  => ArchivedMessage::where('room_id', $item->room_id)
                                                ->where('is_room_deleted', true)
                                                ->exists(),
                        ];
                    });

        return response()->json($rooms);
    }

    public function show($roomId, Request $request)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403);
        }

        $messages = ArchivedMessage::where('room_id', $roomId)
                                   ->orderBy('origin_server_ts')
                                   ->get();

        return response()->json($messages);
    }

public function store(Request $request)
{
    if (!$request->user()) {
        abort(401);
    }

    $data = $request->validate([
        'room_id'         => 'required|string',
        'room_name'       => 'nullable|string',
        'sender'          => 'required|string',
        'body'            => 'nullable|string',
        'msgtype'         => 'nullable|string|in:m.text,m.image,m.file,m.audio,m.video,m.emote',
        'event_id'        => 'nullable|string',
        'origin_server_ts'=> 'nullable|integer',
        'media_url'       => 'nullable|string',
    ]);

    // ✅ Не сохраняем дубликаты
    if ($data['event_id'] && ArchivedMessage::where('event_id', $data['event_id'])->exists()) {
        return response()->json(['message' => 'Already archived'], 200);
    }

    ArchivedMessage::create([
        'room_id'         => $data['room_id'],
        'room_name'       => $data['room_name'] ?? null,
        'sender'          => $data['sender'],
        'body'            => $data['body'] ?? '',
        'msgtype'         => $data['msgtype'] ?? 'm.text',
        'event_id'        => $data['event_id'] ?? null,
        'origin_server_ts'=> $data['origin_server_ts'] ?? (time() * 1000),
        'media_url'       => $data['media_url'] ?? null,
    ]);

    return response()->json(['message' => 'Archived'], 201);
}

    /**
     * Скачивание файла из архива (только для админа).
     */
public function download($eventId, Request $request)
{
    $this->authorizeAdmin($request);

    $msg = ArchivedMessage::where('event_id', $eventId)->firstOrFail();

    $mxcUri = $msg->media_url;
    if (!$mxcUri) {
        abort(404, 'Media URL not found for this message');
    }

    if (!preg_match('/^mxc:\/\/([^\/]+)\/(.+)$/', $mxcUri, $matches)) {
        abort(400, 'Invalid MXC URI');
    }

    $server = $matches[1];
    $mediaId = $matches[2];

    $mediaStorePath = config('matrix.media_store_path', '/home/maksim/synapse/media_store');
    $localContentPath = $mediaStorePath . '/local_content';
    $foundPath = null;

    if (strlen($mediaId) >= 4) {
        $prefix1 = substr($mediaId, 0, 2);
        $prefix2 = substr($mediaId, 2, 2);
        $remaining = substr($mediaId, 4);
        $candidate = $localContentPath . '/' . $prefix1 . '/' . $prefix2 . '/' . $remaining;
        $candidate = realpath($candidate);
        if ($candidate && str_starts_with($candidate, realpath($mediaStorePath))) {
            if (file_exists($candidate) && is_file($candidate)) {
                $foundPath = $candidate;
            }
        }
    }

    if (!$foundPath) {
        $directPath = $localContentPath . '/' . $mediaId;
        $directPath = realpath($directPath);
        if ($directPath && str_starts_with($directPath, realpath($mediaStorePath))) {
            if (file_exists($directPath) && is_file($directPath)) {
                $foundPath = $directPath;
            }
        }
    }

    if (!$foundPath) {
        abort(404, 'File not found on disk');
    }

    $mime = mime_content_type($foundPath) ?: 'application/octet-stream';
    $fileName = $msg->body ?: basename($foundPath);
    
    // Добавляем расширение, если его нет
    $ext = \Illuminate\Support\Str::afterLast($fileName, '.');
    if (empty($ext)) {
        $ext = match(true) {
            str_starts_with($mime, 'audio/') => 'webm',
            str_starts_with($mime, 'image/') => 'jpg',
            str_starts_with($mime, 'video/') => 'mp4',
            default => 'bin'
        };
        $fileName .= '.' . $ext;
    }

    return response()->download($foundPath, $fileName, [
        'Content-Type' => $mime,
    ]);
}

    public function destroy($id, Request $request)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403);
        }

        ArchivedMessage::destroy($id);
        return response()->json(['message' => 'Message deleted']);
    }

    protected function authorizeAdmin(Request $request)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }
    }
}