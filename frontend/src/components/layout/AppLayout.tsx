import { useState } from 'react';
import { useJsApiLoader } from '@react-google-maps/api';
import Header from './Header';
import FreeBanner from './FreeBanner';
import LeftPanel from './LeftPanel';
import RightPanel from './RightPanel';
import RightToolbar from './RightToolbar';
import MapCanvas from '../map/MapCanvas';
import AreaCreator from '../areas/AreaCreator';
import ImportWizard from '../data/ImportWizard';
import { useMapStore } from '../../stores/mapStore';

// Stable module-level reference; mutating this array would force LoadScript
// to reload and emit performance warnings.
const LIBRARIES = ['drawing', 'visualization', 'geometry', 'places'] as const;

export default function AppLayout() {
  const { selectedAreaId } = useMapStore();
  const [creatorOpen, setCreatorOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';

  const { isLoaded, loadError } = useJsApiLoader({
    googleMapsApiKey: apiKey,
    libraries: LIBRARIES as any,
  });

  return (
    <div className="flex flex-col h-screen">
      <Header />
      <FreeBanner />
      <div className="flex flex-1 min-h-0">
        <LeftPanel
          onCreateArea={() => setCreatorOpen(true)}
          onImport={() => setImportOpen(true)}
        />
        <div className="flex-1 relative min-w-0">
          {loadError && (
            <div className="absolute inset-0 flex items-center justify-center p-6 text-center">
              <div className="text-sm text-red-600 max-w-md">
                Google Maps failed to load. Verify <code>VITE_GOOGLE_MAPS_API_KEY</code> is set and that
                the key allows referrer <code>{location.host}</code>.
              </div>
            </div>
          )}
          {!loadError && !isLoaded && (
            <div className="absolute inset-0 flex items-center justify-center text-slate-500 text-sm">
              Loading map…
            </div>
          )}
          {isLoaded && <MapCanvas />}
        </div>
        {selectedAreaId && isLoaded && <RightPanel />}
        <RightToolbar
          onCreateArea={() => setCreatorOpen(true)}
          onImport={() => setImportOpen(true)}
        />
      </div>
      {creatorOpen && isLoaded && <AreaCreator onClose={() => setCreatorOpen(false)} />}
      {importOpen && <ImportWizard onClose={() => setImportOpen(false)} />}
    </div>
  );
}
