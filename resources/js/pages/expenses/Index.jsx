import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Banknote, CalendarDays, FileText, Hash, Landmark, Paperclip, Pencil, Plus, Tags, Trash2, UserRound } from 'lucide-react';
import {
    Button,
    ContactBlock,
    DataTable,
    DetailPanel,
    Dialog,
    Drawer,
    Field,
    FilterBar,
    FilterSelect,
    FormGrid,
    InfoCards,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Select,
    StatCard,
    StatGrid,
    Tag,
    Toggle,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { cx, date, dateTime, money } from '@/lib/format';

function ExpenseDrawer({ categories, bankAccounts, myShift, today, onClose }) {
    const active = categories.filter((c) => c.is_active);
    const { data, setData, post, processing, errors } = useForm({
        category: active[0]?.id ?? '',
        amount: '',
        method: myShift ? 'cash' : 'bank_transfer',
        bank: bankAccounts[0]?.value ?? '',
        business_date: today,
        description: '',
        reference: '',
        attachment: null,
    });
    const cash = data.method === 'cash';

    function submit() {
        post(route('expenses.store'), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title="Add Expense"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : `Add Expense${data.amount ? ` — ${money(data.amount)}` : ''}`}
                    </Button>
                </>
            }
        >
            <div className="cash-type-buttons pay-methods pos-disc-types" role="radiogroup" aria-label="Paid from">
                {[
                    ['cash', myShift ? `Cash · ${myShift.code}` : 'Cash (open your shift)', Banknote, !myShift],
                    ['bank_transfer', 'Bank Account', Landmark, !bankAccounts.length],
                ].map(([value, label, Icon, disabled]) => (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={data.method === value}
                        className={cx('cash-type-btn', data.method === value && 'on')}
                        disabled={disabled}
                        onClick={() => setData('method', value)}
                    >
                        <Icon size={14} strokeWidth={1.5} />
                        {label}
                    </button>
                ))}
            </div>
            {errors.method && <div className="field-error">{errors.method}</div>}

            <FormGrid>
                <Field label="Category" required error={errors.category}>
                    <Select value={data.category} invalid={errors.category} options={active.map((c) => ({ value: c.id, label: c.name }))} onChange={(e) => setData('category', e.target.value)} />
                </Field>
                <Field label="Amount" required error={errors.amount} hint={cash ? 'Comes out of your drawer' : undefined}>
                    <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={data.amount} invalid={errors.amount} onChange={(e) => setData('amount', e.target.value)} autoFocus />
                </Field>
                <Field label="Description" required full error={errors.description}>
                    <Input value={data.description} invalid={errors.description} placeholder="What was it for? e.g. Electricity bill — September" onChange={(e) => setData('description', e.target.value)} />
                </Field>
                {!cash && (
                    <Field label="Bank account" required error={errors.bank}>
                        <Select value={data.bank} invalid={errors.bank} options={bankAccounts} onChange={(e) => setData('bank', e.target.value)} />
                    </Field>
                )}
                {!cash && (
                    <Field label="Business day" error={errors.business_date} hint="The day it counts for">
                        <Input type="date" value={data.business_date} max={today} onChange={(e) => setData('business_date', e.target.value)} />
                    </Field>
                )}
                <Field label="Reference no." error={errors.reference} hint="Bill / receipt / transfer no.">
                    <Input mono value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                </Field>
                <Field label="Receipt / bill" full error={errors.attachment}>
                    <label className="stg-upload-box">
                        <Paperclip size={18} strokeWidth={1.5} />
                        <span>{data.attachment ? data.attachment.name : 'Click to attach a photo or PDF (max 5 MB)'}</span>
                        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className="sr-only" onChange={(e) => setData('attachment', e.target.files?.[0] ?? null)} />
                    </label>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

function VoidDialog({ expense, onClose, onDone }) {
    const { data, setData, put, processing, errors } = useForm({ reason: '' });

    function submit(e) {
        e.preventDefault();
        put(route('expenses.void', expense.id), { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Void ${expense.code} — ${money(expense.amount)}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Keep it
                    </Button>
                    <Button variant="danger" type="submit" form="expense-void-form" disabled={processing}>
                        {processing ? 'Voiding…' : 'Void Expense'}
                    </Button>
                </>
            }
        >
            <form id="expense-void-form" onSubmit={submit} noValidate>
                {expense.method.value === 'cash' && <p className="dialog-note">{money(expense.amount)} goes back into {expense.shift ? `${expense.shift.code} if it is still open, else your open shift` : 'your open shift'} as cash in.</p>}
                <Field label="Reason" required error={errors.reason}>
                    <Input value={data.reason} invalid={errors.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="e.g. Entered twice" autoFocus />
                </Field>
            </form>
        </Dialog>
    );
}

function CategoriesDialog({ categories, onClose }) {
    const can = useCan();
    const [editing, setEditing] = useState(null);
    const add = useForm({ name: '', is_active: true });
    const edit = useForm({ name: '', is_active: true });
    const trash = useTrash({ noun: 'expense category', destroy: 'expense-categories.destroy', restore: 'expense-categories.restore' });

    function addCategory(e) {
        e.preventDefault();
        add.post(route('expense-categories.store'), { preserveScroll: true, onSuccess: () => add.reset() });
    }

    function startEdit(c) {
        setEditing(c.id);
        edit.setData({ name: c.name, is_active: c.is_active });
        edit.clearErrors();
    }

    function save(c, changes = {}) {
        edit.transform((d) => ({ ...d, ...changes }));
        edit.put(route('expense-categories.update', c.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
    }

    return (
        <Dialog open onClose={onClose} title="Expense Categories" className="exp-cat-dialog">
            {can('expense-categories.store') && (
                <form className="exp-cat-add-row" onSubmit={addCategory} noValidate>
                    <Input value={add.data.name} invalid={add.errors.name} placeholder="New category name…" onChange={(e) => add.setData('name', e.target.value)} />
                    <Button variant="primary" type="submit" disabled={add.processing || !add.data.name.trim()}>
                        Add
                    </Button>
                </form>
            )}
            {add.errors.name && <div className="field-error">{add.errors.name}</div>}

            <div className="exp-cat-list">
                {categories.map((c) =>
                    editing === c.id ? (
                        <form
                            key={c.id}
                            className="exp-cat-row"
                            onSubmit={(e) => {
                                e.preventDefault();
                                save(c);
                            }}
                        >
                            <Input value={edit.data.name} invalid={edit.errors.name} onChange={(e) => edit.setData('name', e.target.value)} autoFocus />
                            <Button variant="primary" type="submit" disabled={edit.processing}>
                                Save
                            </Button>
                            <Button variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                        </form>
                    ) : (
                        <div key={c.id} className={cx('exp-cat-row', !c.is_active && 'row-dim')}>
                            <span className="exp-cat-name">{c.name}</span>
                            <span className="exp-cat-count">{c.expenses_count} used</span>
                            {can('expense-categories.update') && (
                                <>
                                    <Toggle checked={c.is_active} label={`${c.name} active`} onChange={(v) => save(c, { name: c.name, is_active: v })} />
                                    <button type="button" className="exp-cat-icon-btn" aria-label={`Rename ${c.name}`} onClick={() => startEdit(c)}>
                                        <Pencil size={13} strokeWidth={1.5} />
                                    </button>
                                </>
                            )}
                            {can('expense-categories.destroy') && (
                                <button type="button" className="exp-cat-icon-btn exp-cat-del" aria-label={`Trash ${c.name}`} onClick={() => trash.ask(c)}>
                                    <Trash2 size={13} strokeWidth={1.5} />
                                </button>
                            )}
                        </div>
                    ),
                )}
            </div>
            {edit.errors.name && <div className="field-error">{edit.errors.name}</div>}
            {trash.dialog}
        </Dialog>
    );
}

/** Expenses of the branch (PLAN §4.18): paid from a drawer or a bank account, voided instead of deleted. */
export default function ExpensesIndex({ expenses, filters, stats, categories, bankAccounts, myShift, today }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [adding, setAdding] = useState(false);
    const [showing, setShowing] = useState(null);
    const [voiding, setVoiding] = useState(null);
    const [managing, setManaging] = useState(false);

    const columns = [
        {
            key: 'code',
            label: 'Expense',
            render: (e) => (
                <>
                    <span className="si-id-link mono">{e.code}</span>
                    {e.reference_no && <span className="cell-sub mono">{e.reference_no}</span>}
                </>
            ),
        },
        { key: 'date', label: 'Business Day', render: (e) => date(e.business_date) },
        { key: 'category', label: 'Category', render: (e) => <Tag tone="neutral">{e.category?.name}</Tag> },
        {
            key: 'description',
            label: 'Description',
            className: 'cell-strong',
            render: (e) => (
                <>
                    {e.description}
                    {e.attachment_url && <Paperclip size={11} strokeWidth={1.5} className="exp-clip" aria-label="Has attachment" />}
                </>
            ),
        },
        {
            key: 'method',
            label: 'Paid From',
            render: (e) => (
                <>
                    <Tag tone={e.method.tone}>{e.method.label}</Tag>
                    <span className="cell-sub">{e.shift?.code ?? e.bank?.name}</span>
                </>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            align: 'right',
            render: (e) => (
                <>
                    <span className={cx('mono', e.voided && 'exp-voided')}>{money(e.amount)}</span>
                    {e.voided && <span className="cell-sub">Voided</span>}
                </>
            ),
        },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Expenses"
                primary={
                    can('expenses.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setAdding(true)}>
                            Add Expense
                        </Button>
                    )
                }
            >
                {(can('expense-categories.store') || can('expense-categories.update')) && (
                    <Button icon={Tags} onClick={() => setManaging(true)}>
                        Categories
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    {money(stats.today)} today · {money(stats.this_month)} in {stats.month_label} · {myShift ? `cash comes out of ${myShift.code}` : 'open your shift to pay in cash'}
                </span>
            </PageStatus>

            <StatGrid>
                <StatCard label={`Total (${stats.month_label})`} value={money(stats.this_month)} sub={`${money(stats.today)} today`} tone="accent" />
                <StatCard label={`Total (${stats.last_month_label})`} value={money(stats.last_month)} sub="last month" tone="neutral" />
                <StatCard label={`Cash Spent (${stats.month_label})`} value={money(stats.cash_this_month)} sub="from shift drawers" tone="neutral" />
                <StatCard label={`Largest Category (${stats.month_label})`} value={stats.top_category?.name ?? '—'} sub={stats.top_category ? money(stats.top_category.total) : 'nothing spent yet'} tone="neutral" />
            </StatGrid>

            <FilterBar count={`${expenses.meta.total} expenses`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Description, reference, EXP no.…" />
                <FilterSelect label="Category" value={query.category} onChange={(v) => setQuery('category', v)} options={[{ value: '', label: 'All' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]} />
                <FilterSelect
                    label="Paid from"
                    value={query.method}
                    onChange={(v) => setQuery('method', v)}
                    options={[
                        { value: '', label: 'All' },
                        { value: 'cash', label: 'Cash' },
                        { value: 'bank_transfer', label: 'Bank' },
                    ]}
                />
                <FilterSelect
                    label="Show"
                    value={query.status}
                    onChange={(v) => setQuery('status', v)}
                    options={[
                        { value: '', label: 'Recorded' },
                        { value: 'voided', label: 'Voided' },
                        { value: 'all', label: 'All' },
                    ]}
                />
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={expenses.data}
                meta={expenses.meta}
                noun="expenses"
                empty="No expenses"
                onRowClick={setShowing}
                rowClassName={(e) => (e.voided ? 'row-dim' : undefined)}
                stack
            />

            <DetailPanel
                open={Boolean(showing)}
                onClose={() => setShowing(null)}
                avatar={<Banknote size={18} strokeWidth={1.5} />}
                title={showing ? `${showing.code} — ${money(showing.amount)}` : ''}
                sub={showing ? `${showing.category?.name} · ${date(showing.business_date)}` : ''}
                actions={
                    showing &&
                    !showing.voided &&
                    can('expenses.void') && (
                        <Button variant="ghost" className="text-danger" onClick={() => setVoiding(showing)}>
                            Void
                        </Button>
                    )
                }
            >
                {showing && (
                    <>
                        <InfoCards
                            items={[
                                { label: 'Amount', value: money(showing.amount) },
                                { label: 'Paid From', value: showing.method.label },
                                { label: 'Status', value: showing.voided ? 'Voided' : 'Recorded' },
                            ]}
                        />
                        <ContactBlock
                            rows={[
                                { icon: FileText, text: showing.description },
                                { icon: showing.method.value === 'cash' ? Banknote : Landmark, text: showing.shift ? `Cash from ${showing.shift.code}` : showing.bank?.name },
                                { icon: Hash, text: showing.reference_no, mono: true },
                                { icon: CalendarDays, text: `Recorded ${dateTime(showing.created_at)}` },
                                { icon: UserRound, text: showing.created_by?.name && `By ${showing.created_by.name}` },
                                { icon: Trash2, text: showing.voided && `Voided ${dateTime(showing.voided_at)} by ${showing.voided_by?.name ?? '—'} — ${showing.void_reason}` },
                            ]}
                        />
                        {showing.attachment_url && (
                            <div className="exp-attachment">
                                {showing.attachment_is_pdf ? (
                                    <a className="btn btn-secondary" href={showing.attachment_url} target="_blank" rel="noreferrer">
                                        <Paperclip strokeWidth={1.5} />
                                        Open the PDF
                                    </a>
                                ) : (
                                    <a href={showing.attachment_url} target="_blank" rel="noreferrer">
                                        <img src={showing.attachment_url} alt={`Receipt of ${showing.code}`} />
                                    </a>
                                )}
                            </div>
                        )}
                    </>
                )}
            </DetailPanel>

            {adding && <ExpenseDrawer categories={categories} bankAccounts={bankAccounts} myShift={myShift} today={today} onClose={() => setAdding(false)} />}
            {voiding && (
                <VoidDialog
                    expense={voiding}
                    onClose={() => setVoiding(null)}
                    onDone={() => {
                        setVoiding(null);
                        setShowing(null);
                        router.reload({ only: ['expenses', 'stats'] });
                    }}
                />
            )}
            {managing && <CategoriesDialog categories={categories} onClose={() => setManaging(false)} />}
        </PageBody>
    );
}
