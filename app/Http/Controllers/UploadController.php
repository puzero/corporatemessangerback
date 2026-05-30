<?php

namespace App\Http\Controllers;

use App\Models\UserMatrixToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $matrixToken = UserMatrixToken::where('user_id', $user->id)->value('access_token');
        if (!$matrixToken) {
            Log::error('Upload: matrix token not found', ['user_id' => $user->id]);
            return response()->json(['error' => 'Matrix token not found'], 403);
        }

        $file = $request->file('file');
        $filename = urlencode($file->getClientOriginalName());
        $homeserver = rtrim(config('matrix.homeserver_url'), '/');
        $url = "{$homeserver}/_matrix/media/v3/upload?filename={$filename}";

        try {

            $response = Http::withToken($matrixToken)
                ->withBody(file_get_contents($file->getRealPath()), 'application/octet-stream')
                ->post($url);

            Log::info('Matrix upload response', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'url'    => $url,
            ]);

            if (!$response->successful()) {
                $errorMsg = $response->json()['error'] ?? $response->body();
                return response()->json(['error' => 'Matrix upload failed', 'details' => $errorMsg], 500);
            }

            $data = $response->json();
            return response()->json(['content_uri' => $data['content_uri']]);
        } catch (\Exception $e) {
            Log::error('Upload exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Server error'], 500);
        }
    }
}