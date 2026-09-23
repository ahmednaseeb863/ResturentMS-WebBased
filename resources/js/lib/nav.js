import {
    LayoutDashboard,
    ShoppingCart,
    ClipboardList,
    LayoutGrid,
    ChefHat,
    FolderTree,
    UtensilsCrossed,
    Package,
    BadgePercent,
    Percent,
    Wheat,
    History,
    FileText,
    Building2,
    Trash2,
    ClipboardCheck,
    Scale,
    PackageSearch,
    UserRound,
    Users,
    IdCard,
    Bike,
    Clock,
    Truck,
    CalendarDays,
    Receipt,
    BarChart2,
    Store,
    UserCog,
    ShieldCheck,
    Landmark,
    Calculator,
    Printer,
    Settings,
    ArchiveRestore,
} from 'lucide-react';

/**
 * Sidebar navigation. `route` is a Ziggy route name; items whose route does not
 * exist yet render disabled ("coming in a later phase") so the menu is complete
 * from day one. `can` is the permission checked once roles land in Phase 1.
 */
export const navSections = [
    {
        section: 'Main',
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, can: 'dashboard.view' },
            { label: 'Point of Sale', route: 'pos.index', icon: ShoppingCart, can: 'pos.use' },
            { label: 'Orders', route: 'orders.index', icon: ClipboardList, can: 'orders.view' },
            { label: 'Tables', route: 'tables.floor', icon: LayoutGrid, can: 'tables.view' },
            { label: 'Kitchen Display', route: 'kitchen.index', icon: ChefHat, can: 'kitchen.view' },
        ],
    },
    {
        section: 'Menu',
        items: [
            { label: 'Categories', route: 'categories.index', icon: FolderTree, can: 'menu.view' },
            { label: 'Menu Items', route: 'menu-items.index', icon: UtensilsCrossed, can: 'menu.view' },
            { label: 'Ready Items', route: 'ready-items.index', icon: Package, can: 'menu.view' },
            { label: 'Deals', route: 'deals.index', icon: BadgePercent, can: 'deals.view' },
            { label: 'Discounts', route: 'discounts.index', icon: Percent, can: 'discounts.view' },
        ],
    },
    {
        section: 'Inventory',
        items: [
            { label: 'Raw Materials', route: 'raw-materials.index', icon: Wheat, can: 'inventory.view' },
            { label: 'Stock Ledger', route: 'stock-ledger.index', icon: History, can: 'inventory.view' },
            { label: 'Purchases', route: 'purchases.index', icon: FileText, can: 'purchases.view' },
            { label: 'Suppliers', route: 'suppliers.index', icon: Building2, can: 'suppliers.view' },
            { label: 'Waste', route: 'waste.index', icon: Trash2, can: 'waste.view' },
            { label: 'Stock Counts', route: 'stock-counts.index', icon: ClipboardCheck, can: 'stock-counts.view' },
            { label: 'Pending Consumption', route: 'consumptions.pending', icon: Scale, can: 'inventory.view' },
            { label: 'Low Stock', route: 'low-stock.index', icon: PackageSearch, can: 'inventory.view' },
        ],
    },
    {
        section: 'People',
        items: [
            { label: 'Customers', route: 'customers.index', icon: UserRound, can: 'customers.view' },
            { label: 'Employees', route: 'employees.index', icon: Users, can: 'employees.view' },
            { label: 'Designations', route: 'designations.index', icon: IdCard, can: 'designations.view' },
            { label: 'Riders', route: 'riders.index', icon: Bike, can: 'riders.view' },
        ],
    },
    {
        section: 'Operations',
        items: [
            { label: 'Shifts', route: 'shifts.index', icon: Clock, can: 'shifts.view' },
            { label: 'Deliveries', route: 'deliveries.index', icon: Truck, can: 'deliveries.view' },
            { label: 'Reservations', route: 'reservations.index', icon: CalendarDays, can: 'reservations.view' },
            { label: 'Expenses', route: 'expenses.index', icon: Receipt, can: 'expenses.view' },
            { label: 'Reports', route: 'reports.index', icon: BarChart2, can: 'reports.view' },
        ],
    },
    {
        section: 'Administration',
        items: [
            { label: 'Branches', route: 'branches.index', icon: Store, can: 'branches.view' },
            { label: 'Admin Accounts', route: 'admins.index', icon: UserCog, can: 'admins.view' },
            { label: 'Roles & Permissions', route: 'roles.index', icon: ShieldCheck, can: 'roles.view' },
            { label: 'Bank Accounts', route: 'bank-accounts.index', icon: Landmark, can: 'bank-accounts.view' },
            { label: 'Cash Counters', route: 'counters.index', icon: Calculator, can: 'counters.view' },
            { label: 'Printers', route: 'printers.index', icon: Printer, can: 'printers.view' },
            { label: 'Settings', route: 'settings.index', icon: Settings, can: 'settings.view' },
            { label: 'Recycle Bin', route: 'trash.index', icon: ArchiveRestore, can: 'trash.view' },
        ],
    },
];
