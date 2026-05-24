import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useMapStore } from '../../stores/mapStore';
import { collabApi, type Comment } from '../../api/advanced';
import { Spinner, Empty } from './shared';

export default function CommentsTab({ projectId }: { projectId: string }) {
  const [comments, setComments] = useState<Comment[]>([]);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const { selectedAreaId } = useMapStore();

  async function load() {
    try {
      const r = await collabApi.listComments(projectId);
      setComments(r.comments);
    } catch {}
  }
  useEffect(() => { load(); }, [projectId]);

  async function post() {
    if (!body.trim()) return;
    setBusy(true);
    try {
      await collabApi.createComment(projectId, {
        body: body.trim(),
        area_id: selectedAreaId || undefined,
      });
      setBody('');
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }
  async function resolve(id: string) {
    try { await collabApi.resolveComment(id); await load(); } catch {}
  }

  return (
    <div className="space-y-3">
      <textarea className="input text-sm" placeholder="Leave a comment…" value={body}
        onChange={(e) => setBody(e.target.value)} rows={2} />
      <button className="btn btn-primary w-full h-9" onClick={post} disabled={busy || !body.trim()}>
        {busy ? <Spinner /> : null} Post comment
      </button>
      <ul className="space-y-2">
        {comments.map((c) => (
          <li key={c.id} className={`text-xs border rounded p-2 ${c.resolved_at ? 'bg-slate-50 border-slate-200 opacity-70' : 'bg-white border-slate-200'}`}>
            <div className="flex items-center justify-between mb-0.5">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>{c.author_name ?? 'Someone'}</span>
              <span className="text-slate-400">{new Date(c.created_at).toLocaleString()}</span>
            </div>
            <div className="text-slate-700 whitespace-pre-wrap">{c.body}</div>
            {!c.resolved_at && (
              <button className="mt-1 text-violet-700 font-semibold hover:underline" onClick={() => resolve(c.id)}>Mark resolved</button>
            )}
          </li>
        ))}
        {comments.length === 0 && <Empty msg="No comments yet." />}
      </ul>
    </div>
  );
}
