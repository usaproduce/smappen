import { useState } from 'react';
import { FileText } from 'lucide-react';
import toast from 'react-hot-toast';
import { reportsApi } from '../../api/reports';
import { api } from '../../api/client';

export default function ReportButton({ areaId }: { areaId: string }) {
  const [loading, setLoading] = useState(false);

  async function generate() {
    setLoading(true);
    const t = toast.loading('Generating report…');
    try {
      const r = await reportsApi.generate(areaId);

      // /api/reports/{id}/download is auth-protected. A naive
      // window.open(url, '_blank') strips the Authorization header → 401
      // in a fresh tab, which is the "leads nowhere" bug. Fetch with our
      // interceptor, wrap as a Blob, and trigger a real save dialog.
      const resp = await api.get(r.download_url, { responseType: 'blob' });
      const blob = new Blob([resp.data], { type: 'application/pdf' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `smappen-area-${areaId.slice(0, 8)}-${new Date().toISOString().slice(0, 10)}.pdf`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success('Report downloaded', { id: t });
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Report failed', { id: t });
    } finally {
      setLoading(false);
    }
  }

  return (
    <button className="btn btn-primary" disabled={loading} onClick={generate}>
      <FileText size={14} /> {loading ? 'Generating…' : 'Report'}
    </button>
  );
}
