import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { X, ArrowRight, MapPin, Sparkles, Loader2, Search } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../../api/client';
import { isochroneApi } from '../../api/isochrone';
import { areasApi } from '../../api/areas';
import { reachApi } from '../../api/reach';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import AnimatedNumber from '../common/AnimatedNumber';

/**
 * First-run wizard. Three steps:
 *   1. Use case picker (sets users.use_case + tailors defaults)
 *   2. Address input — pick a starting point
 *   3. Auto-isochrone preview — 15-min drive, demographics, value moment
 *
 * Opens automatically when the user has no onboarding_flags.wizard_complete
 * AND the project area count is 0. After completion, stamps the flag and
 * never re-opens. "Skip" also stamps the flag so dismissive users aren't
 * pestered.
 */
const USE_CASES = [
  { key: 'franchise',       label: 'Franchise planning',      sub: 'Find new locations for your brand', icon: '🏬' },
  { key: 'sales_territory', label: 'Sales territories',       sub: 'Carve up coverage between reps',    icon: '🗺️' },
  { key: 'site_selection',  label: 'Site selection',          sub: 'Compare candidate addresses',       icon: '📍' },
  { key: 'delivery_zone',   label: 'Delivery zones',          sub: 'Optimize last-mile reach',          icon: '🚚' },
  { key: 'other',           label: 'Something else',          sub: 'I have my own use case',            icon: '✨' },
];

interface Props { onClose: () => void; }

