import { useId, useMemo, useRef, useState, type ReactNode } from 'react';
import toast from 'react-hot-toast';
import { TriangleAlert } from 'lucide-react';
import { DAYPARTS } from '../../../utils/truck/model';
import type { FuelType } from '../../../utils/truck/model';
import { fmtMoneyCents } from '../../../utils/truck/format';
import {
  FUEL_TYPE_LABELS,
  PROFILE_LABELS,
  PROFILE_TEXT_LIMITS,
  daypartRule,
  extraShareOfFactor,
  extraShareRule,
  factorOfExtraShare,
  previewFuelPrice,
  profileDraftDirty,
  profileFromDraft,
  profilePatch,
  profileRule,
  settingsMeaning,
  validateProfileDraft,
  type ProfileDraft,
  type ProfileNumberKey,
} from '../../../utils/truck/profileForm';
import { DAYPART_LABELS, profileWarningTexts } from '../../../utils/truck/wording';
import { useBootstrap, useSaveProfile, useTruck } from '../data';
import { Field, MoneyField, NumberField, StatList, StatRow, Toggle } from '../ui';
import BasePicker from './BasePicker';
import CountyChecklist from './CountyChecklist';
import FuelPriceCard from './FuelPriceCard';
import StartingValuesModal from './StartingValuesModal';

export interface TruckCostsTabProps {
  /** The profile as the owner is editing it. */
  draft: ProfileDraft;
  onChange: (next: ProfileDraft) => void;
  /** The draft was saved: the form follows the saved profile from here on. */
  onSaved: () => void;
  /** Throw the draft away: the form follows the saved profile again. */
  onDiscard: () => void;
}

const CARD = 'bg-white rounded-xl border p-4 sm:p-5';
const NO_REGIONS: never[] = [];

/**
 * "Truck and costs" (docs/truck-planner/05_FRONTEND.md 4.9): the truck profile. The page edits a
 * draft; "Save changes" sends route 3 with only the keys that changed. Every range is the seed
 * file's (`profile_defaults.<field>.min` and `.max`): a value outside it stays in its field with
 * the range message and holds the save back. Nothing is clamped.
 */
