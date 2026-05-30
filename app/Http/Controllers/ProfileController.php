<?php

namespace App\Http\Controllers;

use App\Models\UserMatrixToken;
use App\Services\MatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProfileController extends Controller
{
    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|max:2048',
        ]);

        $user = $request->user();
        $matrixToken = UserMatrixToken::where('user_id', $user->id)->value('access_token');
        if (!$matrixToken) {
            return response()->json(['error' => 'Matrix token not found'], 403);
        }

        $file = $request->file('avatar');
        $contentUri = $this->matrixService->updateUserAvatar($matrixToken, $file);

        if (!$contentUri) {
            return response()->json(['error' => 'Failed to update avatar'], 500);
        }

        $user->avatar_url = $contentUri;
        $user->save();

        return response()->json(['avatar_url' => $contentUri]);
    }

    public function updatePhone(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $user->phone = $request->phone;
        $user->save();

        return response()->json(['phone' => $user->phone]);
    }
}