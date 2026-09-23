import { cx } from '@/lib/format';

/** Small uppercase label. tone: accent | neutral | info | warn */
export default function Tag({ tone = 'neutral', className, children }) {
    return <span className={cx('tag', `tag-${tone}`, className)}>{children}</span>;
}
