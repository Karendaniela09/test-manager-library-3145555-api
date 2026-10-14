<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Security\JwtToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, JwtToken $tokens): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ]);
        $username = mb_strtolower(trim($data['username']));
        $user = DB::table('library.user')->where('username', $username)->first();

        if ($user === null || ! Hash::check($data['password'], $user->password_hash)) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Usuario o contraseña inválidos.',
                'details' => [],
            ], 401);
        }

        return response()->json([
            'access_token' => $tokens->issue($user->id, $user->role),
            'token_type' => 'Bearer',
            'expires_in' => JwtToken::TTL_SECONDS,
            'role' => $user->role,
        ]);
    }
}
