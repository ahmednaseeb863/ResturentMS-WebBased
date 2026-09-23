# Restaurant MS — Project Rules

Laravel + Inertia + React (**JavaScript/JSX, no TypeScript**) + MySQL. Full plan: `docs/PLAN.md` (read only the section you need).

## Rules for every module / table / model (always apply, no need to be asked)

### 1. No permanent deletes — `Trashable`
- Every business table: `$table->trashable();` (adds `deleted_at`, `deleted_by`, `delete_reason`, `trash_batch`).
- Every business model: `use Trashable;` (`App\Models\Concerns\Trashable`). **Never** use Laravel `SoftDeletes`.
- Remove records only with `$model->trash($reason)`; bring back with `$model->restoreFromTrash()`. `delete()`, `forceDelete()`, `destroy()`, bulk `->delete()` are blocked.
- Put business rules in `canBeTrashed()` (e.g. has active children / open order) and child relations in `$trashCascade`.
- Controller: `destroy` → `trash()`, plus `Route::post('<module>/{model}/restore', …)->withTrashed()->name('<module>.restore')`. Use `ListsWithTrash` (`listTab`, `applyTab`, `tabCounts`, `trashReason`). A refused trash (`canBeTrashed()` string) throws `TrashNotAllowed` → goes back with an error flash automatically.
- Register each trashable module in `config/trash.php` (Recycle Bin).
- Unique validation: `new UniqueWithTrash('<table>', '<column>', $ignoreId, '<noun>')` (also checks trashed rows, tells the user to restore).
- Pivot links: pivot model with `Trashable` + `protected bool $logTrashActivity = false;`, synced with `TrashablePivot::sync(...)` (never `sync()`/`detach()`).
- Orders, order items, payments, refunds, shifts, cash movements: **never trashed** — void/cancel/refund/reverse. Ledgers (`stock_movements`, `*_histories`, `order_item_consumptions`, `activity_log`, `print_jobs`) are append-only.

### 2. No numeric ids outside the server — `HasPublicUuid`
- Every table shown in the UI: `$table->publicUuid();` (UUID v7, unique). `id` BIGINT stays the internal PK/FK. Pivots don't need it.
- Every such model: `use HasPublicUuid;` → route key is `uuid`, `id`/`*_id` hidden.
- Routes/URLs use `{model:uuid}` binding only. Never put an `id` in a URL, Inertia prop, form, JS, broadcast channel, or export.
- React receives data **only through a resource extending `App\Http\Resources\Resource`**: `id` = uuid, relations via `$this->ref('rel')` / `$this->refs('rel')`, `$this->trashFields()` for Trash tabs, dates via `static::iso()`. No foreign keys.
- Forms send uuids; Form Requests validate `exists:<table>,uuid` and convert to ids before saving.

