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
import { useMapStore } from '../../stores/mapStore';

const LIBRARIES: ('drawing' | 'visualization' | 'geometry' | 'places')[] = [
  'drawing', 'visualization', 'geometry', 'places',
];

export default function AppLayout() {
  const { selectedAreaId } = useMapStore();
  const [creatorOpen, setCreatorOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [stuckLoading, setStuckLoading] = useState(false);
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
        {isLoaded && <MapCanvas />}

        {isLoaded && (
          <>
            <LeftPanel
              onCreateArea={() => setCreatorOpen(true)}
              onImport={() => setImportOpen(true)}
            />
            {selectedAreaId && <RightPanel />}
            <RightToolbar
              onCreateArea={() => setCreatorOpen(true)}
              onImport={() => setImportOpen(true)}
            />
            <MiniMapToggle />
          </>
        )}
      </div>

      {creatorOpen && isLoaded && <AreaCreator onClose={() => setCreatorOpen(false)} />}
      {importOpen && <ImportWizard onClose={() => setImportOpen(false)} />}
    </div>
  );
}
