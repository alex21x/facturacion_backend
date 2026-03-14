<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

class ModuleRbacAccess
{
    public function handle($request, Closure $next, $moduleCode, $action = 'view')
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $module = DB::table('appcfg.modules')
            ->select('id', 'code', 'status')
            ->where('code', $moduleCode)
            ->where('status', 1)
            ->first();

        if (!$module) {
            return response()->json([
                'message' => 'Module not found or inactive',
                'module_code' => $moduleCode,
            ], 403);
        }

        $roleAccess = DB::table('auth.role_module_access as rma')
            ->join('auth.user_roles as ur', 'ur.role_id', '=', 'rma.role_id')
            ->where('ur.user_id', $authUser->id)
            ->where('rma.module_id', $module->id)
            ->selectRaw('COALESCE(bool_or(rma.can_view), false) as can_view')
            ->selectRaw('COALESCE(bool_or(rma.can_create), false) as can_create')
            ->selectRaw('COALESCE(bool_or(rma.can_update), false) as can_update')
            ->selectRaw('COALESCE(bool_or(rma.can_delete), false) as can_delete')
            ->selectRaw('COALESCE(bool_or(rma.can_export), false) as can_export')
            ->selectRaw('COALESCE(bool_or(rma.can_approve), false) as can_approve')
            ->first();

        $userOverride = DB::table('auth.user_module_overrides')
            ->where('user_id', $authUser->id)
            ->where('module_id', $module->id)
            ->first();

        $access = [
            'view' => $this->resolveFlag($userOverride, $roleAccess, 'can_view'),
            'create' => $this->resolveFlag($userOverride, $roleAccess, 'can_create'),
            'update' => $this->resolveFlag($userOverride, $roleAccess, 'can_update'),
            'delete' => $this->resolveFlag($userOverride, $roleAccess, 'can_delete'),
            'export' => $this->resolveFlag($userOverride, $roleAccess, 'can_export'),
            'approve' => $this->resolveFlag($userOverride, $roleAccess, 'can_approve'),
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

        $request->attributes->set('rbac_module_id', $module->id);
        $request->attributes->set('rbac_module_code', $module->code);
        $request->attributes->set('rbac_access', $access);

        return $next($request);
    }

    private function resolveFlag($userOverride, $roleAccess, string $column): bool
    {
        if ($userOverride && $userOverride->{$column} !== null) {
            return (bool) $userOverride->{$column};
        }

        if ($roleAccess && $roleAccess->{$column} !== null) {
            return (bool) $roleAccess->{$column};
        }

        return false;
    }
}
