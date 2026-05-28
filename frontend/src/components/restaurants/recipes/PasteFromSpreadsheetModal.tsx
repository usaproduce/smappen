import { useState } from 'react';
import { createPortal } from 'react-dom';
import toast from 'react-hot-toast';
import { X, ClipboardPaste, CheckCircle2, AlertTriangle, XCircle, Loader2 } from 'lucide-react';
import { menuApi, type PastePreviewResult, type PasteCommitResult } from '../../../api/restaurants';

/**
 * Paste-from-spreadsheet flow — the fastest path from zero recipes to a
 * full menu's worth. Operator copies a TSV block (Excel default when
 * copying cells), pastes here, sees a server-validated preview, then
 * commits. One transaction, plate costs recomputed at the end.
 *
 * Why server-side preview? So a sneaky paste between preview and commit
 * can't slip past validation, and so unit normalization / unknown unit
 * warnings match what the commit actually does.
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
  const [text, setText] = useState('');
  const [preview, setPreview] = useState<PastePreviewResult | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [committing, setCommitting] = useState(false);
  const [includeWarnings, setIncludeWarnings] = useState(true);

  async function doPreview() {
    if (!text.trim()) {
      toast.error('Paste some data first');
      return;
    }
    setPreviewing(true);
    try {
      setPreview(await menuApi.previewPaste(restaurantId, text));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Preview failed');
    } finally {
      setPreviewing(false);
    }
  }

  async function doCommit() {
    if (!preview) return;
    setCommitting(true);
    try {
      const result = await menuApi.commitPaste(restaurantId, text, includeWarnings);
      toast.success(
        `${result.created_count} recipe${result.created_count === 1 ? '' : 's'} created` +
          (result.linked_count > 0 ? ` (${result.linked_count} auto-linked to menu items)` : '')
      );
      onCommitted(result);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Commit failed');
    } finally {
      setCommitting(false);
    }
  }

  function reset() {
    setPreview(null);
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

  const canCommit =
    preview !== null &&
    preview.summary.recipes > 0 &&
    (preview.summary.ok > 0 || (includeWarnings && preview.summary.warnings > 0));

  return createPortal(
    <div
      className="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] flex items-center justify-center p-4"
      onClick={onClose}
    >
      <div
        className="bg-white rounded-xl shadow-2xl border border-slate-200 w-[min(900px,95vw)] max-h-[90vh] flex flex-col overflow-hidden"
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
            <div className="flex items-center justify-between">
              <button onClick={loadExample} className="text-xs text-violet-700 hover:underline">
                Load example
              </button>
              <span className="text-xs text-slate-500">Header row optional — auto-detected.</span>
            </div>
            <textarea
              className="input text-sm font-mono w-full h-64 leading-tight"
              placeholder={'Cheeseburger\tground_beef_80_20\t6\toz\nCheeseburger\tburger_bun\t1\teach\n…'}
              value={text}
              onChange={(e) => setText(e.target.value)}
              spellCheck={false}
            />
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
                Preview {preview ? 'again' : ''}
              </button>
            </div>
          </div>
        ) : (
          <div className="flex-1 overflow-auto p-5 space-y-4">
            <PreviewSummary preview={preview} />
            <PreviewGroups preview={preview} />
            <div className="border-t border-slate-100 pt-3 flex flex-wrap items-center justify-between gap-2">
              <label className="flex items-center gap-2 text-xs text-slate-700">
                <input
                  type="checkbox"
                  checked={includeWarnings}
                  onChange={(e) => setIncludeWarnings(e.target.checked)}
                />
                Include rows with warnings ({preview.summary.warnings})
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
                  Commit {preview.summary.recipes} recipe{preview.summary.recipes === 1 ? '' : 's'}
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

function PreviewSummary({ preview }: { preview: PastePreviewResult }) {
  const { summary } = preview;
  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
      <Stat label="Recipes" value={summary.recipes} tone="brand" />
      <Stat label="Rows OK" value={summary.ok} tone={summary.ok > 0 ? 'good' : 'neutral'} />
      <Stat label="Warnings" value={summary.warnings} tone={summary.warnings > 0 ? 'warn' : 'neutral'} />
      <Stat label="Errors" value={summary.errors} tone={summary.errors > 0 ? 'bad' : 'neutral'} />
    </div>
  );
}

function Stat({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
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

function PreviewGroups({ preview }: { preview: PastePreviewResult }) {
  if (preview.groups.length === 0) {
    return (
      <div className="bg-slate-50 rounded-lg p-6 text-center text-sm text-slate-500">
        No recipes detected in the paste.
      </div>
    );
  }
  return (
    <div className="space-y-2">
      {preview.groups.map((g) => (
        <details key={g.normalized_name} className="bg-white border border-slate-200 rounded-lg" open={g.error_count > 0}>
          <summary className="px-3 py-2 cursor-pointer flex items-center justify-between list-none">
            <div className="flex items-center gap-2">
              <span className="font-semibold text-sm" style={{ color: '#1A1A2E' }}>{g.item_name}</span>
              <span className="text-xs text-slate-500">{g.row_count} row{g.row_count === 1 ? '' : 's'}</span>
            </div>
            <div className="flex items-center gap-2 text-xs">
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
                  <th className="text-right py-1">Qty</th>
                  <th className="text-left py-1 pl-3">Unit</th>
                  <th className="text-left py-1 pl-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {g.rows.map((row, idx) => (
                  <tr key={idx} className="border-t border-slate-50">
                    <td className="py-1 font-mono">
                      {row.ingredient_key || <span className="text-rose-700 italic">(missing)</span>}
                      {row.raw_ingredient && row.ingredient_key !== row.raw_ingredient.toLowerCase() && (
                        <span className="text-slate-400 ml-1">← {row.raw_ingredient}</span>
                      )}
                    </td>
                    <td className="py-1 text-right tabular-nums">{row.qty || '—'}</td>
                    <td className="py-1 pl-3 font-mono">{row.unit || '—'}</td>
                    <td className="py-1 pl-3">
                      {row.status === 'ok' && <span className="text-emerald-700">ok</span>}
                      {row.status === 'warning' && (
                        <span className="text-amber-700">warn{row.message ? `: ${row.message}` : ''}</span>
                      )}
                      {row.status === 'error' && (
                        <span className="text-rose-700">error{row.message ? `: ${row.message}` : ''}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      ))}
    </div>
  );
}