export default function TruckCostsTab({ draft, onChange, onSaved, onDiscard }: TruckCostsTabProps) {
  const ids = useId();
  const { profile, fuel, region } = useTruck();
  const regions = useBootstrap((data) => data.regions).data ?? NO_REGIONS;
  const save = useSaveProfile();
  const [tried, setTried] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [showStarting, setShowStarting] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  const errors = validateProfileDraft(draft);
  const visible = tried ? errors : {};
  const dirty = profileDraftDirty(profile, draft);
  const set = (part: Partial<ProfileDraft>) => {
    onChange({ ...draft, ...part });
    setProblem(null);
  };

  // "What these settings mean" follows the draft; the rest of the app follows the saved profile.
  const preview = useMemo(() => profileFromDraft(profile, draft), [profile, draft]);
  const meaning = useMemo(() => (preview === null ? null : settingsMeaning(preview, previewFuelPrice(profile, draft, fuel))), [preview, profile, draft, fuel]);

  const submit = () => {
    if (save.isPending) return;
    // A field that still shows its own refusal holds the save back: what is on screen there is
    // not what would be saved.
    const refused = box.current === null ? null : box.current.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (Object.keys(errors).length > 0 || refused !== null) {
      setTried(true);
      setProblem('Check the marked fields first. Nothing was saved.');
      if (refused !== null) refused.focus();
      return;
    }
    const patch = profilePatch(profile, draft);
    if (Object.keys(patch).length === 0) {
      onDiscard();
      return;
    }
    setProblem(null);
    save
      .mutateAsync(patch)
      .then((answer) => {
        setTried(false);
        onSaved();
        for (const text of profileWarningTexts(answer.warnings)) toast(text, { duration: 8000 });
      })
      .catch(() => {
        // The hook has put the saved values back and shown the server's sentence.
      });
  };

  const number = (key: ProfileNumberKey) => {
    const rule = profileRule(key);
    return {
      id: ids + key,
      label: PROFILE_LABELS[key],
      value: draft[key],
      onCommit: (value: number | null) => set({ [key]: value } as Partial<ProfileDraft>),
      min: rule.min,
      max: rule.max,
      integer: rule.integer,
      required: true,
      error: visible[key],
    };
  };
  const fit = daypartRule();
  const extra = extraShareRule();
  const fuelPending = draft.fuel_type !== profile.fuel_type || draft.fuel_price_override !== profile.fuel_price_override;

  return (
    <div ref={box} className="grid gap-4 lg:grid-cols-12">
      <div className="min-w-0 space-y-4 lg:col-span-8">
        <Card title="Truck">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id={ids + 'name'} label="Truck name" error={visible.name} required>
              {(control) => (
                <input
                  {...control}
                  type="text"
                  className="input h-11 md:h-9 text-sm font-semibold"
                  value={draft.name}
                  maxLength={PROFILE_TEXT_LIMITS.nameMax}
                  autoComplete="off"
                  onChange={(e) => set({ name: e.target.value })}
                />
              )}
            </Field>
            <div>
              <div className="label">Region</div>
              <p className="text-sm font-semibold" style={{ color: 'var(--ink)' }}>
                {region !== null ? region.name : 'No map data for this area'}
              </p>
              <p className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
                Set by where the base is.
              </p>
            </div>
          </div>
          <BasePicker value={draft.base} onChange={(base) => set({ base })} regions={regions} error={visible.base} />
        </Card>

        <Card title="Sales">
          <div className="grid gap-4 sm:grid-cols-2">
            <MoneyField {...number('avg_ticket')} help="What one order comes to on average, before tax and tips." />
            <NumberField {...number('capacity_orders_per_hour')} help="The most you can serve in an hour. Estimates never go above this." />
          </div>
          <fieldset>
            <legend className="label">How well your menu fits each part of the day</legend>
            <div className="grid items-end gap-3 grid-cols-2 lg:grid-cols-4">
              {DAYPARTS.map((part) => (
                <NumberField
                  key={part}
                  id={ids + 'fit-' + part}
                  label={DAYPART_LABELS[part]}
                  format="percent"
                  value={draft.daypart_fit[part]}
                  onCommit={(value) => set({ daypart_fit: { ...draft.daypart_fit, [part]: value } })}
                  min={fit.min}
                  max={fit.max}
                  required
                  error={visible['daypart_fit.' + part]}
                />
              ))}
            </div>
            <p className="mt-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              100% is a full fit. 30% means about a third of the people buying then would consider your menu.
            </p>
          </fieldset>
        </Card>

        <Card title="Crew">
          <div className="grid gap-4 sm:grid-cols-3">
            <NumberField {...number('paid_crew')} help="Do not count yourself." />
            <MoneyField {...number('wage_per_hour')} />
            <NumberField {...number('payroll_burden_pct')} format="percent" />
          </div>
        </Card>

        <Card title="Food and fees">
          <div className="grid gap-4 sm:grid-cols-2">
            <NumberField {...number('food_cost_pct')} format="percent" help="The share of sales that goes on ingredients." />
            <MoneyField {...number('packaging_per_order')} help="Set to $0 if your food cost already includes packaging." />
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <NumberField {...number('card_fee_pct')} format="percent" decimals={1} step={0.001} />
            <MoneyField {...number('card_fee_fixed')} />
            <NumberField {...number('card_share')} format="percent" />
          </div>
          <Toggle id={ids + 'tips'} label={PROFILE_LABELS.tips_include} checked={draft.tips_include} onChange={(tips_include) => set({ tips_include })} />
          {draft.tips_include ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <NumberField {...number('tips_pct_of_card_sales')} format="percent" />
            </div>
          ) : null}
        </Card>

        <Card title="Vehicle and fuel">
          <div className="grid gap-4 sm:grid-cols-2">
            <NumberField {...number('mpg')} />
            <Field id={ids + 'fuel-type'} label={PROFILE_LABELS.fuel_type}>
              {(control) => (
                <select
                  {...control}
                  className="select h-11 md:h-9 text-sm font-semibold"
                  style={{ paddingTop: 0, paddingBottom: 0 }}
                  value={draft.fuel_type}
                  onChange={(e) => set({ fuel_type: e.target.value === 'diesel' ? 'diesel' : 'gasoline' })}
                >
                  {(Object.keys(FUEL_TYPE_LABELS) as FuelType[]).map((type) => (
                    <option key={type} value={type}>
                      {FUEL_TYPE_LABELS[type]}
                    </option>
                  ))}
                </select>
              )}
            </Field>
            <NumberField {...number('generator_gal_per_hour')} suffix="gal" step={0.1} help="Set to 0 on shore power." />
            <NumberField
              id={ids + 'truck_time_factor'}
              label={PROFILE_LABELS.truck_time_factor}
              format="percent"
              value={draft.truck_time_factor === null ? null : extraShareOfFactor(draft.truck_time_factor)}
              onCommit={(share) => set({ truck_time_factor: share === null ? null : factorOfExtraShare(share) })}
              min={extra.min}
              max={extra.max}
              required
              error={visible.truck_time_factor}
              help="10% means a 20 minute car drive takes the truck 22."
            />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Toggle id={ids + 'avoid-tolls'} label={PROFILE_LABELS.avoid_tolls} checked={draft.avoid_tolls} onChange={(avoid_tolls) => set({ avoid_tolls })} />
            <Toggle id={ids + 'avoid-highways'} label={PROFILE_LABELS.avoid_highways} checked={draft.avoid_highways} onChange={(avoid_highways) => set({ avoid_highways })} />
          </div>
          <FuelPriceCard
            id={ids + 'fuel-price'}
            fuel={fuel}
            value={draft.fuel_price_override}
            onChange={(fuel_price_override) => set({ fuel_price_override })}
            pendingChange={fuelPending}
            error={visible.fuel_price_override}
          />
        </Card>

        <Card title="Day routine">
          <div className="grid items-end gap-4 grid-cols-2 lg:grid-cols-4">
            <NumberField {...number('prep_minutes')} suffix="min" />
            <NumberField {...number('setup_minutes')} suffix="min" />
            <NumberField {...number('teardown_minutes')} suffix="min" />
            <NumberField {...number('closeout_minutes')} suffix="min" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <MoneyField {...number('fixed_cost_per_service_day')} help="Commissary, insurance or anything else you pay on each day you go out." />
          </div>
        </Card>

        <Card title="Where you trade">
          <fieldset>
            <legend className="label">Counties you hold a licence for</legend>
            <CountyChecklist
              counties={region !== null ? region.counties : NO_REGIONS}
              value={draft.licence_counties}
              onChange={(licence_counties) => set({ licence_counties })}
              error={visible.licence_counties}
            />
            <p className="mt-2 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              Scout only lists places in these counties. Leave all unticked to see every county within reach. Where your licence applies is yours to
              know.
            </p>
          </fieldset>
          <div className="grid gap-4 sm:grid-cols-2">
            <NumberField {...number('scout_drive_minutes_limit')} suffix="min" />
          </div>
        </Card>
      </div>

      <aside className="min-w-0 lg:col-span-4">
        <div className="lg:sticky" style={{ top: 112 }}>
          <section className={CARD} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby={ids + 'meaning'}>
            <h2 id={ids + 'meaning'} className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              What these settings mean
            </h2>
            {meaning === null ? (
              <p className="mt-2 text-sm font-semibold" style={{ color: 'var(--body)' }}>
                Fill in the marked fields to see what they come to.
              </p>
            ) : (
              <StatList className="mt-1">
                <StatRow
                  label="Left per order after food, packaging and card fees"
                  value={fmtMoneyCents(meaning.perOrder)}
                  negative={meaning.perOrder < 0}
                  sub={meaning.perOrder <= 0 ? 'Every order loses money before any other cost.' : undefined}
                  strong
                />
                <StatRow label="Crew cost per paid hour" value={fmtMoneyCents(meaning.crewPerPaidHour)} />
                <StatRow
                  label="Fuel per mile"
                  value={fmtMoneyCents(meaning.fuelPerMile)}
                  sub={meaning.fuelPerMile === null ? 'Shown once the fuel change is saved.' : undefined}
                />
                <StatRow
                  label="Generator per hour"
                  value={fmtMoneyCents(meaning.generatorPerHour)}
                  sub={meaning.generatorPerHour === null ? 'Shown once the fuel change is saved.' : undefined}
                />
              </StatList>
            )}
            <button
              type="button"
              className="mt-2 inline-flex min-h-[44px] md:min-h-0 items-center text-left text-[13px] font-bold underline underline-offset-2"
              style={{ color: 'var(--ink)' }}
              onClick={() => setShowStarting(true)}
            >
              See the starting values and where they come from
            </button>
          </section>
        </div>
      </aside>

      {dirty ? (
        <div className="lg:col-span-12" style={{ position: 'sticky', bottom: 12, zIndex: 15 }}>
          <div
            role="region"
            aria-label="Unsaved changes"
            className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border bg-white px-4 py-3 shadow-float"
            style={{ borderColor: 'var(--line)' }}
          >
            {problem !== null ? (
              <p role="alert" className="mr-auto flex items-start gap-1.5 text-sm font-bold" style={{ color: 'var(--money-negative)' }}>
                <TriangleAlert size={15} aria-hidden className="mt-0.5 flex-none" />
                <span>{problem}</span>
              </p>
            ) : (
              <p className="mr-auto text-sm font-bold" style={{ color: 'var(--ink)' }}>
                Unsaved changes
              </p>
            )}
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onDiscard} disabled={save.isPending}>
              Discard
            </button>
            <button type="button" className="btn btn-primary h-11 md:h-9 px-4 text-sm" onClick={submit} disabled={save.isPending}>
              {save.isPending ? 'Saving...' : 'Save changes'}
            </button>
          </div>
        </div>
      ) : null}

      <StartingValuesModal open={showStarting} onClose={() => setShowStarting(false)} />
    </div>
  );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className={CARD + ' space-y-4'} style={{ borderColor: 'var(--line-soft)' }}>
      <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        {title}
      </h2>
      {children}
    </section>
  );
}
