<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
            'role' => ['required', 'in:librarian'],
        ]);
        $user = [
            'id' => (string) Str::uuid(),
            'username' => mb_strtolower(trim($data['username'])),
            'password_hash' => Hash::make($data['password']),
            'role' => 'librarian',
        ];
        DB::table('library.user')->insert($user);

        return response()->json([
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ], 201);
    }
}
