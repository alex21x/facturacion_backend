<?php

namespace App\Http\Middleware;

use Closure;

class EnsureCompanyScope
{
    public function handle($request, Closure $next)
    {
        $authUser = $request->attributes->get('auth_user');
        if (!$authUser || !isset($authUser->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $authCompanyId = (int) $authUser->company_id;
        $requestedCompany = $request->query('company_id', $request->input('company_id'));
        $resolvedCompanyId = ($requestedCompany === null || $requestedCompany === '')
            ? $authCompanyId
            : (int) $requestedCompany;

        if ($resolvedCompanyId !== $authCompanyId) {
            return response()->json(['message' => 'Unauthorized company scope'], 403);
        }

        $request->attributes->set('resolved_company_id', $resolvedCompanyId);

        return $next($request);
    }
}
