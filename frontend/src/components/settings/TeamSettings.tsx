import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Trash2 } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import { collabApi, type Collaborator } from '../../api/advanced';

const ROLE_BADGE: Record<string, { bg: string; fg: string }> = {
  owner:  { bg: 'bg-violet-100', fg: 'text-violet-800' },
  admin:  { bg: 'bg-blue-100',   fg: 'text-blue-800' },
  editor: { bg: 'bg-emerald-100', fg: 'text-emerald-800' },
  viewer: { bg: 'bg-slate-100',  fg: 'text-slate-700' },
};
// Unknown role values (e.g. legacy 'approver' rows from before migration 007)
// fall back to a neutral badge so the table still renders.
const UNKNOWN_BADGE = { bg: 'bg-gray-100', fg: 'text-gray-700' };

export default function TeamSettings() {
  const { currentProject } = useProjectStore();
  const [collabs, setCollabs] = useState<Collaborator[]>([]);
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<Collaborator['role']>('viewer');
  const [busy, setBusy] = useState(false);

  async function load() {
    if (!currentProject) return;
    try {
      setCollabs((await collabApi.listCollaborators(currentProject.id)).collaborators);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Could not load');
    }
  }
  useEffect(() => { load(); }, [currentProject?.id]);

  async function invite() {
    if (!currentProject || !email.trim()) return;
    setBusy(true);
    try {
      await collabApi.addCollaborator(currentProject.id, email.trim(), role);
      setEmail('');
      await load();
      toast.success('Invited');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Invite failed');
    } finally {
      setBusy(false);
    }
  }
  async function remove(userId: string) {
    if (!currentProject) return;
    if (!confirm('Remove this collaborator?')) return;
    try {
      await collabApi.removeCollaborator(currentProject.id, userId);
      await load();
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    }
  }

  if (!currentProject) {
    return <div className="card">Open a project from the map first — team membership is per-project.</div>;
  }

  return (
    <div className="space-y-6 max-w-3xl">
      <section className="card space-y-3">
        <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>Invite a team member</h2>
        <p className="text-xs text-slate-500">User must already have a Smappen account. They'll see this project as soon as the role is assigned.</p>
        <div className="flex gap-2">
          <input
            className="input flex-1"
            placeholder="Email address"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <select className="input w-32" value={role} onChange={(e) => setRole(e.target.value as any)}>
            <option value="viewer">Viewer</option>
            <option value="editor">Editor</option>
            <option value="admin">Admin</option>
          </select>
          <button className="btn btn-primary" onClick={invite} disabled={busy || !email.trim()}>
            {busy ? 'Inviting…' : 'Invite'}
          </button>
        </div>
        <div className="text-[11px] text-slate-500 leading-relaxed">
          <b>Viewer</b>: read-only. <b>Editor</b>: create + edit areas, comments. <b>Admin</b>: invite/remove + approvals.
        </div>
      </section>

      <section className="card space-y-2">
        <div className="flex items-center justify-between">
          <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>Members</h2>
          <span className="text-xs text-slate-500">{collabs.length} member{collabs.length === 1 ? '' : 's'}</span>
        </div>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-[11px] uppercase font-bold text-slate-500 tracking-wider">
              <th className="py-2">Name</th><th>Email</th><th>Role</th><th>Joined</th><th></th>
            </tr>
          </thead>
          <tbody>
            {collabs.map((c) => {
              const b = ROLE_BADGE[c.role] ?? UNKNOWN_BADGE;
              return (
                <tr key={c.user_id} className="border-t border-slate-100">
                  <td className="py-2 font-semibold" style={{ color: '#1A1A2E' }}>{c.name}</td>
                  <td className="text-slate-600">{c.email}</td>
                  <td><span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${b.bg} ${b.fg}`}>{c.role}</span></td>
                  <td className="text-slate-400 text-xs">{c.accepted_at ? new Date(c.accepted_at).toLocaleDateString() : '—'}</td>
                  <td className="text-right">
                    {c.role !== 'owner' && (
                      <button className="text-slate-400 hover:text-rose-600 p-1" onClick={() => remove(c.user_id)} title="Remove">
                        <Trash2 size={14} />
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
            {collabs.length === 0 && (
              <tr><td colSpan={5} className="text-center text-slate-500 py-6">Just you so far.</td></tr>
            )}
          </tbody>
        </table>
      </section>
    </div>
  );
}
