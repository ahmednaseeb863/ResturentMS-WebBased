// Live bill of the POS cart — the same steps as App\Support\OrderPricing, so the cashier
// sees the total while picking items. The server recalculates everything when the order
// is saved; its totals are the real ones.

const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;

/** Amount off `base`: 0 below the minimum, never above the cap or the base. */
export function discountOn({ type, value, max = null, min = null }, base) {
    if (base <= 0 || (min !== null && min !== undefined && base < Number(min))) return 0;
    let off = type === 'percent' ? (base * Number(value)) / 100 : Number(value);
    if (max !== null && max !== undefined) off = Math.min(off, Number(max));
    return round2(Math.max(0, Math.min(off, base)));
}

/** Payments setting "round the bill to": none / 1 / 5 / 10. */
export function roundBill(amount, rule) {
    const step = parseInt(rule, 10);
    return step > 0 ? round2(Math.round(amount / step) * step) : round2(amount);
}

/**
 * lines: [{ gross, discount }]; orderDiscount: { type, value, max, min } | null.
 * rates: { type, serviceRate, serviceRemoved, deliveryFee, taxRate, rounding }.
 */
export function bill(lines, orderDiscount, rates) {
    const items = round2(lines.reduce((s, l) => s + Number(l.gross), 0));
    const lineDiscounts = round2(lines.reduce((s, l) => s + Number(l.discount || 0), 0));
    const orderOff = orderDiscount ? discountOn(orderDiscount, round2(items - lineDiscounts)) : 0;
    const discount = round2(Math.min(items, lineDiscounts + orderOff));
    const net = round2(items - discount);
    const service = rates.type === 'dine_in' && !rates.serviceRemoved ? round2((net * rates.serviceRate) / 100) : 0;
    const delivery = rates.type === 'delivery' ? round2(rates.deliveryFee) : 0;
    const taxBase = round2(net + service + delivery);
    const tax = round2((taxBase * rates.taxRate) / 100);
    const before = round2(taxBase + tax);
    const grand = roundBill(before, rates.rounding);

    return { items, lineDiscounts, orderOff, discount, net, service, delivery, tax, roundOff: round2(grand - before), grand };
}
