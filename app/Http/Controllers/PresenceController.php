<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PresenceController extends Controller
{
    /**
     * Handle the presence heartbeat request.
     *
     * Updates the authenticated user's online_status and last_seen_at
     * to keep their presence fresh even when idle on a page.
     */
    public function heartbeat(Request $request): JsonResponse
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

        return response()->json([
            'success' => true,
        ]);
    }
}