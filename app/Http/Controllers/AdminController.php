<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMatrixToken;
use App\Services\MatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    protected MatrixService $matrixService;

    public function __construct(MatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

    public function index(Request $request)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $users = User::all();
        $result = [];
        foreach ($users as $user) {
            $matrixUserId = $user->matrix_user_id ?? ($user->matrixToken ? $user->matrixToken->matrix_user_id : null);
            $result[] = [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'phone'          => $user->phone,
                'is_admin'       => $user->is_admin,
                'position'       => $user->position,
                'role'           => $user->role,
                'matrix_user_id' => $matrixUserId,
                'is_blocked'     => (bool) $user->is_blocked,
                'created_at'     => $user->created_at,
            ];
        }
        return response()->json(['users' => $result]);
    }

    public function store(Request $request)
{
    if (!$request->user() || !$request->user()->isAdmin()) {
        return response()->json(['error' => 'Forbidden'], 403);
    }

    $data = $request->validate([
        'name'     => 'required|string|min:3|max:255',
        'email'    => 'required|email|unique:users,email',
        'phone'    => 'nullable|string',
        'position' => 'nullable|string|max:255',
        'password' => 'required|string|min:8',
        'role'     => 'sometimes|string|in:developer,project_manager,admin,customer',
    ]);

    $normalizedName = strtolower($data['name']);
    $normalizedName = preg_replace('/[^a-z0-9=_\-.+]/', '', $normalizedName);
    if (empty($normalizedName)) {
        return response()->json(['error' => 'Invalid username after normalization'], 422);
    }

    $role = $data['role'] ?? 'developer';
    $serverDomain = $this->matrixService->getServerDomain();
    $matrixUserId = '@' . $normalizedName . ':' . $serverDomain;

    if (User::where('name', $normalizedName)->exists()) {
        return response()->json(['error' => 'User already exists locally'], 422);
    }

    $created = $this->matrixService->createUser($normalizedName, $data['password'], $data['name']);
    if (!$created) {
        Log::error('Matrix createUser failed', ['name' => $normalizedName]);
        return response()->json(['error' => 'Failed to create user in Matrix'], 500);
    }

    $matrixToken = $this->matrixService->loginUser($normalizedName, $data['password']);
    if (!$matrixToken) {
        return response()->json(['error' => 'Matrix login failed after creation'], 500);
    }

    $user = User::create([
        'name'           => $data['name'],
        'email'          => $data['email'],
        'phone'          => $data['phone'] ?? null,
        'position'       => $data['position'] ?? null,
        'password'       => Hash::make($data['password']),
        'matrix_user_id' => $matrixUserId,
        'is_admin'       => $role === 'admin',
        'role'           => $role,
    ]);

    UserMatrixToken::create([
        'user_id'        => $user->id,
        'matrix_user_id' => $matrixUserId,
        'access_token'   => $matrixToken,
        'expires_at'     => null,
    ]);

    // Установка начального аватара (инициалы)
    $this->matrixService->setInitialAvatar($normalizedName, $matrixToken);

    $profile = $this->matrixService->getUserProfile($matrixUserId, $matrixToken);
    if ($profile && isset($profile['avatar_url'])) {
        $user->avatar_url = $profile['avatar_url'];
        $user->save();
    }

    return response()->json(['message' => 'User created successfully', 'user' => $user], 201);
}
private function generateLatinUsername(string $name): string
{
    $transliterated = \Illuminate\Support\Str::slug($name, '_', 'ru');
    $transliterated = str_replace('-', '_', $transliterated);
    $transliterated = preg_replace('/[^a-z0-9_]/', '', strtolower($transliterated));
    return $transliterated ?: 'user_' . bin2hex(random_bytes(4));
}

/**
 * Генерация латинского username из произвольного имени.
 */

    public function destroyUser(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($user->is_admin && $user->id !== $request->user()->id) {
            return response()->json(['error' => 'Cannot delete another admin user'], 403);
        }

        $matrixUserId = $user->matrix_user_id ?? ($user->matrixToken ? $user->matrixToken->matrix_user_id : null);
        $matrixDeleted = false;
        if ($matrixUserId) {
            $matrixDeleted = $this->matrixService->deactivateMatrixUser($matrixUserId);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
            'matrix_deactivated' => $matrixDeleted
        ]);
    }

    public function blockUser(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        if ($user->matrix_user_id) {
            $this->matrixService->setUserBlocked($user->matrix_user_id, true);
        }
        $user->is_blocked = true;
        $user->save();
        $user->tokens()->delete();
        return response()->json(['message' => 'User blocked successfully']);
    }

    public function unblockUser(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        if ($user->matrix_user_id) {
            $this->matrixService->setUserBlocked($user->matrix_user_id, false);
        }
        $user->is_blocked = false;
        $user->save();
        return response()->json(['message' => 'User unblocked successfully']);
    }
    public function resetPassword(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'new_password' => 'required|string|min:8',
        ]);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        $matrixUserId = $user->matrix_user_id ?? ($user->matrixToken ? $user->matrixToken->matrix_user_id : null);
        if (!$matrixUserId) {
            return response()->json(['error' => 'User has no Matrix account'], 400);
        }

        $success = $this->matrixService->resetUserPassword($matrixUserId, $request->new_password);
        if (!$success) {
            return response()->json(['error' => 'Failed to reset password'], 500);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully']);
    }
}