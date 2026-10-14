<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Security\JwtToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateLibraryToken
{
    public function __construct(private readonly JwtToken $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $claims = $token ? $this->tokens->verify($token) : null;

        if ($claims === null) {
            throw new ApiException('UNAUTHENTICATED', 'Autenticación requerida.', 401);
        }

        $user = DB::table('library.user')->where('id', $claims['sub'])->first();

        if ($user === null || $user->role !== $claims['role']) {
            throw new ApiException('UNAUTHENTICATED', 'Autenticación requerida.', 401);
        }

        $request->attributes->set('library_user', $user);

        return $next($request);
    }
}
