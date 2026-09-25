import { CheckItem } from '@/components/ui';

/** Dine-in / Takeaway / Delivery ticks (the item is sold for these order types). */
export default function OrderTypePicker({ value, onChange, options }) {
    function toggle(type, on) {
        onChange(on ? [...value, type] : value.filter((v) => v !== type));
    }

    return (
        <div className="order-type-picker">
            {options.map((o) => (
                <CheckItem key={o.value} checked={value.includes(o.value)} onChange={(on) => toggle(o.value, on)}>
                    {o.label}
                </CheckItem>
            ))}
        </div>
    );
}
