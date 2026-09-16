<?php

namespace App\Http\Middleware;

use App\Fleet\FleetError;
use Closure;
use Illuminate\Http\Request;

class FleetMutation
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe()) {
            $origin = $request->header('Origin');
            if ($origin && $origin !== $request->getSchemeAndHttpHost()) {
                throw new FleetError('ORIGIN_MISMATCH', '같은 관제 화면에서 요청해 주세요.', 403);
            }
            $token = $request->header('X-CSRF-TOKEN');
            if (! is_string($token) || ! hash_equals($request->session()->token(), $token)) {
                throw new FleetError('CSRF_MISMATCH', '화면을 새로고침한 뒤 다시 요청해 주세요.', 419);
            }
        }

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
