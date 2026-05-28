import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import toast from 'react-hot-toast';
import {
  X, ClipboardPaste, CheckCircle2, AlertTriangle, XCircle, Loader2, Trash2, Plus, AlertOctagon,
} from 'lucide-react';
import {
  menuApi,
  type PastePreviewResult,
  type PastePreviewGroup,
  type PastePreviewRow,
  type PasteCommitResult,
  type PasteDuplicateAction,
  type PasteEditedGroup,
} from '../../../api/restaurants';

const UNITS = ['oz', 'lb', 'g', 'kg', 'each', 'tbsp', 'tsp', 'cup', 'ml', 'l'];
const DRAFT_KEY_PREFIX = 'smappen_paste_draft_';

/**
 * Paste-from-spreadsheet flow. Phase 2 improvements:
 *   - localStorage-persist the paste text + scale per restaurant, so an
 *     accidental close doesn't lose 40 rows of typing
 *   - "scale" multiplier so a yield-10 batch can be entered as per-plate
 *     in one click
 *   - server-side plate-cost estimate per group in the preview, so the
 *     operator sees "$4.20/plate" before committing
 *   - inline-editable preview rows — fix a typo without re-pasting
 *   - duplicate-recipe handling: each group with a name collision gets
 *     surfaced; operator chooses skip / replace / create-new for the
 *     whole commit
 */
