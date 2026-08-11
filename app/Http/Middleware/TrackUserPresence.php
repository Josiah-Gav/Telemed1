<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackUserPresence
{
    /**
     * Handle an incoming request.
     *
     * Updates the authenticated user's online_status and last_seen_at
     * on every request to keep presence tracking accurate.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user) {
            DB::table('users')
                ->where('user_id', $user->user_id)
                ->update([
                    'online_status' => 'online',
                    'last_seen_at' => now(),
                ]);
        }

        return $next($request);
    }
}