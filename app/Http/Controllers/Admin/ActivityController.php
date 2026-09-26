<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only audit trail. Normal admins see their current branch + global entries. */
class ActivityController extends Controller
{
    public const EVENTS = ['created', 'updated', 'trashed', 'restored', 'permissions', 'role_changed', 'branch_access', 'manager_changed', 'addresses', 'settings', 'test_print', 'menu_setup', 'options', 'menu_copied', 'deal_setup', 'floor_arranged', 'table_status', 'shift_opened', 'cash_movement', 'shift_closed', 'shift_reopened', 'shift_staff', 'shift_report', 'login', 'logout'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));
        $event = in_array($request->query('event'), self::EVENTS, true) ? $request->query('event') : '';
        $actor = $request->user('admin');
        $branchId = app(CurrentBranch::class)->id();

        $logs = ActivityLog::query()
            ->with(['admin', 'branch'])
            ->when(! $actor->is_super_admin, fn ($q) => $q->where(fn ($q) => $q
                ->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('subject_label', 'like', "%{$search}%")
                ->orWhereRelation('admin', 'name', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('activity/Index', [
            'logs' => ActivityLogResource::collection($logs),
            'events' => self::EVENTS,
            'filters' => ['search' => $search, 'event' => $event],
        ]);
    }
}
