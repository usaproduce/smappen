import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';

interface Props {
  lat: number;
  lng: number;
  label?: string;
  onClose: () => void;
}

/**
 * Street View modal — drops you into a panoramic for the given lat/lng.
 * Uses Google Maps JS Street View Panorama (already loaded for the map),
 * so no extra API surface or key configuration is needed.
 *
 * If Street View has no coverage at the exact point, this falls back to
 * the nearest panorama within 50m and shows a small note.
 */
export default function StreetViewModal({ lat, lng, label, onClose }: Props) {
  const ref = useRef<HTMLDivElement>(null);
  const noCoverageRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!ref.current || typeof google === 'undefined') return;
    const sv = new google.maps.StreetViewService();
    sv.getPanorama({ location: { lat, lng }, radius: 50 }, (data, status) => {
      if (status === 'OK' && data?.location?.latLng) {
        new google.maps.StreetViewPanorama(ref.current!, {
          position: data.location.latLng,
          pov: { heading: 0, pitch: 0 },
          zoom: 1,
          fullscreenControl: false,
          addressControl: false,
        });
      } else if (noCoverageRef.current) {
        noCoverageRef.current.style.display = 'flex';
      }
    });
    // Escape closes the modal so users don't have to mouse over the X.
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [lat, lng, onClose]);

  return createPortal(
    <div className="fixed inset-0 z-[60] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-white rounded-xl shadow-2xl w-[min(960px,100%)] h-[min(640px,90vh)] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-4 py-2.5 border-b border-slate-200">
          <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>
            Street View{label ? ` · ${label}` : ''}
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 rounded text-slate-500">
            <X size={16} />
          </button>
        </div>
        <div className="relative flex-1">
          <div ref={ref} className="absolute inset-0" />
          <div
            ref={noCoverageRef}
            className="absolute inset-0 hidden flex-col items-center justify-center bg-slate-100 text-slate-600 text-sm"
          >
            <div className="font-semibold mb-1">No Street View coverage</div>
            <div className="text-xs">Google doesn't have imagery within 50m of this point.</div>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
