import { useEffect, useState } from 'react';
import { useJsApiLoader } from '@react-google-maps/api';
import Header from './Header';
import LeftPanel from './LeftPanel';
import RightPanel from './RightPanel';
import RightToolbar from './RightToolbar';
import MapCanvas from '../map/MapCanvas';
import AreaCreator from '../areas/AreaCreator';
import ImportWizard from '../data/ImportWizard';
import AdvancedPanel from '../advanced/AdvancedPanel';
import TimeMachinePanel from '../map/TimeMachinePanel';
import ShortcutsModal from '../common/ShortcutsModal';
import CommandPalette from '../common/CommandPalette';
import OnboardingChecklist from '../common/OnboardingChecklist';
import WhatsNewModal from '../common/WhatsNewModal';
import FirstRunWizard from '../onboarding/FirstRunWizard';
import { api } from '../../api/client';
import ErrorBoundary from '../ErrorBoundary';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { useShortcuts } from '../../hooks/useShortcuts';
import { useDynamicFavicon } from '../../hooks/useDynamicFavicon';
import { useViewUrl } from '../../hooks/useViewUrl';
import { saveProjectSnapshot, downloadMapSnapshot } from '../../utils/mapExport';
import { SMAPPEN_MAP_STYLE_DARK, MAP_STYLE_PRESETS } from '../../utils/mapStyle';

const LIBRARIES: ('drawing' | 'visualization' | 'geometry' | 'places')[] = [
  'drawing', 'visualization', 'geometry', 'places',
];

