import { initials } from '@/lib/format';

export default function Avatar({ name }) {
    return <div className="cust-avatar-sm">{initials(name)}</div>;
}
