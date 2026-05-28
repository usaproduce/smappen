import { useEffect, useRef, useState } from 'react';
import { menuApi, type IngredientSuggestion } from '../../../api/restaurants';

/**
 * Ingredient-key autocomplete ranked by frequency. The server's ranking:
 *   1. prefix match before substring
 *   2. how often this restaurant uses the ingredient already
 *   3. has a benchmark price (so plate cost will actually compute)
 *   4. how often the org uses it (other restaurants' learning)
 *   5. alphabetical tiebreak
 *
 * Operator sees market price inline so they know what they're committing
 * to before adding the ingredient.
 */
export default function IngredientAutocomplete({
  restaurantId,
  value,
  onChange,
  onPick,
  placeholder = 'ingredient_key (e.g. tomato_roma)',
  autoFocus = false,
}: {
  restaurantId: string;
  value: string;
  onChange: (val: string) => void;
  /**
   * Called when the user explicitly picks a suggestion (click or Enter).
   * Lets the parent pre-fill a unit field from the benchmark, so the
   * operator doesn't have to retype it.
   */
  onPick?: (suggestion: IngredientSuggestion) => void;
  placeholder?: string;
  autoFocus?: boolean;
}) {
  const [suggestions, setSuggestions] = useState<IngredientSuggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let cancelled = false;
    const q = value.trim();
    // Empty query still returns top-N (frequency-ranked) so the dropdown
    // is useful right from focus.
    const t = setTimeout(async () => {
      try {
        const out = await menuApi.ingredientAutocomplete(restaurantId, q, 12);
        if (!cancelled) setSuggestions(out);
      } catch { /* swallow */ }
    }, 120);
    return () => { cancelled = true; clearTimeout(t); };
  }, [restaurantId, value]);

  // Close on outside click.
  useEffect(() => {
    function handler(e: MouseEvent) {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    }
    window.addEventListener('mousedown', handler);
    return () => window.removeEventListener('mousedown', handler);
  }, []);

  function pick(s: IngredientSuggestion) {
    onChange(s.key);
    onPick?.(s);
    setOpen(false);
  }

  function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) setOpen(true);
    if (!open) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setHighlight((h) => Math.min(h + 1, suggestions.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHighlight((h) => Math.max(h - 1, 0)); }
    else if (e.key === 'Enter') {
      if (suggestions[highlight]) { e.preventDefault(); pick(suggestions[highlight]); }
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  }

  return (
    <div ref={wrapRef} className="relative">
      <input
        className="input h-9 text-sm w-full font-mono"
        placeholder={placeholder}
        value={value}
        autoFocus={autoFocus}
        onChange={(e) => { onChange(e.target.value); setOpen(true); setHighlight(0); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        spellCheck={false}
        autoComplete="off"
      />
      {open && suggestions.length > 0 && (
        <ul className="absolute z-50 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-72 overflow-auto">
          {suggestions.map((s, idx) => (
            <li
              key={s.key}
              onMouseDown={(e) => { e.preventDefault(); pick(s); }}
              onMouseEnter={() => setHighlight(idx)}
              className={`px-3 py-1.5 text-xs cursor-pointer flex items-center justify-between gap-2 ${
                idx === highlight ? 'bg-violet-100' : 'hover:bg-slate-50'
              }`}
            >
              <span className="font-mono truncate flex-1 min-w-0" style={{ color: '#1A1A2E' }}>
                {s.key}
              </span>
              <span className="flex items-center gap-2 text-[10px] text-slate-500 flex-shrink-0">
                {s.own_freq > 0 && (
                  <span className="text-violet-700 font-semibold">{s.own_freq}× used</span>
                )}
                {s.has_benchmark && s.market_price_cents !== null && s.unit ? (
                  <span className="tabular-nums text-slate-700">
                    ${(s.market_price_cents / 100).toFixed(2)}/{s.unit}
                  </span>
                ) : (
                  <span className="text-amber-600">no benchmark</span>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
