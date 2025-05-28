<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->role === 'admin') {
            return $next($request);
        }
        return response()->json(['success' => false, 'error' => 'Admin access required'], 403);
    }
}
