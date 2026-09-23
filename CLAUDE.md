# Restaurant MS — Project Rules

Laravel + Inertia + React (**JavaScript/JSX, no TypeScript**) + MySQL. Full plan: `docs/PLAN.md` (read only the section you need).

## Rules for every module / table / model (always apply, no need to be asked)

### 1. No permanent deletes — `Trashable`
- Every business table: `$table->trashable();` (adds `deleted_at`, `deleted_by`, `delete_reason`, `trash_batch`).
- Every business model: `use Trashable;` (`App\Models\Concerns\Trashable`). **Never** use Laravel `SoftDeletes`.
- Remove records only with `$model->trash($reason)`; bring back with `$model->restoreFromTrash()`. `delete()`, `forceDelete()`, `destroy()`, bulk `->delete()` are blocked.
- Put business rules in `canBeTrashed()` (e.g. has active children / open order) and child relations in `$trashCascade`.
- Controller: `destroy` → `trash()`, plus a `restore` route. UI list: **Trash tab + Restore**; ask a reason where required.
- Permissions: `<module>.trash`, `<module>.restore`.
- Unique validation must also check trashed rows → offer "restore existing".
- Orders, order items, payments, refunds, shifts, cash movements: **never trashed** — void/cancel/refund/reverse. Ledgers (`stock_movements`, `*_histories`, `order_item_consumptions`, `activity_log`, `print_jobs`) are append-only.

### 2. No numeric ids outside the server — `HasPublicUuid`
- Every table shown in the UI: `$table->publicUuid();` (UUID v7, unique). `id` BIGINT stays the internal PK/FK. Pivots don't need it.
- Every such model: `use HasPublicUuid;` → route key is `uuid`, `id`/`*_id` hidden.
- Routes/URLs use `{model:uuid}` binding only. Never put an `id` in a URL, Inertia prop, form, JS, broadcast channel, or export.
- React receives data **only through a `JsonResource`**: `id` = uuid, relations as `{ id: uuid, … }`, no foreign keys.
- Forms send uuids; Form Requests validate `exists:<table>,uuid` and convert to ids before saving.

### 3. Other standing conventions
- Branch data: `branch_id` + `use BelongsToBranch;` (auto-fill + current-branch scope). Policies check permission **and** branch access.
- Settings: read via the settings resolver (branch override → global → default); never hard-code.
- Money `DECIMAL(12,2)`, quantities `DECIMAL(12,3)`; statuses are PHP backed enums.
- Financial/stock records store `business_date`; reports use it, never `created_at`.
- Multi-step money/stock writes in a DB transaction; totals recalculated on the server.
- Thin controllers → Actions/Services; Form Requests for validation; activity log on important changes.
- UI: match the pos-react "Industry" design (`D:\laragon\www\pos-react`) exactly, reuse `resources/js/components/ui/*`, CSS in files (no inline styles), responsive + touch-friendly.
- Each module ships with Pest feature tests, including: trash/restore works, hard delete throws, no numeric id in the Inertia response, other-branch data not accessible.
