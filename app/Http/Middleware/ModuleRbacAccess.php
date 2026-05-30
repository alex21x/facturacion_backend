<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthSessionService;
use Closure;

class ModuleRbacAccess
{
    public function __construct(
        private AuthSessionService $authSessionService
    ) {
    }

    public function handle($request, Closure $next, $moduleCode, $action = 'view')
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $permissions = $this->authSessionService->resolveUserPermissions((int) $authUser->id);
        $modulePermission = $permissions[$moduleCode] ?? null;

        if (!is_array($modulePermission)) {
            return response()->json([
                'message' => 'Module not found or inactive',
                'module_code' => $moduleCode,
            ], 403);
        }

        $access = [
            'view' => (bool) ($modulePermission['can_view'] ?? false),
            'create' => (bool) ($modulePermission['can_create'] ?? false),
            'update' => (bool) ($modulePermission['can_update'] ?? false),
            'delete' => (bool) ($modulePermission['can_delete'] ?? false),
            'export' => (bool) ($modulePermission['can_export'] ?? false),
            'approve' => (bool) ($modulePermission['can_approve'] ?? false),
        ];

        if (!array_key_exists($action, $access)) {
            return response()->json([
                'message' => 'Unsupported RBAC action',
                'action' => $action,
            ], 500);
        }

        $allowed = $access[$action];

        if ($action !== 'view' && $allowed) {
            $allowed = $access['view'];
        }

        if (!$allowed) {
            return response()->json([
                'message' => 'Forbidden',
                'module_code' => $moduleCode,
                'action' => $action,
            ], 403);
        }

        $request->attributes->set('rbac_module_id', null);
        $request->attributes->set('rbac_module_code', $moduleCode);
        $request->attributes->set('rbac_access', $access);

        return $next($request);
    }
}
