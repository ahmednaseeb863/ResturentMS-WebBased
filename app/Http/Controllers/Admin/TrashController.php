<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Resource;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recycle Bin: trashed records of every module listed in config/trash.php.
 * Branch-scoped models are automatically limited to the current branch.
 */
class TrashController extends Controller
{
    private const PER_PAGE = 20;

    private const MAX_PER_MODULE = 500;

    public function index(Request $request): Response
    {
        $modules = config('trash.modules');
        $module = array_key_exists((string) $request->query('module'), $modules) ? $request->query('module') : '';
        $search = trim((string) $request->query('search'));

        $rows = collect($modules)
            ->when($module !== '', fn ($c) => $c->only($module))
            ->flatMap(fn (array $config, string $key) => $this->trashedOf($key, $config, $search))
            ->sortByDesc('deleted_at')
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('trash/Index', [
            'items' => [
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ],
            ],
            'modules' => collect($modules)->map(fn (array $c, string $key) => ['value' => $key, 'label' => $c['label']])->values(),
            'filters' => ['module' => $module, 'search' => $search],
        ]);
    }

    public function restore(Request $request, string $module, string $uuid): RedirectResponse
    {
        $config = config("trash.modules.{$module}") ?? abort(404);

        /** @var Model $model */
        $model = $config['model']::query()->onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        if ($model instanceof Admin) {
            abort_unless($request->user('admin')->canManage($model), 403);
        }

        $model->restoreFromTrash();

        return back()->with('success', "{$config['label']} “{$model->trashLabel()}” restored.");
    }

    private function trashedOf(string $key, array $config, string $search): array
    {
        return $config['model']::query()
            ->onlyTrashed()
            ->with('deletedBy')
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($config, $search) {
                foreach ($config['search'] as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }
            }))
            ->latest('deleted_at')
            ->limit(self::MAX_PER_MODULE)
            ->get()
            ->map(fn (Model $m) => [
                'id' => $m->uuid,
                'module' => $key,
                'module_label' => $config['label'],
                'name' => $m->trashLabel(),
                'deleted_at' => Resource::iso($m->deleted_at),
                'deleted_by' => $m->deletedBy?->name,
                'delete_reason' => $m->delete_reason,
            ])
            ->all();
    }
}
