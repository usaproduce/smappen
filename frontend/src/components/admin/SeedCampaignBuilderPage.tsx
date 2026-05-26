import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useQuery, useMutation } from '@tanstack/react-query';
import { AlertTriangle, Sparkles } from 'lucide-react';
import {
  carafeApi,
  VENDOR_TYPES,
  type EnrichPolicy,
  type DensityProfile,
  type EstimateResult,
} from '../../api/carafe';

/**
 * /admin/carafe/campaigns/new — campaign builder + live cost preview.
 * Spec v3 §5.2 + §8.
 *
 * Region input is bbox (4 numeric fields) — quick + accurate. A future
 * iteration can swap in an interactive Google-Maps rectangle draw via
 * @react-google-maps/api's DrawingManager.
 *
 * Cost preview refreshes ~600ms after the last edit (debounced). It
 * fires POST /estimate which makes zero API calls upstream.
 */
export default function SeedCampaignBuilderPage() {
  const nav = useNavigate();

  // ── Form state ────────────────────────────────────────────────────
  const [name, setName] = useState('');
  // Default: rough DC metro bbox so the live estimate has something to chew on.
  const [latMin, setLatMin] = useState(38.80);
  const [lngMin, setLngMin] = useState(-77.10);
  const [latMax, setLatMax] = useState(38.92);
  const [lngMax, setLngMax] = useState(-76.95);
  const [selectedTypes, setSelectedTypes] = useState<string[]>(['produce', 'meat', 'seafood']);
  const [policy,  setPolicy]  = useState<EnrichPolicy>('priority_types');
  const [density, setDensity] = useState<DensityProfile>('mixed');
  const [budgetCap, setBudgetCap] = useState<string>('100');

  const bbox: [number, number, number, number] = [latMin, lngMin, latMax, lngMax];
  const bboxValid =
    Number.isFinite(latMin) && Number.isFinite(lngMin) &&
    Number.isFinite(latMax) && Number.isFinite(lngMax) &&
    latMax > latMin && lngMax > lngMin &&
    latMin >= -90 && latMax <= 90 && lngMin >= -180 && lngMax <= 180;

  // Debounce input → estimate.
  const [debounced, setDebounced] = useState({ bbox, types: selectedTypes, policy, density });
  useEffect(() => {
    const id = setTimeout(() => setDebounced({ bbox, types: selectedTypes, policy, density }), 600);
    return () => clearTimeout(id);
  }, [latMin, lngMin, latMax, lngMax, selectedTypes, policy, density]);

  const inputValid = bboxValid && selectedTypes.length > 0;

  const { data: estimate, isFetching: estimating, error: estimateErr } = useQuery({
    queryKey: ['carafe', 'estimate', debounced],
    enabled: inputValid,
    queryFn: () => carafeApi.estimate({
      bbox: debounced.bbox,
      vendor_types: debounced.types,
      enrich_policy: debounced.policy,
      density_profile: debounced.density,
    }),
    staleTime: 30_000,
  });

  // ── Create + run ──────────────────────────────────────────────────
  const create = useMutation({
    mutationFn: async () => {
      const r = await carafeApi.createCampaign({
        name: name.trim(),
        bbox,
        vendor_types: selectedTypes,
        enrich_policy: policy,
        density_profile: density,
        budget_cap_usd: budgetCap.trim() === '' ? null : parseFloat(budgetCap),
      });
      return r.campaign;
    },
    onSuccess: (c) => {
      toast.success('Campaign created');
      nav(`/admin/carafe/campaigns/${c.id}`);
    },
    onError: (e: any) => toast.error(e?.response?.data?.error ?? 'Create failed'),
  });

  return (
    <div>
      <h1 className="text-2xl font-extrabold text-slate-900 mb-6">New campaign</h1>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* ─── LEFT: form ──────────────────────────────────────── */}
        <div className="lg:col-span-2 space-y-6">
          <Section title="Identity">
            <label className="block text-xs font-semibold text-slate-600 mb-1">Name</label>
            <input
              className="input h-10 w-full"
              placeholder="e.g. Virginia produce + seafood"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </Section>

          <Section title="Region (bbox)">
            <p className="text-xs text-slate-500 mb-3">
              Lat/lng box that contains every place to be swept. Estimator + tile worker
              both use this verbatim.
            </p>
            <div className="grid grid-cols-2 gap-3">
              <NumField label="lat min"  value={latMin} onChange={setLatMin} />
              <NumField label="lng min"  value={lngMin} onChange={setLngMin} />
              <NumField label="lat max"  value={latMax} onChange={setLatMax} />
              <NumField label="lng max"  value={lngMax} onChange={setLngMax} />
            </div>
            {!bboxValid && (
              <div className="mt-2 text-xs text-red-600 flex items-center gap-1">
                <AlertTriangle size={12} /> lat_max must &gt; lat_min, lng_max must &gt; lng_min, and values must be in range.
              </div>
            )}
            <BboxMap latMin={latMin} lngMin={lngMin} latMax={latMax} lngMax={lngMax} />
          </Section>

          <Section title="Vendor types">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {VENDOR_TYPES.map((vt) => (
                <label key={vt.key} className={`flex items-start gap-2 p-3 border rounded cursor-pointer ${
                  selectedTypes.includes(vt.key) ? 'border-violet-400 bg-violet-50' : 'border-slate-200 hover:border-slate-300'
                }`}>
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={selectedTypes.includes(vt.key)}
                    onChange={(e) => {
                      setSelectedTypes((prev) =>
                        e.target.checked ? [...prev, vt.key] : prev.filter((k) => k !== vt.key)
                      );
                    }}
                  />
                  <div>
                    <div className="text-sm font-semibold text-slate-900">{vt.label}</div>
                    <div className="text-[11px] text-slate-500">{vt.description}</div>
                  </div>
                </label>
              ))}
            </div>
          </Section>

          <Section title="Density profile">
            <div className="grid grid-cols-4 gap-2">
              {(['rural', 'suburban', 'dense', 'mixed'] as DensityProfile[]).map((d) => (
                <button
                  key={d}
                  type="button"
                  onClick={() => setDensity(d)}
                  className={`px-3 py-2 text-sm font-semibold rounded border ${
                    density === d
                      ? 'border-violet-400 bg-violet-50 text-violet-900'
                      : 'border-slate-200 text-slate-600 hover:border-slate-300'
                  }`}
                >
                  {d}
                </button>
              ))}
            </div>
            <p className="text-xs text-slate-500 mt-2">
              Tile size shrinks at higher density so saturated Places searches don't drop results.
            </p>
          </Section>

          <Section title="Enrich policy">
            <div className="space-y-2">
              <PolicyRadio
                value="priority_types"
                current={policy}
                onChange={setPolicy}
                label="Priority types"
                hint="Pull Place Details only for broadline / cash_carry / produce / seafood. Default."
              />
              <PolicyRadio
                value="on_demand"
                current={policy}
                onChange={setPolicy}
                label="On demand"
                hint="Identity only at seed. Enrich a vendor the first time an admin opens it (cache permanently)."
              />
              <PolicyRadio
                value="all"
                current={policy}
                onChange={setPolicy}
                label="All"
                hint="Place Details on every vendor. Highest cost (~$0.025/vendor). Use for small high-value regions only."
              />
            </div>
          </Section>

          <Section title="Budget cap (USD)">
            <input
              className="input h-10 w-48"
              type="number"
              placeholder="100"
              value={budgetCap}
              onChange={(e) => setBudgetCap(e.target.value)}
            />
            <p className="text-xs text-slate-500 mt-2">
              Worker pauses the campaign BEFORE the next call when projected spend exceeds this cap.
              Leave blank for no cap (cost ledger still records every call).
            </p>
          </Section>

          <div className="pt-2 flex items-center gap-3">
            <button
              className="btn btn-primary h-10 px-5 text-sm"
              disabled={!inputValid || !name.trim() || create.isPending}
              onClick={() => create.mutate()}
            >
              {create.isPending ? 'Creating…' : 'Create campaign'}
            </button>
            <span className="text-xs text-slate-500">
              Created campaigns are in <code className="text-[11px]">draft</code> status until you press Run on the detail page.
            </span>
          </div>
        </div>

        {/* ─── RIGHT: live cost preview ─────────────────────────── */}
        <div className="lg:col-span-1">
          <div className="sticky top-4">
            <CostPreview estimate={estimate?.estimate} budgetCap={parseFloat(budgetCap) || null} loading={estimating} error={estimateErr as Error | undefined} valid={inputValid} />
          </div>
        </div>
      </div>
    </div>
  );
}

