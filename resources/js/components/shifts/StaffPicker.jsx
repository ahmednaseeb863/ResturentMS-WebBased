import { CheckItem } from '@/components/ui';

/** Tick the staff on duty. options: [{ value: uuid, label, sub }] */
export default function StaffPicker({ options, value, onChange }) {
    function toggle(id, on) {
        onChange(on ? [...value, id] : value.filter((v) => v !== id));
    }

    return (
        <div className="order-type-picker staff-picker">
            {options.map((o) => (
                <CheckItem key={o.value} checked={value.includes(o.value)} onChange={(on) => toggle(o.value, on)}>
                    {o.label}
                    {o.sub && <span className="staff-picker-sub">{o.sub}</span>}
                </CheckItem>
            ))}
        </div>
    );
}
