<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLibraryRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('library_user');

        if ($user === null || ! in_array($user->role, $roles, true)) {
            throw new ApiException(
                'FORBIDDEN_ROLE',
                'No tiene permiso para esta operación.',
                403,
            );
        }

        return $next($request);
    }
}
