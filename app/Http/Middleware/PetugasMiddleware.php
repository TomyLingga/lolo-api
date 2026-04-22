<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PetugasMiddleware
{
    /**
     * Role yang diizinkan sebagai petugas operasional.
     * Admin juga boleh mengakses endpoint petugas.
     */
    protected array $allowedRoles = [
        'admin',
        'petugas'
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $this->allowedRoles)) {
            return response()->json([
                'message' => 'Akses ditolak. Role tidak diizinkan.',
            ], 403);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun tidak aktif.',
            ], 403);
        }

        return $next($request);
    }
}
