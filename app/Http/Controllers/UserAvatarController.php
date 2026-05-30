<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserAvatarController extends Controller
{
    public function show(Request $request, string $server, string $mediaId)
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
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
            Log::warning('Avatar file not found', ['media_id' => $mediaId, 'user_id' => $user->id]);

            return response()->file(public_path('default-avatar.png'), ['Content-Type' => 'image/png']);
        }

        $mime = mime_content_type($foundPath) ?: 'application/octet-stream';
        $fileName = basename($foundPath);

        return response()->file($foundPath, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }
}