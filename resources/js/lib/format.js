// Display formatters. Values arrive from the server as strings/numbers already
// calculated there — these only format, never recalculate money.

const moneyFmt = new Intl.NumberFormat('en-PK', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const qtyFmt = new Intl.NumberFormat('en-PK', { minimumFractionDigits: 0, maximumFractionDigits: 3 });

// Display settings shared by the server (context.settings); set by AppLayout.
const display = { currencySymbol: 'Rs', hour12: true };

export function configureFormat(settings) {
    if (!settings) return;
    display.currencySymbol = settings.currency_symbol || 'Rs';
    display.hour12 = settings.time_format !== '24h';
}

/** "Rs 1,250" — the symbol comes from the General settings. */
export function money(value, symbol = display.currencySymbol) {
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
const timeFmt = {
    format: (d) => d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: display.hour12 }),
};

/** "19:00" (from a TIME value) → "7:00 PM" or "19:00" per the time format setting. */
export function clock(hhmm) {
    if (!hhmm) return '—';
    const [h, m] = hhmm.split(':').map(Number);
    return timeFmt.format(new Date(2000, 0, 1, h, m));
}

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

/** ISO string → "3:15 PM" (or "15:15") */
export function time(iso) {
    return iso ? timeFmt.format(new Date(iso)) : '—';
}

/** "12 min", "1 h 05" since `iso` (waiting / seated time). */
export function since(iso, now = Date.now()) {
    const minutes = Math.max(0, Math.floor((now - Date.parse(iso)) / 60000));
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
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
