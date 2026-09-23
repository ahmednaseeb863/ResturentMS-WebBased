// Display formatters. Values arrive from the server as strings/numbers already
// calculated there — these only format, never recalculate money.

const moneyFmt = new Intl.NumberFormat('en-PK', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const qtyFmt = new Intl.NumberFormat('en-PK', { minimumFractionDigits: 0, maximumFractionDigits: 3 });

/** "Rs 1,250" — currency symbol comes from settings later (Phase 3). */
export function money(value, symbol = 'Rs') {
    const n = Number(value ?? 0);
    const text = moneyFmt.format(Math.abs(n));
    return n < 0 ? `(${symbol} ${text})` : `${symbol} ${text}`;
}

/** Plain number with grouping, no symbol (table cells). */
export function number(value) {
    return moneyFmt.format(Number(value ?? 0));
}

export function qty(value, unit = '') {
    const text = qtyFmt.format(Number(value ?? 0));
    return unit ? `${text} ${unit}` : text;
}

export function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

/** Join class names, skipping falsy values. */
export function cx(...parts) {
    return parts.filter(Boolean).join(' ');
}
