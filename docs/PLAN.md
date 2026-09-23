# Restaurant Management System — Project Plan

**Stack:** Laravel (latest) · React (JavaScript/JSX, no TypeScript) · Inertia.js · MySQL 8 · Tailwind CSS v4 + the **"Industry" design system from `D:\laragon\www\pos-react`** (see §8 UI)
**Target:** a mid-size restaurant business with **multiple branches**, managed centrally by a Super Admin.

---

## 1. Goals & Scope

A system to run daily restaurant operations for every branch from one place:

- **Multiple branches**: the Super Admin creates branches and allocates managers; each branch has its own staff, tables, counters, printers, stock, shifts, and sales
- Take orders from the **cashier POS** or the **waiter app** (waiter's phone/tablet): dine-in (table + waiter), takeaway, and delivery (with rider)
- Send orders to the kitchen in real time: **kitchen screens (KDS) and printed kitchen tickets (thermal)**
- Manage tables, customers, employees, riders, and reservations
- **Each branch has its own menu** (menu items, ready items, deals, discounts) managed by the branch manager
- **Three kinds of items** (see §2.1): **raw materials** (kitchen stock), **ready items** (sold as-is, stock kept), **menu items** (prepared, no stock — sales only)
- **Two-level settings for everything**: one **global** settings set by the Super Admin + **branch-level** settings that override it
- Bill with **tax-exclusive prices** — tax is added on the **total bill** for all orders, using the branch's tax setting; **service charge on dine-in** can be turned on/off in settings
- Take payments: **cash** or **bank transfer** (multiple bank accounts)
- Run **shifts (including overnight)** on **multiple cash counters** per branch and manage the cash in each drawer
- **Inventory in v1**: stock of raw materials and ready items; a recipe on every menu item with **cook-verified consumption** (the cook confirms or adjusts the quantities used, then raw material stock is deducted); ready items deducted when sold; purchases, suppliers, waste, stock counts
- Expenses, dashboards, and reports per branch and for all branches combined

**Hosting:** one central server for all branches (cloud). Each branch reaches it through the browser.

**Out of scope for v1:** offline mode (working without internet), online ordering website, customer mobile app, QR self-ordering, third-party delivery platforms, card/wallet payment gateways, accounting integration, payroll, stock transfers between branches, sub-recipes / production batches.
The schema is prepared for the **customer app** (customers live in the `users` table so they can log in later).

---

## 2. Branches

### What belongs where
| Shared across all branches (global) | Per branch |
|---|---|
| Admin accounts, roles & permissions, designations | Employees (home branch), manager allocation |
| Customers (`users`) and their addresses | Areas & tables, reservations |
| Units of measure (kg, g, L, ml, pcs) | **Menu**: categories, menu items, ready items, variants, modifiers, recipes, deals, discounts |
| Suppliers | **Raw materials & ready items with their stock**, purchases, waste, stock counts |
| Bank accounts (with a "which branches" selection) | Kitchen stations, printers, cash counters |
| **Global settings** (all groups, set by Super Admin) | **Branch settings** (same groups; any value set here overrides the global one) |
| | Shift types, shifts, cash movements |
| | Orders, payments, refunds, deliveries, delivery zones, expenses |

The Super Admin can **copy a menu (with recipes, raw materials, and ready items — without stock) from one branch to another** when setting up a new branch; after that, each branch manager maintains their own.

### 2.1 Item types
| Type | What it is | Examples | Sold on POS? | Stock kept? | How stock goes down |
|---|---|---|---|---|---|
| **Raw material** | Kitchen material used to prepare menu items | chicken, flour, cheese, oil, buns, packaging | No | **Yes** | When the cook **confirms consumption** of a prepared menu item (recipe quantities, adjustable) |
| **Ready item** | Bought or made ready, served to the customer as-is | soft drinks, water, juice boxes, packaged desserts | **Yes** | **Yes** | When the order is **sent/placed** (sold); returned if voided before serving |
| **Menu item** | Prepared in the kitchen from raw materials | Zinger burger, pizza, biryani | **Yes** | **No** — only sales are tracked | Its **recipe** consumes raw materials |

- Menu items and ready items share the same **POS categories**, so the POS shows them together (e.g. a "Drinks" category with ready items).
- A ready item shows its **stock on the POS tile** and is blocked/warned when out of stock (setting).
- **Deals** can contain both menu items and ready items (e.g. *Burger + fries + 1.5L drink*).
- Both raw materials and ready items are bought through **purchases** / **add stock**, and use the same stock ledger, waste, and stock count screens.

### Branch access rules
- **Super Admin**: sees and manages every branch; can switch branch or view **"All branches"** in reports and dashboard.
- **Super Admin allocates a manager** to a branch (`branches.manager_id`). The manager gets access to that branch automatically.
- Any admin account can be given access to one or more branches (`admin_branch`), e.g. an area manager covering two branches.
- A user who has access to several branches picks the **current branch** (branch switcher in the toolbar). It is saved in the session, and all data is filtered by it.
- Implementation: a `BelongsToBranch` trait adds `branch_id`, fills it automatically, and applies a **global query scope** for the current branch, so a query can never leak another branch's data. Policies also check branch access.

---

## 3. People, Accounts & Access

| Table | Who | Logs in? |
|---|---|---|
| `admins` | Everyone who uses the system: the **Super Admin** and **every employee's login account** | Yes — `admin` guard |
| `employees` | Staff records (HR info) with a **designation** and a **home branch**; each is linked to one `admins` account | Through their linked admin account |
| `users` | **Customers** (walk-in, dine-in, delivery), shared across branches. Password optional now; for the future customer app | Not in v1 (`web` guard reserved) |

```
branches ──1:N── employees ──N:1── designations (Manager, Cashier, Waiter, Chef, Rider, …)
   │                 │ 1:1
   │ N:N             admins (login, roles/permissions)
   └──── admin_branch ┘

users (customers) ──1:N── orders
```

### Designation vs Role
- **Designation** = job title (Waiter, Chef, Rider, Cashier, Manager…). Used for pickers: the POS waiter list shows the branch's *Waiter* employees, the rider list shows *Rider* employees.
- **Role** = what the admin account may do (spatie/laravel-permission, `admin` guard). A designation has a **default role** used when the login is created; it can be changed.
- **Super Admin** = `admins.is_super_admin`, bypasses all permission and branch checks. No employee record needed.
- Effective access = **role permissions** × **allowed branches**.

### Default roles
| Role | Access |
|---|---|
| **Super Admin** | Everything, all branches: branches, managers, global settings & tax, branch print setup, roles, reports |
| **Manager** | Their branch(es): **menu, recipes, deals, discounts**, raw materials & stock, employees, tables, void/refund/discount approval, shifts, expenses, branch settings, reports |
| **Cashier** | POS, billing, payments, open/close own shift, cash in/out, customers |
| **Waiter** | Waiter app: tables, create/add to dine-in orders, send to kitchen, request bill |
| **Kitchen (Cook)** | Kitchen Display: mark items preparing/ready, **confirm/adjust raw material used**, reprint tickets |
| **Rider** | Rider panel: assigned deliveries, picked up/delivered, cash to settle |
| **Storekeeper** *(optional)* | Inventory: raw materials, ready items stock, add stock, purchases, stock counts, waste |

Permissions are granular (e.g. `orders.create`, `orders.void`, `orders.discount`, `shifts.close`, `stock.adjust`, `reports.view`) and editable by the Super Admin. Sensitive actions (void, refund, large discount, removing service charge, reopening a shift) can require a **manager PIN**.

---

## 4. Modules

### 4.1 Authentication
- Admin login (email/username + password); **PIN quick login / switch user** on shared POS, kitchen, and waiter devices
- Branch switcher after login for multi-branch users
- Rate-limited login, active/inactive accounts, activity/audit log (spatie/laravel-activitylog)

### 4.2 Branches *(Super Admin)*
- Branch: code, name, address, phone, email, tax/NTN number, logo override, active
- **Allocate manager** (from employees) and grant extra admin access
- Branch settings page (see 4.5)

### 4.3 Employees & Designations
- Designations CRUD (name, default role)
- Employee: code, name, phone, CNIC, address, photo, designation, **home branch**, joining date, salary (info only), status
- Create/manage the linked login (email/username, password, PIN, role, extra branches) from the employee page
- Deactivating an employee deactivates their login

### 4.4 Customers (`users`)
- Name, phone (unique, main POS lookup), email (optional), password (nullable), birthday, notes
- Multiple delivery addresses
- Quick create from POS/waiter app by phone; walk-in orders can have no customer
- Profile: order history across all branches, total spent, visits, last visit

### 4.5 Settings — two levels: Global + Branch
**Every setting exists at two levels:**
1. **Global settings** — one set, managed by the **Super Admin**; the default for all branches.
2. **Branch settings** — the **same groups and fields** for each branch. A field left on **"Use global"** follows the global value; a field given its own value **overrides** the global one for that branch only.

```
value used = branch value (if overridden)  →  else global value  →  else built-in default
```
- The branch settings page shows each field with a **"Use global / Override"** switch and the current global value next to it, so it is always clear where a value comes from.
- Super Admin edits global settings and any branch's settings; a manager can edit their branch's settings only if given `settings.branch.manage` (per group, e.g. allow receipt text but not tax).
- Changing a global value instantly applies to every branch that has not overridden it.
- Settings are cached per branch and cleared when a global or branch value changes.

**Setting groups** (same list at both levels):

| Group | Fields |
|---|---|
| **General** | business/branch display name, logo, phone, address, NTN, currency & symbol, timezone, date/time format |
| **Orders** | order types on/off (dine-in, takeaway, delivery), order number format & daily reset, business day cutoff (default `05:00`), hold orders on/off, require customer for delivery |
| **Tax** | tax on/off, tax name (e.g. "GST"), rate % — see 4.5.1 |
| **Service charge** | on/off, % (dine-in only), can be removed per order (with permission) |
| **Delivery** | default delivery fee, minimum order amount, use delivery zones on/off |
| **Payments** | cash on/off, bank transfer on/off, require reference no. / screenshot for transfers, rounding rule (none / nearest 1 / 5 / 10) |
| **Receipt** | header text, footer text, show logo / NTN / waiter / table / customer / tax line / bank accounts, paper width (58/80mm), copies |
| **Printing** | method (**QZ Tray** / **browser print**), KDS on/off, KOT printing on/off (both allowed), auto-print KOT on send, print void slips, KOT copies |
| **Kitchen** | ticket colour timers (amber after X min, red after Y min), confirm consumption on ready on/off |
| **Inventory** | allow negative stock, block/warn when a ready item is out of stock, auto-confirm unconfirmed consumption (order completion / shift close / never), low-stock alerts on/off |
| **Shifts** | blind close on/off, require denomination count, max cash difference before manager approval |
| **Security & approvals** | manager PIN required for: void, refund, discount above X %, removing service charge, reopening shift |

Physical things that only make sense per branch (printers, cash counters, kitchen stations, tables) are **records**, not settings; they are managed on their own screens.

#### 4.5.1 Tax (for now)
- Prices are always **tax-exclusive**. Tax is calculated on the **total bill** and applies to **all orders** — every order type and payment method.
- **Tax base = the whole bill after discount** (items − discounts + service charge + delivery fee).
- Rate and on/off come from the branch's settings (branch override → else global).
- The tax name and rate used are **saved on the order**, so changing the settings never changes old bills.
- Designed so per-order-type / per-payment-method rules or multiple taxes can be added later without changing old data.

#### 4.5.2 Printing & receipts
- Printing method, receipt template, and kitchen print options come from the **Receipt** and **Printing** setting groups (global + branch override).
- **Printers** (per branch records): name, type (receipt / kitchen), connection (USB / network), device name or IP, paper width, linked cash counter or kitchen station, **Test print** button.

### 4.6 Bank Accounts
- Bank name, account title, account number, IBAN, active, show-on-receipt, **available at branches** (pivot)
- Used for bank-transfer payments, expenses, and supplier payments; reports show totals per account

### 4.7 Menu *(per branch, managed by the branch manager)*
- **Categories** (sort order, image, active, kitchen station) — shared by menu items and ready items on the POS
- **Menu items** (prepared, **no stock**, sales tracked): name, description, image, price, category, kitchen station, active, "sold out today", prep time, available for dine-in/takeaway/delivery
- **Ready items** (sold as-is, **stock kept**): name, code/barcode, image, category, sale price, cost, stock unit (usually pcs), purchase unit (e.g. crate of 24), current stock, low-stock alert level, active, available for dine-in/takeaway/delivery, optional kitchen station (only if it must go through the kitchen/bar)
- **Recipe (raw materials used)** — set while adding/editing the item: pick raw materials and the quantity one serving uses (e.g. *Zinger Burger → bun 1 pcs, chicken fillet 150 g, mayo 20 g, lettuce 15 g*)
- **Variants / sizes** with their own price and **their own recipe quantities** (Large pizza uses more cheese than Small)
- **Modifier groups & modifiers** (add-ons) with min/max selection, price, and **optional raw materials** (e.g. *Extra cheese → cheese 30 g*)
- **Kitchen stations** (Grill, Fryer, Drinks, Desserts); each station has a KDS screen and/or a printer
- **Copy menu from another branch** (Super Admin), including recipes — raw materials and ready items are matched by name or created in the target branch (with zero stock)

### 4.8 Deals & Discounts *(per branch)*
- **Deals / combos**: fixed price bundle with fixed items and "choose any 1 from…" slots; slots can hold **menu items and ready items**. Menu items inside a deal consume raw materials through their recipes; ready items inside a deal reduce their own stock
- Availability: date range, days of week, time window, order types
- **Discounts**: percent or fixed, on order or item, manual (permission + reason) or predefined

### 4.9 Tables & Floor *(per branch)*
- Areas (Hall, Rooftop, Family), tables (name, capacity, position, shape)
- Status: `available` · `occupied` · `reserved` · `cleaning`
- Open table, **transfer** order, **merge** tables, split bill

### 4.10 POS (cashier) *(core module)*
- Order types (enabled per branch): **Dine-in** (table + waiter + guests), **Takeaway**, **Delivery** (customer + address + rider)
- Category tabs, item grid (**menu items + ready items**; ready items show stock left), search / barcode scan, cart, modifier/deal dialogs, notes per item
- Ready items that don't need the kitchen go straight to the order (no KOT); their stock is deducted when the order is placed
- Customer lookup by phone with quick-add
- **Send to kitchen** → KOT on the KDS and/or printer; later items create new KOTs on the same order
- Hold / recall, void after sending (reason + permission), take payment, print receipt
- Requires the cashier's **shift to be open on their cash counter**

### 4.11 Waiter App *(new — responsive web app / PWA for phones & tablets)*
- PIN login on the waiter's device; shows only their branch
- **Table grid** with live status → open table → pick items (same menu, modifiers, deals) → **send to kitchen**
- Add more items later, see which items are ready (real-time), mark served
- **Request bill**: prints a pre-bill / sends it to the cashier; payment is taken at a cash counter
- Waiter orders need the branch to have **at least one open shift**; the order is paid in whichever counter shift takes the payment

### 4.12 Kitchen: KDS + Kitchen Tickets + Consumption
- **KDS**: live board per station, colour by waiting time, item status `pending → preparing → ready → served`, bump/recall tickets
- **KOT printing**: each station's items print on that station's thermal printer when sent (and reprint on demand); voided items print a *VOID* slip
- Ready notifications to the POS and waiter app (Laravel Reverb)
- **Confirm consumption** when marking an item/ticket **ready**: see 4.16 — the cook sees the raw materials pre-filled from the recipe and taps **Confirm**, or adjusts quantities up/down first

### 4.13 Billing & Payments
- Prices are **tax-exclusive**; tax is calculated on the **total bill**. Bill calculation (in `OrderPricingService`):
  ```
  items total      = Σ (unit price + modifiers) × qty − item discounts
  order discount   = manual/predefined discount on the order
  net              = items total − order discount
  service charge   = net × service% (dine-in only, if enabled for the branch and not removed)
  delivery fee     = zone/flat fee (delivery only)
  tax base         = net + service charge + delivery fee          (the whole bill)
  tax              = tax base × tax rate          (if tax is on; rate from branch → global settings)
  grand total      = tax base + tax ± round-off  (round-off from the Payments setting)
  ```
- The same tax applies to every order type and payment method (for now).
- Payment methods: **Cash** and **Bank Transfer** (choose account, reference no., optional screenshot)
- **Split payment** (cash + transfer) and **split bill** (by items or equally)
- Refunds / partial refunds (manager), recorded in the current shift
- **Thermal receipt** printing (58/80mm), pre-bill, reprint; PDF invoice download

### 4.14 Riders & Delivery
- Riders = employees with *Rider* designation, own login and mobile **rider panel**
- Status: `pending → assigned → out_for_delivery → delivered / failed / returned`
- Delivery fee flat or by delivery zone (per branch)
- **COD cash** tracked as *held by rider* until **settled** at a cash counter (recorded in that shift). Unsettled rider cash is shown on the dashboard and blocks shift close unless a manager carries it over.

### 4.15 Shifts, Cash Counters & Cash Management
See §6.
- Multiple **cash counters** per branch, each with its own drawer, receipt printer, and shift
- Shift types per branch, e.g. **Morning 11:00–19:00**, **Night 19:00–04:00** (overnight)
- Open with float, cash in/out, safe drops, rider settlements, paid-out expenses, staff on duty
- Close with denomination count, over/short, handover; **X-report** and **Z-report** (thermal + PDF)

### 4.16 Inventory *(v1, per branch)*

Stock is kept for **raw materials** and **ready items**. **Menu items have no stock** — they only record sales, and consume raw materials through their recipe.

**Raw materials**
- Add raw materials: name, code, category (Meat, Dairy, Vegetables, Packaging…), **stock unit** (kg, g, L, ml, pcs), purchase unit (optional, e.g. *carton of 12*), low-stock alert level, cost per unit
- **Opening stock** when adding; after that, stock changes only through recorded movements
- Units convert automatically (kg ↔ g, L ↔ ml, carton ↔ pcs), so a recipe in grams deducts correctly from stock kept in kg

**Ready items** (defined in the menu, 4.7)
- **Opening stock** when adding; stock in through **add stock** or **purchases**
- **Sold** → stock goes down when the order is placed / sent (inside a deal too)
- **Voided** before being served → stock returned; after being served → choose *wasted* (stays deducted) or *returned*
- Out of stock → POS warns or blocks (setting)

**Adding stock (both types)**
- **Add stock** (quick: item, quantity, unit cost, supplier optional, note)
- **Purchases** (supplier invoice with many lines mixing raw materials and ready items)
- Average cost updates on every stock-in

**Consumption flow for menu items — cook-verified**
```
Menu item recipe          Order sent to kitchen          Cook marks item READY              Raw material
(set by manager)    ──►   KOT / KDS shows item     ──►   "Confirm consumption" panel  ──►  stock deducted
bun 1 pcs                 (no stock change yet)          bun 1 · chicken 150g · mayo 20g    (actual qty)
chicken 150 g                                            cook taps Confirm, or ± adjusts
mayo 20 g                                                (e.g. chicken 170 g) then Confirm
```
- Expected quantity = recipe × item quantity (+ modifier materials, + variant quantities, + deal item recipes).
- The cook can **adjust each quantity up or down** and optionally add a reason (e.g. "bigger fillet"); they can also add a raw material that wasn't in the recipe.
- On **Confirm**, raw material stock is deducted by the **actual** quantities and both expected and actual are saved → **variance report** (over/under use per item, per cook, per day).
- **Confirm all** button for a whole ticket when nothing needs changing, so the kitchen stays fast.
- **No stock change before the item is prepared**: voiding before ready needs no return. Voiding after it was prepared asks whether the food is **wasted** (stays deducted, logged as waste) or reused.
- Kitchens working only from printed tickets: unconfirmed items are confirmed with recipe quantities automatically at order completion or shift close (setting) and appear in a **"Pending consumption"** list a manager can review and adjust.

**Other stock operations (both types)**
- **Suppliers** (shared across branches), supplier payments (cash from shift or bank account), purchase returns
- **Waste / damage** entries with reason; **stock counts** (physical count → variance → manager approves adjustment)
- **Low-stock alerts** on the dashboard and stock lists
- **Stock ledger** per raw material / ready item: every in/out with date, reason, who, and balance after
- Reports: stock levels, raw material consumption (expected vs actual), variance by item/cook, waste, ready item sales vs stock, food cost %, cost & profit per menu item, purchases by supplier

### 4.17 Reservations
- Date/time, party size, customer, table (optional), notes, status (`pending`, `confirmed`, `seated`, `cancelled`, `no_show`); calendar view per branch

### 4.18 Expenses
- Categories, amount, business date, note, attachment; paid from **shift cash drawer** (creates a cash-out) or a **bank account**; per branch

### 4.19 Dashboard & Reports
- **Branch selector**: one branch, or **All branches** (Super Admin / multi-branch users)
- **Dashboard**: open shifts per counter, today's sales, orders, average order value, cash in drawers, rider cash outstanding, open tables, kitchen queue, low stock, top items, sales by hour
- **Reports** (by business date range or shift; Excel/PDF): sales summary; by order type / payment method / bank account / branch; branch comparison; shift X/Z and cash over/short per cashier; item, category, deal sales; discounts, voids, refunds; service charge collected; tax report; waiter sales; rider deliveries & COD; expenses; inventory (stock, consumption, waste, variance, food cost); profit & loss; top customers

---

## 5. Order Lifecycle

```
                 ┌──────────── cancelled (before payment, needs reason)
                 │
draft ──► placed ──► preparing ──► ready ──► served ──► completed (paid)
 (cart)   (sent to    (kitchen     (all items  (dine-in)      │
          kitchen)     started)     ready)                    └──► refunded (full/partial)
```
- **Takeaway:** `ready → completed` on pickup. **Delivery:** `ready → out_for_delivery → delivered → completed`.
- Order `source`: `pos` or `waiter_app` (later `customer_app`).
- A dine-in table becomes `available` (or `cleaning`) when its order is completed.

---

## 6. Shifts, Counters & Cash (overnight-safe)

### Business day
- Branch setting `business_day_cutoff` (default `05:00`). With a Night shift 19:00–04:00, a sale at 01:30 AM belongs to the **previous business day**.
- A shift's `business_date` is fixed **when it opens** and never changes.
- Every order, payment, refund, cash movement, expense, and stock movement stores `branch_id`, `shift_id` (where relevant) and `business_date`. **Reports always use `business_date`.**

### Shift types
Per branch templates, e.g. `Morning 11:00–19:00`, `Night 19:00–04:00` (end < start ⇒ overnight). Used for labels, planning, and "shift overdue to close" warnings.

### Counters & sessions
- A branch has one or more **cash counters**; each counter has at most **one open shift** at a time.
- A cashier opens a shift on a counter; POS payments go to that shift's drawer.
- Waiter orders need any open shift in the branch; payment goes to the counter shift that receives it.
- **Closing with unpaid open orders:** they carry over; the Z-report lists them.
- **Rider cash** unsettled → close blocked unless a manager carries it over.
- **Blind close** option; **reopen** needs `shifts.reopen` and is logged.

### Expected cash
```
expected_cash = opening_cash + cash payments − cash refunds + cash in + rider settlements
              − cash out / paid-out expenses / supplier cash payments − safe drops
difference    = counted_cash − expected_cash      (+ over / − short)
```
Bank transfers are listed per bank account on the Z-report, not counted in the drawer.

### Handover
On close: cash **left as float** for the next shift on that counter + cash **handed over to the manager/safe**. The next shift's opening cash is pre-filled with the float.

---

## 7. Database Design (MySQL)

Conventions: BIGINT `id` (internal only) + **public `uuid` on every table shown in the UI — ids never appear in URLs or frontend data (see §7.2)**, timestamps, **our own trash columns on every business table (no permanent deletes — see §7.1)**, money `DECIMAL(12,2)`, quantities `DECIMAL(12,3)`, statuses as strings backed by **PHP enums**. Tables marked **[B]** have a `branch_id` (with the `BelongsToBranch` scope).

> **Rule:** order items store a *snapshot* of name, price, modifiers, and tax/service settings at the time of ordering, so later menu or settings changes never alter old bills.

### Branches, accounts & people
| Table | Key columns |
|---|---|
| `branches` | code, name, address, phone, email, tax_number, logo, manager_id (employee), is_active |
| `admins` | name, email, username, password, pin (hashed), is_super_admin, is_active, last_login_at |
| `admin_branch` | admin_id, branch_id |
| `roles`, `permissions`, … | spatie/laravel-permission, guard `admin` |
| `designations` | name, default_role_id, is_active |
| `employees` **[B]** | admin_id (unique, nullable), designation_id, code, name, phone, cnic, address, photo, joining_date, salary, status |
| `users` *(customers)* | name, phone (unique), email, password (nullable), birthday, notes, total_spent, visits_count, last_visit_at |
| `user_addresses` | user_id, label, address, area, landmark, lat, lng, is_default |
| `settings` | branch_id (**NULL = global**, else branch override), group, key, value (json); unique (branch_id, group, key). A branch row exists only for overridden fields — switching back to "use global" **trashes** the override row (kept as history) |
| `activity_log` | spatie/laravel-activitylog (+ branch_id in properties) |

### Tax
Tax rate and on/off live in `settings` (group `tax`, global + branch override). The values used are snapshotted on each order (`orders.tax_name`, `tax_rate`, `tax_total`).

### Menu *(all per branch)*
| Table | Key columns |
|---|---|
| `kitchen_stations` **[B]** | name, has_screen, printer_id, is_active |
| `categories` **[B]** | name, slug, image, kitchen_station_id, sort_order, is_active |
| `menu_items` **[B]** | category_id, kitchen_station_id (override), name, slug, description, image, price, prep_time_minutes, available_for (json), is_active, is_sold_out, sort_order — **no stock columns** |
| `ready_items` **[B]** | category_id, kitchen_station_id (nullable), code, barcode, name, image, price, stock_unit_id, purchase_unit_id, purchase_unit_factor, current_stock, alert_level, avg_cost, available_for (json), is_active, sort_order |
| `menu_item_variants` | menu_item_id, name, price, is_default, sort_order |
| `modifier_groups` **[B]** / `modifiers` | name, min_select, max_select, is_required / group_id, name, price, is_active |
| `menu_item_modifier_group` | menu_item_id, modifier_group_id, sort_order |
| `recipe_items` | recipeable (morph: menu_item / menu_item_variant / modifier), raw_material_id, quantity, unit_id |

Recipe rule: a variant's own recipe replaces the item's recipe if it has one; modifier recipes are added on top.

### Deals & discounts *(per branch)*
| Table | Key columns |
|---|---|
| `deals` **[B]** | name, description, image, price, starts_at, ends_at, days_of_week, start_time, end_time, available_for, is_active |
| `deal_slots` / `deal_slot_options` | deal_id, name, quantity / slot_id, sellable (morph: menu_item / ready_item), variant_id, extra_price |
| `discounts` **[B]** | name, type, value, applies_to, requires_approval, is_active |

### Floor, counters, printing
| Table | Key columns |
|---|---|
| `areas` **[B]** | name, sort_order |
| `tables` **[B]** | area_id, name, capacity, status, pos_x, pos_y, shape |
| `cash_counters` **[B]** | name, receipt_printer_id, is_active |
| `printers` **[B]** | name, type (receipt/kitchen), connection (usb/network), device_name_or_ip, paper_width, is_active |
| `print_jobs` **[B]** | printer_id, document_type (kot/receipt/pre_bill/z_report/void), reference (morph), status, attempts, error |
| `reservations` **[B]** | user_id, table_id, reserved_for, party_size, status, notes, created_by |

### Shifts & cash
| Table | Key columns |
|---|---|
| `shift_types` **[B]** | name, start_time, end_time, is_active |
| `shifts` **[B]** | cash_counter_id, shift_type_id, business_date, status, opened_by, opened_at, opening_cash, closed_by, closed_at, expected_cash, counted_cash, difference, float_left, handed_over_amount, notes, reopened_by, reopened_at |
| `shift_cash_counts` | shift_id, type (opening/closing), denomination, quantity, amount |
| `cash_movements` **[B]** | shift_id, business_date, type (cash_in, cash_out, safe_drop, rider_settlement, expense, supplier_payment), amount, reason, rider_id, reference (morph), admin_id |
| `shift_employees` | shift_id, employee_id, checked_in_at, checked_out_at |

### Orders, kitchen & delivery
| Table | Key columns |
|---|---|
| `orders` **[B]** | order_number, type, source (pos/waiter_app), status, business_date, shift_id (paid in), user_id, table_id, waiter_id, created_by, guests, items_total, discount_total, net_total, service_charge_rate, service_charge, delivery_fee, tax_name, tax_rate, tax_total, round_off, grand_total, paid_total, payment_status, notes, placed_at, completed_at, cancelled_at, cancel_reason |
| `order_items` | order_id, sellable (morph: menu_item / ready_item), variant_id, deal_id, parent_order_item_id, item_name, variant_name, quantity, unit_price, modifiers_total, discount_amount, line_total, kitchen_status, kitchen_station_id, kitchen_ticket_id, consumption_status (pending/confirmed/auto_confirmed/not_required — ready items are "not_required", their stock moves on sale), notes, voided_at, void_reason, voided_by, void_wasted |
| `order_item_consumptions` | order_item_id, raw_material_id, expected_qty, actual_qty, unit_id, reason, confirmed_by, confirmed_at, stock_movement_id |
| `order_item_modifiers` | order_item_id, modifier_id, name, price |
| `order_discounts` | order_id, order_item_id, discount_id, type, value, amount, approved_by, reason |
| `kitchen_tickets` **[B]** | order_id, kitchen_station_id, ticket_number, status, sent_at, started_at, completed_at, printed_at |
| `order_status_histories` | order_id, from_status, to_status, admin_id, note |
| `delivery_zones` **[B]** | name, fee, min_order_amount |
| `deliveries` **[B]** | order_id, user_address_id, address_snapshot, rider_id, delivery_zone_id, fee, status, assigned_at, picked_up_at, delivered_at, cash_to_collect, cash_collected, settled_at, settlement_movement_id |

### Payments & money
| Table | Key columns |
|---|---|
| `bank_accounts` + `bank_account_branch` | bank_name, account_title, account_number, iban, is_active, show_on_receipt |
| `payments` **[B]** | order_id, shift_id, business_date, method (cash/bank_transfer), bank_account_id, amount, tendered, change, reference_no, proof_image, received_by, collected_by_rider_id, status |
| `refunds` **[B]** | order_id, payment_id, shift_id, business_date, method, bank_account_id, amount, reason, approved_by |
| `expense_categories` | name |
| `expenses` **[B]** | expense_category_id, amount, business_date, description, attachment, paid_from (cash/bank), shift_id, bank_account_id, created_by |

### Inventory *(per branch)*
| Table | Key columns |
|---|---|
| `units` *(global)* | name, short_name, base_unit_id, factor (e.g. g → kg = 0.001) |
| `raw_material_categories` **[B]** | name |
| `raw_materials` **[B]** | category_id, code, name, stock_unit_id, purchase_unit_id, purchase_unit_factor, current_stock, alert_level, avg_cost, is_active |
| `suppliers` *(global)* | name, contact_person, phone, email, address, ntn |
| `purchases` / `purchase_items` **[B]** | supplier_id, invoice_no, business_date, status, subtotal, tax, total, paid_total / stockable (morph: raw_material / ready_item), quantity, unit_id, unit_cost, line_total |
| `supplier_payments` **[B]** | supplier_id, purchase_id, amount, paid_from (cash/bank), shift_id, bank_account_id, reference_no |
| `purchase_returns` / items **[B]** | purchase_id, supplier_id, reason / stockable, quantity, unit_cost |
| `stock_adjustments` / items **[B]** | type (opening/stock_in/waste/damage/count_correction), reason, approved_by / stockable, quantity (±), unit_cost |
| `stock_counts` / items **[B]** | status (draft/submitted/approved), counted_by / stockable, system_qty, counted_qty, variance |
| `stock_movements` **[B]** | stockable (morph: raw_material / ready_item), business_date, type (opening, stock_in, purchase, consumption, sale, sale_return, waste, adjustment, count_correction, purchase_return), quantity (±, in stock unit), unit_cost, balance_after, reference (morph), admin_id |

`current_stock` on `raw_materials` / `ready_items` is updated in the same transaction as each `stock_movements` row (row lock), so the ledger and the balance always match. **Menu items never get stock movements.**

**Key indexes:** `(branch_id, business_date, status)` on orders; `orders(shift_id)`; `orders(table_id, status)`; `order_items(kitchen_status, kitchen_station_id)`; `order_items(consumption_status)`; `payments(shift_id, method)`; `payments(branch_id, bank_account_id, business_date)`; `settings(branch_id, group, key)` unique; `users(phone)`; `deliveries(rider_id, status)`; `raw_materials(branch_id, code)` unique; `ready_items(branch_id, code)` unique; `stock_movements(branch_id, stockable_type, stockable_id, business_date)`.

---

### 7.1 No permanent deletes — custom `Trashable` trait
**Rule: nothing is ever permanently deleted from the database.** Laravel's built-in `SoftDeletes` is **not** used; we use our own trait with explicit, manual functions so a delete can never happen by accident.

**Columns** — added to every business table by a migration macro `$table->trashable()`:
| Column | Purpose |
|---|---|
| `deleted_at` (nullable, indexed) | when it was trashed; `NULL` = active |
| `deleted_by` (nullable → admins) | who trashed it |
| `delete_reason` (nullable) | why (required for sensitive records, e.g. customers, employees, menu items) |
| `trash_batch` (nullable uuid) | links records trashed together (cascade), so they are restored together |

**Trait `App\Models\Concerns\Trashable`**
| Function | What it does |
|---|---|
| `$model->trash(?string $reason)` | Moves the record to trash: fills the 4 columns, fires `trashing` / `trashed` events, cascades to child relations listed in `$trashCascade` (same `trash_batch`), writes the activity log. Checks `canBeTrashed()` first |
| `$model->restoreFromTrash()` | Brings it back: clears the columns, restores the children from the same batch, fires `restoring` / `restored`. Refuses if its parent is still trashed (e.g. an item whose category is trashed) |
| `$model->isTrashed()` | `true` / `false` |
| `$model->canBeTrashed()` | Override per model for business rules, e.g. a category with active items, a table with an open order, an employee with an open shift → returns the reason it can't be trashed |
| `Model::withTrashed()` / `onlyTrashed()` / `withoutTrashed()` | Query scopes. A global scope hides trashed rows by default |
| `Model::query()->…->trash($reason)` / `->restoreFromTrash()` | Bulk versions (each row still gets logged) |
| `$model->deletedBy()` | Relation to the admin who trashed it |
| `delete()`, `forceDelete()`, `Model::destroy()`, bulk `->delete()` | **Blocked** — throw `PermanentDeleteNotAllowed`. The only way to remove something is `trash()` |

**How it is used**
- Every "Delete" button in the UI calls `trash()` (asks for a reason where required); every list has a **"Trash" tab** with **Restore**.
- **Recycle Bin** screen (Super Admin / permission `trash.view`): all trashed records across modules, filter by module/branch/who/date, restore.
- Permissions per module: `<module>.trash` and `<module>.restore`.
- **Unique fields** (customer phone, employee code, raw material code, email): validation also checks trashed rows; if a match is in the trash the UI offers **"Restore the existing record"** instead of creating a duplicate.
- **Relations**: records that reference a trashed row (e.g. old orders → trashed menu item) still load it with `withTrashed()`; order items also keep their own name/price snapshot.
- **Pivot tables** (`admin_branch`, `menu_item_modifier_group`, `bank_account_branch`) use pivot models with the trait; detaching trashes the pivot row instead of deleting it.

**Records that are never trashed at all — they are corrected, not removed**
- Money & operations: orders, order items, payments, refunds, shifts, cash movements → **cancel / void / refund / reverse entry**
- Ledgers & history: `stock_movements`, `order_status_histories`, `order_item_consumptions`, `activity_log`, `print_jobs` → **append-only**; mistakes are fixed with a new correcting entry
- The trait blocks trash on these (`canBeTrashed()` returns "use void/cancel instead").

**Exceptions:** Laravel's own framework tables (`sessions`, `cache`, `jobs`, `failed_jobs`, `password_reset_tokens`) are cleaned up normally — they hold no business data.

### 7.2 UUIDs — internal ids never leave the server
**Rule: numeric `id`s are never shown in URLs, Inertia props, forms, or JavaScript.** Everything outside the server uses the record's `uuid`.

- **Two keys per table:** `id` BIGINT stays the primary key (fast joins and foreign keys); a `uuid` column (`CHAR(36)`, unique index) is the **public key**. Pivot tables don't need one.
- **UUID v7** (time-ordered) so the unique index stays fast and records sort by creation time.
- **Trait `App\Models\Concerns\HasPublicUuid`**:
  - generates the `uuid` automatically when a record is created (never changes after)
  - `getRouteKeyName()` returns `uuid` → route model binding works with uuids: `/orders/0192f1c4-…`, `/menu-items/0192f1d0-…/edit`
  - hides `id` and all `*_id` foreign keys from `toArray()` / JSON
  - helper `Model::findByUuidOrFail($uuid)`
- **Migration macro** `$table->publicUuid()` adds the column + unique index (used together with `$table->trashable()` on every business table).
- **Data sent to React goes through API Resources** (`JsonResource`): `id` → the uuid, relations → their uuids (e.g. `category: { id: uuid, name }`), never raw foreign keys.
- **Forms send uuids back** (e.g. selected category, table, waiter). Form Requests validate with `exists:<table>,uuid` and convert them to internal ids before saving (`UuidToId` helper / `prepareForValidation`).
- **Real-time channels, print jobs, and exports** also use uuids (e.g. `branch.{uuid}.orders`).
- Human-friendly numbers are still shown where people need them — **order number, receipt number, employee code, shift number** — but those are display values, not keys in URLs.
- A test checks every Inertia response: **no numeric `id` or `*_id` field leaks** to the frontend.

## 8. Application Architecture

### Backend (Laravel)
```
app/
  Enums/            OrderType, OrderSource, OrderStatus, KitchenStatus, TableStatus, PaymentMethod,
                    PaymentStatus, ShiftStatus, CashMovementType, DeliveryStatus, StockMovementType
  Models/           Branch, Admin, Employee, User (customer), Order, Shift, RawMaterial, …
  Models/Concerns/  BelongsToBranch (auto branch_id + global scope),
                    Trashable (our soft delete: trash / restoreFromTrash / scopes; blocks real deletes),
                    HasPublicUuid (uuid v7 route key; hides numeric ids)
  Http/Resources/   one JsonResource per model — the only shape React receives (uuids, no ids)
  Exceptions/       PermanentDeleteNotAllowed
  Support/          CurrentBranch (resolves & stores the active branch), BranchSettings
  Http/
    Controllers/    thin — validate, call an Action, return an Inertia response
    Requests/       Form Request validation
    Middleware/     HandleInertiaRequests (shares admin, permissions, branch, branches list,
                    open shift, settings, flash), SetCurrentBranch, EnsureShiftIsOpen
  Actions/
    Orders/         CreateOrder, AddItems, SendToKitchen, VoidItem, TransferTable, MergeTables,
                    SplitBill, RequestBill, CompleteOrder, CancelOrder
    Payments/       TakePayment, RefundPayment
    Shifts/         OpenShift, CloseShift, ReopenShift, RecordCashMovement, SettleRiderCash
    Deliveries/     AssignRider, UpdateDeliveryStatus
    Inventory/      AddRawMaterial, AddReadyItem, AddStock, ReceivePurchase, ConfirmConsumption,
                    AutoConfirmPendingConsumption, DeductReadyItemsForOrder, ReturnReadyItemsForVoid,
                    RecordWaste, ApproveStockCount
    Branches/       CreateBranch, AllocateManager, CopyMenuFromBranch
  Services/
    OrderPricingService    all totals, service charge, tax in one place
    SettingsResolver       setting('tax.rate') → branch override → global → default (cached per branch)
    DealService            deal selections & availability
    RecipeResolver         expected raw materials for an order item (item/variant + modifiers + deal)
    BusinessDateResolver   timestamp → business_date using the branch cutoff
    ShiftCashCalculator    expected cash, X/Z report data
    StockService           raw materials & ready items: converts units, writes stock_movements, updates stock & avg cost
    PrintService           builds ESC/POS documents (KOT, receipt, pre-bill, Z) → print_jobs
    OrderNumberGenerator   per branch per business day
  Events/           OrderPlaced, KitchenTicketCreated, OrderItemStatusChanged, TableStatusChanged,
                    BillRequested, DeliveryAssigned, StockLow
  Policies/         per model: permission + branch access
```
- Guards: **`admin`** (whole system) and **`web`** (reserved for the customer app/API).
- All money and stock flows run in **DB transactions**; totals are always recalculated on the server.
- Real-time: **Laravel Reverb** + **Echo**, channels scoped by branch (`branch.{uuid}.kitchen.{station}`, `branch.{uuid}.orders`, `branch.{uuid}.tables`, `rider.{uuid}`, `waiter.{uuid}`).

### Thermal printing (KOT + receipts)
The server is in the cloud, so it cannot reach the printers in a branch directly — printing always happens **from a browser in the branch**. The method comes from the **Printing** settings group (global default, branch override):
- **QZ Tray** *(recommended)*: a small free app installed on each counter/kitchen PC. The page sends raw **ESC/POS** to USB or network thermal printers **silently** (no dialog), including auto-print of KOTs.
- **Browser print**: an 80mm/58mm print-CSS page with `window.print()` (shows the print dialog; fine for small branches).
- The server builds each document from the branch's receipt template and logs a `print_jobs` row; the browser at the counter/kitchen station picks it up (via Reverb), prints it, and reports success/failure → **retry & reprint** from the UI.
- QZ Tray needs a signing certificate so it doesn't ask for permission every time — generate one once and install it with QZ Tray on each PC.

### Frontend (React + Inertia, JavaScript)
Laravel's React starter kit is TypeScript-only, so we set up **Inertia + React (JSX) manually** with Vite. The look is taken **1:1 from `D:\laragon\www\pos-react`**; only routing and data change (React Router → Inertia, dummy data → Laravel props).
```
resources/
  css/
    app.css           @import "tailwindcss" + the files below
    tokens.css        colours, ramps, fonts, dark theme — from pos-react index.css :root
    base.css          body, headings, focus ring, selection, scrollbars
    layout.css        app shell: sidebar, toolbar, status bar, mobile drawer, breakpoints
    components/       buttons, tags, corners, stat-card, rgrid (table), drawer, modal,
                      form fields, tabs, pagination, empty-state, toast
    pages/            pos.css, waiter.css, kitchen.css, floor-plan.css, shifts.css, rider.css, …
    print/            receipt.css, kot.css (fallback print layouts)
  js/
    app.jsx
    layouts/          AppLayout (sidebar + toolbar + status bar), PosLayout (no sidebar),
                      WaiterLayout (mobile), KitchenLayout (full screen), RiderLayout (mobile),
                      AuthLayout
    pages/            dashboard/ branches/ pos/ waiter/ orders/ kitchen/ tables/ reservations/
                      menu/ deals/ discounts/ customers/ employees/ designations/ riders/
                      shifts/ counters/ printers/ bank-accounts/ expenses/ inventory/
                      (raw-materials, stock-ledger, suppliers, purchases, waste, counts, pending-consumption)
                      reports/ admins/ roles/ settings/
    components/
      layout/         Sidebar, Toolbar, StatusBar, BranchSwitcher, MobileNavButton, ThemeToggle
      ui/             Corners, Button, Tag, StatCard, DataTable, Drawer, Modal, Field, Input,
                      Select, Tabs, Pagination, EmptyState, ConfirmDialog, PinPrompt
      pos/            ItemGrid, CategoryTabs, Cart, CartSheet, ModifierDialog, DealDialog,
                      PaymentDialog, CustomerPicker, TableWaiterPicker
      kitchen/        TicketCard, StationBoard
      tables/         FloorPlan, TableCard
      shifts/         OpenShiftDrawer, CashCountForm, CashMovementDrawer, ZReport
      inventory/      RecipeEditor, UnitQtyInput, StockLevelBadge, ConsumptionPanel (KDS)
    hooks/            useCart, useEcho, useCan, useMoney, useShift, useBranch, useMediaQuery, usePrinter
    lib/              formatters (money "Rs 1,250", business date), nav config, qz-tray wrapper
```
The **cart and item-picking components are shared** between the cashier POS and the waiter app; only the layouts differ.

### UI / Design system — "Industry" (from pos-react), 100% match + responsive

**Source of truth:** `D:\laragon\www\pos-react` — `src/index.css`, `src/components/layout/*`, `src/pages/*`, and the mockups in `sample-design/` (`POS System Mockups.dc.html`, `styles.css`, `_ds/.../readme.md`).
The mockups are a desktop (WPF) app: **skip the window title bar**, match everything else.

**Visual language (must be kept exactly):**
- Light technical ground `#f2f2f3`, text `#1d1f20`, **single steel-blue accent `#5980a6`** with its 100–900 ramp; danger red only for destructive/negative values
- **Barlow Condensed** (600) for headings, titles, buttons; **Barlow** for body — self-hosted via `@fontsource` so it works offline
- **Square corners, 1px hairline borders, no filled/rounded cards** — cards, stat cards, chart boxes are line drawings with **"+" registration marks** (`<Corners />`)
- The solid accent primary button is the only filled object; secondary = outlined, ghost = text-only
- **Lucide icons at stroke-width 1.5**
- Compact desktop density: 200px sidebar with uppercase section labels, 12.5px nav items with a 2px accent left border when active, toolbar (title left, actions right), status bar at the bottom
- Data tables in the `.rgrid` style: uppercase 10px headers, zebra rows, accent-100 hover, mono numbers
- Add/edit forms in a **right-side slide-in drawer**; small confirmations in a centred modal
- **Light & dark theme** via `<html data-theme>` like pos-react, saved per user

**How we port it:**
1. Copy tokens and component CSS from pos-react and **split the 86 KB `index.css`** into `tokens / base / layout / components / pages`; turn inline `style={{…}}` values into classes.
2. Rebuild repeated markup as **reusable components** (`StatCard`, `DataTable`, `Drawer`, `Field`, `Tag`, …).
3. React Router → Inertia: `NavLink` → Inertia `<Link>` with active state from the current route; each page renders its `<Toolbar>` inside a **persistent layout** (no flicker).
4. The **status bar** shows: branch, user, counter + open shift + business date, app version. The **branch switcher** sits in the toolbar for multi-branch users.
5. New screens (branches, floor plan, waiter app, kitchen display, rider panel, deal builder, modifier dialog, cash count, recipe editor, stock transfer) are designed **in the same language**.

**Responsiveness (desktop look unchanged; these rules are added):**

| Breakpoint | Behaviour |
|---|---|
| **≥ 1280px** desktop | Exactly as the design |
| **1024–1279px** laptop | Same shell; stat cards 4 → 3/2 columns; dashboard panels stack when narrow |
| **768–1023px** tablet | Sidebar collapses to a **56px icon rail** (tooltips; expands as overlay); POS 2 columns with narrower cart; drawers 420px |
| **< 768px** mobile | Sidebar becomes an **off-canvas drawer** from a menu button; toolbar actions collapse into "⋯" (primary action stays); stat cards 1 column; form grids 1 column; drawers full-width; status bar shows only branch + shift |

- **Tables on small screens:** horizontal scroll with a sticky first column; high-use lists (orders, deliveries, shifts, stock) switch to a **stacked card list** under 640px.
- **POS on mobile/tablet:** product grid full screen + floating **cart bar** ("3 items · Rs 2,450 — View cart") opening the cart as a **bottom sheet**.
- **Waiter app & rider panel:** mobile-first, big buttons, bottom navigation, tap-to-call, open address in Maps.
- **Touch devices** (`@media (pointer: coarse)`): buttons, nav items, rows, and POS tiles grow to **≥ 40–44px**; mouse view keeps its compact density.
- **Kitchen display:** large type, high contrast, auto-fits 1–6 ticket columns.
- No horizontal page scroll at any width; test at 360, 768, 1024, 1366, 1920px.
- POS keyboard shortcuts (search, pay, new order, hold).
- Charts: **Recharts** coloured from the accent ramp. Tables: our own `DataTable` on `.rgrid` with server-side pagination/filtering.

### Packages
| Purpose | Package |
|---|---|
| SPA bridge | inertiajs/inertia-laravel + @inertiajs/react |
| Styling | tailwindcss v4 (@tailwindcss/vite) + our design-system CSS (no shadcn/ui) |
| Icons & fonts | lucide-react (stroke 1.5), @fontsource/barlow, @fontsource/barlow-condensed |
| Charts | recharts |
| Routes in JS | tightenco/ziggy |
| Roles & permissions | spatie/laravel-permission |
| Audit log | spatie/laravel-activitylog |
| Websockets | laravel/reverb + laravel-echo + pusher-js |
| Thermal printing | QZ Tray (qz-tray JS) + an ESC/POS builder; print-CSS fallback |
| PWA (waiter/rider) | vite-plugin-pwa |
| PDF | barryvdh/laravel-dompdf |
| Excel | maatwebsite/excel |
| Backups | spatie/laravel-backup |
| Tests | Pest |
| Code style | Laravel Pint, ESLint + Prettier |

---

## 9. Key Screens

1. Login, PIN login / switch user, branch switcher
2. Dashboard (branch / all branches)
3. **Branches** (Super Admin) — list, create, allocate manager
4. **Settings** — Global settings (Super Admin) and Branch settings (same groups, "Use global / Override" per field)
5. **POS (cashier)** — order type, table & waiter, customer, rider, cart (menu + ready items), payment, print
6. **Waiter app** — tables grid, order taking, ready alerts, request bill
7. Floor plan with live table status
8. **Kitchen Display** per station + **confirm consumption panel** + KOT reprint
9. Orders list & order detail
10. Menu (per branch): categories, **menu items with recipe editor**, **ready items**, variants, modifiers, deals, discounts; copy menu from branch
11. Customers & profile
12. Employees, designations, admin accounts, roles & permissions
13. Riders panel + deliveries board (assign, track, settle cash)
14. **Shifts** — counters, open/close, cash in/out, safe drop, rider settlement, X/Z reports
15. **Inventory** — raw materials, ready item stock, add stock, stock ledger, suppliers, purchases, waste, stock counts, pending consumption, low stock
16. Printers, cash counters, bank accounts, expenses, reservations, reports
17. **Recycle Bin** + a "Trash" tab with Restore on every list

---

## 10. Development Phases

| # | Phase | Deliverables |
|---|---|---|
| 0 | **Setup & design system** | Laravel, Inertia + React (JSX), Tailwind v4, MySQL, Pint/ESLint/Prettier, Pest. Port the pos-react design (tokens, CSS split, fonts, dark mode), app shell with all breakpoints, shared UI components, login — checked side by side with pos-react |
| 1 | **Branches & access** | **`Trashable` + `HasPublicUuid` traits, `trashable()` / `publicUuid()` migration macros, base API Resource, and their tests (used by every table from here on)**, Recycle Bin screen, branches, admins, `admin` guard, roles & permissions UI, super admin, branch access + switcher + `BelongsToBranch` scope, PIN login, activity log |
| 2 | **People** | Designations, employees with logins, manager allocation, customers with addresses |
| 3 | **Settings & setup** | **Two-level settings engine** (global + branch override, "Use global" switch, caching) with all groups: general, orders, tax, service charge, delivery, payments, receipt, printing, kitchen, inventory, shifts, approvals; bank accounts, cash counters, shift types, printers + test print |
| 4 | **Stock items & menu** | Units, **raw materials** and **ready items** with opening stock, add stock, stock ledger; kitchen stations, categories, **menu items with recipe editor**, variants, modifiers, images; copy menu between branches |
| 5 | **Deals & discounts** | Deal builder with slots, discounts (per branch) |
| 6 | **Tables** | Areas, tables, floor plan |
| 7 | **Shifts & cash** | Multi-counter shifts, overnight business date, cash movements, cash counts, X/Z reports |
| 8 | **POS & orders** | Cart, dine-in/takeaway/delivery, pricing service (service charge + tax on total bill), ready items deducted on sale, send to kitchen, order list/detail |
| 9 | **Kitchen** | Reverb, KDS station boards, KOT thermal printing, void slips, ready notifications, **confirm consumption → stock deduction** |
| 10 | **Billing** | Cash & bank transfer, split pay/bill, pre-bill, thermal receipts, refunds |
| 11 | **Waiter app** | Mobile layout/PWA, tables → order → kitchen, request bill, ready alerts |
| 12 | **Riders & delivery** | Delivery zones, rider assignment, rider panel, COD settlement |
| 13 | **Inventory (rest)** | Suppliers, purchases & supplier payments, purchase returns, waste, stock counts, pending-consumption review & auto-confirm, low-stock alerts |
| 14 | **Reservations & expenses** | Reservation calendar, expenses (drawer or bank) |
| 15 | **Dashboard & reports** | Branch & consolidated dashboard, all reports, Excel/PDF export |
| 16 | **Hardening & launch** | Feature tests (orders, payments, shifts, stock, branch isolation), performance, backups, deployment, pilot in one branch |

A **pilot branch** can start after Phase 12 (sales flow complete); full v1 go-live after Phase 16.

---

## 11. Non-Functional Requirements

- **Branch isolation:** automated tests prove a user can never see or change another branch's data without access.
- **Speed:** POS and waiter actions feel instant; menu cached per branch and invalidated on change.
- **No ids in URLs:** only uuids leave the server; a test fails if a numeric id reaches the frontend.
- **No permanent deletes:** every business record is trashed with who/when/why and can be restored; money and ledger records are only voided/reversed. A test fails if any model can be hard-deleted.
- **Reliability:** transactions on money and stock flows.
- **Security:** policies on every route, rate-limited PIN login, manager approval for sensitive actions.
- **Auditability:** who did what, when, in which branch.
- **Correct dates:** every financial and stock record carries `business_date`.
- **Printing:** every print is logged; failed prints are visible and can be retried.
- **Backups:** daily MySQL backup.

---

## 12. Decided
- JavaScript (JSX); UI copies the pos-react "Industry" design 100% on desktop + responsive rules
- **Multi-branch**: Super Admin manages branches and allocates managers; customers shared, everything else per branch
- **One central server** for all branches; offline mode not needed for now
- **Each branch has its own menu**, managed by its manager (Super Admin can copy a menu between branches)
- **Settings for everything at two levels**: one global set (Super Admin) + branch-level overrides
- **Tax**: on the total bill (after discount, incl. service charge & delivery fee), applied to all orders; rate/on-off from branch settings → else global
- **Printing & receipts** come from settings (QZ Tray or browser print), global + branch override
- **Three item types**: raw materials (stock), ready items (stock, sold as-is), menu items (no stock, sales only)
- **Stock**: recipe on each menu item → cook confirms/adjusts actual use when the item is ready → raw material stock deducted; ready items deducted when sold
- `admins` for all logins, `employees` with designations and home branch, `users` for customers
- Order types: dine-in (table + waiter), takeaway, delivery (customer + rider); each can be turned on/off per branch
- Orders from **cashier POS and waiter app**
- **Prices tax-exclusive**; tax added on the final invoice
- **Service charge on dine-in**, on/off from settings
- Shifts like Morning 11:00–19:00, Night 19:00–04:00, with a business-day cutoff; **multiple cash counters**
- Kitchen: **KDS + thermal KOT printing**; thermal receipt printers available
- Payments: cash and bank transfer, multiple bank accounts
- Riders with their own panel and cash settlement
- **Inventory in v1**
- **No sub-recipes / production batches** — recipes use raw materials directly
- **No stock transfers between branches** — each branch manages its own stock
- **Nothing is permanently deleted**: custom `Trashable` trait (not Laravel SoftDeletes) with manual trash / restore functions; Recycle Bin
- **UUIDs everywhere public**: numeric ids stay internal; URLs, props, forms, and channels use uuid v7

## 13. Still Open
Nothing — the plan is ready for Phase 0.
