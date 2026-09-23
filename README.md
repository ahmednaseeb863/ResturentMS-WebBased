# Restaurant MS

Multi-branch restaurant management system — Laravel 13 · Inertia v3 · React 19 (JSX) · MySQL · Tailwind v4, with the "Industry" design ported from `pos-react`.

- Plan: [docs/PLAN.md](docs/PLAN.md)
- Project rules: [CLAUDE.md](CLAUDE.md)

## Setup

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
# MySQL databases: resturent_ms and resturent_ms_testing (utf8mb4)
php artisan migrate --seed   # permission catalog, Main Branch, default roles, super admin
npm run build        # or: npm run dev
```

Seeded sign-in: `superadmin` / `password` (PIN `1234`) — set `SUPERADMIN_*` in `.env` before seeding, and change them after the first login.

After adding routes to the permission catalog (`app/Support/Permissions/PermissionCatalog.php`): `php artisan permissions:sync`.

Served by Laragon at `http://resturent-ms.test` (reload Laragon once to create the host), or `php artisan serve`.

## Checks

```bash
./vendor/bin/pest
./vendor/bin/pint
npm run lint
npm run build
```

Design-system gallery (local only): `/dev/ui`.
