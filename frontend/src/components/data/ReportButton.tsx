import { useState } from 'react';
import { FileText } from 'lucide-react';
import toast from 'react-hot-toast';
import { reportsApi } from '../../api/reports';

export default function ReportButton({ areaId }: { areaId: string }) {
  const [loading, setLoading] = useState(false);
  async function generate() {
    setLoading(true);
    try {
      const r = await reportsApi.generate(areaId);
      window.open(r.download_url, '_blank');
      toast.success('Report ready');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Report failed');
    } finally { setLoading(false); }
  }
  return (
    <button className="btn btn-primary" disabled={loading} onClick={generate}>
      <FileText size={14} /> {loading ? 'Generating…' : 'Report'}
    </button>
  );
}
