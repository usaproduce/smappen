import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import toast from 'react-hot-toast';
import { X, ChefHat, Loader2, CheckCircle2, Trash2, Plus, Sparkles } from 'lucide-react';
import {
  menuApi,
  type MenuItem,
  type SuggestedRecipe,
  type SuggestedRecipeIngredient,
} from '../../../api/restaurants';

const UNITS = ['oz', 'lb', 'g', 'kg', 'each', 'tbsp', 'tsp', 'cup', 'ml', 'l'];

/**
 * "Start with suggestions for my menu" — pulls every menu item that
 * doesn't have a recipe yet, asks the server for a seed-dictionary draft
 * per item, lets the operator confirm/edit/skip each, then bulk-commits
 * the accepted ones in sequence (no transaction wrapper: each recipe
 * survives on its own merits even if one fails).
 *
 * Honest about misses: items where the seed dictionary doesn't have a
 * match show "no template — start manual" instead of a confusing fake draft.
 */
export default function SuggestForMenuModal({
  restaurantId,
  menuItems,
  onClose,
  onDone,
}: {
  restaurantId: string;
  menuItems: MenuItem[];
  onClose: () => void;
  onDone: (createdCount: number) => void;
}) {
  // Unlinked items only — already-recipe'd items shouldn't show here.
  const candidates = useMemo(
    () => menuItems.filter((mi) => !mi.recipe_id && mi.is_active),
    [menuItems]
  );

  const [drafts, setDrafts] = useState<Record<string, SuggestedRecipe | null>>({});
  const [loading, setLoading] = useState(true);
  const [accepted, setAccepted] = useState<Record<string, boolean>>({});
  const [editing, setEditing] = useState<Record<string, SuggestedRecipeIngredient[]>>({});
  const [committing, setCommitting] = useState(false);

  useEffect(() => {
    if (candidates.length === 0) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      const out: Record<string, SuggestedRecipe | null> = {};
      const edits: Record<string, SuggestedRecipeIngredient[]> = {};
      const accept: Record<string, boolean> = {};
      // Sequential to avoid hammering the API; the dictionary lookup is
      // cheap so this stays well under 2s for typical 40-item menus.
      for (const mi of candidates) {
        if (cancelled) return;
        try {
          const draft = await menuApi.suggestRecipe(restaurantId, mi.name, mi.category ?? undefined);
          out[mi.id] = draft;
          edits[mi.id] = draft.ingredients.map((i) => ({ ...i }));
          accept[mi.id] = draft.matched && draft.ingredients.length > 0;
        } catch {
          out[mi.id] = null;
        }
      }
      if (cancelled) return;
      setDrafts(out);
      setEditing(edits);
      setAccepted(accept);
      setLoading(false);
    })();
    return () => { cancelled = true; };
  }, [restaurantId, candidates]);

  const acceptedCount = Object.values(accepted).filter(Boolean).length;

  async function commit() {
    setCommitting(true);
    let created = 0;
    try {
      for (const mi of candidates) {
        if (!accepted[mi.id]) continue;
        const ings = editing[mi.id] ?? [];
        if (ings.length === 0) continue;
        try {
          const { id: recipeId } = await menuApi.createRecipe(restaurantId, mi.name);
          for (const ing of ings) {
            if (!ing.ingredient_key || !ing.qty || !ing.unit) continue;
            await menuApi.addIngredient(recipeId, {
              ingredient_key: ing.ingredient_key,
              qty: ing.qty,
              unit: ing.unit,
            });
          }
          await menuApi.setRecipe(mi.id, recipeId);
          created++;
        } catch (e: any) {
          toast.error(`Failed for "${mi.name}": ${e?.response?.data?.error ?? 'unknown'}`);
        }
      }
      // Recompute plate costs once at the end, not per recipe.
      if (created > 0) {
        try { await menuApi.recomputePlateCosts(restaurantId); } catch { /* non-fatal */ }
      }
      toast.success(`${created} recipe${created === 1 ? '' : 's'} created from suggestions`);
      onDone(created);
    } finally {
      setCommitting(false);
    }
  }

  function updateIng(itemId: string, idx: number, patch: Partial<SuggestedRecipeIngredient>) {
    setEditing((prev) => {
      const arr = [...(prev[itemId] ?? [])];
      arr[idx] = { ...arr[idx], ...patch } as SuggestedRecipeIngredient;
      return { ...prev, [itemId]: arr };
    });
  }
  function removeIng(itemId: string, idx: number) {
    setEditing((prev) => {
      const arr = [...(prev[itemId] ?? [])];
      arr.splice(idx, 1);
      return { ...prev, [itemId]: arr };
    });
  }
  function addIng(itemId: string) {
    setEditing((prev) => ({
      ...prev,
      [itemId]: [...(prev[itemId] ?? []), { ingredient_key: '', qty: 1, unit: 'oz', benchmark: null }],
    }));
  }

  return createPortal(
    <div
      className="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] flex items-center justify-center p-4"
      onClick={onClose}
    >
      <div
        className="bg-white rounded-xl shadow-2xl border border-slate-200 w-[min(960px,95vw)] max-h-[90vh] flex flex-col overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        <header
          className="px-5 py-3 flex items-center justify-between text-white"
          style={{ background: 'linear-gradient(135deg, #7848BB 0%, #5535A0 100%)' }}
        >
          <div className="flex items-center gap-2 font-bold">
            <Sparkles size={18} /> Suggestions for your menu
          </div>
          <button onClick={onClose} className="text-white/80 hover:text-white">
            <X size={18} />
          </button>
        </header>

        {candidates.length === 0 ? (
          <div className="p-10 text-center text-sm text-slate-600">
            Every active menu item already has a recipe linked. Nothing to suggest.
          </div>
        ) : loading ? (
          <div className="p-10 text-center text-sm text-slate-500 flex items-center justify-center gap-2">
            <Loader2 size={16} className="animate-spin" />
            Pulling draft recipes for {candidates.length} item{candidates.length === 1 ? '' : 's'}…
          </div>
        ) : (
          <>
            <div className="flex-1 overflow-auto px-5 py-3 space-y-2">
              {candidates.map((mi) => {
                const draft = drafts[mi.id];
                const ings = editing[mi.id] ?? [];
                const isAccepted = accepted[mi.id];
                if (!draft || !draft.matched) {
                  return (
                    <div key={mi.id} className="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 flex items-center justify-between">
                      <div>
                        <div className="text-sm font-semibold" style={{ color: '#1A1A2E' }}>{mi.name}</div>
                        <div className="text-xs text-slate-500">No template match — build manually after closing.</div>
                      </div>
                      <span className="text-[10px] uppercase tracking-wider text-slate-400">skip</span>
                    </div>
                  );
                }
                return (
                  <div key={mi.id} className={`border rounded-lg ${isAccepted ? 'border-violet-300 bg-violet-50/40' : 'border-slate-200 bg-white'}`}>
                    <div className="px-3 py-2 flex items-center justify-between">
                      <label className="flex items-center gap-2 cursor-pointer flex-1 min-w-0">
                        <input
                          type="checkbox"
                          checked={isAccepted}
                          onChange={(e) => setAccepted((p) => ({ ...p, [mi.id]: e.target.checked }))}
                        />
                        <ChefHat size={14} className="text-violet-700 flex-shrink-0" />
                        <div className="min-w-0">
                          <div className="text-sm font-semibold truncate" style={{ color: '#1A1A2E' }}>{mi.name}</div>
                          <div className="text-[10px] text-slate-500">
                            template: <span className="font-mono">{draft.source_key}</span> · {ings.length} ingredient{ings.length === 1 ? '' : 's'}
                          </div>
                        </div>
                      </label>
                    </div>
                    {isAccepted && (
                      <div className="px-3 pb-3 border-t border-slate-100">
                        <table className="w-full text-xs mt-2">
                          <thead className="text-[10px] uppercase tracking-wider text-slate-500">
                            <tr>
                              <th className="text-left py-1">Ingredient</th>
                              <th className="text-right py-1 w-20">Qty</th>
                              <th className="text-left py-1 pl-2 w-24">Unit</th>
                              <th className="text-right py-1 pl-2 w-24">Market</th>
                              <th className="w-8" />
                            </tr>
                          </thead>
                          <tbody>
                            {ings.map((ing, idx) => (
                              <tr key={idx} className="border-t border-slate-50">
                                <td className="py-1">
                                  <input
                                    className="input h-7 text-xs w-full font-mono"
                                    value={ing.ingredient_key}
                                    onChange={(e) => updateIng(mi.id, idx, { ingredient_key: e.target.value })}
                                  />
                                </td>
                                <td className="py-1 pl-2">
                                  <input
                                    type="number"
                                    min={0}
                                    step={0.01}
                                    className="input h-7 text-xs w-full text-right"
                                    value={ing.qty}
                                    onChange={(e) => updateIng(mi.id, idx, { qty: Number(e.target.value) })}
                                  />
                                </td>
                                <td className="py-1 pl-2">
                                  <select
                                    className="input h-7 text-xs w-full"
                                    value={ing.unit}
                                    onChange={(e) => updateIng(mi.id, idx, { unit: e.target.value })}
                                  >
                                    {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
                                  </select>
                                </td>
                                <td className="py-1 pl-2 text-right text-xs tabular-nums">
                                  {ing.benchmark ? (
                                    <span className="text-slate-700">${(ing.benchmark.market_price_cents / 100).toFixed(2)}/{ing.benchmark.unit}</span>
                                  ) : (
                                    <span className="text-amber-600">—</span>
                                  )}
                                </td>
                                <td className="py-1 pl-1 text-right">
                                  <button
                                    onClick={() => removeIng(mi.id, idx)}
                                    className="p-1 text-slate-400 hover:text-rose-700"
                                    title="Remove"
                                  >
                                    <Trash2 size={12} />
                                  </button>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                        <button
                          onClick={() => addIng(mi.id)}
                          className="mt-2 text-xs text-violet-700 hover:underline flex items-center gap-1"
                        >
                          <Plus size={12} /> add ingredient
                        </button>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
            <footer className="border-t border-slate-200 px-5 py-3 flex items-center justify-between bg-slate-50">
              <div className="text-xs text-slate-600">
                <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{acceptedCount}</span>
                {' '}of {candidates.length} accepted
              </div>
              <div className="flex items-center gap-2">
                <button onClick={onClose} className="btn h-9 px-3 text-sm">Cancel</button>
                <button
                  onClick={commit}
                  disabled={committing || acceptedCount === 0}
                  className="btn btn-primary h-9 px-3 text-sm flex items-center gap-1.5"
                >
                  {committing ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />}
                  Create {acceptedCount} recipe{acceptedCount === 1 ? '' : 's'}
                </button>
              </div>
            </footer>
          </>
        )}
      </div>
    </div>,
    document.body
  );
}