export default function PasteFromSpreadsheetModal({
  restaurantId,
  onClose,
  onCommitted,
}: {
  restaurantId: string;
  onClose: () => void;
  onCommitted: (result: PasteCommitResult) => void;
}) {
  const draftKey = DRAFT_KEY_PREFIX + restaurantId;
  const [text, setText] = useState<string>(() => {
    try { return localStorage.getItem(draftKey + '_text') ?? ''; } catch { return ''; }
  });
  const [scale, setScale] = useState<number>(() => {
    try {
      const raw = localStorage.getItem(draftKey + '_scale');
      const n = raw ? Number(raw) : 1;
      return Number.isFinite(n) && n > 0 ? n : 1;
    } catch { return 1; }
  });
  const [preview, setPreview] = useState<PastePreviewResult | null>(null);
  const [editedGroups, setEditedGroups] = useState<PastePreviewGroup[] | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [committing, setCommitting] = useState(false);
  const [includeWarnings, setIncludeWarnings] = useState(true);
  const [dupAction, setDupAction] = useState<PasteDuplicateAction>('skip');

  // Persist text + scale as the operator types, debounced.
  useEffect(() => {
    const t = setTimeout(() => {
      try {
        if (text) localStorage.setItem(draftKey + '_text', text);
        else localStorage.removeItem(draftKey + '_text');
      } catch { /* quota or disabled storage */ }
    }, 250);
    return () => clearTimeout(t);
  }, [text, draftKey]);
  useEffect(() => {
    try { localStorage.setItem(draftKey + '_scale', String(scale)); } catch { /* */ }
  }, [scale, draftKey]);

  async function doPreview() {
    if (!text.trim()) {
      toast.error('Paste some data first');
      return;
    }
    setPreviewing(true);
    try {
      const result = await menuApi.previewPaste(restaurantId, text, scale);
      setPreview(result);
      // Seed editable copy so the operator can fix rows inline.
      setEditedGroups(result.groups.map((g) => ({ ...g, rows: g.rows.map((r) => ({ ...r })) })));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Preview failed');
    } finally {
      setPreviewing(false);
    }
  }

  async function doCommit() {
    if (!editedGroups) return;
    setCommitting(true);
    try {
      const groups: PasteEditedGroup[] = editedGroups.map((g) => ({
        item_name: g.item_name,
        rows: g.rows
          .filter((r) => r.status !== 'error' || (r.ingredient_key && r.qty > 0 && r.unit))
          .map((r) => ({ ingredient_key: r.ingredient_key, qty: Number(r.qty), unit: r.unit })),
      }));
      const result = await menuApi.commitPaste(restaurantId, {
        groups,
        includeWarnings,
        scale: 1, // groups already have qty applied at scale=1 (preview applied it)
        duplicateAction: dupAction,
      });
      const bits = [`${result.created_count} created`];
      if (result.replaced_count) bits.push(`${result.replaced_count} replaced`);
      if (result.linked_count)   bits.push(`${result.linked_count} auto-linked`);
      if (result.skipped.length) bits.push(`${result.skipped.length} skipped`);
      toast.success(bits.join(' · '));
      try {
        localStorage.removeItem(draftKey + '_text');
        localStorage.removeItem(draftKey + '_scale');
      } catch { /* */ }
      onCommitted(result);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Commit failed');
    } finally {
      setCommitting(false);
    }
  }

  function reset() {
    setPreview(null);
    setEditedGroups(null);
  }

  function clearDraft() {
    setText('');
    setScale(1);
    setPreview(null);
    setEditedGroups(null);
    try {
      localStorage.removeItem(draftKey + '_text');
      localStorage.removeItem(draftKey + '_scale');
    } catch { /* */ }
  }

  function loadExample() {
    setText(
      [
        'Cheeseburger\tground_beef_80_20\t6\toz',
        'Cheeseburger\tburger_bun\t1\teach',
        'Cheeseburger\tcheddar_sliced\t1\toz',
        'Caesar Salad\tlettuce_romaine\t5\toz',
        'Caesar Salad\tparmesan_grated\t0.5\toz',
        'Caesar Salad\tcaesar_dressing\t1.5\toz',
      ].join('\n')
    );
  }

  const hasDraft = !!text;

  const summaryStats = useMemo(() => {
    if (!editedGroups) return null;
    let ok = 0, warn = 0, err = 0, est = 0;
    for (const g of editedGroups) {
      for (const row of g.rows) {
        if (row.status === 'ok') ok++;
        if (row.status === 'warning') warn++;
        if (row.status === 'error') err++;
      }
      est += g.est_plate_cost_cents;
    }
    return { ok, warn, err, est };
  }, [editedGroups]);

  const dupGroups = editedGroups?.filter((g) => g.existing_recipe_id) ?? [];

  const canCommit =
    editedGroups !== null &&
    editedGroups.some((g) => g.rows.some((r) => r.ingredient_key && r.qty > 0 && r.unit));

  return createPortal(
    <div
      className="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] flex items-center justify-center p-4"
      onClick={onClose}
    >
      <div
        className="bg-white rounded-xl shadow-2xl border border-slate-200 w-[min(1000px,95vw)] max-h-[92vh] flex flex-col overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        <header
          className="px-5 py-3 flex items-center justify-between text-white"
          style={{ background: 'linear-gradient(135deg, #7848BB 0%, #5535A0 100%)' }}
        >
          <div className="flex items-center gap-2 font-bold">
            <ClipboardPaste size={18} /> Paste from spreadsheet
          </div>
          <button onClick={onClose} className="text-white/80 hover:text-white">
            <X size={18} />
          </button>
        </header>

        {!preview ? (
          <div className="p-5 space-y-3 flex-1 overflow-auto">
            <p className="text-sm text-slate-700">
              Copy a block from Excel or Google Sheets with columns:
              <span className="font-mono text-xs ml-1 bg-slate-100 px-1.5 py-0.5 rounded">
                item_name &nbsp;&nbsp; ingredient &nbsp;&nbsp; qty &nbsp;&nbsp; unit
              </span>
              . One row per ingredient; repeat the item name on every row of that recipe.
            </p>
            <div className="flex items-center justify-between flex-wrap gap-3">
              <div className="flex items-center gap-3">
                <button onClick={loadExample} className="text-xs text-violet-700 hover:underline">
                  Load example
                </button>
                {hasDraft && (
                  <button onClick={clearDraft} className="text-xs text-rose-700 hover:underline flex items-center gap-1">
                    <Trash2 size={11} /> Clear draft
                  </button>
                )}
              </div>
              <div className="flex items-center gap-2 text-xs text-slate-600">
                <label htmlFor="paste-scale" className="font-semibold">Scale (yield):</label>
                <input
                  id="paste-scale"
                  type="number"
                  min={0.01}
                  step={0.5}
                  className="input h-7 text-xs w-20 text-right tabular-nums"
                  value={scale}
                  onChange={(e) => setScale(Math.max(0.01, Number(e.target.value) || 1))}
                />
                <span className="text-slate-500">qty ÷ this</span>
              </div>
            </div>
            <textarea
              className="input text-sm font-mono w-full h-64 leading-tight"
              placeholder={'Cheeseburger\tground_beef_80_20\t6\toz\nCheeseburger\tburger_bun\t1\teach\n…'}
              value={text}
              onChange={(e) => setText(e.target.value)}
              spellCheck={false}
            />
            <p className="text-[10px] text-slate-500">
              Draft is auto-saved to this browser. Header row optional — auto-detected.
            </p>
            <div className="flex items-center justify-end gap-2 pt-2">
              <button onClick={onClose} className="btn h-9 px-3 text-sm">
                Cancel
              </button>
              <button
                onClick={doPreview}
                disabled={previewing || !text.trim()}
                className="btn btn-primary h-9 px-3 text-sm flex items-center gap-1.5"
              >
                {previewing ? <Loader2 size={14} className="animate-spin" /> : null}
                Preview
              </button>
            </div>
          </div>
        ) : (
          <div className="flex-1 overflow-auto p-5 space-y-4">
            <PreviewSummary
              preview={preview}
              summaryStats={summaryStats}
              scale={scale}
            />
            {dupGroups.length > 0 && (
              <DuplicateBanner
                dupGroups={dupGroups}
                dupAction={dupAction}
                onChange={setDupAction}
              />
            )}
            <PreviewGroups
              groups={editedGroups ?? []}
              onChange={(idx, next) => setEditedGroups((prev) => {
                if (!prev) return prev;
                const out = [...prev];
                out[idx] = next;
                return out;
              })}
            />
            <div className="border-t border-slate-100 pt-3 flex flex-wrap items-center justify-between gap-2">
              <label className="flex items-center gap-2 text-xs text-slate-700">
                <input
                  type="checkbox"
                  checked={includeWarnings}
                  onChange={(e) => setIncludeWarnings(e.target.checked)}
                />
                Include rows with warnings ({summaryStats?.warn ?? 0})
              </label>
              <div className="flex items-center gap-2">
                <button onClick={reset} className="btn h-9 px-3 text-sm">
                  Edit paste
                </button>
                <button
                  onClick={doCommit}
                  disabled={committing || !canCommit}
                  className="btn btn-primary h-9 px-3 text-sm flex items-center gap-1.5"
                >
                  {committing ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />}
                  Commit {editedGroups?.length ?? 0} recipe{(editedGroups?.length ?? 0) === 1 ? '' : 's'}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>,
    document.body
  );
}

function PreviewSummary({
  preview, summaryStats, scale,
}: {
  preview: PastePreviewResult;
  summaryStats: { ok: number; warn: number; err: number; est: number } | null;
  scale: number;
}) {
  if (!summaryStats) return null;
  return (
    <div className="space-y-2">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
        <Stat label="Recipes" value={String(preview.summary.recipes)} tone="brand" />
        <Stat label="Rows OK" value={String(summaryStats.ok)} tone={summaryStats.ok > 0 ? 'good' : 'neutral'} />
        <Stat label="Warnings" value={String(summaryStats.warn)} tone={summaryStats.warn > 0 ? 'warn' : 'neutral'} />
        <Stat label="Errors" value={String(summaryStats.err)} tone={summaryStats.err > 0 ? 'bad' : 'neutral'} />
      </div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
        <Stat
          label="Est. total plate cost"
          value={`$${(summaryStats.est / 100).toFixed(2)}`}
          tone="brand"
        />
        <Stat
          label="Coverable / not"
          value={`${preview.summary.ingredients_coverable} / ${preview.summary.ingredients_not_coverable}`}
          tone="neutral"
        />
        <Stat
          label="Scale applied"
          value={scale === 1 ? '1× (no scaling)' : `÷ ${scale}`}
          tone={scale === 1 ? 'neutral' : 'brand'}
        />
      </div>
    </div>
  );
}

function DuplicateBanner({
  dupGroups, dupAction, onChange,
}: {
  dupGroups: PastePreviewGroup[];
  dupAction: PasteDuplicateAction;
  onChange: (a: PasteDuplicateAction) => void;
}) {
  return (
    <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 space-y-2">
      <div className="flex items-center gap-2 text-amber-800 text-sm font-bold">
        <AlertOctagon size={14} /> {dupGroups.length} recipe name{dupGroups.length === 1 ? '' : 's'} already exist
      </div>
      <div className="text-xs text-amber-900 flex flex-wrap gap-x-3 gap-y-1">
        {dupGroups.slice(0, 8).map((g) => (
          <span key={g.normalized_name} className="font-semibold">{g.item_name}</span>
        ))}
        {dupGroups.length > 8 && <span>… +{dupGroups.length - 8}</span>}
      </div>
      <fieldset className="flex flex-wrap gap-3 pt-1">
        {(['skip', 'replace', 'create_new'] as const).map((action) => (
          <label key={action} className="flex items-center gap-1.5 text-xs cursor-pointer">
            <input
              type="radio"
              checked={dupAction === action}
              onChange={() => onChange(action)}
            />
            <span className="font-semibold text-amber-900">
              {action === 'skip' && 'Skip duplicates'}
              {action === 'replace' && 'Replace existing ingredients'}
              {action === 'create_new' && 'Create new (with "(2)" suffix)'}
            </span>
          </label>
        ))}
      </fieldset>
    </div>
  );
}

function Stat({
  label, value, tone,
}: {
  label: string;
  value: string;
  tone: 'good' | 'warn' | 'bad' | 'neutral' | 'brand';
}) {
  const color =
    tone === 'good' ? '#059669' : tone === 'warn' ? '#d97706' : tone === 'bad' ? '#dc2626' : tone === 'brand' ? '#7848BB' : '#1A1A2E';
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-2.5">
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="text-lg font-extrabold tabular-nums" style={{ color }}>{value}</div>
    </div>
  );
}

function PreviewGroups({
  groups, onChange,
}: {
  groups: PastePreviewGroup[];
  onChange: (idx: number, next: PastePreviewGroup) => void;
}) {
  if (groups.length === 0) {
    return (
      <div className="bg-slate-50 rounded-lg p-6 text-center text-sm text-slate-500">
        No recipes detected in the paste.
      </div>
    );
  }

  function updateRow(gIdx: number, rIdx: number, patch: Partial<PastePreviewRow>) {
    const g = groups[gIdx];
    const rows = g.rows.slice();
    const next = { ...rows[rIdx], ...patch };
    // Clear error if user filled in the missing field; treat as ok unless still bad.
    if (
      next.ingredient_key &&
      typeof next.qty === 'number' && next.qty > 0 &&
      next.unit
    ) {
      if (next.status === 'error') next.status = 'ok';
    } else {
      next.status = 'error';
    }
    rows[rIdx] = next;
    onChange(gIdx, { ...g, rows });
  }
  function removeRow(gIdx: number, rIdx: number) {
    const g = groups[gIdx];
    onChange(gIdx, { ...g, rows: g.rows.filter((_, i) => i !== rIdx) });
  }
  function addRow(gIdx: number) {
    const g = groups[gIdx];
    onChange(gIdx, {
      ...g,
      rows: [
        ...g.rows,
        {
          ingredient_key: '',
          qty: 1,
          unit: 'oz',
          status: 'error',
          message: 'New row',
          raw_ingredient: '',
          line: 0,
        },
      ],
    });
  }

  return (
    <div className="space-y-2">
      {groups.map((g, gIdx) => (
        <details
          key={g.normalized_name + ':' + gIdx}
          className={`bg-white border rounded-lg ${
            g.existing_recipe_id ? 'border-amber-300' : 'border-slate-200'
          }`}
          open={g.error_count > 0 || !!g.existing_recipe_id}
        >
          <summary className="px-3 py-2 cursor-pointer flex items-center justify-between list-none gap-2 flex-wrap">
            <div className="flex items-center gap-2 min-w-0">
              <span className="font-semibold text-sm truncate" style={{ color: '#1A1A2E' }}>{g.item_name}</span>
              <span className="text-xs text-slate-500">{g.rows.length} row{g.rows.length === 1 ? '' : 's'}</span>
              {g.existing_recipe_id && (
                <span className="text-[10px] uppercase tracking-wider text-amber-700 font-bold bg-amber-100 px-1.5 py-0.5 rounded">
                  duplicate
                </span>
              )}
            </div>
            <div className="flex items-center gap-2 text-xs">
              <span className="font-semibold tabular-nums" style={{ color: '#7848BB' }}>
                ~${(g.est_plate_cost_cents / 100).toFixed(2)}
              </span>
              {g.ok_count > 0 && (
                <span className="flex items-center gap-1 text-emerald-700">
                  <CheckCircle2 size={12} /> {g.ok_count}
                </span>
              )}
              {g.warning_count > 0 && (
                <span className="flex items-center gap-1 text-amber-700">
                  <AlertTriangle size={12} /> {g.warning_count}
                </span>
              )}
              {g.error_count > 0 && (
                <span className="flex items-center gap-1 text-rose-700">
                  <XCircle size={12} /> {g.error_count}
                </span>
              )}
            </div>
          </summary>
          <div className="border-t border-slate-100 px-3 pb-2">
            <table className="w-full text-xs">
              <thead className="text-[10px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="text-left py-1">Ingredient</th>
                  <th className="text-right py-1 w-20">Qty</th>
                  <th className="text-left py-1 pl-2 w-24">Unit</th>
                  <th className="text-left py-1 pl-2">Status</th>
                  <th className="w-8" />
                </tr>
              </thead>
              <tbody>
                {g.rows.map((row, rIdx) => (
                  <tr key={rIdx} className="border-t border-slate-50">
                    <td className="py-1">
                      <input
                        className="input h-7 text-xs w-full font-mono"
                        value={row.ingredient_key}
                        onChange={(e) => updateRow(gIdx, rIdx, { ingredient_key: e.target.value.trim().toLowerCase().replace(/\s+/g, '_') })}
                      />
                    </td>
                    <td className="py-1 pl-2">
                      <input
                        type="number"
                        min={0}
                        step={0.01}
                        className="input h-7 text-xs w-full text-right tabular-nums"
                        value={row.qty}
                        onChange={(e) => updateRow(gIdx, rIdx, { qty: Number(e.target.value) })}
                      />
                    </td>
                    <td className="py-1 pl-2">
                      <select
                        className="input h-7 text-xs w-full"
                        value={row.unit}
                        onChange={(e) => updateRow(gIdx, rIdx, { unit: e.target.value })}
                      >
                        {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
                        {row.unit && !UNITS.includes(row.unit) && (
                          <option value={row.unit}>{row.unit}</option>
                        )}
                      </select>
                    </td>
                    <td className="py-1 pl-2">
                      {row.status === 'ok' && <span className="text-emerald-700">ok</span>}
                      {row.status === 'warning' && (
                        <span className="text-amber-700 truncate inline-block max-w-[180px]" title={row.message ?? ''}>
                          warn{row.message ? `: ${row.message}` : ''}
                        </span>
                      )}
                      {row.status === 'error' && (
                        <span className="text-rose-700 truncate inline-block max-w-[180px]" title={row.message ?? ''}>
                          error{row.message ? `: ${row.message}` : ''}
                        </span>
                      )}
                    </td>
                    <td className="py-1 pl-1 text-right">
                      <button
                        onClick={() => removeRow(gIdx, rIdx)}
                        className="p-1 text-slate-400 hover:text-rose-700"
                        title="Remove row"
                      >
                        <Trash2 size={12} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <button
              onClick={() => addRow(gIdx)}
              className="mt-2 text-xs text-violet-700 hover:underline flex items-center gap-1"
            >
              <Plus size={12} /> add row
            </button>
          </div>
        </details>
      ))}
    </div>
  );
}
