import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import useOverlay from '@/hooks/useOverlay';
import { cx } from '@/lib/format';

/**
 * Small centred modal for confirmations and short forms (same header/body/footer parts as
 * the drawer). For a form, give the <form> an id and the footer button `type="submit" form={id}`.
 */
export default function Dialog({ open, onClose, title, footer, className, children }) {
    useOverlay(open, onClose);
    if (!open) return null;

    return createPortal(
        <div className="cust-overlay overlay-center" onClick={onClose}>
            <div className={cx('ui-dialog', className)} role="alertdialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
                <div className="cust-modal-header">
                    <span>{title}</span>
                    <button type="button" className="cust-close" onClick={onClose} aria-label="Close">
                        <X size={14} strokeWidth={1.5} />
                    </button>
                </div>
                <div className="cust-modal-body">{children}</div>
                {footer && <div className="cust-modal-footer">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