export default function FirstRunWizard({ onClose }: Props) {
  const { currentProject, addArea } = useProjectStore() as any;
  const { fitBoundsToArea } = useMapStore();
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [useCase, setUseCase] = useState<string | null>(null);
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState<number | null>(null);
  const [lng, setLng] = useState<number | null>(null);
  const [working, setWorking] = useState(false);
  const [result, setResult] = useState<any | null>(null);
  const [population, setPopulation] = useState<number | null>(null);
  const addressRef = useRef<HTMLInputElement>(null);

  // Google Places autocomplete on step 2.
  useEffect(() => {
    if (step !== 2 || !addressRef.current || typeof google === 'undefined' || !google.maps?.places) return;
    const ac = new google.maps.places.Autocomplete(addressRef.current, { fields: ['geometry', 'formatted_address'] });
    const listener = ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place.geometry?.location) return;
      setLat(place.geometry.location.lat());
      setLng(place.geometry.location.lng());
      setAddress(place.formatted_address ?? '');
    });
    return () => {
      google.maps.event.removeListener(listener);
      document.querySelectorAll('.pac-container').forEach((el) => el.remove());
    };
  }, [step]);

  async function dismiss() {
    try { await api.post('/api/onboarding/seen', { flag: 'wizard_complete' }); } catch {}
    onClose();
  }

  async function pickUseCase(uc: string) {
    setUseCase(uc);
    try { await api.post('/api/onboarding/use-case', { use_case: uc }); } catch {}
    setStep(2);
  }

  async function buildFirstArea() {
    if (lat == null || lng == null || !currentProject) {
      toast.error('Pick an address first');
      return;
    }
    setWorking(true);
    try {
      const r = await isochroneApi.calculate({ lat, lng, time_minutes: 15, travel_mode: 'driving-car' });
      setResult(r);
      fitBoundsToArea(r.geojson);
      // Demographics preview — for the count-up.
      try {
        const demo = await reachApi.previewGeometry(r.geojson);
        setPopulation(demo.population ?? 0);
      } catch { /* non-blocking */ }
      // Save the area for the user.
      const a = await areasApi.create(currentProject.id, {
        name: `${address.split(',')[0]} – 15 min Car`,
        area_type: 'isochrone',
        center_lat: lat, center_lng: lng, center_address: address,
        travel_mode: 'driving-car',
        travel_time_minutes: 15,
        fill_color: '#7848BB', stroke_color: '#7848BB',
        geometry: r.geojson,
      } as any);
      addArea({ ...a, geometry: r.geojson } as any);
      setStep(3);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Could not compute drive-time');
    } finally { setWorking(false); }
  }

  async function finish() {
    try {
      await api.post('/api/onboarding/seen', { flag: 'wizard_complete' });
      await api.post('/api/onboarding/activate', { step: 'first_area' });
    } catch {}
    onClose();
  }

  return createPortal(
    <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-[300] flex items-center justify-center p-4" onClick={dismiss}>
      <div
        className="bg-white rounded-2xl shadow-2xl w-[min(560px,95vw)] overflow-hidden card-expand"
        onClick={(e) => e.stopPropagation()}
      >
        <div
          className="px-5 py-3 flex items-center justify-between"
          style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
        >
          <div className="flex items-center gap-2 text-white font-extrabold">
            <Sparkles size={16} /> Welcome to Smappen
          </div>
          <button onClick={dismiss} className="text-white/85 hover:text-white" title="Skip">
            <X size={16} />
          </button>
        </div>

        {/* Step indicator */}
        <div className="px-5 pt-3 flex items-center gap-1">
          {[1, 2, 3].map((s) => (
            <div
              key={s}
              className={`h-1 flex-1 rounded-full transition-colors ${
                s <= step ? 'bg-violet-500' : 'bg-slate-200'
              }`}
            />
          ))}
        </div>

        <div className="p-5 min-h-[280px]">
          {step === 1 && (
            <>
              <h2 className="font-extrabold text-xl" style={{ color: '#1A1A2E' }}>What brings you here?</h2>
              <p className="text-sm text-slate-600 mt-1 mb-4">We'll tailor the first steps to what you're trying to do.</p>
              <div className="grid grid-cols-1 gap-2">
                {USE_CASES.map((uc) => (
                  <button
                    key={uc.key}
                    onClick={() => pickUseCase(uc.key)}
                    className="flex items-center gap-3 px-3 py-3 rounded-lg border border-slate-200 hover:border-violet-400 hover:bg-violet-50 text-left transition-all"
                  >
                    <span className="text-2xl">{uc.icon}</span>
                    <div className="flex-1">
                      <div className="font-bold" style={{ color: '#1A1A2E' }}>{uc.label}</div>
                      <div className="text-xs text-slate-500">{uc.sub}</div>
                    </div>
                    <ArrowRight size={16} className="text-slate-400" />
                  </button>
                ))}
              </div>
            </>
          )}

          {step === 2 && (
            <>
              <h2 className="font-extrabold text-xl" style={{ color: '#1A1A2E' }}>Pick a starting point</h2>
              <p className="text-sm text-slate-600 mt-1 mb-4">
                Enter an address — your store, office, or anywhere you want to see the demographic reach of.
              </p>
              <div className="relative">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
                <input
                  ref={addressRef}
                  className="input pl-9 text-sm"
                  placeholder="Address or city"
                  value={address}
                  onChange={(e) => { setAddress(e.target.value); setLat(null); setLng(null); }}
                  autoFocus
                />
              </div>
              {lat != null && lng != null && (
                <div className="text-[10px] text-slate-400 mt-1 tabular-nums">
                  {lat.toFixed(5)}, {lng.toFixed(5)}
                </div>
              )}
              <div className="mt-4 flex justify-end gap-2">
                <button onClick={() => setStep(1)} className="btn btn-secondary h-9 px-3 text-sm">Back</button>
                <button
                  onClick={buildFirstArea}
                  disabled={working || lat == null || lng == null}
                  className="btn btn-primary h-9 px-4 text-sm"
                >
                  {working ? <><Loader2 size={13} className="animate-spin" /> Calculating…</> : 'See 15-min drive →'}
                </button>
              </div>
            </>
          )}

          {step === 3 && result && (
            <>
              <h2 className="font-extrabold text-xl" style={{ color: '#1A1A2E' }}>Look at that 🎉</h2>
              <p className="text-sm text-slate-600 mt-1 mb-4">
                Here's a 15-minute drive-time area around <b>{address.split(',')[0]}</b>. Everything inside is reachable from here in under 15 minutes during normal traffic.
              </p>
              <div className="grid grid-cols-2 gap-3 mb-4">
                <div className="rounded-lg p-3 bg-violet-50 border border-violet-100">
                  <div className="text-[10px] uppercase tracking-wider font-bold text-violet-700">Reach</div>
                  <div className="text-2xl font-extrabold mt-0.5" style={{ color: '#1A1A2E' }}>
                    <AnimatedNumber value={population} format={(n) => Math.round(n).toLocaleString()} /> people
                  </div>
                </div>
                <div className="rounded-lg p-3 bg-emerald-50 border border-emerald-100">
                  <div className="text-[10px] uppercase tracking-wider font-bold text-emerald-700">Area</div>
                  <div className="text-2xl font-extrabold mt-0.5" style={{ color: '#1A1A2E' }}>
                    <AnimatedNumber value={result.area_sq_km} format={(n) => n.toFixed(1)} /> km²
                  </div>
                </div>
              </div>
              <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600 leading-relaxed">
                The area is saved to your project. Open the <b>Demographics</b> tab on the right to see age, income, and housing breakdowns. Try the <b>Advanced ✨</b> panel for territory generation, analog finding, and revenue forecasting.
              </div>
              <div className="mt-4 flex justify-end">
                <button onClick={finish} className="btn btn-primary h-9 px-4 text-sm">Start mapping →</button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
