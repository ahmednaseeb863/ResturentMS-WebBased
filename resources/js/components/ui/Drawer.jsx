import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import useOverlay from '@/hooks/useOverlay';
import { cx } from '@/lib/format';

/**
 * Right-side slide-in panel for add/edit forms (pos-react `.cust-overlay` + `.cust-modal`).
 * Pass `onSubmit` to make the panel a <form>; footer buttons can then be type="submit".
 */
export default function Drawer({ open, onClose, title, footer, wide = false, onSubmit, children }) {
    useOverlay(open, onClose);
    if (!open) return null;

    const Panel = onSubmit ? 'form' : 'div';
    const formProps = onSubmit
        ? {
              noValidate: true,
              onSubmit: (e) => {
                  e.preventDefault();
                  onSubmit(e);
              },
          }
        : {};

    return createPortal(
        <div className="cust-overlay" onClick={onClose}>
            <Panel
                className={cx('cust-modal', wide && 'drawer-wide')}
                role="dialog"
                aria-modal="true"
                aria-label={typeof title === 'string' ? title : undefined}
                onClick={(e) => e.stopPropagation()}
                {...formProps}
            >
                <div className="cust-modal-header">
                    <span>{title}</span>
                    <button type="button" className="cust-close" onClick={onClose} aria-label="Close">
                        <X size={14} strokeWidth={1.5} />
                    </button>
                </div>
                <div className="cust-modal-body">{children}</div>
                {footer && <div className="cust-modal-footer">{footer}</div>}
            </Panel>
        </div>,
        document.body,
    );
}