// ── Sub-components ────────────────────────────────────────────────────

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <h3 className="font-bold text-slate-900 text-sm mb-3">{title}</h3>
      {children}
    </div>
  );
}

function NumField({ label, value, onChange }: { label: string; value: number; onChange: (v: number) => void }) {
  return (
    <label className="block">
      <span className="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">{label}</span>
      <input
        type="number"
        step="0.0001"
        className="input h-9 w-full font-mono text-sm"
        value={Number.isFinite(value) ? value : ''}
        onChange={(e) => onChange(parseFloat(e.target.value))}
      />
    </label>
  );
}

function PolicyRadio({ value, current, onChange, label, hint }: { value: EnrichPolicy; current: EnrichPolicy; onChange: (v: EnrichPolicy) => void; label: string; hint: string }) {
  return (
    <label className={`flex gap-3 items-start p-3 rounded border cursor-pointer ${
      current === value ? 'border-violet-400 bg-violet-50' : 'border-slate-200 hover:border-slate-300'
    }`}>
      <input type="radio" name="policy" value={value} checked={current === value} onChange={() => onChange(value)} className="mt-0.5" />
      <div>
        <div className="text-sm font-semibold text-slate-900">{label}</div>
        <div className="text-[11px] text-slate-500">{hint}</div>
      </div>
    </label>
  );
}

