import { useState, useRef } from 'react';
import { X } from 'lucide-react';
import toast from 'react-hot-toast';
import { importsApi } from '../../api/imports';
import { useProjectStore } from '../../stores/projectStore';

const AUTO_MATCH: Record<string, string> = {
  address: 'address', addr: 'address', street: 'address',
  lat: 'lat', latitude: 'lat',
  lng: 'lng', long: 'lng', longitude: 'lng', lon: 'lng',
  name: 'name', label: 'name', title: 'name',
};

function autoDetect(header: string): string {
  const h = header.toLowerCase().trim();
  return AUTO_MATCH[h] ?? '';
}

export default function ImportWizard({ onClose }: { onClose: () => void }) {
  const { currentProject } = useProjectStore();
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [uploading, setUploading] = useState(false);
  const [preview, setPreview] = useState<any | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [customCols, setCustomCols] = useState<string[]>([]);
  const [result, setResult] = useState<any | null>(null);
  const [processing, setProcessing] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  async function onFile(f: File) {
    if (!currentProject) return;
    if (f.size > 10 * 1024 * 1024) return toast.error('Max 10MB');
    setUploading(true);
    try {
      const r = await importsApi.upload(currentProject.id, f);
      setPreview(r);
      const init: Record<string, string> = {};
      r.headers.forEach((h: string) => { init[h] = autoDetect(h); });
      setMapping(init);
      setStep(2);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Upload failed');
    } finally { setUploading(false); }
  }

  async function startImport() {
    if (!currentProject || !preview) return;
    const mappedRoles = Object.fromEntries(Object.entries(mapping).filter(([, v]) => v));
    const addressCol = Object.entries(mappedRoles).find(([, v]) => v === 'address')?.[0];
    const nameCol = Object.entries(mappedRoles).find(([, v]) => v === 'name')?.[0];
    const latCol = Object.entries(mappedRoles).find(([, v]) => v === 'lat')?.[0];
    const lngCol = Object.entries(mappedRoles).find(([, v]) => v === 'lng')?.[0];
    if (!addressCol && !(latCol && lngCol)) return toast.error('Map address OR lat+lng columns');

    setStep(3);
    setProcessing(true);
    try {
      const r = await importsApi.configure(currentProject.id, {
        import_token: preview.import_token,
        column_mapping: {
          address_column: addressCol ?? null,
          name_column: nameCol ?? null,
          lat_column: latCol ?? null,
          lng_column: lngCol ?? null,
          custom_columns: customCols,
        },
      });
      setResult(r);
      toast.success(`Imported ${r.imported} / ${r.total_rows}`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Import failed');
      setStep(2);
    } finally { setProcessing(false); }
  }

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="p-4 border-b border-slate-100 flex justify-between items-center">
          <h2 className="font-bold">Import Data — Step {step}/3</h2>
          <button className="btn btn-ghost p-1" onClick={onClose}><X size={16} /></button>
        </div>
        <div className="p-4 flex-1 overflow-auto">
          {step === 1 && (
            <div className="text-center py-12">
              <div className="border-2 border-dashed border-slate-300 rounded-lg p-8">
                <p className="text-slate-500 mb-4">Drop a CSV or XLSX file here, or</p>
                <input ref={fileRef} type="file" accept=".csv,.xlsx" hidden
                  onChange={(e) => e.target.files?.[0] && onFile(e.target.files[0])} />
                <button className="btn btn-primary" disabled={uploading} onClick={() => fileRef.current?.click()}>
                  {uploading ? 'Uploading…' : 'Choose file'}
                </button>
                <p className="text-xs text-slate-400 mt-2">Max 10MB</p>
              </div>
            </div>
          )}
          {step === 2 && preview && (
            <div>
              <p className="text-sm text-slate-600 mb-4">Map your columns. {preview.total_rows} total rows.</p>
              <div className="overflow-auto border border-slate-200 rounded mb-4">
                <table className="w-full text-xs">
                  <thead className="bg-slate-50">
                    <tr>
                      {preview.headers.map((h: string) => (
                        <th key={h} className="p-2 text-left border-b border-slate-200">
                          <div className="font-semibold">{h}</div>
                          <select className="select text-xs mt-1" value={mapping[h] ?? ''} onChange={(e) => setMapping({ ...mapping, [h]: e.target.value })}>
                            <option value="">— Ignore —</option>
                            <option value="address">Address</option>
                            <option value="name">Name / Label</option>
                            <option value="lat">Latitude</option>
                            <option value="lng">Longitude</option>
                          </select>
                          <label className="flex items-center gap-1 mt-1 text-[10px]">
                            <input type="checkbox" checked={customCols.includes(h)}
                              onChange={(e) => setCustomCols(e.target.checked ? [...customCols, h] : customCols.filter((x) => x !== h))} />
                            Custom
                          </label>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {preview.preview.map((row: any[], i: number) => (
                      <tr key={i} className="border-b border-slate-100">
                        {row.map((c, j) => <td key={j} className="p-2 text-slate-600">{String(c ?? '').slice(0, 40)}</td>)}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="text-xs text-slate-500">
                {Object.values(mapping).filter((v) => v === 'lat').length && Object.values(mapping).filter((v) => v === 'lng').length
                  ? '✅ Coordinates detected — geocoding will be skipped.'
                  : `🌍 ${preview.total_rows} rows will be geocoded (uses API credits).`}
              </div>
            </div>
          )}
          {step === 3 && (
            <div className="text-center py-12">
              {processing ? <div className="text-slate-500">Processing…</div> : result && (
                <div>
                  <h3 className="text-xl font-bold mb-2 text-emerald-600">Import complete</h3>
                  <p className="text-sm">{result.imported} imported · {result.failed_count} failed · {result.geocoded_count} geocoded</p>
                  {result.failures?.length > 0 && (
                    <div className="text-left mt-4 max-h-48 overflow-auto text-xs">
                      <div className="font-semibold mb-1">Failures:</div>
                      {result.failures.map((f: any, i: number) => (
                        <div key={i}>Row {f.row}: {f.error}</div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
        <div className="p-4 border-t border-slate-100 flex justify-end gap-2">
          {step === 2 && <button className="btn btn-primary" onClick={startImport}>Import</button>}
          {step === 3 && !processing && <button className="btn btn-primary" onClick={onClose}>Done</button>}
        </div>
      </div>
    </div>
  );
}
