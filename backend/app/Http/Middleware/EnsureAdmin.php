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
    ): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin' || $user->status !== 'active') {
            return response()->json([
                'message' => 'Accès réservé aux administrateurs.'
            ], 403);
        }

        return $next($request);
    }
}
