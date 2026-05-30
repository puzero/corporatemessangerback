<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class CheckBlocked
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->is_blocked) {
            $request->user()->currentAccessToken()->delete();
            return response()->json([
                'error' => 'Your account has been blocked.'
            ], 403);
        }
        return $next($request);
    }
}