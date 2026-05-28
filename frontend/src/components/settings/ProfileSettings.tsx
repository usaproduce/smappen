import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/authStore';

interface Profile {
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  notify_email: 0 | 1;
  notify_competitor_alerts: 0 | 1;
  notify_team_activity: 0 | 1;
  slack_webhook_url: string | null;
  theme: 'light' | 'dark' | 'auto';
}

export default function ProfileSettings() {
  // Pick the slices we need so this component re-renders only on relevant
  // changes — the `as any` cast was hiding the fact that user/setUser are
  // first-class on the store now.
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);
  const [profile, setProfile] = useState<Partial<Profile>>((user as any) ?? {});
  const [saving, setSaving] = useState(false);
  const [pwd, setPwd] = useState({ current: '', next: '' });
  const [pwdSaving, setPwdSaving] = useState(false);

  useEffect(() => { setProfile(user || {}); }, [user]);

  async function save() {
    setSaving(true);
    try {
      const { data } = await api.put('/api/auth/profile', {
        name: profile.name,
        email: profile.email,
        notify_email: profile.notify_email,
        notify_competitor_alerts: profile.notify_competitor_alerts,
        notify_team_activity: profile.notify_team_activity,
        slack_webhook_url: profile.slack_webhook_url || '',
        theme: profile.theme,
      });
      setUser(data.data.user);
      toast.success('Saved');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Save failed');
    } finally {
      setSaving(false);
    }
  }

  async function changePassword() {
    if (!pwd.current || pwd.next.length < 8) {
      toast.error('Enter current password and a new one (8+ chars)');
      return;
    }
    setPwdSaving(true);
    try {
      await api.post('/api/auth/change-password', {
        current_password: pwd.current,
        new_password: pwd.next,
      });
      setPwd({ current: '', next: '' });
      toast.success('Password changed');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setPwdSaving(false);
    }
  }

  async function resend() {
    try {
      await api.post('/api/auth/resend-verification');
      toast.success('Verification email sent');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    }
  }

  async function resetOnboarding() {
    if (!confirm('Reset onboarding? The first-run wizards (Smappen and Carafe) will re-appear on your next page load.')) return;
    try {
      await api.post('/api/onboarding/reset');
      toast.success('Onboarding reset — wizards will reappear on next page load');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    }
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <Card title="Profile">
        <Field label="Name">
          <input className="input" value={profile.name ?? ''} onChange={(e) => setProfile({ ...profile, name: e.target.value })} />
        </Field>
        <Field label="Email">
          <input className="input" value={profile.email ?? ''} onChange={(e) => setProfile({ ...profile, email: e.target.value })} />
          {!profile.email_verified_at && (
            <div className="mt-1 text-xs text-amber-700 flex items-center justify-between bg-amber-50 border border-amber-200 rounded px-2 py-1">
              <span>Email not verified.</span>
              <button className="font-semibold text-amber-800 hover:underline" onClick={resend}>Resend verification</button>
            </div>
          )}
        </Field>
        <Field label="Appearance">
          <select className="input" value={profile.theme ?? 'light'} onChange={(e) => setProfile({ ...profile, theme: e.target.value as any })}>
            <option value="light">Light</option>
            <option value="dark">Dark</option>
            <option value="auto">Auto (match system)</option>
          </select>
        </Field>
      </Card>

      <Card title="Notifications">
        <Toggle label="Email notifications"
          checked={!!profile.notify_email}
          onChange={(b) => setProfile({ ...profile, notify_email: b ? 1 : 0 })} />
        <Toggle label="Competitor alerts"
          checked={!!profile.notify_competitor_alerts}
          onChange={(b) => setProfile({ ...profile, notify_competitor_alerts: b ? 1 : 0 })} />
        <Toggle label="Team activity (comments, approvals)"
          checked={!!profile.notify_team_activity}
          onChange={(b) => setProfile({ ...profile, notify_team_activity: b ? 1 : 0 })} />
        <Field label="Slack webhook (optional)">
          <input className="input" placeholder="https://hooks.slack.com/services/…" value={profile.slack_webhook_url ?? ''}
            onChange={(e) => setProfile({ ...profile, slack_webhook_url: e.target.value })} />
        </Field>
      </Card>

      <div className="flex justify-end">
        <button className="btn btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save changes'}</button>
      </div>

      <Card title="Change password">
        <Field label="Current password">
          <input className="input" type="password" value={pwd.current} onChange={(e) => setPwd({ ...pwd, current: e.target.value })} />
        </Field>
        <Field label="New password (8+ chars)">
          <input className="input" type="password" value={pwd.next} onChange={(e) => setPwd({ ...pwd, next: e.target.value })} />
        </Field>
        <div className="flex justify-end">
          <button className="btn btn-secondary" onClick={changePassword} disabled={pwdSaving}>
            {pwdSaving ? 'Saving…' : 'Update password'}
          </button>
        </div>
      </Card>

      <Card title="Onboarding">
        <div className="flex items-center justify-between gap-3">
          <div className="text-sm text-slate-600">
            Reset the first-run wizards. The next time you visit Smappen or Carafe, the welcome modal will appear again.
          </div>
          <button className="btn btn-secondary flex-shrink-0" onClick={resetOnboarding}>
            Reset onboarding
          </button>
        </div>
      </Card>
    </div>
  );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="card space-y-3">
      <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>{title}</h2>
      {children}
    </section>
  );
}
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1 block">{label}</span>
      {children}
    </label>
  );
}
function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (b: boolean) => void }) {
  return (
    <label className="flex items-center justify-between text-sm font-medium py-1.5 cursor-pointer" style={{ color: '#1A1A2E' }}>
      {label}
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="h-4 w-4 accent-violet-600" />
    </label>
  );
}
