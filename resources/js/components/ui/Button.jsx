import { Link } from '@inertiajs/react';
import { cx } from '@/lib/format';

/**
 * pos-react `.btn`. variant: primary (the only filled object) | secondary (outlined)
 * | ghost (text only) | danger. Pass `href` to render an Inertia <Link>.
 */
export default function Button({
    variant = 'secondary',
    icon: Icon,
    href,
    type = 'button',
    className,
    children,
    ...props
}) {
    const classes = cx('btn', `btn-${variant}`, className);
    const content = (
        <>
            {Icon && <Icon strokeWidth={1.5} />}
            {children}
        </>
    );

    if (href) {
        return (
            <Link href={href} className={classes} {...props}>
                {content}
            </Link>
        );
    }

    return (
        <button type={type} className={classes} {...props}>
            {content}
        </button>
    );
}
