<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Auth\AuthenticationException;

class AuthenticateApiRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {

        $token = $request->bearerToken();

        if (!$token && $request->has('token')) {
            $token = $request->query('token');
        }

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken && $accessToken->tokenable) {
                auth()->guard('sanctum')->setUser($accessToken->tokenable);
            } else {

                throw new AuthenticationException('Unauthenticated.');
            }
        } else {

            throw new AuthenticationException('Unauthenticated.');
        }

        return $next($request);
    }
}