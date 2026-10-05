<?php

namespace App\Http\Middleware;

use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
| The two endpoints that write need a user: the bearer token is checked here, before the controller and so before
| the post id is looked at (SPEC.md: auth first, then the id). The user is put on the request for the controller.
*/
class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Jwt::user($request->header('Authorization'));
        if (is_string($user)) {
            return response()->json(['error' => $user], 401);
        }
        $request->attributes->set('user_id', $user[0]);
        $request->attributes->set('username', $user[1]);

        return $next($request);
    }
}
