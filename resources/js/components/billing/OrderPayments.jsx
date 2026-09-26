import { Banknote, Printer, Split, Undo2 } from 'lucide-react';
import { Tag } from '@/components/ui';
import { cx, dateTime, number } from '@/lib/format';

/**
 * Money on the order detail: parts of a split bill (print / pay each), payments taken
 * (method, account, reference, screenshot, cash received / change) and refunds.
 */
export default function OrderPayments({ order, refunds, canPrint, canPay, onPrint, onPay }) {
    const name = (id) => order.lines.find((l) => l.id === id)?.full_name ?? '—';

    return (
        <>
            {order.splits.length > 0 && (
                <>
                    <div className="section-title order-section">
                        <Split strokeWidth={1.5} />
                        Split Bill — {order.split_mode === 'items' ? 'by items' : 'equally'}
                    </div>
                    <div className="rgrid-wrap">
                        <table className="rgrid">
                            <thead>
                                <tr>
                                    <th>Part</th>
                                    <th>Items</th>
                                    <th className="text-right">Amount</th>
                                    <th className="text-right">Paid</th>
                                    <th className="text-right">Due</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {order.splits.map((s) => {
                                    const owes = Number(s.due) > 0;
                                    return (
                                        <tr key={s.id}>
                                            <td className="order-strong">{s.label}</td>
                                            <td className="cell-muted">{s.items.length ? s.items.map((it) => `${it.quantity} × ${name(it.item)}`).join(', ') : 'Equal share'}</td>
                                            <td className="mono text-right">{number(s.amount)}</td>
                                            <td className="mono text-right">{number(s.paid)}</td>
                                            <td className={cx('mono text-right', owes && 'order-strong')}>{owes ? number(s.due) : '—'}</td>
                                            <td className="text-right">
                                                <span className="order-row-tools">
                                                    {owes && canPrint && (
                                                        <button type="button" className="cart-tool" title={`Print ${s.label}’s bill`} aria-label={`Print ${s.label}’s bill`} onClick={() => onPrint(s)}>
                                                            <Printer size={13} strokeWidth={1.5} />
                                                        </button>
                                                    )}
                                                    {owes && canPay && (
                                                        <button type="button" className="text-link" onClick={() => onPay(s)}>
                                                            Pay
                                                        </button>
                                                    )}
                                                    {!owes && <Tag tone="accent">Paid</Tag>}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </>
            )}

            {order.payments.length > 0 && (
                <>
                    <div className="section-title order-section">
                        <Banknote strokeWidth={1.5} />
                        Payments
                    </div>
                    <div className="rgrid-wrap">
                        <table className="rgrid">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th className="text-right">Amount</th>
                                    <th className="text-right">Received / Change</th>
                                    <th>Taken By</th>
                                    <th className="text-right">Refunded</th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.payments.map((p) => (
                                    <tr key={p.id}>
                                        <td className="mono cell-muted">
                                            {dateTime(p.created_at)}
                                            {p.shift && <span className="cell-sub">{p.shift.code}</span>}
                                        </td>
                                        <td>
                                            <Tag tone={p.method.tone}>{p.method.label}</Tag>
                                            {(p.bank || p.split || p.rider) && (
                                                <span className="cell-sub">{[p.bank?.name, p.split?.label, p.rider && `Collected by ${p.rider.name}`].filter(Boolean).join(' · ')}</span>
                                            )}
                                        </td>
                                        <td className="mono">
                                            {p.reference_no ?? '—'}
                                            {p.proof_url && (
                                                <a className="cell-sub text-link" href={p.proof_url} target="_blank" rel="noreferrer">
                                                    Screenshot
                                                </a>
                                            )}
                                        </td>
                                        <td className="mono text-right order-strong">{number(p.amount)}</td>
                                        <td className="mono text-right cell-muted">{p.tendered ? `${number(p.tendered)} / ${number(p.change)}` : '—'}</td>
                                        <td>{p.received_by?.name}</td>
                                        <td className={cx('mono text-right', Number(p.refunded_total) > 0 && 'order-minus')}>
                                            {Number(p.refunded_total) > 0 ? `(${number(p.refunded_total)})` : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}

            {refunds.length > 0 && (
                <>
                    <div className="section-title order-section">
                        <Undo2 strokeWidth={1.5} />
                        Refunds
                    </div>
                    <div className="rgrid-wrap">
                        <table className="rgrid">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Given Back As</th>
                                    <th>Reason</th>
                                    <th className="text-right">Amount</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                {refunds.map((r) => (
                                    <tr key={r.id}>
                                        <td className="mono cell-muted">
                                            {dateTime(r.created_at)}
                                            {r.shift && <span className="cell-sub">{r.shift.code}</span>}
                                        </td>
                                        <td>
                                            {r.method.label}
                                            {r.bank && (
                                                <span className="cell-sub">
                                                    {r.bank.name}
                                                    {r.reference_no ? ` · ${r.reference_no}` : ''}
                                                </span>
                                            )}
                                        </td>
                                        <td className="cell-muted">{r.reason}</td>
                                        <td className="mono text-right order-minus">({number(r.amount)})</td>
                                        <td>
                                            {r.refunded_by?.name}
                                            {r.approved_by && <span className="cell-sub">Approved by {r.approved_by.name}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </>
    );
}
