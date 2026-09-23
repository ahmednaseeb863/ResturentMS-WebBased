/** Coloured dot + label (pos-react `.sdot`). status: active | inactive | draft | … */
export default function StatusDot({ status = 'active', children }) {
    return <span className={`sdot s-${status}`}>{children}</span>;
}
