<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'Forbidden, admin access required'], 403);
        }
        return $next($request);
    }
}