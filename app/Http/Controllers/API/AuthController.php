<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login dan issue Sanctum token.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])
            ->where('is_active', true)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        // single session
        $user->tokens()->delete();

        $token = $user->createToken('vcf-token', [$user->role])->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token'   => $token,
            'user'    => [
                'id' => $user->id,
                'name' => $user->nama,
                'email' => $user->email,
                'jabatan' => $user->jabatan,
                'bagian' => $user->bagian,],
                'role' => $user->role,
        ]);
    }

    /**
     * Logout — hapus token aktif.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    /**
     * Kembalikan data user yang sedang login.
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => [
                'id'       => $request->user()->id,
                'nama'     => $request->user()->nama,
                'username' => $request->user()->username,
                'role'     => $request->user()->role,
            ],
        ]);
    }
}
