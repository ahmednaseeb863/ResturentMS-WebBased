<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-name based permissions. A request passes when the route is whitelisted
 * (config/permissions.php), the admin is a super admin, or their role grants
 * the route name (PermissionCatalog). Anything else → 403.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        $routeName = $request->route()?->getName();

        abort_unless($admin && $routeName && $admin->canRoute($routeName), 403);

        return $next($request);
    }
}
