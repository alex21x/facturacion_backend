<?php

namespace App\Http\Middleware;

use Closure;

class RequireAdminRole
{
    public function handle($request, Closure $next)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $isAdmin = in_array($roleCode, ['ADMIN', 'ADMINISTRADOR', 'SUPERADMIN', 'SUPER_ADMIN'], true);

        if (!$isAdmin) {
            return response()->json([
                'message' => 'No autorizado para esta operacion.',
            ], 403);
        }

        return $next($request);
    }
}
