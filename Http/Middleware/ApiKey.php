<?php

namespace Modules\Repile\Http\Middleware;

use Closure;
use Modules\Repile\Support\Settings;

class ApiKey
{
    public function handle($request, Closure $next)
    {
        $expected = (string) \Option::get('repile.api_key');
        $given = (string) ($request->header('X-FreeScout-API-Key') ?: $request->bearerToken());

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid Repile API key'], 401);
        }

        return $next($request);
    }
}
