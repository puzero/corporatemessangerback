<?php

namespace App\Http\Middleware;

use App\Models\UserMatrixToken;
use Closure;
use Illuminate\Http\Request;

class MatrixAccessToken
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $tokenRecord = UserMatrixToken::where('user_id', $user->id)->first();
        if (!$tokenRecord || !$tokenRecord->access_token) {
            return response()->json(['error' => 'User matrix token not found.'], 403);
        }

        $request->attributes->set('matrix_access_token', $tokenRecord->access_token);
        $request->attributes->set('matrix_user_id', $tokenRecord->matrix_user_id);

        return $next($request);
    }
}