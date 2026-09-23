import { Inbox } from 'lucide-react';
import Corners from './Corners';

export default function EmptyState({ icon: Icon = Inbox, title = 'Nothing here yet', children, action }) {
    return (
        <div className="empty-state">
            <Corners />
            <Icon size={28} strokeWidth={1.5} />
            <div className="empty-state-title">{title}</div>
            {children && <div className="empty-state-text">{children}</div>}
            {action}
        </div>
    );
}
