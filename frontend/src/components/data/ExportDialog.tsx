import { useState } from 'react';
import { X } from 'lucide-react';
import toast from 'react-hot-toast';
import { exportsApi } from '../../api/exports';
import { useProjectStore } from '../../stores/projectStore';

export default function ExportDialog({ areaId, onClose }: { areaId?: string; onClose: () => void }) {
  const { currentProject } = useProjectStore();
  const [busy, setBusy] = useState<string | null>(null);

  async function download(label: string, action: () => Promise<{ download_url: string }>) {
    setBusy(label);
    try {
      const r = await action();
      window.open(r.download_url, '_blank');
      toast.success('Download ready');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Export failed');
    } finally { setBusy(null); }
  }

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full" onClick={(e) => e.stopPropagation()}>
        <div className="p-4 border-b border-slate-100 flex justify-between items-center">
          <h2 className="font-bold">Export</h2>
          <button className="btn btn-ghost p-1" onClick={onClose}><X size={16} /></button>
        </div>
        <div className="p-4 space-y-4">
          {currentProject && (
            <div>
              <div className="font-semibold text-sm mb-1">Areas</div>
              <div className="flex gap-2 flex-wrap">
                {(['csv', 'xlsx', 'geojson', 'kml'] as const).map((f) => (
                  <button key={f} className="btn btn-secondary"
                    disabled={busy === `areas-${f}`}
                    onClick={() => download(`areas-${f}`, () => exportsApi.exportAreas(currentProject.id, f))}>
                    {f.toUpperCase()}
                  </button>
                ))}
              </div>
            </div>
          )}
          {areaId && (
            <div>
              <div className="font-semibold text-sm mb-1">POI Results (current area)</div>
              <div className="flex gap-2">
                {(['csv', 'xlsx'] as const).map((f) => (
                  <button key={f} className="btn btn-secondary"
                    disabled={busy === `poi-${f}`}
                    onClick={() => download(`poi-${f}`, () => exportsApi.exportPOIs(areaId, f))}>
                    {f.toUpperCase()}
                  </button>
                ))}
              </div>
            </div>
          )}
          {currentProject && (
            <div>
              <div className="font-semibold text-sm mb-1">Imported Points</div>
              <div className="flex gap-2">
                {(['csv', 'xlsx'] as const).map((f) => (
                  <button key={f} className="btn btn-secondary"
                    disabled={busy === `pts-${f}`}
                    onClick={() => download(`pts-${f}`, () => exportsApi.exportImportedPoints(currentProject.id, f))}>
                    {f.toUpperCase()}
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
