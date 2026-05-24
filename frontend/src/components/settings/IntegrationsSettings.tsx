import { Link } from 'react-router-dom';
import { KeyRound, Webhook, Slack, Mail, ExternalLink } from 'lucide-react';

export default function IntegrationsSettings() {
  return (
    <div className="space-y-6 max-w-3xl">
      <div className="card">
        <h1 className="font-bold text-lg mb-1" style={{ color: '#1A1A2E' }}>Integrations</h1>
        <p className="text-sm text-slate-600">Connect Smappen to the rest of your stack.</p>
      </div>

      <Tile
        icon={<KeyRound size={20} style={{ color: '#7848BB' }} />}
        title="API key"
        description="Programmatic access for scripts, integrations, and the REST API."
        to="/settings/api"
        cta="Manage API key"
      />

      <Tile
        icon={<Webhook size={20} style={{ color: '#7848BB' }} />}
        title="Webhooks"
        description="POST signed events (competitor alerts, territory done, comments, approvals) to your URL."
        to="/settings/webhooks"
        cta="Manage webhooks"
      />

      <Tile
        icon={<Slack size={20} style={{ color: '#7848BB' }} />}
        title="Slack"
        description="Pipe competitor alerts to a Slack channel via incoming webhook. Set the URL in your profile."
        to="/settings/profile"
        cta="Configure in profile"
      />

      <Tile
        icon={<Mail size={20} style={{ color: '#7848BB' }} />}
        title="Email"
        description="Inbound email integration is on the roadmap. Outbound (alerts, password reset, verification) is already live."
        disabled
      />

      <div className="card">
        <h2 className="font-bold text-base mb-1" style={{ color: '#1A1A2E' }}>Coming next</h2>
        <p className="text-sm text-slate-600">HubSpot, Salesforce, and Google Sheets sync are on the integration roadmap.</p>
      </div>
    </div>
  );
}

function Tile({ icon, title, description, to, cta, disabled }: { icon: React.ReactNode; title: string; description: string; to?: string; cta?: string; disabled?: boolean }) {
  return (
    <div className="card flex items-center gap-4">
      <div className="shrink-0">{icon}</div>
      <div className="flex-1 min-w-0">
        <div className="font-bold text-base" style={{ color: '#1A1A2E' }}>{title}</div>
        <div className="text-sm text-slate-600">{description}</div>
      </div>
      {to && !disabled && (
        <Link to={to} className="btn btn-secondary inline-flex items-center gap-1">
          {cta} <ExternalLink size={12} />
        </Link>
      )}
      {disabled && <span className="text-xs text-slate-400 font-semibold uppercase">Soon</span>}
    </div>
  );
}