export default function AppLayout() {
  const { selectedAreaId, mapInstance, timeMachineRequest, closeTimeMachine } = useMapStore();
  const { currentProject } = useProjectStore();
  const [creatorOpen, setCreatorOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [stuckLoading, setStuckLoading] = useState(false);
  const [wizardOpen, setWizardOpen] = useState(false);

  // Check onboarding state once on mount — open the wizard only if the user
  // hasn't completed/skipped it AND has no areas in their current project.
  // Stamped on dismiss inside the wizard, so this only fires once per user.
  useEffect(() => {
    let cancelled = false;
    api.get('/api/onboarding/state').then((r) => {
      if (cancelled) return;
      const flags = r.data?.data?.flags ?? {};
      if (!flags.wizard_complete) setWizardOpen(true);
    }).catch(() => {});
    return () => { cancelled = true; };
  }, []);

  useShortcuts({
    onCreateArea: () => setCreatorOpen(true),
    onSaveSnapshot: () => currentProject && saveProjectSnapshot(currentProject.id),
  });
  useDynamicFavicon();
  useViewUrl();

  // Pull the project's areas at snapshot-time so the static map captures
  // every polygon + center pin currently visible. Hidden areas are filtered
  // out so the export both auto-fits to and renders only what's on screen.
  const { areas: projectAreas } = useProjectStore();
  const screenshot = () => {
    const mapState = useMapStore.getState();
    const visibleAreas = projectAreas.filter((a) => !mapState.hiddenAreaIds.has(a.id));
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const mapStylePref = useUiPrefsStore.getState().mapStyle;
    const preset = MAP_STYLE_PRESETS.find((p) => p.id === mapStylePref) ?? MAP_STYLE_PRESETS[0];
    const mapStyle = (preset.id !== 'satellite' && isDark)
      ? SMAPPEN_MAP_STYLE_DARK
      : (preset.styles ?? MAP_STYLE_PRESETS[0].styles!);

    // Live viewport is the fallback when there's nothing to auto-fit to.
    const c = mapInstance?.getCenter();
    const z = mapInstance?.getZoom();
    downloadMapSnapshot({
      areas: visibleAreas,
      heatmapFeatures: mapState.heatmapFeatures,
      showHeatmap: mapState.showHeatmap,
      mapStyle,
      lat: c?.lat(),
      lng: c?.lng(),
      zoom: z ?? undefined,
    });
  };
  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';

  const { isLoaded, loadError } = useJsApiLoader({
    googleMapsApiKey: apiKey,
    libraries: LIBRARIES,
  });

  useEffect(() => {
    if (isLoaded || loadError) return;
    const t = window.setTimeout(() => setStuckLoading(true), 12_000);
    return () => window.clearTimeout(t);
  }, [isLoaded, loadError]);

  return (
    <div className="flex flex-col h-screen">
      <Header />
      {/* Map fills the row; sidebars and toolbar float over it as cards. */}
      <div className="relative flex-1 min-h-0 bg-slate-50">
        {loadError && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
            <div className="text-sm text-red-600 max-w-md">
              Google Maps failed to load. Verify the Maps key has Maps JS, Places, and Geocoding enabled
              and is allowed for referrer <code>{location.host}/*</code>.
            </div>
            <button className="btn btn-primary" onClick={() => location.reload()}>Reload page</button>
          </div>
        )}
        {!loadError && !isLoaded && (
          // Branded loading state w/ animated logo + indeterminate progress
          // bar. Cuts the dead "Loading map…" gap that lasts 1-3s on first
          // visit while Google Maps JS downloads.
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-slate-50">
            <div className="page-loading-logo">S</div>
            <div className="text-sm text-slate-500 font-semibold">Loading map…</div>
            <div style={{ width: 180 }}><div className="progress-bar"><span /></div></div>
            {stuckLoading && (
              <button className="btn btn-secondary mt-2" onClick={() => location.reload()}>
                Map is taking too long. Reload?
              </button>
            )}
          </div>
        )}
        {isLoaded && (
          <ErrorBoundary scope="Map" inline>
            <MapCanvas />
          </ErrorBoundary>
        )}

        {isLoaded && (
          <>
            <ErrorBoundary scope="Left panel" inline>
              <LeftPanel
                onCreateArea={() => setCreatorOpen(true)}
                onImport={() => setImportOpen(true)}
              />
            </ErrorBoundary>
            {selectedAreaId && (
              <ErrorBoundary scope="Area details" inline>
                <RightPanel />
              </ErrorBoundary>
            )}
            <RightToolbar
              onCreateArea={() => setCreatorOpen(true)}
              onImport={() => setImportOpen(true)}
              onOpenAdvanced={() => setAdvancedOpen((v) => !v)}
              advancedOpen={advancedOpen}
              onScreenshot={screenshot}
            />
            {/* MiniMapToggle bottom-left preview was visually distracting +
                duplicative with the toolbar's heatmap button. Removed —
                heatmap toggle now lives only in the toolbar (with a gradient
                background when active, see RightToolbar). */}
            {advancedOpen && (
              <ErrorBoundary scope="Advanced tools" inline onReset={() => setAdvancedOpen(false)}>
                <AdvancedPanel onClose={() => setAdvancedOpen(false)} />
              </ErrorBoundary>
            )}
            {timeMachineRequest && (
              <ErrorBoundary scope="Time machine" inline onReset={closeTimeMachine}>
                <TimeMachinePanel
                  lat={timeMachineRequest.lat}
                  lng={timeMachineRequest.lng}
                  defaultMinutes={timeMachineRequest.minutes}
                  color={timeMachineRequest.color}
                  onClose={closeTimeMachine}
                />
              </ErrorBoundary>
            )}
          </>
        )}
      </div>

      {creatorOpen && isLoaded && (
        <ErrorBoundary scope="Area creator" inline onReset={() => setCreatorOpen(false)}>
          <AreaCreator onClose={() => setCreatorOpen(false)} />
        </ErrorBoundary>
      )}
      {importOpen && (
        <ErrorBoundary scope="Import wizard" inline onReset={() => setImportOpen(false)}>
          <ImportWizard onClose={() => setImportOpen(false)} />
        </ErrorBoundary>
      )}
      <ShortcutsModal />
      <CommandPalette />
      <OnboardingChecklist />
      <WhatsNewModal />
      {wizardOpen && isLoaded && (
        <ErrorBoundary scope="First-run wizard" inline onReset={() => setWizardOpen(false)}>
          <FirstRunWizard onClose={() => setWizardOpen(false)} />
        </ErrorBoundary>
      )}
    </div>
  );
}
