<?php

namespace App\Http\Middleware;

use App\Fleet\FleetError;
use Closure;
use Illuminate\Http\Request;

class FleetAgent
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('fleet.agent_token');
        $provided = $request->header('X-Fleet-Agent-Token');

        if (! is_string($expected) || $expected === ''
            || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            throw new FleetError('AGENT_UNAUTHORIZED', '로봇 실행기 인증에 실패했습니다.', 401);
        }

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