### 3. Other standing conventions
- Branch data: `branch_id` + `use BelongsToBranch;` (auto-fill + current-branch scope via `App\Support\CurrentBranch`; `forBranch()` / `allBranches()` only for super-admin reports/jobs). `SetCurrentBranch` runs before route model binding, so `{model}` of another branch is a 404. In tests pick the branch with `withSession([CurrentBranch::SESSION_KEY => $branch->id])` and create records with `->forBranch($branch)`.
- People: `admins` = logins, `employees` [B] = staff (home branch, designation, optional `admin_id` login; trashing an employee trashes the login), `users` table = customers (`App\Models\Customer`, shared by all branches, phone normalized with `Customer::normalizePhone`). Pickers find waiters/riders by `designations.type` (`DesignationType` enum), never by name. Change branch access with `$admin->syncBranches($ids)` / `grantBranch($branch)` (logged).
- Child rows edited inside the parent form (e.g. customer addresses) are synced like pivots: kept rows updated, missing ones trashed, parent `$trashCascade` restores them.
- **Permissions = route names (no package).** Every signed-in route sits in the `['auth:admin', 'permission']` group and must be named. When adding routes, add them to `App\Support\Permissions\PermissionCatalog` (group → permission title → route names; e.g. "Add item" → `items.store`) and run `php artisan permissions:sync`; routes every admin may use go in `config/permissions.php` whitelist. A test fails if a protected route is not in the catalog. Super admins (`admins.is_super_admin`) pass everything. React: `const can = useCan(); can('items.store')`.
- Scope checks beyond the route (e.g. "only admins of my branches") go in the Form Request `authorize()` / controller (`$admin->canManage()`, `canAccessBranch()`).
- Activity log: our own append-only `activity_logs` (no package). Models `use LogsActivity` (created/updated diffs, ids/secrets stripped); trash/restore logged by `Trashable`; anything else `Activity::log('event', $model, [names, not ids])`.
- Settings: read via the settings resolver (branch override → global → default); never hard-code.
- Money `DECIMAL(12,2)`, quantities `DECIMAL(12,3)`; statuses are PHP backed enums.
- Financial/stock records store `business_date`; reports use it, never `created_at`.
- Multi-step money/stock writes in a DB transaction; totals recalculated on the server.
- Thin controllers → Actions (`app/Actions`, e.g. `SaveEmployee`, `SaveCustomer`); Form Requests for validation; activity log on important changes.
- UI: match the pos-react "Industry" design (`D:\laragon\www\pos-react`) exactly, reuse `resources/js/components/ui/*`, CSS in files (no inline styles), responsive + touch-friendly.
- Each module ships with Pest feature tests, including: trash/restore works, hard delete throws, no numeric id in the Inertia response (`expectNoNumericIds($response->inertiaProps())`), other-branch data not accessible. Tests run on MySQL `resturent_ms_testing`.

### 4. Frontend conventions (Phase 0 foundation)
- Pages: `resources/js/pages/<module>/<Page>.jsx`, rendered as `Inertia::render('<module>/<Page>')`. Layout is chosen in `app.jsx` (`auth/*` none, `pos/*` no sidebar, else `AppLayout`).
- Every page sets its toolbar with `<PageToolbar title primary={<Button variant="primary"/>}>secondary actions</PageToolbar>` and optional `<PageStatus>`; no toolbar context/useEffect.
- Build screens from `@/components/ui` (DataTable, Drawer, DetailPanel/InfoCards/ContactBlock, ConfirmDialog, Field/Input/Select, FormSection, PhotoUpload, CheckItem, FilterBar, TrashTabs, StatCard, Tag, StatusDot, EmptyState…). File uploads: `useForm` + `forceFormData` and `_method: 'put'` on edit; files on the `public` disk (`php artisan storage:link`), old files are kept.. List screen pattern (copy `pages/branches/Index.jsx`): `PageBody` → `TrashTabs` → `FilterBar` → `DataTable meta={…} stack`; filters with `useListQuery(filters)`; Trash tab columns `trashColumns({ onRestore })`; add/edit = `Drawer onSubmit` with `useForm`; trash = `useTrash({ noun, destroy, restore, reason })` (`trash.ask(row)`, `{trash.dialog}`).
- CSS: pos-react classes are ported verbatim (`resources/css/*`, same names — reuse them); new styles go in `ui.css` / a page css file, breakpoints in `responsive.css`. Inline `style` only for data-driven CSS variables (ESLint warns).
- Sidebar menu: `resources/js/lib/nav.js` — items auto-enable when their Ziggy route exists and hide when the admin lacks that route permission. Money/qty/dates via `@/lib/format`.
- Tests: `loginSuperAdmin()`, `loginAdminWithRoutes(['items.index', …], $branch)`, factories `Admin/Branch/Role` (`Role::factory()->withRoutes(...)`). Trait fixtures live in `tests/Fixtures`.
- Checks before finishing: `npm run lint`, `npm run build`, `./vendor/bin/pint`, `./vendor/bin/pest`. Design gallery: `/dev/ui` (local only).
