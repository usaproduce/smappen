import { useMemo, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { Settings2 } from 'lucide-react';
import type { OverrideMap } from '../../../utils/truck/model';
import { profileDraftOf, type ProfileDraft } from '../../../utils/truck/profileForm';
import { useTruck } from '../data';
import AssumptionsTab from '../settings/AssumptionsTab';
import DataTab from '../settings/DataTab';
import TruckCostsTab from '../settings/TruckCostsTab';
import { TabPanel, Tabs } from '../ui';

/** The three tabs, in order: "Truck and costs", "Assumptions", "Data and export". */
const TABS = ['truck', 'assumptions', 'data'];
const TAB_ITEMS = [
  { id: 'truck', label: 'Truck and costs' },
  { id: 'assumptions', label: 'Assumptions' },
  { id: 'data', label: 'Data and export' },
];
const TABS_LABEL = 'Settings';

/**
 * Settings (docs/truck-planner/05_FRONTEND.md 4.9): the truck profile, the model's assumptions and
 * the data tab. The tab is part of the address (`/truck/settings/:tab`).
 *
 * What stays: the tab in the URL is checked before anything else (an unknown tab goes to `truck`).
 */
export default function SettingsPage() {
  const { tab } = useParams();
  if (tab === undefined || !TABS.includes(tab)) return <Navigate to="/truck/settings/truck" replace />;
  return <Settings tab={tab} />;
}

/**
 * The page under a valid tab. The two drafts live here, above the tabs, so that looking at the
 * other tab does not throw an unsaved edit away. A draft is null until the owner changes something:
 * until then the form simply follows what is saved.
 */
function Settings({ tab }: { tab: string }) {
  const navigate = useNavigate();
  const { profile, A } = useTruck();
  const [profileDraft, setProfileDraft] = useState<ProfileDraft | null>(null);
  const [overrideDraft, setOverrideDraft] = useState<OverrideMap | null>(null);
  // A discard starts the fields afresh: one that still shows a refused value is clean again.
  const [version, setVersion] = useState(0);

  const savedProfileDraft = useMemo(() => profileDraftOf(profile), [profile]);

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Settings2 size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Settings
      </h1>

      <Tabs tabs={TAB_ITEMS} value={tab} onChange={(id) => navigate('/truck/settings/' + id)} variant="underline" ariaLabel={TABS_LABEL} />

      <TabPanel tabs={TABS_LABEL} id="truck" active={tab === 'truck'} className="focus:outline-none">
        <TruckCostsTab
          key={'truck-' + String(version)}
          draft={profileDraft ?? savedProfileDraft}
          onChange={setProfileDraft}
          onSaved={() => setProfileDraft(null)}
          onDiscard={() => {
            setProfileDraft(null);
            setVersion((v) => v + 1);
          }}
        />
      </TabPanel>

      <TabPanel tabs={TABS_LABEL} id="assumptions" active={tab === 'assumptions'} className="focus:outline-none">
        <AssumptionsTab
          draft={overrideDraft ?? A.overrides}
          version={version}
          onChange={setOverrideDraft}
          onSaved={() => setOverrideDraft(null)}
          onDiscard={() => {
            setOverrideDraft(null);
            setVersion((v) => v + 1);
          }}
        />
      </TabPanel>

      <TabPanel tabs={TABS_LABEL} id="data" active={tab === 'data'} className="focus:outline-none">
        <DataTab />
      </TabPanel>
    </div>
  );
}
