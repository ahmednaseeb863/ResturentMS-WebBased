import { cx } from '@/lib/format';

/** Standard page padding + vertical rhythm (pos-react `.products-page`). */
export default function PageBody({ className, children }) {
    return <div className={cx('products-page', className)}>{children}</div>;
}
