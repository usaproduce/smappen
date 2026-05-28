import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import {
  X, ArrowRight, ArrowLeft, Sparkles, Loader2, Search,
  Store, Compass, MapPin, ChefHat,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../../api/client';
import { restaurantsApi } from '../../api/restaurants';
import type { Recommendation } from '../../stores/restaurantStore';
import { MoneyStat, RecommendationCard } from '../carafe';

/**
 * Carafe first-run wizard — spec §9.
 *
 *   Step 1   Use case picker (existing / planning / exploring)
 *   Step 2   Address (real) OR "Try with sample" (instant sample restaurant)
 *   Step 3   "We found these for you" — animated reveal of top recs +
 *            MoneyStat headline, then lands the operator on the war-room.
 *
 * Polished to match the Carafe motion + token vocabulary established in
 * earlier prompts. Mobile-first sizing (≤440px sheet on small viewports,
 * 540px card on desktop). Step indicator chips are clickable so back-
 * navigation works without a separate Back button — though a Back button
 * is also there for thumb-reach.
 *
 * Dismissal paths feed activation_metrics via /api/onboarding/dismiss-wizard:
 *   - skipped_step_1 / _2 / _3   (X button or backdrop)
 *   - completed_sample            (sample-restaurant happy path)
 *   - completed_real_manual       (the operator added their real address)
 */

type UseCaseKey = 'existing' | 'planning' | 'exploring';

const USE_CASES: { key: UseCaseKey; label: string; sub: string; Icon: typeof Store }[] = [
  { key: 'existing',  label: 'I run a restaurant',        sub: 'Wire up your menu and start finding margin.',         Icon: Store },
  { key: 'planning',  label: "I'm opening a new spot",   sub: "Study a neighborhood before you sign a lease.",       Icon: MapPin },
  { key: 'exploring', label: 'Just curious about Carafe', sub: 'Tour with a fully-loaded sample restaurant.',         Icon: Compass },
];

interface Props { onClose: () => void; onComplete?: () => void; }

export default function CarafeFirstRunWizard({ onClose, onComplete }: Props) {
  const navigate = useNavigate();
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [useCase, setUseCase] = useState<UseCaseKey | null>(null);
  const [restaurantName, setRestaurantName] = useState('');
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState<number | null>(null);
  const [lng, setLng] = useState<number | null>(null);
  const [working, setWorking] = useState(false);
  const [revealedRestaurantId, setRevealedRestaurantId] = useState<string | null>(null);
  const [sampleRecs, setSampleRecs] = useState<Recommendation[]>([]);
  const [foundCents, setFoundCents] = useState<number>(0);
  const addressRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);

  // Google Places autocomplete on step 2 — same pattern as the Smappen wizard.
  useEffect(() => {
    if (step !== 2 || useCase === 'exploring' || !addressRef.current
        || typeof google === 'undefined' || !google.maps?.places) return;
    const ac = new google.maps.places.Autocomplete(addressRef.current, {
      fields: ['geometry', 'formatted_address'],
    });
    const listener = ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place.geometry?.location) return;
      setLat(place.geometry.location.lat());
      setLng(place.geometry.location.lng());
      setAddress(place.formatted_address ?? '');
    });
    return () => {
      google.maps.event.removeListener(listener);
      // pac-container is body-mounted; remove leftovers so a re-open
      // doesn't stack multiple dropdowns.
      document.querySelectorAll('.pac-container.cf-wizard-pac').forEach((el) => el.remove());
    };
  }, [step, useCase]);

  // Step 1 → 2 transition: stamp use case + advance.
  async function pickUseCase(uc: UseCaseKey) {
    setUseCase(uc);
    // Wizard-state save is fire-and-forget; failure shouldn't block
    // the user from continuing.
    api.post('/api/onboarding/wizard-state', {
      wizard: 'carafe',
      state: { step: 2, useCase: uc },
    }).catch(() => undefined);
    setStep(2);
    // Move focus to the name field on the next paint. For 'exploring'
    // the step renders a single CTA so no focus target — skip.
    if (uc !== 'exploring') {
      requestAnimationFrame(() => nameRef.current?.focus());
    }
  }

  function dismiss(reason: 'skipped_step_1' | 'skipped_step_2' | 'skipped_step_3') {
    api.post('/api/onboarding/dismiss-wizard', { wizard: 'carafe', path: reason })
      .catch(() => undefined);
    onClose();
  }

  // The "Try with sample" path — builds a fully-populated demo restaurant
  // (24 items, 60 days of synthetic POS, open + measured recs, goals,
  // labor) in the caller's org via the SampleDataService entry point.
  // Idempotent: re-running upserts the same identifiers, so a back-button
  // round-trip never creates duplicates.
  async function trySample() {
    if (working) return;
    setWorking(true);
    try {
      const { id } = await restaurantsApi.createSample();
      // For the reveal we synthesize a tasteful sample-recs preview from
      // a static fixture — the real overview endpoint is fetched on the
      // war-room itself. This keeps the reveal animation instant rather
      // than waiting for another round-trip.
      setRevealedRestaurantId(id);
      setSampleRecs(buildSampleRecs());
      setFoundCents(4280 * 100);
      setStep(3);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Could not load sample');
    } finally {
      setWorking(false);
    }
  }

  async function createReal() {
    if (working) return;
    if (!restaurantName.trim()) {
      toast.error('Restaurant name required');
      return;
    }
    if (lat == null || lng == null) {
      toast.error('Pick an address from the suggestions');
      return;
    }
    setWorking(true);
    try {
      const { id } = await restaurantsApi.create({
        name: restaurantName.trim(),
        address: address || undefined,
        lat, lng,
      });
      setRevealedRestaurantId(id);
      // Real restaurants don't have recs yet — show the encouragement
      // tile instead of the "$X found" reveal. Step 3 picks the right
      // copy off `sampleRecs.length`.
      setSampleRecs([]);
      setFoundCents(0);
      setStep(3);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Could not create restaurant');
    } finally {
      setWorking(false);
    }
  }

  async function landOnWarRoom() {
    if (!revealedRestaurantId) return;
    const path = sampleRecs.length > 0 ? 'completed_sample' : 'completed_real_manual';
    api.post('/api/onboarding/dismiss-wizard', { wizard: 'carafe', path }).catch(() => undefined);
    onComplete?.();
    onClose();
    navigate(`/app/restaurants/${revealedRestaurantId}`);
  }

  // ── Step indicator → also allows back-navigation when the step has
  //    already been visited. Step 3 is terminal so it never lets you
  //    rewind (the restaurant has been created at that point). ───────
  function goToStep(target: 1 | 2 | 3) {
    if (target >= step) return;
    if (step === 3) return;
    setStep(target);
  }

  return createPortal(
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Welcome to Carafe"
      className="fixed inset-0 z-[300] flex items-end sm:items-center justify-center p-2 sm:p-4"
      style={{ background: 'rgba(15, 23, 42, 0.45)', backdropFilter: 'blur(4px)' }}
      onClick={() => dismiss(`skipped_step_${step}` as `skipped_step_${1 | 2 | 3}`)}
    >
      <div
        className="card-expand w-[min(540px,100vw-1rem)] max-h-[calc(100vh-1rem)] overflow-y-auto rounded-2xl shadow-float flex flex-col"
        style={{ background: 'white' }}
        onClick={(e) => e.stopPropagation()}
      >
        <header
          className="px-5 py-3.5 flex items-center justify-between border-b"
          style={{ borderColor: 'var(--line-soft)' }}
        >
          <div className="flex items-center gap-2 font-extrabold text-sm" style={{ color: 'var(--ink)' }}>
            <span
              aria-hidden
              className="inline-flex items-center justify-center w-7 h-7 rounded-md text-white font-extrabold"
              style={{ background: 'var(--carafe-accent)' }}
            >
              <ChefHat size={14} />
            </span>
            Welcome to Carafe
          </div>
          <button
            type="button"
            onClick={() => dismiss(`skipped_step_${step}` as `skipped_step_${1 | 2 | 3}`)}
            aria-label="Skip wizard"
            className="inline-flex items-center justify-center w-11 h-11 rounded-lg hover:bg-slate-50"
            style={{ color: 'var(--slate)' }}
          >
            <X size={16} />
          </button>
        </header>

        {/* ── Step indicator ────────────────────────────────────────── */}
        <nav
          aria-label="Wizard progress"
          className="px-5 pt-3 pb-2 flex items-center gap-1.5"
        >
          {[1, 2, 3].map((s) => {
            const isPast = (s as 1 | 2 | 3) < step;
            const isCurrent = s === step;
            const isClickable = isPast && step !== 3;
            return (
              <button
                key={s}
                type="button"
                onClick={() => goToStep(s as 1 | 2 | 3)}
                disabled={!isClickable}
                aria-label={`Step ${s}${isCurrent ? ', current' : isPast ? ', go back' : ''}`}
                aria-current={isCurrent ? 'step' : undefined}
                className="flex-1 h-1.5 rounded-full transition-colors disabled:cursor-default"
                style={{
                  background: s <= step ? 'var(--carafe-accent)' : 'var(--bg-panel)',
                  cursor: isClickable ? 'pointer' : 'default',
                }}
              />
            );
          })}
        </nav>

        <div className="p-5 min-h-[320px]">
          {step === 1 && <Step1 onPick={pickUseCase} />}
          {step === 2 && useCase && (
            <Step2
              useCase={useCase}
              restaurantName={restaurantName}
              setRestaurantName={setRestaurantName}
              address={address}
              setAddress={setAddress}
              setLat={setLat}
              setLng={setLng}
              latLngSet={lat != null && lng != null}
              nameRef={nameRef}
              addressRef={addressRef}
              working={working}
              onBack={() => setStep(1)}
              onTrySample={trySample}
              onCreateReal={createReal}
            />
          )}
          {step === 3 && (
            <Step3
              foundCents={foundCents}
              recs={sampleRecs}
              isSample={sampleRecs.length > 0}
              onContinue={landOnWarRoom}
            />
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}

/* ── Step 1: Use case picker ─────────────────────────────────────────── */
function Step1({ onPick }: { onPick: (uc: UseCaseKey) => void }) {
  return (
    <>
      <h2 className="font-extrabold text-xl sm:text-2xl" style={{ color: 'var(--ink)' }}>
        How can Carafe help today?
      </h2>
      <p className="text-sm mt-1.5 mb-4" style={{ color: 'var(--body)' }}>
        We'll tailor your first minute to where you are.
      </p>
      <div className="grid grid-cols-1 gap-2.5">
        {USE_CASES.map((uc, i) => {
          const Icon = uc.Icon;
          return (
            <button
              key={uc.key}
              type="button"
              onClick={() => onPick(uc.key)}
              className="stagger-in flex items-center gap-3 px-3 py-3.5 rounded-xl border text-left min-h-[64px] focus-visible:outline-none focus-visible:ring-2"
              style={{
                background: 'white',
                borderColor: 'var(--line)',
                ['--stagger-i' as any]: i,
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.borderColor = 'var(--carafe-accent)';
                e.currentTarget.style.background = 'var(--carafe-accent-50)';
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.borderColor = 'var(--line)';
                e.currentTarget.style.background = 'white';
              }}
            >
              <span
                aria-hidden
                className="inline-flex items-center justify-center w-11 h-11 rounded-lg flex-shrink-0"
                style={{ background: 'var(--carafe-accent-light)', color: 'var(--carafe-accent-dark)' }}
              >
                <Icon size={20} strokeWidth={2.2} />
              </span>
              <div className="flex-1 min-w-0">
                <div className="font-bold text-sm" style={{ color: 'var(--ink)' }}>{uc.label}</div>
                <div className="text-xs mt-0.5" style={{ color: 'var(--slate)' }}>{uc.sub}</div>
              </div>
              <ArrowRight size={16} className="flex-shrink-0" style={{ color: 'var(--slate)' }} />
            </button>
          );
        })}
      </div>
    </>
  );
}

/* ── Step 2: Address (real) OR sample CTA ────────────────────────────── */
function Step2({
  useCase, restaurantName, setRestaurantName, address, setAddress,
  setLat, setLng, latLngSet, nameRef, addressRef, working,
  onBack, onTrySample, onCreateReal,
}: {
  useCase: UseCaseKey;
  restaurantName: string;
  setRestaurantName: (s: string) => void;
  address: string;
  setAddress: (s: string) => void;
  setLat: (n: number | null) => void;
  setLng: (n: number | null) => void;
  latLngSet: boolean;
  nameRef: React.RefObject<HTMLInputElement>;
  addressRef: React.RefObject<HTMLInputElement>;
  working: boolean;
  onBack: () => void;
  onTrySample: () => void;
  onCreateReal: () => void;
}) {
  const exploring = useCase === 'exploring';
  return (
    <>
      <h2 className="font-extrabold text-xl sm:text-2xl" style={{ color: 'var(--ink)' }}>
        {exploring ? 'Tour with our sample restaurant' : 'Where are you operating?'}
      </h2>
      <p className="text-sm mt-1.5 mb-4" style={{ color: 'var(--body)' }}>
        {exploring
          ? "We'll spin up a fully-loaded Italian restaurant so you can play with the dashboard, recs, and reports. Takes a few seconds."
          : useCase === 'planning'
            ? "Drop the address you're considering. We'll set you up with that location and you can dig into the trade area first."
            : "We'll create your restaurant profile so we can wire your POS and find margin in your menu."}
      </p>

      {!exploring && (
        <div className="space-y-3">
          <label className="block">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              Restaurant name
            </span>
            <input
              ref={nameRef}
              type="text"
              value={restaurantName}
              onChange={(e) => setRestaurantName(e.target.value)}
              placeholder="e.g. Casa Pesto"
              className="input mt-1"
            />
          </label>
          <label className="block">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              Address
            </span>
            <div className="relative mt-1">
              <Search
                size={14}
                className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                style={{ color: 'var(--slate)' }}
                aria-hidden
              />
              <input
                ref={addressRef}
                type="text"
                value={address}
                onChange={(e) => { setAddress(e.target.value); setLat(null); setLng(null); }}
                placeholder="Street, city, state"
                className="input pl-9 cf-wizard-pac-anchor"
              />
            </div>
            {latLngSet && (
              <div className="text-[10px] mt-1" style={{ color: 'var(--money-positive)' }}>
                ✓ Picked from suggestions
              </div>
            )}
          </label>
        </div>
      )}

      {exploring && (
        <div
          className="rounded-xl border p-4 flex items-start gap-3"
          style={{ background: 'var(--carafe-accent-50)', borderColor: 'var(--carafe-accent-light)' }}
        >
          <span
            aria-hidden
            className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
            style={{ background: 'var(--carafe-accent-light)', color: 'var(--carafe-accent-dark)' }}
          >
            <ChefHat size={18} strokeWidth={2.2} />
          </span>
          <div className="text-sm" style={{ color: 'var(--body)' }}>
            <div className="font-bold" style={{ color: 'var(--ink)' }}>You'll get</div>
            <ul className="mt-1 space-y-0.5 list-disc list-inside text-xs">
              <li>A 35-item menu with plate costs already computed</li>
              <li>90 days of synthetic POS sales</li>
              <li>Open recommendations you can accept or dismiss</li>
              <li>Sample USDA-region COGS attribution</li>
            </ul>
          </div>
        </div>
      )}

      <div className="mt-5 flex items-center gap-2 flex-wrap">
        <button
          type="button"
          onClick={onBack}
          className="inline-flex items-center gap-1.5 px-3 min-h-[44px] rounded-lg text-sm font-semibold"
          style={{ background: 'white', border: '1px solid var(--line)', color: 'var(--ink)' }}
        >
          <ArrowLeft size={14} /> Back
        </button>
        <div className="flex-1" />
        {exploring ? (
          <button
            type="button"
            onClick={onTrySample}
            disabled={working}
            className="inline-flex items-center gap-1.5 px-4 min-h-[44px] rounded-lg text-sm font-bold text-white disabled:opacity-60"
            style={{ background: 'var(--carafe-accent)' }}
          >
            {working ? <><Loader2 size={14} className="animate-spin" /> Loading sample…</>
                     : <>Try with sample <ArrowRight size={14} /></>}
          </button>
        ) : (
          <>
            <button
              type="button"
              onClick={onTrySample}
              disabled={working}
              className="inline-flex items-center gap-1.5 px-3 min-h-[44px] rounded-lg text-sm font-semibold"
              style={{ background: 'white', border: '1px solid var(--line)', color: 'var(--ink)' }}
            >
              Try sample first
            </button>
            <button
              type="button"
              onClick={onCreateReal}
              disabled={working || !restaurantName.trim() || !latLngSet}
              className="inline-flex items-center gap-1.5 px-4 min-h-[44px] rounded-lg text-sm font-bold text-white disabled:opacity-60"
              style={{ background: 'var(--carafe-accent)' }}
            >
              {working ? <><Loader2 size={14} className="animate-spin" /> Creating…</>
                       : <>Create restaurant <ArrowRight size={14} /></>}
            </button>
          </>
        )}
      </div>
    </>
  );
}

/* ── Step 3: Reveal ──────────────────────────────────────────────────── */
function Step3({
  foundCents, recs, isSample, onContinue,
}: {
  foundCents: number;
  recs: Recommendation[];
  isSample: boolean;
  onContinue: () => void;
}) {
  return (
    <>
      <div className="flex items-center gap-2 mb-2">
        <Sparkles size={16} style={{ color: 'var(--carafe-accent)' }} aria-hidden />
        <span
          className="text-[10px] font-bold uppercase tracking-wider"
          style={{ color: 'var(--carafe-accent)' }}
        >
          {isSample ? "Here's what's already on the table" : "You're all set"}
        </span>
      </div>

      {isSample ? (
        <MoneyStat
          label="We found these moves for you"
          value={Math.round(foundCents / 100)}
          tone="positive"
          size="xl"
          footer={
            <span style={{ color: 'var(--slate)' }}>
              monthly impact across the recs below
            </span>
          }
        />
      ) : (
        <h2 className="font-extrabold text-2xl" style={{ color: 'var(--ink)' }}>
          Your war-room is ready
        </h2>
      )}

      {isSample ? (
        <ul className="space-y-2 mt-4">
          {recs.map((rec, i) => (
            <li
              key={rec.id}
              className="stagger-in"
              style={{ ['--stagger-i' as any]: i }}
            >
              <RecommendationCard
                rec={rec}
                itemName={rec.payload?.menu_item_name as string | undefined ?? null}
                density="compact"
                readonly
              />
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm mt-3" style={{ color: 'var(--body)' }}>
          Next we'll walk you through connecting your POS and adding recipes so we can start
          measuring real margin. Both take about a minute each.
        </p>
      )}

      <div className="mt-5 flex justify-end">
        <button
          type="button"
          onClick={onContinue}
          className="inline-flex items-center gap-1.5 px-4 min-h-[44px] rounded-lg text-sm font-bold text-white"
          style={{ background: 'var(--carafe-accent)' }}
        >
          {isSample ? 'Open the war-room' : 'Start with my restaurant'}
          <ArrowRight size={14} />
        </button>
      </div>
    </>
  );
}

/* ── Sample-recs fixture (matches the seeded sample restaurant) ──────── */
function buildSampleRecs(): Recommendation[] {
  const now = new Date().toISOString();
  return [
    {
      id: 'sample-rec-1',
      menu_item_id: 'sample-1',
      kind: 'price_raise',
      payload: { menu_item_name: 'Carbonara', price_delta_cents: 150, est_monthly_qty: 280 },
      narrative: 'Lifting Carbonara to $19.50 captures the 22% margin gap your POS shows.',
      dollar_estimate_cents: 1640 * 100,
      status: 'suggested',
      measured_impact_cents: null,
      created_at: now,
      decided_at: null,
      measured_at: null,
    },
    {
      id: 'sample-rec-2',
      menu_item_id: 'sample-2',
      kind: 'reposition',
      payload: { menu_item_name: 'Bruschetta plate', est_monthly_qty: 140 },
      narrative: 'Bruschetta is a Star — feature it on the menu top to lift attach.',
      dollar_estimate_cents: 820 * 100,
      status: 'suggested',
      measured_impact_cents: null,
      created_at: now,
      decided_at: null,
      measured_at: null,
    },
    {
      id: 'sample-rec-3',
      menu_item_id: 'sample-3',
      kind: 'cut',
      payload: { menu_item_name: 'Calamari fritti', est_monthly_qty: 18 },
      narrative: 'Calamari is a Dog: low margin, low volume. Cut to free menu real estate.',
      dollar_estimate_cents: 480 * 100,
      status: 'suggested',
      measured_impact_cents: null,
      created_at: now,
      decided_at: null,
      measured_at: null,
    },
  ];
}
