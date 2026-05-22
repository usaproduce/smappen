import { LoadScript } from '@react-google-maps/api';
import Header from './Header';
import LeftPanel from './LeftPanel';
import RightPanel from './RightPanel';
import MapCanvas from '../map/MapCanvas';
import { useMapStore } from '../../stores/mapStore';

const LIBRARIES: ('drawing' | 'visualization' | 'geometry' | 'places')[] = [
  'drawing', 'visualization', 'geometry', 'places',
];

export default function AppLayout() {
  const { selectedAreaId } = useMapStore();
  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';

  return (
    <div className="flex flex-col h-screen">
      <Header />
      <LoadScript googleMapsApiKey={apiKey} libraries={LIBRARIES} loadingElement={<div className="p-4 text-slate-500">Loading map…</div>}>
        <div className="flex flex-1 min-h-0">
          <LeftPanel />
          <div className="flex-1 relative min-w-0">
            <MapCanvas />
          </div>
          {selectedAreaId && <RightPanel />}
        </div>
      </LoadScript>
    </div>
  );
}
