<?php

namespace App\Http\Controllers;

use App\Models\UserMatrixToken;
use App\Services\MatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DownloadController extends Controller
{
    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

    public function download(Request $request, $server, $mediaId)
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            Log::error('Download: Unauthorized', ['media_id' => $mediaId]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $mediaId)) {
            Log::warning('Download: invalid mediaId format', ['media_id' => $mediaId]);
            return response()->json(['error' => 'Invalid media identifier'], 400);
        }

        $matrixToken = UserMatrixToken::where('user_id', $user->id)->value('access_token');
        if (!$matrixToken) {
            Log::error('Download: matrix token not found', ['user_id' => $user->id]);
            return response()->json(['error' => 'Matrix token missing'], 403);
        }

        $roomId = $request->query('room_id');
        if ($roomId) {
            if (!$this->matrixService->isUserInRoom($roomId, $matrixToken)) {
                Log::warning('Download: user not in provided room', [
                    'user_id' => $user->id,
                    'room_id' => $roomId,
                ]);
                return response()->json(['error' => 'Access denied'], 403);
            }
        } else {
            $roomId = $this->matrixService->findRoomByMediaId($server, $mediaId, $matrixToken);
            if (!$roomId) {
                Log::warning('Download: could not determine room for media', ['media_id' => $mediaId]);
                return response()->json(['error' => 'File not accessible'], 403);
            }
        }

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
            Log::warning('Download: file not found', ['media_id' => $mediaId]);
            return response()->json(['error' => 'File not found'], 404);
        }

        $mime = mime_content_type($foundPath) ?: 'application/octet-stream';
        $fileName = basename($foundPath);

        return response()->file($foundPath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }
}