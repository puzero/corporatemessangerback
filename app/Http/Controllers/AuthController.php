<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMatrixToken;
use App\Services\MatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid email or password'], 401);
        }

        if ($user->is_blocked) {
            return response()->json(['error' => 'Your account has been blocked. Please contact administrator.'], 403);
        }

        $matrixAccessToken = $this->matrixService->loginUser($user->name, $request->password);
        if (!$matrixAccessToken) {
            return response()->json(['message' => 'Could not authenticate with Matrix server.'], 500);
        }

        $serverDomain = $this->matrixService->getServerDomain();
        $matrixUserId = '@' . $user->name . ':' . $serverDomain;

        $profile = $this->matrixService->getUserProfile($matrixUserId, $matrixAccessToken);
        if ($profile && isset($profile['avatar_url'])) {
            $user->avatar_url = $profile['avatar_url'];
            $user->save();
        }

        UserMatrixToken::updateOrCreate(
            ['user_id' => $user->id],
            [
                'matrix_user_id' => $matrixUserId,
                'access_token'   => $matrixAccessToken,
                'expires_at'     => null,
            ]
        );

        $user->matrix_user_id = $matrixUserId;
        $user->save();

        $user->tokens()->delete();
        $localToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'           => $user->only(['id', 'name', 'email', 'phone', 'is_admin', 'role']),
            'token'          => $localToken,
            'matrix_token'   => $matrixAccessToken,
            'matrix_user_id' => $matrixUserId,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        UserMatrixToken::where('user_id', $user->id)->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Генерация латинского username из произвольного имени.
     */
    private function generateLatinUsername(string $name): string
    {
        $transliterated = \Illuminate\Support\Str::slug($name, '_', 'ru');
        $transliterated = str_replace('-', '_', $transliterated);
        $transliterated = preg_replace('/[^a-z0-9_]/', '', strtolower($transliterated));
        return $transliterated ?: 'user_' . bin2hex(random_bytes(4));
    }
}