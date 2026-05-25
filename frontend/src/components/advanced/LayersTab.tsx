import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Eye, EyeOff, Trash2, Plus } from 'lucide-react';
import {
  customLayersApi,
  type CustomLayer,
  type ImportBatch,
} from '../../api/customLayers';
import { useMapStore } from '../../stores/mapStore';
import { Empty, Field, SkeletonRow } from './shared';

const PALETTES = [
  { id: 'viridis', label: 'Viridis' },
  { id: 'magma',   label: 'Magma' },
  { id: 'plasma',  label: 'Plasma' },
  { id: 'turbo',   label: 'Turbo' },
];

const PALETTE_PREVIEW: Record<string, string> = {
  viridis: '#7848BB',
  magma:   '#dc2626',
  plasma:  '#f59e0b',
  turbo:   '#2196f3',
};

export default function LayersTab({ projectId }: { projectId: string }) {
  const [layers, setLayers] = useState<CustomLayer[] | null>(null);
  const [batches, setBatches] = useState<ImportBatch[]>([]);
  const [showCreate, setShowCreate] = useState(false);
  const [busy, setBusy] = useState(false);
  const bumpCustomLayers = useMapStore((s) => s.bumpCustomLayers);

  async function load() {
    try {
      const [ls, bs] = await Promise.all([
        customLayersApi.list(projectId),
        customLayersApi.batches(projectId),
      ]);
      setLayers(ls);
      setBatches(bs);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to load layers');
      setLayers([]);
    }
  }
  useEffect(() => { load(); }, [projectId]);

  async function toggleVisibility(layer: CustomLayer) {
    const next = layer.visible ? false : true;
    setLayers((cur) => cur?.map((l) => l.id === layer.id ? { ...l, visible: next ? 1 : 0 } : l) ?? null);
    try {
      await customLayersApi.update(layer.id, { visible: next });
      bumpCustomLayers();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to toggle');
      await load();
    }
  }

  async function remove(layer: CustomLayer) {
    if (!confirm(`Delete layer "${layer.name}"?`)) return;
    try {
      await customLayersApi.remove(layer.id);
      bumpCustomLayers();
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to delete');
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div className="text-[11px] text-slate-600">
          Overlay imported CSV data as map layers. Each layer points at one import batch.
        </div>
        <button
          className="text-[11px] font-semibold text-violet-700 hover:underline inline-flex items-center gap-1 whitespace-nowrap pl-2"
          onClick={() => setShowCreate((v) => !v)}
        >
          <Plus size={12} /> {showCreate ? 'Cancel' : 'New layer'}
        </button>
      </div>

      {showCreate && (
        <CreateLayerForm
          batches={batches}
          busy={busy}
          onCancel={() => setShowCreate(false)}
          onCreate={async (input) => {
            setBusy(true);
            try {
              await customLayersApi.create(projectId, input);
              setShowCreate(false);
              bumpCustomLayers();
              await load();
              toast.success('Layer created');
            } catch (e: any) {
              toast.error(e?.response?.data?.error ?? 'Failed to create');
            } finally {
              setBusy(false);
            }
          }}
        />
      )}

      {layers === null && (
        <>
          <SkeletonRow />
          <SkeletonRow />
        </>
      )}

      {layers?.length === 0 && !showCreate && (
        <Empty msg={
          batches.length === 0
            ? 'Import a CSV first (Data → Import), then create a layer here.'
            : 'No layers yet. Click "New layer" to overlay an import batch.'
        } />
      )}

      <ul className="space-y-1.5">
        {layers?.map((l) => {
          const swatch = PALETTE_PREVIEW[l.palette_id] ?? '#7848BB';
          const batch = batches.find((b) => b.batch_id === l.source_import_batch);
          const pointCount = batch?.point_count ?? 0;
          const isVisible = !!l.visible;
          return (
            <li key={l.id} className="bg-white border border-slate-200 rounded p-2 text-xs">
              <div className="flex items-center gap-2">
                <span
                  className="inline-block w-3 h-3 rounded-full flex-shrink-0"
                  style={{ background: swatch, opacity: isVisible ? 1 : 0.3 }}
                  title={l.palette_id}
                />
                <div className="flex-1 min-w-0">
                  <div className="font-semibold truncate" style={{ color: '#1A1A2E' }}>
                    {l.name}
                  </div>
                  <div className="text-slate-500 text-[10px]">
                    {l.kind} · {pointCount > 0 ? `${pointCount} pts` : 'no points'} · r={l.radius_meters}m
                  </div>
                </div>
                <button
                  className="p-1 text-slate-500 hover:text-slate-800 rounded hover:bg-slate-50"
                  onClick={() => toggleVisibility(l)}
                  title={isVisible ? 'Hide' : 'Show'}
                >
                  {isVisible ? <Eye size={14} /> : <EyeOff size={14} />}
                </button>
                <button
                  className="p-1 text-rose-500 hover:text-rose-700 rounded hover:bg-rose-50"
                  onClick={() => remove(l)}
                  title="Delete"
                >
                  <Trash2 size={14} />
                </button>
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function CreateLayerForm({
  batches,
  busy,
  onCancel,
  onCreate,
}: {
  batches: ImportBatch[];
  busy: boolean;
  onCancel: () => void;
  onCreate: (input: {
    name: string;
    kind: 'point' | 'heatmap';
    source_import_batch: string;
    palette_id: string;
    radius_meters: number;
  }) => void;
}) {
  const [name, setName] = useState('');
  const [batchId, setBatchId] = useState<string>(batches[0]?.batch_id ?? '');
  const [kind, setKind] = useState<'point' | 'heatmap'>('point');
  const [palette, setPalette] = useState('viridis');
  const [radius, setRadius] = useState(800);

  const canSubmit = name.trim().length > 0 && batchId.length > 0 && !busy;

  return (
    <div className="bg-slate-50 rounded p-2 space-y-1.5">
      <Field label="Layer name">
        <input
          className="input h-9 text-sm"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="e.g. Customers Q1"
          autoFocus
        />
      </Field>

      <Field label="Source import batch">
        {batches.length === 0 ? (
          <div className="text-[11px] text-slate-500 italic">
            No imports yet. Use Data → Import to upload a CSV.
          </div>
        ) : (
          <select
            className="input h-9 text-sm"
            value={batchId}
            onChange={(e) => setBatchId(e.target.value)}
          >
            {batches.map((b) => (
              <option key={b.batch_id} value={b.batch_id}>
                {b.sample_label ? `${b.sample_label} · ` : ''}
                {b.point_count} pts · {new Date(b.first_imported_at).toLocaleDateString()}
              </option>
            ))}
          </select>
        )}
      </Field>

      <div className="grid grid-cols-2 gap-1.5">
        <Field label="Type">
          <select
            className="input h-9 text-sm"
            value={kind}
            onChange={(e) => setKind(e.target.value as 'point' | 'heatmap')}
          >
            <option value="point">Markers</option>
            <option value="heatmap">Heatmap</option>
          </select>
        </Field>
        <Field label="Palette">
          <select
            className="input h-9 text-sm"
            value={palette}
            onChange={(e) => setPalette(e.target.value)}
          >
            {PALETTES.map((p) => (
              <option key={p.id} value={p.id}>{p.label}</option>
            ))}
          </select>
        </Field>
      </div>

      <Field label={`Marker radius (meters): ${radius}`}>
        <input
          type="range"
          min={100}
          max={3000}
          step={100}
          value={radius}
          onChange={(e) => setRadius(Number(e.target.value))}
          className="w-full"
        />
      </Field>

      <div className="flex gap-1.5 pt-1">
        <button
          className="btn btn-primary h-9 flex-1"
          disabled={!canSubmit}
          onClick={() => onCreate({
            name: name.trim(),
            kind,
            source_import_batch: batchId,
            palette_id: palette,
            radius_meters: radius,
          })}
        >
          Create
        </button>
        <button className="btn h-9" onClick={onCancel} disabled={busy}>
          Cancel
        </button>
      </div>
    </div>
  );
}
