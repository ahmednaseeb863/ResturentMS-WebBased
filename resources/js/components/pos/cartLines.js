import { discountOn } from '@/lib/pricing';

// A cart line (not sent yet):
// { key, type, id, name, variant: {id,name,price}|null, modifiers: [{id,name,price}],
//   picks: [{slot, option, label, extra}], quantity, notes, discount: {discount,type,value,reason,name,max,min}|null }

let seq = 0;
export const newKey = () => `l${Date.now().toString(36)}${(seq++).toString(36)}`;

/** Price of one: size (or item) price, a deal's price plus its picks' extras. */
export function unitPrice(line, item) {
    if (line.type === 'deal') return Number(item?.price ?? 0) + line.picks.reduce((s, p) => s + Number(p.extra || 0), 0);
    return Number(line.variant?.price ?? item?.price ?? 0);
}

export function modifiersTotal(line) {
    return line.modifiers.reduce((s, m) => s + Number(m.price), 0);
}

export function lineGross(line, item) {
    return Math.round((unitPrice(line, item) + modifiersTotal(line)) * line.quantity * 100) / 100;
}

export function lineDiscount(line, item) {
    return line.discount ? discountOn(line.discount, lineGross(line, item)) : 0;
}

/** "Large · Garlic, Cheese" / "Coke, Fries" (deal picks) */
export function lineDetail(line) {
    const parts = [];
    if (line.variant) parts.push(line.variant.name);
    if (line.modifiers.length) parts.push(line.modifiers.map((m) => m.name).join(', '));
    if (line.picks.length) parts.push(line.picks.map((p) => p.label).join(', '));
    return parts.join(' · ');
}

/** Same item, size, add-ons, picks and notes → add to the quantity instead of a new line. */
export function sameLine(a, b) {
    return (
        a.type === b.type &&
        a.id === b.id &&
        (a.variant?.id ?? null) === (b.variant?.id ?? null) &&
        a.modifiers.map((m) => m.id).join() === b.modifiers.map((m) => m.id).join() &&
        a.picks.map((p) => p.option).join() === b.picks.map((p) => p.option).join() &&
        (a.notes || '') === (b.notes || '') &&
        !a.discount &&
        !b.discount
    );
}

/** What the server expects for one line (uuids only). */
export function payloadOf(line) {
    return {
        type: line.type,
        id: line.id,
        variant: line.variant?.id ?? null,
        modifiers: line.modifiers.map((m) => m.id),
        picks: line.picks.map((p) => ({ slot: p.slot, option: p.option })),
        quantity: line.quantity,
        notes: line.notes || null,
        discount: line.discount
            ? { discount: line.discount.discount, type: line.discount.type, value: line.discount.value, reason: line.discount.reason }
            : null,
    };
}

/** Rebuild the cart of a held order from its saved lines and today's menu. */
export function fromHeld(held, index, discounts) {
    return held.map((h) => {
        const item = index.get(`${h.type}:${h.id}`);
        const groups = item?.groups ?? [];
        const allModifiers = groups.flatMap((g) => g.modifiers);
        const preset = h.discount?.discount ? discounts.find((d) => d.id === h.discount.discount) : null;

        return {
            key: newKey(),
            type: h.type,
            id: h.id,
            name: item?.name ?? h.name,
            variant: item?.variants?.find((v) => v.id === h.variant) ?? null,
            modifiers: h.modifiers.map((id) => allModifiers.find((m) => m.id === id)).filter(Boolean),
            picks: (h.picks ?? []).map((p) => {
                const option = item?.slots?.find((s) => s.id === p.slot)?.options.find((o) => o.id === p.option);
                return { slot: p.slot, option: p.option, label: option?.label ?? '', extra: option?.extra ?? 0 };
            }),
            quantity: h.quantity,
            notes: h.notes ?? '',
            discount: h.discount
                ? {
                      ...h.discount,
                      name: preset?.name ?? 'Manual discount',
                      max: preset?.max_amount ?? null,
                      min: preset?.min_amount ?? null,
                  }
                : null,
        };
    });
}
