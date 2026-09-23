import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import useOverlay from '@/hooks/useOverlay';

/**
 * Right-side profile panel (pos-react CustomerDetail: `.cust-detail-panel`).
 * Header = avatar + title/sub + actions; children = info cards, contact block, tabs…
 */
export default function DetailPanel({ open, onClose, avatar, title, sub, actions, children }) {
    useOverlay(open, onClose);
    if (!open) return null;

    return createPortal(
        <div className="cust-overlay" onClick={onClose}>
            <div
                className="cust-detail-panel"
                role="dialog"
                aria-modal="true"
                aria-label={typeof title === 'string' ? title : undefined}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="cust-detail-header">
                    {avatar && <div className="cust-detail-avatar">{avatar}</div>}
                    <div className="cust-detail-title">
                        <div className="cust-detail-name">{title}</div>
                        {sub && <div className="cust-detail-id">{sub}</div>}
                    </div>
                    <div className="detail-actions">
                        {actions}
                        <button type="button" className="cust-close" onClick={onClose} aria-label="Close">
                            <X size={14} strokeWidth={1.5} />
                        </button>
                    </div>
                </div>
                {children}
            </div>
        </div>,
        document.body,
    );
}

/** Row of label/value cards under the header (`.cust-info-grid`). items: [{ label, value, tone }] */
export function InfoCards({ items }) {
    return (
        <div className="cust-info-grid">
            {items.map((i) => (
                <div key={i.label} className="cust-info-card">
                    <div className="cust-info-label">{i.label}</div>
                    <div className={`cust-info-value ${i.className ?? ''}`}>{i.value}</div>
                </div>
            ))}
        </div>
    );
}

/** Icon + text lines (`.cust-contact-block`). rows: [{ icon: LucideIcon, text, mono }] — empty texts skipped. */
export function ContactBlock({ rows }) {
    const shown = rows.filter((r) => r.text);
    if (!shown.length) return null;

    return (
        <div className="cust-contact-block">
            {shown.map(({ icon: Icon, text, mono }, i) => (
                <div key={i} className="cust-contact-row">
                    <Icon size={13} strokeWidth={1.5} />
                    <span className={mono ? 'mono' : undefined}>{text}</span>
                </div>
            ))}
        </div>
    );
}
