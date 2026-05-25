import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Trash2, BookOpen, ChefHat } from 'lucide-react';
import {
  menuApi,
  type Recipe, type RecipeWithIngredients, type IngredientCatalogItem,
} from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';

const UNITS = ['oz', 'lb', 'g', 'kg', 'each', 'tbsp', 'tsp', 'cup', 'ml', 'l'];

/**
 * Recipes — the operator-essential workflow. Without recipes, plate cost
 * can't be computed and the engine can't suggest price moves. This page
 * is two-pane: recipes on the left, ingredient builder on the right,
 * with cogs_benchmark prices shown inline so the operator sees what
 * each ingredient is "really" costing them at market rate.
 */
export default function RecipesPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [recipes, setRecipes] = useState<Recipe[]>([]);
  const [catalog, setCatalog] = useState<IngredientCatalogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [selectedRecipe, setSelectedRecipe] = useState<RecipeWithIngredients | null>(null);
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const [rs, cat] = await Promise.all([
          menuApi.listRecipes(restaurantId),
          menuApi.ingredientCatalog().catch(() => []),
        ]);
        if (cancelled) return;
        setRecipes(rs);
        setCatalog(cat);
        if (rs.length > 0 && !selectedId) setSelectedId(rs[0].id);
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
      // Refresh the recipe list so ingredient_count bumps.
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

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
            <BookOpen size={22} style={{ color: '#7848BB' }} /> Recipes
          </h1>
          <button onClick={recomputeCosts} className="btn h-9 px-3 text-sm">
            Recompute plate costs
          </button>
        </div>

        <p className="text-sm text-slate-600">
          A recipe is what turns a menu item into a plate cost — without it, Carafe can't tell you what an item really costs to serve.
        </p>

        {loading ? (
          <div className="grid grid-cols-12 gap-4">
            <div className="col-span-12 md:col-span-4 skeleton h-64" />
            <div className="col-span-12 md:col-span-8 skeleton h-64" />
          </div>
        ) : (
          <div className="grid grid-cols-12 gap-4">
            {/* Recipe list */}
            <aside className="col-span-12 md:col-span-4 bg-white border border-slate-200 rounded-xl p-3">
              <CreateRecipeForm onCreate={createRecipe} disabled={creating} />
              {recipes.length === 0 ? (
                <div className="text-center py-8 text-sm text-slate-500">No recipes yet.</div>
              ) : (
                <ul className="space-y-1 mt-3">
                  {recipes.map((r) => (
                    <li key={r.id}>
                      <button
                        onClick={() => setSelectedId(r.id)}
                        className={`w-full text-left p-2 rounded-md flex items-center gap-2 transition-colors ${
                          selectedId === r.id ? 'bg-violet-100 text-violet-900' : 'hover:bg-slate-50'
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
                    </li>
                  ))}
                </ul>
              )}
            </aside>

            {/* Ingredient builder */}
            <main className="col-span-12 md:col-span-8">
              {selectedRecipe ? (
                <RecipeEditor
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
    </RestaurantWorkspaceLayout>
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
  recipe, catalog, onAdd, onRemove,
}: {
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
    for (const ing of recipe.ingredients) {
      const cat = catalog.find((c) => c.ingredient_key === ing.ingredient_key);
      if (!cat) continue;
      // Naïve same-unit total — proper conversion happens server-side
      // in PlateCostService. This is just a hint for the operator.
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
        {recipe.ingredients.length === 0 ? (
          <div className="text-sm text-slate-500 italic">None yet.</div>
        ) : (
          <ul className="space-y-1">
            {recipe.ingredients.map((ing) => {
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
            <input
              list="catalog-keys"
              className="input h-9 text-sm w-full"
              placeholder="ingredient_key (e.g. tomato_roma)"
              value={key}
              onChange={(e) => setKey(e.target.value)}
            />
            <datalist id="catalog-keys">
              {catalog.map((c) => (
                <option key={c.ingredient_key} value={c.ingredient_key}>
                  {c.ingredient_key} — ${(c.market_price_cents / 100).toFixed(2)}/{c.unit}
                </option>
              ))}
            </datalist>
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
          Type any ingredient_key. {catalog.length} have benchmark prices — others save but won't contribute to plate cost until added to <code>cogs_benchmark</code>.
        </p>
      </div>
    </div>
  );
}
