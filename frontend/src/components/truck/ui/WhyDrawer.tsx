import { useEffect, useId, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { defaultHourIndex, whyHourLabels, whySteps } from '../../../utils/truck/breakdown';
import type { WhyMore, WhyRow, WhySeed, WhyStep, WhySubject } from '../../../utils/truck/breakdown';
import { PLACEHOLDER_SEED, STANDING, WHY } from '../../../utils/truck/wording';
import { useTruck } from '../data/TruckContext';
import PermissionNotice from './PermissionNotice';
import SeedTag from './SeedTag';
import Sheet from './Sheet';
import SourceLine from './SourceLine';

export type { WhySubject } from '../../../utils/truck/breakdown';

export interface WhyDrawerProps {
  open: boolean;
  onClose: () => void;
  /** What to explain: a window at a spot, an event stop, or a whole day. */
  subject: WhySubject | null;
}

function Rows({ rows }: { rows: WhyRow[] }) {
  if (rows.length === 0) return null;
  return (
    <dl className="tp-stat-list">
      {rows.map((row, i) => (
        <div key={String(i) + row.label} className="flex flex-wrap items-baseline justify-between gap-x-4 py-1.5">
          <dt className="min-w-[38%] flex-1 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
            {row.label}
          </dt>
          <dd className="ml-auto max-w-full text-right text-[13px] font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {row.value}
          </dd>
          {row.note !== undefined && row.note !== '' ? (
            <dd className="basis-full text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {row.note}
            </dd>
          ) : null}
        </div>
      ))}
    </dl>
  );
}

function More({ more, id }: { more: WhyMore; id: string }) {
  const [open, setOpen] = useState(false);
  if (more.rows.length === 0) return null;
  return (
    <div className="mt-1">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-controls={id}
        className="inline-flex min-h-[44px] md:min-h-[28px] items-center gap-1 text-xs font-bold underline underline-offset-2"
        style={{ color: 'var(--body)' }}
      >
        {open ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
        {more.label}
      </button>
      <div id={id} hidden={!open} className="card-expand">
        {open ? <Rows rows={more.rows} /> : null}
      </div>
    </div>
  );
}

function Seeds({ seeds }: { seeds: WhySeed[] }) {
  if (seeds.length === 0) return null;
  return (
    <ul className="tp-stat-list">
      {seeds.map((seed) => (
        <li key={seed.path} className="py-2">
          <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
            <span className="min-w-0 text-[13px] font-bold" style={{ color: 'var(--ink)' }}>
              {seed.label}
            </span>
            {seed.tag !== null ? <SeedTag tag={seed.tag} size="sm" /> : null}
          </div>
          <div className="mt-0.5 text-[13px] font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {seed.overridden ? WHY.yourValue + ': ' : ''}
            {seed.value}
            {seed.unit !== '' ? (
              <span className="font-semibold" style={{ color: 'var(--body)' }}>
                {' '}
                ({seed.unit})
              </span>
            ) : null}
          </div>
          {seed.overridden && seed.startingValue !== null ? (
            <div className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {WHY.startingValue}: {seed.startingValue}
            </div>
          ) : null}
          {seed.placeholder ? (
            <div className="text-xs font-bold" style={{ color: 'var(--ink)' }}>
              {PLACEHOLDER_SEED}
            </div>
          ) : null}
          {seed.source !== '' ? (
            <div className="text-xs font-medium" style={{ color: 'var(--body)' }}>
              {seed.source}
            </div>
          ) : null}
          <code className="block break-all text-[11px] font-bold" style={{ color: 'var(--slate)', fontFamily: 'inherit' }}>
            {seed.path}
          </code>
        </li>
      ))}
    </ul>
  );
}

function Step({ step, open, onToggle, uid }: { step: WhyStep; open: boolean; onToggle: () => void; uid: string }) {
  const bodyId = uid + '-step-' + String(step.n);
  return (
    <section className="border-t" style={{ borderColor: 'var(--line-soft)' }}>
      <h3 className="m-0">
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={open}
          aria-controls={bodyId}
          className="flex min-h-[44px] w-full items-center gap-2 py-2 text-left"
          style={{ color: 'var(--ink)' }}
        >
          <span
            aria-hidden
            className="inline-flex h-6 w-6 flex-none items-center justify-center rounded-full text-[11px] font-extrabold tabular-nums"
            style={{ background: 'var(--bg-panel)', color: 'var(--ink)' }}
          >
            {step.n}
          </span>
          <span className="min-w-0 flex-1 text-sm font-extrabold">
            <span className="sr-only">
              {WHY.step} {step.n}:{' '}
            </span>
            {step.title}
          </span>
          {open ? <ChevronDown size={16} aria-hidden className="flex-none" /> : <ChevronRight size={16} aria-hidden className="flex-none" />}
        </button>
      </h3>
      <div id={bodyId} hidden={!open} className="pb-3 pl-8">
        {open ? (
          <>
            {step.lines.length > 0 ? (
              <div className="space-y-1">
                {step.lines.map((line, i) => (
                  <p key={i} className="text-[13px] font-semibold leading-snug" style={{ color: 'var(--body)' }}>
                    {line}
                  </p>
                ))}
              </div>
            ) : null}
            <Rows rows={step.rows} />
            {step.more !== null ? <More more={step.more} id={bodyId + '-more'} /> : null}
            <Seeds seeds={step.seeds} />
            {step.n === 13 && step.seeds.length > 0 ? (
              <Link to="/truck/settings/assumptions" className="mt-2 inline-flex min-h-[44px] md:min-h-0 items-center text-xs font-bold underline underline-offset-2" style={{ color: 'var(--ink)' }}>
                {WHY.changeInSettings}
              </Link>
            ) : null}
          </>
        ) : null}
      </div>
    </section>
  );
}

/**
 * "Why this number" (docs/truck-planner/05_FRONTEND.md 3.4): the thirteen steps of the model's
 * breakdown in their fixed order, each a section that can be collapsed, never reordered, merged or
 * dropped. For a window, a row of hour chips at the top chooses which hour steps 1 to 9 describe;
 * the busiest hour is chosen at first. Under the steps, always: the standing lines.
 */
export default function WhyDrawer({ open, onClose, subject }: WhyDrawerProps) {
  const { A, profile, cal, region } = useTruck();
  const [hourIndex, setHourIndex] = useState(0);
  const [closed, setClosed] = useState<Record<number, boolean>>({});

  // A new subject starts on its busiest hour with every step open.
  useEffect(() => {
    setHourIndex(subject !== null && subject.kind === 'window' ? defaultHourIndex(subject.window) : 0);
    setClosed({});
  }, [subject]);

  const steps = useMemo(() => (subject === null ? [] : whySteps(subject, A, profile, cal, { hourIndex })), [subject, A, profile, cal, hourIndex]);
  const hours = subject !== null && subject.kind === 'window' ? whyHourLabels(subject.window) : [];
  const uid = 'tp-why-' + useId().split(':').join('');

  return (
    <Sheet open={open && subject !== null} onClose={onClose} title={WHY.heading} side="right" width={560}>
      {subject === null ? null : (
        <div>
          <p className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
            {subject.title}
          </p>
          {hours.length > 1 ? (
            <div role="group" aria-label={WHY.hourPicker} className="mt-3 flex flex-wrap items-center gap-1.5">
              <span className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
                {WHY.hourPickerLead}
              </span>
              {hours.map((label, i) => (
                <button
                  key={label + String(i)}
                  type="button"
                  aria-pressed={i === hourIndex}
                  onClick={() => setHourIndex(i)}
                  className={'inline-flex h-11 md:h-8 items-center rounded-full border px-3 text-xs font-bold' + (i === hourIndex ? '' : ' bg-white')}
                  style={
                    i === hourIndex
                      ? { background: 'var(--brand-light)', borderColor: 'var(--brand)', color: 'var(--nav-active-fg)' }
                      : { borderColor: 'var(--line)', color: 'var(--body)' }
                  }
                >
                  {label}
                </button>
              ))}
            </div>
          ) : null}
          <div className="mt-3">
            {steps.map((step) => (
              <Step
                key={step.n}
                step={step}
                uid={uid}
                open={closed[step.n] !== true}
                onToggle={() => setClosed((c) => ({ ...c, [step.n]: c[step.n] !== true }))}
              />
            ))}
          </div>
          <div className="mt-2 space-y-2 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
            <p className="text-xs font-bold" style={{ color: 'var(--ink)' }}>
              {STANDING.underBreakdown}
            </p>
            <SourceLine kinds={['vintages']} vintages={region === null ? null : region.vintages} />
            <PermissionNotice variant="line" />
          </div>
        </div>
      )}
    </Sheet>
  );
}
