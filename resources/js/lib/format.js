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

const dateFmt = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
const timeFmt = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' });

/** ISO string → "23 Sep 2026" */
export function date(iso) {
    return iso ? dateFmt.format(new Date(iso)) : '—';
}

/** ISO string → "23 Sep 2026, 3:15 PM" (today → "Today, 3:15 PM") */
export function dateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    const day = d.toDateString() === new Date().toDateString() ? 'Today' : dateFmt.format(d);
    return `${day}, ${timeFmt.format(d)}`;
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