function BboxMap({ latMin, lngMin, latMax, lngMax }: { latMin: number; lngMin: number; latMax: number; lngMax: number }) {
  // Lightweight static OpenStreetMap embed — no API key, no JS map
  // library overhead in this admin tool. For interactive drawing
  // see the @react-google-maps/api DrawingManager pattern used in
  // VendorMapPage.
  const valid = Number.isFinite(latMin) && Number.isFinite(latMax) && latMax > latMin;
  if (!valid) return null;
  const margin = 0.05;
  const bbox = `${lngMin - margin},${latMin - margin},${lngMax + margin},${latMax + margin}`;
  const url = `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${(latMin + latMax) / 2},${(lngMin + lngMax) / 2}`;
  return (
    <div className="mt-3 rounded border border-slate-200 overflow-hidden">
      <iframe
        src={url}
        title="bbox preview"
        className="w-full"
        style={{ height: 260, border: 0 }}
        loading="lazy"
      />
    </div>
  );
}

function CostPreview({ estimate, budgetCap, loading, error, valid }: { estimate?: EstimateResult; budgetCap: number | null; loading: boolean; error?: Error; valid: boolean }) {
  if (!valid) {
    return <Card title="Cost preview"><p className="text-sm text-slate-500">Fill in bbox + at least one vendor type to see the estimate.</p></Card>;
  }
  if (error) {
    return <Card title="Cost preview"><p className="text-sm text-red-600">{error.message}</p></Card>;
  }
  if (!estimate && loading) {
    return <Card title="Cost preview"><p className="text-sm text-slate-500">Estimating…</p></Card>;
  }
  if (!estimate) {
    return <Card title="Cost preview"><p className="text-sm text-slate-500">No estimate yet.</p></Card>;
  }

  const overCap = budgetCap !== null && estimate.total.expected > budgetCap;
  const e = estimate;

  return (
    <Card title={
      <span className="flex items-center gap-2">
        <Sparkles size={14} className="text-violet-500" /> Cost preview
        {loading && <span className="text-[10px] text-slate-400">refreshing…</span>}
      </span>
    }>
      <div className="text-center py-2">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500">Expected</div>
        <div className={`text-4xl font-extrabold mt-1 ${overCap ? 'text-red-600' : 'text-slate-900'}`}>
          ${e.total.expected.toFixed(2)}
        </div>
        <div className="text-xs text-slate-500 mt-1">
          low&nbsp;${e.total.low.toFixed(2)} · high&nbsp;${e.total.high.toFixed(2)}
        </div>
      </div>

      {overCap && (
        <div className="mt-3 p-2 bg-red-50 border border-red-200 rounded text-[11px] text-red-700 font-semibold flex items-center gap-1.5">
          <AlertTriangle size={12} /> Above budget cap (${budgetCap?.toFixed(2)}).
        </div>
      )}

      <div className="mt-4 space-y-3 text-xs">
        <Row label="Tiles"            value={e.meta.tile_count.toLocaleString()} />
        <Row label="Area"             value={`${e.meta.area_km2.toLocaleString()} km²`} />
        <Row label="Tile size"        value={`${e.meta.tile_size_km} km`} />

        <Divider />

        <Row label="Sweep calls"      value={e.sweep.calls.expected.toLocaleString()} />
        <Row label="Sweep cost"       value={`$${e.sweep.cost.expected.toFixed(2)}`} />

        <Divider />

        <Row label="Enrich vendors"   value={e.enrich.vendors.expected.toLocaleString()} />
        <Row label="Enrich calls"     value={e.enrich.calls.expected.toLocaleString()} />
        <Row label="Enrich cost"      value={`$${e.enrich.cost.expected.toFixed(2)}`} />
      </div>

      <Divider />

      <div className="text-[11px] text-slate-500 space-y-1">
        <div className="font-semibold text-slate-600 mb-1">Free-tier remaining (this month)</div>
        {Object.entries(e.free_tier_remaining).map(([sku, n]) => (
          <div key={sku} className="flex justify-between">
            <span className="text-slate-500">{sku}</span>
            <span className="font-semibold tabular-nums text-slate-700">{n.toLocaleString()}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

function Card({ title, children }: { title: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <div className="text-sm font-bold text-slate-900 mb-3">{title}</div>
      {children}
    </div>
  );
}
function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between">
      <span className="text-slate-500">{label}</span>
      <span className="font-semibold tabular-nums text-slate-900">{value}</span>
    </div>
  );
}
function Divider() {
  return <div className="border-t border-slate-100 my-2" />;
}
