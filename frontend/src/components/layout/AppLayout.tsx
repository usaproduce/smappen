import { useEffect, useState } from 'react';
import { useJsApiLoader } from '@react-google-maps/api';
import Header from './Header';
import LeftPanel from './LeftPanel';
import RightPanel from './RightPanel';
import RightToolbar from './RightToolbar';
import MapCanvas from '../map/MapCanvas';
import AreaCreator from '../areas/AreaCreator';
import ImportWizard from '../data/ImportWizard';
import MiniMapToggle from '../map/MiniMapToggle';
import AdvancedPanel from '../advanced/AdvancedPanel';
import TimeMachinePanel from '../map/TimeMachinePanel';
import ErrorBoundary from '../ErrorBoundary';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useShortcuts } from '../../hooks/useShortcuts';
import { saveProjectSnapshot, downloadMapSnapshot } from '../../utils/mapExport';

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

  useShortcuts({
    onCreateArea: () => setCreatorOpen(true),
    onSaveSnapshot: () => currentProject && saveProjectSnapshot(currentProject.id),
  });

  const screenshot = () => {
    const c = mapInstance?.getCenter();
    const z = mapInstance?.getZoom();
    if (!c || z == null) return;
    downloadMapSnapshot({ lat: c.lat(), lng: c.lng(), zoom: z });
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
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 text-slate-500 text-sm">
            <div className="flex items-center gap-2">
              <span className="inline-block w-4 h-4 border-2 border-slate-300 border-t-violet-600 rounded-full animate-spin" />
              Loading map…
            </div>
            {stuckLoading && (
              <button className="btn btn-secondary" onClick={() => location.reload()}>
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
            <MiniMapToggle />
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
    </div>
  );
}
