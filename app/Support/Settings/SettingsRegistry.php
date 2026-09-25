<?php

namespace App\Support\Settings;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Every setting the system knows (PLAN §4.5): group → sections → fields, with the
 * built-in default. The same list is used at both levels (global + branch override).
 *
 * Field types: bool, int, decimal, money, string, text, select, time, image.
 * Read values with `setting('group.key')` (SettingsResolver) — never hard-code them.
 * Adding a field here is all it takes: it appears on both settings screens.
 * Field keys must not end in `_id` (they are sent to the frontend).
 */
class SettingsRegistry
{
    /** @return array<string, array{label: string, description: string, sections: list<array{title: string, fields: array<string, array>}>}> */
    public static function groups(): array
    {
        return once(fn () => [
            'general' => [
                'label' => 'General',
                'description' => 'Business identity and regional formats',
                'sections' => [
                    ['title' => 'Business Identity', 'fields' => [
                        'business_name' => ['label' => 'Display name', 'type' => 'string', 'default' => 'My Restaurant', 'max' => 120, 'sub' => 'Printed on receipts and reports'],
                        'logo' => ['label' => 'Logo', 'type' => 'image', 'default' => null, 'sub' => 'PNG or JPG, up to 1 MB'],
                        'phone' => ['label' => 'Phone', 'type' => 'string', 'default' => null, 'max' => 40],
                        'address' => ['label' => 'Address', 'type' => 'text', 'default' => null, 'max' => 255],
                        'ntn' => ['label' => 'NTN / tax registration no.', 'type' => 'string', 'default' => null, 'max' => 40],
                    ]],
                    ['title' => 'Regional', 'fields' => [
                        'currency_code' => ['label' => 'Currency code', 'type' => 'string', 'default' => 'PKR', 'max' => 3, 'required' => true],
                        'currency_symbol' => ['label' => 'Currency symbol', 'type' => 'string', 'default' => 'Rs', 'max' => 5, 'required' => true],
                        'timezone' => ['label' => 'Timezone', 'type' => 'select', 'default' => 'Asia/Karachi', 'options' => [
                            'Asia/Karachi' => 'Asia/Karachi (PKT, UTC+5)',
                            'Asia/Dubai' => 'Asia/Dubai (GST, UTC+4)',
                            'Asia/Riyadh' => 'Asia/Riyadh (AST, UTC+3)',
                            'Europe/London' => 'Europe/London (GMT/BST)',
                            'UTC' => 'UTC',
                        ]],
                        'date_format' => ['label' => 'Date format', 'type' => 'select', 'default' => 'd M Y', 'options' => [
                            'd M Y' => '23 Sep 2026',
                            'd/m/Y' => '23/09/2026',
                            'm/d/Y' => '09/23/2026',
                            'Y-m-d' => '2026-09-23',
                        ]],
                        'time_format' => ['label' => 'Time format', 'type' => 'select', 'default' => '12h', 'options' => [
                            '12h' => '12-hour (3:15 PM)',
                            '24h' => '24-hour (15:15)',
                        ]],
                    ]],
                ],
            ],
            'orders' => [
                'label' => 'Orders',
                'description' => 'Order types, numbering and the business day',
                'sections' => [
                    ['title' => 'Order Types', 'fields' => [
                        'dine_in' => ['label' => 'Dine-in', 'type' => 'bool', 'default' => true, 'sub' => 'Table + waiter orders'],
                        'takeaway' => ['label' => 'Takeaway', 'type' => 'bool', 'default' => true],
                        'delivery' => ['label' => 'Delivery', 'type' => 'bool', 'default' => true, 'sub' => 'Customer + rider orders'],
                        'require_customer_for_delivery' => ['label' => 'Require customer for delivery', 'type' => 'bool', 'default' => true],
                        'hold_orders' => ['label' => 'Allow holding orders', 'type' => 'bool', 'default' => true, 'sub' => 'Park an order on the POS and resume it later'],
                    ]],
                    ['title' => 'Order Numbering', 'fields' => [
                        'number_prefix' => ['label' => 'Order number prefix', 'type' => 'string', 'default' => null, 'max' => 10, 'sub' => 'Optional, e.g. GUL-'],
                        'number_padding' => ['label' => 'Number padding', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 6, 'suffix' => 'digits'],
                        'number_reset' => ['label' => 'Restart numbering', 'type' => 'select', 'default' => 'daily', 'options' => [
                            'daily' => 'Every business day',
                            'never' => 'Never',
                        ]],
                    ]],
                    ['title' => 'Business Day', 'fields' => [
                        'business_day_cutoff' => ['label' => 'Business day starts at', 'type' => 'time', 'default' => '05:00', 'sub' => 'Sales before this time count for the previous day (overnight shifts)'],
                    ]],
                ],
            ],
            'tax' => [
                'label' => 'Tax',
                'description' => 'Added on the total bill after discount (prices are tax-exclusive)',
                'sections' => [
                    ['title' => 'Sales Tax', 'fields' => [
                        'enabled' => ['label' => 'Charge tax', 'type' => 'bool', 'default' => false, 'sub' => 'Applies to every order type and payment method'],
                        'name' => ['label' => 'Tax name', 'type' => 'string', 'default' => 'GST', 'max' => 30, 'required' => true, 'sub' => 'Printed on the bill, e.g. GST'],
                        'rate' => ['label' => 'Rate', 'type' => 'decimal', 'default' => 16, 'min' => 0, 'max' => 100, 'suffix' => '%'],
                    ]],
                ],
            ],
            'service_charge' => [
                'label' => 'Service Charge',
                'description' => 'Dine-in orders only',
                'sections' => [
                    ['title' => 'Service Charge', 'fields' => [
                        'enabled' => ['label' => 'Charge service on dine-in', 'type' => 'bool', 'default' => false],
                        'rate' => ['label' => 'Rate', 'type' => 'decimal', 'default' => 5, 'min' => 0, 'max' => 100, 'suffix' => '%'],
                        'removable' => ['label' => 'Can be removed per order', 'type' => 'bool', 'default' => true, 'sub' => 'Needs permission (and a manager PIN if set under Approvals)'],
                    ]],
                ],
            ],
            'delivery' => [
                'label' => 'Delivery',
                'description' => 'Delivery charges and limits',
                'sections' => [
                    ['title' => 'Delivery Charges', 'fields' => [
                        'default_fee' => ['label' => 'Default delivery fee', 'type' => 'money', 'default' => 0, 'min' => 0],
                        'min_order_amount' => ['label' => 'Minimum order amount', 'type' => 'money', 'default' => 0, 'min' => 0, 'sub' => '0 = no minimum'],
                        'use_zones' => ['label' => 'Use delivery zones', 'type' => 'bool', 'default' => false, 'sub' => 'Zone fee and minimum replace the defaults'],
                    ]],
                ],
            ],
            'payments' => [
                'label' => 'Payments',
                'description' => 'Payment methods and rounding',
                'sections' => [
                    ['title' => 'Payment Methods', 'fields' => [
                        'cash' => ['label' => 'Cash', 'type' => 'bool', 'default' => true],
                        'bank_transfer' => ['label' => 'Bank transfer', 'type' => 'bool', 'default' => true, 'sub' => 'Into the bank accounts available at the branch'],
                        'transfer_reference_required' => ['label' => 'Require transfer reference no.', 'type' => 'bool', 'default' => true],
                        'transfer_proof_required' => ['label' => 'Require transfer screenshot', 'type' => 'bool', 'default' => false],
                    ]],
                    ['title' => 'Rounding', 'fields' => [
                        'rounding' => ['label' => 'Round the bill to', 'type' => 'select', 'default' => 'none', 'options' => [
                            'none' => 'No rounding',
                            '1' => 'Nearest 1',
                            '5' => 'Nearest 5',
                            '10' => 'Nearest 10',
                        ]],
                    ]],
                ],
            ],
            'receipt' => [
                'label' => 'Receipt',
                'description' => 'What the printed bill shows',
                'sections' => [
                    ['title' => 'Receipt Text', 'fields' => [
                        'header_text' => ['label' => 'Header text', 'type' => 'text', 'default' => null, 'max' => 500, 'sub' => 'Printed under the business name'],
                        'footer_text' => ['label' => 'Footer text', 'type' => 'text', 'default' => 'Thank you for dining with us!', 'max' => 500],
                    ]],
                    ['title' => 'Show on Receipt', 'fields' => [
                        'show_logo' => ['label' => 'Logo', 'type' => 'bool', 'default' => true],
                        'show_ntn' => ['label' => 'NTN', 'type' => 'bool', 'default' => true],
                        'show_waiter' => ['label' => 'Waiter', 'type' => 'bool', 'default' => true],
                        'show_table' => ['label' => 'Table', 'type' => 'bool', 'default' => true],
                        'show_customer' => ['label' => 'Customer', 'type' => 'bool', 'default' => true],
                        'show_tax_line' => ['label' => 'Tax line', 'type' => 'bool', 'default' => true],
                        'show_bank_accounts' => ['label' => 'Bank accounts', 'type' => 'bool', 'default' => false, 'sub' => 'Accounts marked "show on receipt"'],
                    ]],
                    ['title' => 'Paper', 'fields' => [
                        'paper_width' => ['label' => 'Paper width', 'type' => 'select', 'default' => '80', 'options' => ['80' => '80 mm', '58' => '58 mm']],
                        'copies' => ['label' => 'Copies', 'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 5],
                    ]],
                ],
            ],
            'printing' => [
                'label' => 'Printing',
                'description' => 'How receipts and kitchen tickets are printed',
                'sections' => [
                    ['title' => 'Print Method', 'fields' => [
                        'method' => ['label' => 'Print method', 'type' => 'select', 'default' => 'browser', 'options' => [
                            'qz' => 'QZ Tray (silent, thermal)',
                            'browser' => 'Browser print dialog',
                        ], 'sub' => 'QZ Tray must be installed on each counter / kitchen PC'],
                    ]],
                    ['title' => 'Kitchen', 'fields' => [
                        'kds' => ['label' => 'Kitchen display (KDS)', 'type' => 'bool', 'default' => true],
                        'kot' => ['label' => 'Print kitchen tickets (KOT)', 'type' => 'bool', 'default' => true, 'sub' => 'KDS and KOT can both be on'],
                        'auto_print_kot' => ['label' => 'Auto-print KOT on send', 'type' => 'bool', 'default' => true],
                        'kot_copies' => ['label' => 'KOT copies', 'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 5],
                        'void_slips' => ['label' => 'Print void slips', 'type' => 'bool', 'default' => true, 'sub' => 'Tell the kitchen when an item is voided'],
                    ]],
                ],
            ],
            'kitchen' => [
                'label' => 'Kitchen',
                'description' => 'Ticket timers and raw material consumption',
                'sections' => [
                    ['title' => 'Ticket Timers', 'fields' => [
                        'amber_after' => ['label' => 'Amber after', 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 240, 'suffix' => 'min'],
                        'red_after' => ['label' => 'Red after', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 480, 'suffix' => 'min'],
                    ]],
                    ['title' => 'Consumption', 'fields' => [
                        'confirm_consumption' => ['label' => 'Confirm raw material used on ready', 'type' => 'bool', 'default' => true, 'sub' => 'Cook checks / adjusts the recipe quantities'],
                    ]],
                ],
            ],
            'inventory' => [
                'label' => 'Inventory',
                'description' => 'Stock rules',
                'sections' => [
                    ['title' => 'Stock', 'fields' => [
                        'allow_negative_stock' => ['label' => 'Allow negative stock', 'type' => 'bool', 'default' => false],
                        'out_of_stock' => ['label' => 'Ready item out of stock', 'type' => 'select', 'default' => 'warn', 'options' => [
                            'warn' => 'Warn, allow the sale',
                            'block' => 'Block the sale',
                        ]],
                        'auto_confirm_consumption' => ['label' => 'Auto-confirm unconfirmed consumption', 'type' => 'select', 'default' => 'shift_close', 'options' => [
                            'order_completion' => 'When the order completes',
                            'shift_close' => 'When the shift closes',
                            'never' => 'Never',
                        ]],
                        'low_stock_alerts' => ['label' => 'Low-stock alerts', 'type' => 'bool', 'default' => true],
                    ]],
                ],
            ],
            'shifts' => [
                'label' => 'Shifts',
                'description' => 'Closing a shift',
                'sections' => [
                    ['title' => 'Closing', 'fields' => [
                        'blind_close' => ['label' => 'Blind close', 'type' => 'bool', 'default' => false, 'sub' => 'Cashier counts without seeing the expected cash'],
                        'require_denominations' => ['label' => 'Require denomination count', 'type' => 'bool', 'default' => true],
                        'max_difference' => ['label' => 'Max cash difference', 'type' => 'money', 'default' => 500, 'min' => 0, 'sub' => 'Above this, a manager must approve the close'],
                    ]],
                ],
            ],
            'approvals' => [
                'label' => 'Security & Approvals',
                'description' => 'When a manager PIN is required',
                'sections' => [
                    ['title' => 'Manager PIN Required For', 'fields' => [
                        'pin_void' => ['label' => 'Voiding an item', 'type' => 'bool', 'default' => true],
                        'pin_refund' => ['label' => 'Refunds', 'type' => 'bool', 'default' => true],
                        'pin_discount_above' => ['label' => 'Discounts above', 'type' => 'decimal', 'default' => 10, 'min' => 0, 'max' => 100, 'suffix' => '%', 'sub' => '0 = every discount'],
                        'pin_remove_service_charge' => ['label' => 'Removing service charge', 'type' => 'bool', 'default' => true],
                        'pin_reopen_shift' => ['label' => 'Reopening a shift', 'type' => 'bool', 'default' => true],
                    ]],
                ],
            ],
        ]);
    }

    /** @return list<string> */
    public static function groupKeys(): array
    {
        return array_keys(static::groups());
    }

    public static function hasGroup(string $group): bool
    {
        return array_key_exists($group, static::groups());
    }

    /** @return array<string, array> key => field definition */
    public static function fields(string $group): array
    {
        $definition = static::groups()[$group] ?? throw new InvalidArgumentException("Unknown settings group [{$group}].");

        return array_merge(...array_column($definition['sections'], 'fields'));
    }

    public static function field(string $dotKey): array
    {
        [$group, $key] = static::split($dotKey);

        return static::fields($group)[$key] ?? throw new InvalidArgumentException("Unknown setting [{$dotKey}].");
    }

    /** @return array<string, mixed> "group.key" => built-in default */
    public static function defaults(): array
    {
        return once(function () {
            $defaults = [];
            foreach (static::groupKeys() as $group) {
                foreach (static::fields($group) as $key => $field) {
                    $defaults["{$group}.{$key}"] = $field['default'];
                }
            }

            return $defaults;
        });
    }

    /** @return array{0: string, 1: string} */
    public static function split(string $dotKey): array
    {
        $parts = explode('.', $dotKey, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Setting keys look like \"group.key\", got [{$dotKey}].");
        }

        return $parts;
    }

    /** Validation rules for one field's value. */
    public static function rules(array $field): array
    {
        return match ($field['type']) {
            'bool' => ['boolean'],
            'int' => ['required', 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 999999)],
            'decimal' => ['required', 'numeric', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 999999), 'decimal:0,2'],
            'money' => ['required', 'numeric', 'min:'.($field['min'] ?? 0), 'max:9999999999.99', 'decimal:0,2'],
            'string' => [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:'.($field['max'] ?? 255)],
            'text' => ['nullable', 'string', 'max:'.($field['max'] ?? 1000)],
            'select' => ['required', Rule::in(array_map('strval', array_keys($field['options'])))],
            'time' => ['required', 'date_format:H:i'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        };
    }

    /** Validated input → the value stored in the JSON column. */
    public static function cast(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'decimal', 'money' => round((float) $value, 2),
            'string', 'text' => $value === null || trim((string) $value) === '' ? null : trim((string) $value),
            'select' => (string) $value,
            default => $value,
        };
    }
}
