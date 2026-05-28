import { useEffect, useState } from 'react';
import { AnimatePresence } from 'framer-motion';
import { useJsApiLoader } from '@react-google-maps/api';
import Header from './Header';
import AppNav from './AppNav';
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
import { saveProjectSnapshot, downloadMapSnapshot, buildSnapshotFilename } from '../../utils/mapExport';
import type { SnapshotTarget } from '../../utils/mapExport';
import { SMAPPEN_MAP_STYLE_DARK, MAP_STYLE_PRESETS } from '../../utils/mapStyle';
import { GOOGLE_MAPS_LIBRARIES } from '../../utils/mapsLoader';

// Local alias kept for the existing isLoaded call — see ../../utils/mapsLoader
// for why every useJsApiLoader call must share the same options object.
const LIBRARIES = GOOGLE_MAPS_LIBRARIES;

export default function AppLayout() {
  const { selectedAreaId, mapInstance, timeMachineRequest, closeTimeMachine, editingAreaId, closeAreaEditor } = useMapStore();
  const { currentProject, areas } = useProjectStore();
  const [creatorOpen, setCreatorOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [stuckLoading, setStuckLoading] = useState(false);
  const [wizardOpen, setWizardOpen] = useState(false);
  const editingArea = editingAreaId ? areas.find((a) => a.id === editingAreaId) ?? null : null;

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
    onScreenshot: () => screenshot('download'),
  });
  useDynamicFavicon();
  useViewUrl();

  // Pull the project's areas at snapshot-time so the static map captures
  // every polygon + center pin currently visible. Hidden areas are filtered
  // out so the export both auto-fits to and renders only what's on screen.
  const { areas: projectAreas } = useProjectStore();
  const screenshot = (target: SnapshotTarget = 'download') => {
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

    // Title block + filename pull from the active project so each export is
    // self-identifying when pasted into a deck or email.
    const projectName = currentProject?.name ?? null;
    const now = new Date();
    const dateLabel = now.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    const areaCount = visibleAreas.length;
    const subtitle = `${dateLabel}${areaCount > 0 ? ` · ${areaCount} area${areaCount === 1 ? '' : 's'}` : ''}`;

    downloadMapSnapshot({
      areas: visibleAreas,
      heatmapFeatures: mapState.heatmapFeatures,
      showHeatmap: mapState.showHeatmap,
      heatmapMeta: mapState.heatmapMeta,
      heatmapMetric: mapState.heatmapMetric,
      heatmapPaletteId: mapState.heatmapPaletteId,
      mapStyle,
      lat: c?.lat(),
      lng: c?.lng(),
      zoom: z ?? undefined,
      title: projectName ?? 'Untitled map',
      subtitle,
      filename: buildSnapshotFilename(projectName),
      target,
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
      {/* Single bar — AppNav (brand + cross-product tabs + user) wraps
          the map-specific Header (project switcher + undo/redo + cost +
          bell + Share) into its page-context slot. One row, not two. */}
      <AppNav>
        <Header />
      </AppNav>
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
            {/* AnimatePresence so the panel's exit (spring back into the
                icon rail) actually plays before unmount. */}
            <AnimatePresence>
              {selectedAreaId && (
                <ErrorBoundary scope="Area details" inline>
                  <RightPanel />
                </ErrorBoundary>
              )}
            </AnimatePresence>
            <RightToolbar
              onCreateArea={() => setCreatorOpen(true)}
              onImport={() => setImportOpen(true)}
              onOpenAdvanced={() => setAdvancedOpen((v) => !v)}
              advancedOpen={advancedOpen}
              onScreenshot={(target) => screenshot(target)}
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

      {(creatorOpen || editingArea) && isLoaded && (
        <ErrorBoundary
          scope={editingArea ? 'Area editor' : 'Area creator'}
          inline
          onReset={() => { setCreatorOpen(false); closeAreaEditor(); }}
        >
          <AreaCreator
            // `key` so swapping target areas remounts the panel with fresh
            // state — otherwise opening Edit on a second area while the
            // first is still mounted would keep the previous initial values.
            key={editingArea?.id ?? 'create'}
            editing={editingArea ?? undefined}
            onClose={() => { setCreatorOpen(false); closeAreaEditor(); }}
          />
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
