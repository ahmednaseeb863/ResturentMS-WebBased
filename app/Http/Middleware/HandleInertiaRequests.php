<?php

namespace App\Http\Middleware;

use App\Http\Resources\AuthAdminResource;
use App\Http\Resources\BranchOptionResource;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page. Everything here must be uuid-only — never
     * numeric ids (CLAUDE.md §2). `shift` / `business_date` arrive in Phase 7.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $admin = $request->user('admin');
        $current = app(CurrentBranch::class);

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
            ],
            'auth' => [
                'user' => $admin ? (new AuthAdminResource($admin->loadMissing('role')))->resolve() : null,
                // can(routeName) on the client: `all` for super admins, else the granted route names.
                'permissions' => $admin ? [
                    'all' => $admin->is_super_admin,
                    'routes' => $admin->is_super_admin ? [] : [
                        ...config('permissions.whitelist', []),
                        ...$admin->allowedRouteNames(),
                    ],
                ] : null,
            ],
            'context' => [
                'branch' => $current->get() ? (new BranchOptionResource($current->get()))->resolve() : null,
                'branches' => $admin ? BranchOptionResource::collection($current->available())->resolve() : [],
                'shift' => null,
                'business_date' => null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
