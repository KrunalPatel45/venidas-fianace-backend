<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $user = $request->user();

        if (
            !$user ||
            !$user->is_active ||
            !$user->isAdmin()
        ) {
            return response()->json([
                'message' => 'Unauthorized access.'
            ], 403);
        }

        return $next($request);
    }
}