import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Trash2, BookOpen, ChefHat, ClipboardPaste, Sparkles, Pencil, Copy, Search, X } from 'lucide-react';
import {
  menuApi,
  type Recipe, type RecipeWithIngredients, type IngredientCatalogItem,
  type MenuItem,
} from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import PasteFromSpreadsheetModal from './recipes/PasteFromSpreadsheetModal';
import SuggestForMenuModal from './recipes/SuggestForMenuModal';
import IngredientAutocomplete from './recipes/IngredientAutocomplete';

const UNITS = ['oz', 'lb', 'g', 'kg', 'each', 'tbsp', 'tsp', 'cup', 'ml', 'l'];

/**
 * Recipes — the operator-essential workflow. Without recipes, plate cost
 * can't be computed and the engine can't suggest price moves. The page
 * is two-pane (recipes on the left, ingredient builder on the right)
 * once the operator has at least one recipe.
 *
 * The empty state used to be a passive illustration; it's now a
 * three-button picker so a fresh restaurant can go from zero recipes to a
 * usable plate cost in minutes instead of an hour of clicking.
 */
export default function RecipesPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [recipes, setRecipes] = useState<Recipe[]>([]);
  const [menuItems, setMenuItems] = useState<MenuItem[]>([]);
  const [catalog, setCatalog] = useState<IngredientCatalogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [selectedRecipe, setSelectedRecipe] = useState<RecipeWithIngredients | null>(null);
  const [creating, setCreating] = useState(false);
  const [pasteOpen, setPasteOpen] = useState(false);
  const [suggestOpen, setSuggestOpen] = useState(false);
  const [query, setQuery] = useState('');

  const filteredRecipes = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return recipes;
    return recipes.filter((r) => r.name.toLowerCase().includes(q));
  }, [recipes, query]);

  async function refresh() {
    const [rs, mi, cat] = await Promise.all([
      menuApi.listRecipes(restaurantId),
      menuApi.listItems(restaurantId).catch(() => [] as MenuItem[]),
      menuApi.ingredientCatalog().catch(() => [] as IngredientCatalogItem[]),
    ]);
    setRecipes(rs);
    setMenuItems(mi);
    setCatalog(cat);
    if (rs.length > 0 && !selectedId) setSelectedId(rs[0].id);
  }

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        await refresh();
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load recipes');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [restaurantId]);

  useEffect(() => {
    if (!selectedId) { setSelectedRecipe(null); return; }
    let cancelled = false;
    menuApi.showRecipe(selectedId).then((r) => {
      if (!cancelled) setSelectedRecipe(r);
    }).catch((e) => {
      if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load recipe');
    });
    return () => { cancelled = true; };
  }, [selectedId]);

  async function createRecipe(name: string) {
    if (!name.trim()) return;
    setCreating(true);
    try {
      const { id } = await menuApi.createRecipe(restaurantId, name.trim());
      const fresh = await menuApi.listRecipes(restaurantId);
      setRecipes(fresh);
      setSelectedId(id);
      toast.success('Recipe created');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to create recipe');
    } finally {
      setCreating(false);
    }
  }

  async function addIngredient(rid: string, payload: { ingredient_key: string; qty: number; unit: string }) {
    try {
      await menuApi.addIngredient(rid, payload);
      setSelectedRecipe(await menuApi.showRecipe(rid));
      setRecipes(await menuApi.listRecipes(restaurantId));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to add ingredient');
    }
  }

  async function removeIngredient(ingredientId: string) {
    try {
      await menuApi.removeIngredient(ingredientId);
      if (selectedRecipe) setSelectedRecipe({ ...selectedRecipe, ingredients: selectedRecipe.ingredients.filter((i) => i.id !== ingredientId) });
      setRecipes(await menuApi.listRecipes(restaurantId));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to remove');
    }
  }

  async function recomputeCosts() {
    try {
      const r = await menuApi.recomputePlateCosts(restaurantId);
      toast.success(`Plate costs recomputed for ${r.recomputed} items`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Recompute failed');
    }
  }

  async function deleteRecipe(recipeId: string, name: string) {
    if (!window.confirm(`Delete recipe "${name}"? Linked menu items will be unlinked. This can't be undone.`)) return;
    try {
      await menuApi.deleteRecipe(recipeId);
      toast.success(`Deleted "${name}"`);
      if (selectedId === recipeId) setSelectedId(null);
      const fresh = await menuApi.listRecipes(restaurantId);
      setRecipes(fresh);
      if (selectedId === recipeId && fresh.length > 0) setSelectedId(fresh[0].id);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Delete failed');
    }
  }

  async function copyRecipe(recipeId: string, currentName: string) {
    const newName = window.prompt(`Copy "${currentName}" to a new recipe. New name:`, currentName + ' (copy)');
    if (!newName || !newName.trim()) return;
    try {
      const { id } = await menuApi.copyRecipe(recipeId, newName.trim());
      toast.success(`Copied "${currentName}" → "${newName.trim()}"`);
      const fresh = await menuApi.listRecipes(restaurantId);
      setRecipes(fresh);
      setSelectedId(id);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Copy failed');
    }
  }

  const isEmpty = !loading && recipes.length === 0;

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
            <BookOpen size={22} style={{ color: '#7848BB' }} /> Recipes
          </h1>
          <div className="flex items-center gap-2">
            {!isEmpty && (
              <>
                <button onClick={() => setPasteOpen(true)} className="btn h-9 px-3 text-sm flex items-center gap-1.5">
                  <ClipboardPaste size={14} /> Paste
                </button>
                <button onClick={() => setSuggestOpen(true)} className="btn h-9 px-3 text-sm flex items-center gap-1.5">
                  <Sparkles size={14} /> Suggest
                </button>
              </>
            )}
            <button onClick={recomputeCosts} className="btn h-9 px-3 text-sm">
              Recompute plate costs
            </button>
          </div>
        </div>

        <p className="text-sm text-slate-600">
          A recipe is what turns a menu item into a plate cost — without it, Carafe can't tell you what an item really costs to serve.
        </p>

        {loading ? (
          <div className="grid grid-cols-12 gap-4">
            <div className="col-span-12 md:col-span-4 skeleton h-64" />
            <div className="col-span-12 md:col-span-8 skeleton h-64" />
          </div>
        ) : isEmpty ? (
          <EmptyStatePicker
            onPaste={() => setPasteOpen(true)}
            onSuggest={() => setSuggestOpen(true)}
            unlinkedCount={menuItems.filter((mi) => !mi.recipe_id && mi.is_active).length}
            onManual={() => {
              const name = window.prompt('Recipe name?');
              if (name) createRecipe(name);
            }}
          />
        ) : (
          <div className="grid grid-cols-12 gap-4">
            {/* Recipe list */}
            <aside className="col-span-12 md:col-span-4 bg-white border border-slate-200 rounded-xl p-3">
              <CreateRecipeForm onCreate={createRecipe} disabled={creating} />
              {recipes.length > 5 && (
                <div className="relative mt-2">
                  <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input
                    className="input h-8 text-xs w-full pl-7 pr-7"
                    placeholder={`Search ${recipes.length} recipes…`}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                  />
                  {query && (
                    <button
                      onClick={() => setQuery('')}
                      className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700"
                      aria-label="Clear search"
                    >
                      <X size={12} />
                    </button>
                  )}
                </div>
              )}
              {filteredRecipes.length === 0 && query ? (
                <div className="text-center py-6 text-xs text-slate-500">No recipes match "{query}".</div>
              ) : (
                <ul className="space-y-1 mt-3 max-h-[60vh] overflow-y-auto">
                  {filteredRecipes.map((r) => (
                    <li key={r.id} className="group">
                      <div
                        className={`flex items-center gap-1 rounded-md transition-colors ${
                          selectedId === r.id ? 'bg-violet-100' : 'hover:bg-slate-50'
                        }`}
                      >
                        <button
                          onClick={() => setSelectedId(r.id)}
                          className={`flex-1 min-w-0 text-left p-2 flex items-center gap-2 ${
                            selectedId === r.id ? 'text-violet-900' : ''
                          }`}
                        >
                          <ChefHat size={14} className="text-slate-400 flex-shrink-0" />
                          <div className="flex-1 min-w-0">
                            <div className="text-sm font-semibold truncate">{r.name}</div>
                            <div className="text-[10px] text-slate-500">
                              {r.ingredient_count} ingredient{r.ingredient_count === 1 ? '' : 's'}
                              {r.linked_menu_items > 0 && ` · linked to ${r.linked_menu_items} item${r.linked_menu_items === 1 ? '' : 's'}`}
                            </div>
                          </div>
                        </button>
                        <button
                          onClick={() => copyRecipe(r.id, r.name)}
                          className="p-1.5 text-slate-400 hover:text-violet-700 opacity-0 group-hover:opacity-100 focus:opacity-100"
                          title="Copy recipe"
                        >
                          <Copy size={12} />
                        </button>
                        <button
                          onClick={() => deleteRecipe(r.id, r.name)}
                          className="p-1.5 mr-1 text-slate-400 hover:text-rose-700 opacity-0 group-hover:opacity-100 focus:opacity-100"
                          title="Delete recipe"
                        >
                          <Trash2 size={12} />
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </aside>

            {/* Ingredient builder */}
            <main className="col-span-12 md:col-span-8">
              {selectedRecipe ? (
                <RecipeEditor
                  restaurantId={restaurantId}
                  recipe={selectedRecipe}
                  catalog={catalog}
                  onAdd={(payload) => addIngredient(selectedRecipe.id, payload)}
                  onRemove={removeIngredient}
                />
              ) : (
                <div className="bg-slate-50 rounded-xl p-12 text-center text-sm text-slate-500">
                  Pick a recipe to edit, or create a new one.
                </div>
              )}
            </main>
          </div>
        )}
      </div>

      {pasteOpen && (
        <PasteFromSpreadsheetModal
          restaurantId={restaurantId}
          onClose={() => setPasteOpen(false)}
          onCommitted={async (result) => {
            setPasteOpen(false);
            await refresh();
            if (result.created.length > 0) setSelectedId(result.created[0].recipe_id);
          }}
        />
      )}
      {suggestOpen && (
        <SuggestForMenuModal
          restaurantId={restaurantId}
          menuItems={menuItems}
          onClose={() => setSuggestOpen(false)}
          onDone={async () => {
            setSuggestOpen(false);
            await refresh();
          }}
        />
      )}
    </RestaurantWorkspaceLayout>
  );
}

function EmptyStatePicker({
  onPaste,
  onSuggest,
  onManual,
  unlinkedCount,
}: {
  onPaste: () => void;
  onSuggest: () => void;
  onManual: () => void;
  unlinkedCount: number;
}) {
  return (
    <div className="bg-white border border-slate-200 rounded-xl p-6 md:p-10">
      <div className="max-w-2xl mx-auto text-center space-y-1">
        <ChefHat size={28} className="mx-auto text-violet-700" />
        <h2 className="text-lg font-extrabold" style={{ color: '#1A1A2E' }}>
          Start your recipes
        </h2>
        <p className="text-sm text-slate-600">
          Pick the path that matches what you have — three ways to get from zero to a full menu.
        </p>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mt-6 max-w-3xl mx-auto">
        <PickerCard
          icon={<ClipboardPaste size={20} className="text-violet-700" />}
          title="Paste from spreadsheet"
          desc="Got a TSV of items, ingredients, qty, unit? Paste it. We preview, you confirm."
          cta="Paste TSV"
          onClick={onPaste}
        />
        <PickerCard
          icon={<Sparkles size={20} className="text-violet-700" />}
          title="Suggestions for my menu"
          desc={
            unlinkedCount > 0
              ? `Draft recipes for ${unlinkedCount} menu item${unlinkedCount === 1 ? '' : 's'} from common recipe templates.`
              : 'Connect a POS or add menu items first — then we can suggest drafts.'
          }
          cta={unlinkedCount > 0 ? 'See suggestions' : 'Need menu items'}
          onClick={onSuggest}
          disabled={unlinkedCount === 0}
        />
        <PickerCard
          icon={<Pencil size={20} className="text-violet-700" />}
          title="Build manually"
          desc="Start one recipe at a time. Slowest path — but full control."
          cta="New recipe"
          onClick={onManual}
        />
      </div>
    </div>
  );
}

function PickerCard({
  icon, title, desc, cta, onClick, disabled = false,
}: {
  icon: React.ReactNode;
  title: string;
  desc: string;
  cta: string;
  onClick: () => void;
  disabled?: boolean;
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={`text-left bg-slate-50 hover:bg-violet-50 border border-slate-200 rounded-xl p-4 transition-colors disabled:opacity-50 disabled:cursor-not-allowed`}
    >
      <div className="mb-2">{icon}</div>
      <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>{title}</div>
      <div className="text-xs text-slate-600 mt-1 leading-snug">{desc}</div>
      <div className="text-xs font-semibold text-violet-700 mt-3">{cta} →</div>
    </button>
  );
}

function CreateRecipeForm({ onCreate, disabled }: { onCreate: (name: string) => void; disabled: boolean }) {
  const [name, setName] = useState('');
  return (
    <form
      onSubmit={(e) => { e.preventDefault(); onCreate(name); setName(''); }}
      className="flex gap-2"
    >
      <input
        className="input h-9 text-sm flex-1"
        placeholder="New recipe name…"
        value={name}
        onChange={(e) => setName(e.target.value)}
      />
      <button type="submit" className="btn btn-primary h-9 px-3 text-sm" disabled={disabled || !name.trim()}>
        <Plus size={14} />
      </button>
    </form>
  );
}

function RecipeEditor({
  restaurantId, recipe, catalog, onAdd, onRemove,
}: {
  restaurantId: string;
  recipe: RecipeWithIngredients;
  catalog: IngredientCatalogItem[];
  onAdd: (payload: { ingredient_key: string; qty: number; unit: string }) => void;
  onRemove: (ingredientId: string) => void;
}) {
  const [key, setKey] = useState('');
  const [qty, setQty] = useState('');
  const [unit, setUnit] = useState('oz');

  const totalCents = useMemo(() => {
    let total = 0;
    for (const ing of recipe.ingredients ?? []) {
      const cat = catalog.find((c) => c.ingredient_key === ing.ingredient_key);
      if (!cat) continue;
      if (cat.unit === ing.unit) total += Math.round(cat.market_price_cents * Number(ing.qty));
    }
    return total;
  }, [recipe.ingredients, catalog]);

  function submit() {
    const q = Number(qty);
    if (!key || !q || q <= 0 || !unit) {
      toast.error('Ingredient, qty > 0, and unit required');
      return;
    }
    onAdd({ ingredient_key: key, qty: q, unit });
    setKey(''); setQty('');
  }

  return (
    <div className="bg-white border border-slate-200 rounded-xl p-4 space-y-4">
      <div>
        <h2 className="font-extrabold text-lg" style={{ color: '#1A1A2E' }}>{recipe.name}</h2>
        <p className="text-xs text-slate-500">
          Approximate market plate cost (same-unit lines only):{' '}
          <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>${(totalCents / 100).toFixed(2)}</span>
        </p>
      </div>

      <div>
        <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Ingredients</h3>
        {(recipe.ingredients ?? []).length === 0 ? (
          <div className="text-sm text-slate-500 italic">None yet.</div>
        ) : (
          <ul className="space-y-1">
            {(recipe.ingredients ?? []).map((ing) => {
              const cat = catalog.find((c) => c.ingredient_key === ing.ingredient_key);
              return (
                <li key={ing.id} className="flex items-center gap-3 p-2 bg-slate-50 rounded">
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold" style={{ color: '#1A1A2E' }}>
                      {ing.ingredient_key}
                    </div>
                    <div className="text-xs text-slate-500">
                      {Number(ing.qty)} {ing.unit}
                      {cat && (
                        <>
                          {' · market '}
                          <span className="text-slate-700 font-semibold tabular-nums">${(cat.market_price_cents / 100).toFixed(2)}/{cat.unit}</span>
                          {' '}<span className="text-slate-400">({cat.source})</span>
                        </>
                      )}
                      {!cat && <span className="text-amber-600"> · no benchmark</span>}
                    </div>
                  </div>
                  <button
                    onClick={() => onRemove(ing.id)}
                    className="p-1.5 text-slate-400 hover:text-rose-700 hover:bg-rose-50 rounded"
                    title="Remove"
                  >
                    <Trash2 size={14} />
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {/* Add row */}
      <div className="border-t border-slate-100 pt-3">
        <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Add ingredient</h3>
        <div className="grid grid-cols-12 gap-2">
          <div className="col-span-6">
            <IngredientAutocomplete
              restaurantId={restaurantId}
              value={key}
              onChange={setKey}
              onPick={(s) => {
                // Pre-fill unit from cogs_benchmark when picking via dropdown
                // — fewer keystrokes per ingredient, fewer unit mismatches
                // (oz vs lb) that silently break plate-cost coverage.
                if (s.unit) setUnit(s.unit);
              }}
            />
          </div>
          <input
            type="number"
            min={0}
            step={0.01}
            className="input h-9 text-sm col-span-3"
            placeholder="qty"
            value={qty}
            onChange={(e) => setQty(e.target.value)}
          />
          <select
            className="input h-9 text-sm col-span-2"
            value={unit}
            onChange={(e) => setUnit(e.target.value)}
          >
            {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
          </select>
          <button className="btn btn-primary h-9 text-sm col-span-1" onClick={submit}>
            <Plus size={14} />
          </button>
        </div>
        <p className="text-[10px] text-slate-500 mt-2">
          Most-used ingredients surface first. {catalog.length} have benchmark prices — others save but won't contribute to plate cost until added to <code>cogs_benchmark</code>.
        </p>
      </div>
    </div>
  );
}
