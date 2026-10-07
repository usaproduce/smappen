import { useEffect, useId, useMemo, useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { CalendarCheck, TriangleAlert } from 'lucide-react';
import { apiErrorMessage, apiErrorStatus, type Plan, type ServiceBody, type ServiceLog, type ServiceWriteAnswer, type Spot } from '../../../api/truck';
import { addDays } from '../../../utils/truck/model';
import type { CalibrationState } from '../../../utils/truck/model';
import { fmtClock, fmtDay, fmtWindow } from '../../../utils/truck/format';
import {
  CHOICE_CATERING,
  CHOICE_EVENT,
  ENTRY_TEXT,
  ESTIMATE_TEXT,
  SERVICE_LIMITS,
  applyChoice,
  choiceOf,
  estimateLine,
  findPlannedStop,
  linkHolds,
  serviceBody,
  serviceName,
  servicePatch,
  spotChoices,
  spotNamesOf,
  validateServiceDraft,
  type ServiceDraft,
  type ServiceDraftErrors,
} from '../../../utils/truck/logForm';
import { STRIPS } from '../../../utils/truck/wording';
import { TruckContext, useAccuracy, useDayContexts, useNow, usePlan, useSaveService, useTruck } from '../data';
import { DateStepper, Field, MoneyField, NumberField, RangeValue, TimeField, Toggle, WhyDrawer, type WhySubject } from '../ui';

export interface QuickEntryProps {
  /** `new`: the quick entry of the page. `edit`: a logged service being changed (`original`). */
  mode: 'new' | 'edit';
  draft: ServiceDraft;
  onChange: (next: ServiceDraft) => void;
  /** The logged service being changed. */
  original?: ServiceLog;
  /** Every spot of the truck, deleted ones included; undefined while they are on their way. */
  spots: readonly Spot[] | undefined;
  /** The spots could not be loaded. */
  spotsFailed: boolean;
  onRetrySpots: () => void;
  /** The plans of the last seven days with their stops; undefined while they are on their way. */
  plans?: readonly Plan[] | undefined;
  /** The plans could not be loaded. */
  plansFailed?: boolean;
  /**
   * After a save: the server's answer, the calibration from before the save, and the name of the
   * planned event or catering job the service was logged from, when there was one.
   */
  onSaved: (answer: ServiceWriteAnswer, before: CalibrationState, label: string | null) => void;
  onCancel?: () => void;
  /**
   * Where the focus goes when the form appears. `show`: the first control takes it and the form is
   * brought into view (a link asked for the quick entry). `quiet`: the first control takes it and
   * the page stays where it is (the form after a save, ready for the next service).
   */
  focus?: 'none' | 'quiet' | 'show';
}

const NO_PLANS: readonly Plan[] = [];

/**
 * "Log a service" (docs/truck-planner/05_FRONTEND.md 4.7): where, when, how many orders, and
 * whether the truck sold out. Nothing saves before "Save service" is pressed; Enter in a field
 * commits that field only. A value outside its range stays in its field with the range message
 * and holds the save back: nothing is ever moved into range.
 *
 * Under the fields the form shows the estimate the service will be judged against, which is the
 * figure the server keeps with it: the one a saved plan showed when the entry comes from a planned
 * stop with the planned hours, else the window estimate with only the services logged before that
 * date. The same form edits a logged service; an edit that leaves the spot, the date and the hours
 * alone keeps the estimate the service was logged with.
 */
export default function QuickEntry(props: QuickEntryProps) {
  const { mode, draft, onChange, spots, spotsFailed, onRetrySpots, onSaved, onCancel, focus = 'none' } = props;
  const original = mode === 'edit' && props.original !== undefined ? props.original : null;
  const plans = props.plans ?? (mode === 'edit' ? NO_PLANS : undefined);
  const truck = useTruck();
  const { A, profile, cal } = truck;
  const today = useNow().date;
  const ids = 'tp-log-' + useId().split(':').join('') + '-';
  const form = useRef<HTMLFormElement>(null);
  const first = useRef<HTMLSelectElement>(null);
  const save = useSaveService();
  const [tried, setTried] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [whyOpen, setWhyOpen] = useState(false);
  // Counts the saves that were held back: after each one the first marked field takes the focus.
  const [heldBack, setHeldBack] = useState(0);
  // The time and number fields that show their own message in place of the form's: a field that is
  // being typed in, or one whose text was refused (it then says its range). The form's message
  // speaks of the value the form holds, which is not what such a field shows.
  const [ownMessage, setOwnMessage] = useState<readonly string[]>([]);
  // Counts the times one of those fields was left: see the effect under "focus".
  const [fieldsLeft, setFieldsLeft] = useState(0);
  // How often each time field was rebuilt (same effect): part of its key.
  const [rebuilt, setRebuilt] = useState<Readonly<Record<string, number>>>({});

  // ---- the spot list ------------------------------------------------------------------------------
  const choices = useMemo(() => (spots === undefined ? null : spotChoices(spots, draft.spotId)), [spots, draft.spotId]);
  const spotIds = useMemo(() => (choices === null ? null : new Set(choices.map((c) => c.id))), [choices]);
  const names = useMemo(() => spotNamesOf(spots ?? []), [spots]);
  const choice = choiceOf(draft);
  const choiceKnown = choice === '' || choice === CHOICE_EVENT || choice === CHOICE_CATERING || (spotIds !== null && spotIds.has(choice));
  const spot = draft.kind !== 'spot' || draft.spotId === null ? null : spots === undefined ? undefined : spots.find((s) => s.id === draft.spotId) ?? null;

  // ---- the planned stop the entry comes from --------------------------------------------------------
  // A new entry finds its stop in the plans of the last seven days; a logged service names its plan.
  const listed = original === null ? findPlannedStop(plans ?? NO_PLANS, draft.planStopId) : null;
  const planId = original !== null ? original.plan_id : linkHolds(draft, listed) ? listed.planId : null;
  const planQuery = usePlan(planId);
  const plan = planQuery.data;
  const planFailed = plan === undefined && planQuery.isError;
  // A plan that answers 404 was cleared since the list was read: its stops are no stops any more.
  const planGone = plan === undefined && planQuery.isError && apiErrorStatus(planQuery.error) === 404;
  const stop = planGone ? null : original === null ? listed : plan === undefined ? null : findPlannedStop([plan], original.plan_stop_id);
  const holds = linkHolds(draft, stop);
  // A new entry whose plans did not arrive (yet): its stop cannot be looked up. The link that named
  // the stop is then trusted when the entry is saved.
  const stopUnseen = original === null && draft.planStopId !== null && plans === undefined;
  // While the plans that would say are still on their way, the estimate waits for them.
  const stopOnItsWay =
    original === null ? stopUnseen && props.plansFailed !== true : original.plan_stop_id !== null && plan === undefined && !planFailed;
  const treatAs = original !== null ? original.treat_as : holds ? stop.treatAs : null;

  // ---- what the estimate needs ----------------------------------------------------------------------
  const accuracy = useAccuracy();
  const entries = accuracy.data === undefined ? undefined : accuracy.data.entries;
  const entriesFailed = accuracy.data === undefined && accuracy.isError;
  const contexts = useDayContexts(draft.date, 2, { [draft.date]: treatAs });
  const ctx = contexts.contexts[draft.date] ?? null;
  const ctxNext = contexts.contexts[addDays(draft.date, 1)] ?? null;

  const line = useMemo(
    () =>
      estimateLine({
        A,
        profile,
        draft,
        original,
        link: holds ? 'holds' : stopOnItsWay ? 'wait' : 'none',
        plan,
        planFailed,
        treatAs,
        spot,
        entries,
        entriesFailed,
        ctx,
        ctxNext,
      }),
    [A, profile, draft, original, holds, stopOnItsWay, plan, planFailed, treatAs, spot, entries, entriesFailed, ctx, ctxNext],
  );
  const detail = line.kind === 'estimate' ? line.detail : null;

  // "Why this number" explains the estimate with the calibration it was computed with: the
  // services logged before the date, not everything that is logged today.
  const whyTruck = useMemo(() => (detail === null ? truck : { ...truck, cal: detail.cal }), [truck, detail]);
  const whySubject = useMemo((): WhySubject | null => {
    if (detail === null || ctx === null) return null;
    const where = spot === null || spot === undefined ? '' : spot.name + ', ';
    return {
      kind: 'window',
      title: where + fmtDay(draft.date, 'medium') + ', ' + fmtWindow(detail.window.open_minute, detail.window.close_minute),
      window: detail.window,
      vectors: detail.vectors,
      terms: detail.terms,
      ctx,
      ctxNext,
    };
  }, [detail, ctx, ctxNext, spot, draft.date]);

  // ---- focus --------------------------------------------------------------------------------------
  const focused = useRef(false);
  const listReady = choices !== null;
  useEffect(() => {
    if (focus === 'none' || focused.current || !listReady || first.current === null) return;
    focused.current = true;
    first.current.focus({ preventScroll: true });
    if (focus === 'show' && form.current !== null && typeof form.current.scrollIntoView === 'function') {
      form.current.scrollIntoView({ block: 'nearest' });
    }
  }, [focus, listReady]);

  // A save that was held back: once its messages are on the page, the first marked field takes the focus.
  useEffect(() => {
    if (heldBack === 0 || form.current === null) return;
    const marked = form.current.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (marked !== null) marked.focus();
  }, [heldBack]);

  // A field that was left without a refusal of its own shows what the form holds, so the form's
  // message is the one that applies to it again. Read from the page once the field has had its say.
  useEffect(() => {
    const inputOf = (id: string): HTMLInputElement | null => {
      const el = document.getElementById(id);
      return el instanceof HTMLInputElement ? el : null;
    };
    const isMarked = (id: string): boolean => {
      const el = inputOf(id);
      return el !== null && el.getAttribute('aria-invalid') === 'true';
    };
    const hasFocus = (id: string): boolean => {
      const el = inputOf(id);
      return el !== null && el === document.activeElement;
    };
    // The kit's time field keeps a refusal on show when its text is put back exactly as it was
    // before the edit: the sentence then stands next to the time the form holds (or next to an
    // empty field). Such a field is rebuilt, which is the only way to take the sentence away here.
    const held: Record<string, string> = {
      [ids + 'open']: draft.open === null ? '' : fmtClock(draft.open),
      [ids + 'close']: draft.close === null ? '' : fmtClock(draft.close),
    };
    const stale = ownMessage.filter((id) => {
      const el = inputOf(id);
      return el !== null && held[id] !== undefined && el.value === held[id] && isMarked(id) && !hasFocus(id);
    });
    if (stale.length > 0) {
      setRebuilt((counts) => {
        const next = { ...counts };
        for (const id of stale) next[id] = (next[id] ?? 0) + 1;
        return next;
      });
    }
    const speaks = (id: string): boolean => !stale.includes(id) && (hasFocus(id) || isMarked(id));
    if (ownMessage.some((id) => !speaks(id))) setOwnMessage(ownMessage.filter(speaks));
  }, [fieldsLeft, ownMessage, ids, draft.open, draft.close]);

  // ---- checks and saving ----------------------------------------------------------------------------
  const errors = useMemo(() => validateServiceDraft(draft, today, spotIds), [draft, today, spotIds]);
  const visible: ServiceDraftErrors = tried ? errors : {};
  const busy = save.isPending;
  const typedIds = [ids + 'open', ids + 'close', ids + 'actual', ids + 'sales'];
  /** The form's message for a typed field, unless the field shows its own. */
  const messageFor = (id: string, message: string | undefined): string | undefined => (ownMessage.includes(id) ? undefined : message);

  const change = (next: ServiceDraft) => {
    onChange(next);
    setProblem(null);
    setRefusal(null);
  };

  /** A typed field took a value: the form holds what the field shows again. */
  const commit = (id: string, next: ServiceDraft) => {
    setOwnMessage((list) => (list.includes(id) ? list.filter((x) => x !== id) : list));
    change(next);
  };

  const failed = (e: unknown) => {
    // The hook has shown the server's sentence as a toast; it also stays under the form (a second
    // service for the same spot and time is refused, and the owner should see why while it is fixed).
    setRefusal(apiErrorMessage(e, ENTRY_TEXT.saveFailed));
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    // A number or time field that still shows its own refusal holds the save back too: what is on
    // screen there is not what would be saved.
    const refused = form.current === null ? null : form.current.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (Object.keys(errors).length > 0 || refused !== null) {
      setTried(true);
      setProblem(ENTRY_TEXT.checkFields);
      setHeldBack((n) => n + 1);
      return;
    }
    setProblem(null);
    setRefusal(null);
    const before = cal;
    const label = holds && stop.kind !== 'spot' && stop.label.trim() !== '' ? stop.label.trim() : null;
    if (original === null) {
      save
        .mutateAsync({ body: serviceBody(draft, holds || stopUnseen) })
        .then((answer) => onSaved(answer, before, label))
        .catch(failed);
      return;
    }
    const patch = servicePatch(original, draft, stop !== null && !holds);
    if (Object.keys(patch).length === 0) {
      setProblem(ENTRY_TEXT.nothingChanged);
      return;
    }
    save
      // Route 34 takes any subset of the body; the hook's type asks for all of it.
      .mutateAsync({ id: original.id, body: patch as ServiceBody })
      .then((answer) => onSaved(answer, before, label))
      .catch(failed);
  };

  const stopName = !holds ? null : stop.kind === 'spot' ? serviceName({ kind: 'spot', spot_id: stop.spotId }, names) : stop.label.trim() !== '' ? stop.label.trim() : serviceName({ kind: stop.kind, spot_id: null }, names);

  return (
    <>
      <form
        ref={form}
        onSubmit={submit}
        // Enter in a field commits that field and nothing more: only the button saves.
        onKeyDown={(e) => {
          if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.preventDefault();
        }}
        onFocus={(e) => {
          // The kit's time and number fields select their text one frame after they take the
          // focus, so that typing replaces the figure. Keys pressed inside that frame (Tab, then
          // the digits at once; a busy phone makes the frame long) would be selected with it and
          // lost to the next key: "44" would be saved as "4". Text typed that early keeps its
          // caret instead. This runs right after the field's own selection, in the same frame.
          const field = e.target;
          if (!(field instanceof HTMLInputElement) || !typedIds.includes(field.id)) return;
          const atFocus = field.value;
          window.requestAnimationFrame(() => {
            const end = field.value.length;
            if (document.activeElement === field && field.value !== atFocus && field.selectionStart === 0 && field.selectionEnd === end) {
              field.setSelectionRange(end, end);
            }
          });
        }}
        onInput={(e) => {
          const id = e.target instanceof HTMLInputElement ? e.target.id : '';
          if (typedIds.includes(id)) setOwnMessage((list) => (list.includes(id) ? list : [...list, id]));
        }}
        onBlur={(e) => {
          if (e.target instanceof HTMLInputElement && ownMessage.includes(e.target.id)) setFieldsLeft((n) => n + 1);
        }}
        noValidate
        className="@container space-y-4"
      >
        <Field id={ids + 'spot'} label="Spot" error={visible.spot} required>
          {(control) =>
            choices === null ? (
              spotsFailed ? (
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-bold" style={{ color: 'var(--ink)' }} role="alert">
                  <span className="inline-flex items-start gap-1.5">
                    <TriangleAlert size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--money-negative)' }} />
                    Could not load your spots.
                  </span>
                  <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onRetrySpots}>
                    {STRIPS.tryAgain}
                  </button>
                </p>
              ) : (
                <div aria-busy="true">
                  <div className="skeleton h-11 md:h-9" style={{ borderRadius: 8 }} />
                </div>
              )
            ) : (
              <select
                {...control}
                ref={first}
                className="select h-11 md:h-9 text-sm font-semibold"
                style={{ paddingTop: 0, paddingBottom: 0 }}
                value={choiceKnown ? choice : ''}
                onChange={(e) => change(applyChoice(draft, e.target.value))}
              >
                <option value="">Choose where you served</option>
                {choices.length > 0 ? (
                  <optgroup label="Saved spots">
                    {choices.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.label}
                      </option>
                    ))}
                  </optgroup>
                ) : null}
                <optgroup label="Not a saved spot">
                  <option value={CHOICE_EVENT}>An event</option>
                  <option value={CHOICE_CATERING}>A catering job</option>
                </optgroup>
              </select>
            )
          }
        </Field>
        {choices !== null && choices.length === 0 && mode === 'new' ? (
          <p className="-mt-2 text-xs font-semibold" style={{ color: 'var(--body)' }}>
            You have no saved spots yet.{' '}
            <Link to="/truck/spots?new=1" className="font-bold underline underline-offset-2" style={{ color: 'var(--ink)' }}>
              Add a spot
            </Link>
          </p>
        ) : null}
        {stopName !== null && holds ? (
          <p className="-mt-2 flex items-start gap-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
            <CalendarCheck size={14} aria-hidden className="mt-px flex-none" style={{ color: 'var(--ink)' }} />
            <span>
              From your plan for {fmtDay(stop.date, 'medium')}: {stopName}, {fmtWindow(stop.open, stop.close)}.
            </span>
          </p>
        ) : null}

        {/*
          In a narrow form (a phone) the stepper's five controls are 35 px wider than the card and
          its calendar button would wrap onto a line of its own. There the row is kept together:
          a smaller gap, and the date label only as wide as the widest date needs. On the
          narrowest phones (360 px) the gap and the "Today" button give up a few pixels more.
        */}
        <div
          role="group"
          aria-labelledby={ids + 'date-label'}
          className="@max-[439px]:[&>div:last-of-type]:flex-nowrap @max-[439px]:[&>div:last-of-type]:gap-1 @max-[439px]:[&_span[aria-live]]:min-w-[5.6rem] @max-[439px]:[&_span[aria-live]]:whitespace-nowrap @max-[299px]:[&>div:last-of-type]:gap-0.5 @max-[299px]:[&_button.btn]:px-2"
        >
          <div id={ids + 'date-label'} className="label">
            Date
          </div>
          <DateStepper date={draft.date} onChange={(date) => change({ ...draft, date })} max={today} />
          {visible.date !== undefined ? <Problem text={visible.date} /> : null}
        </div>

        <div className="grid gap-x-3 gap-y-4 @[440px]:grid-cols-2">
          <TimeField
            key={'open-' + String(rebuilt[ids + 'open'] ?? 0)}
            id={ids + 'open'}
            label="Opened"
            value={draft.open}
            onCommit={(open) => commit(ids + 'open', { ...draft, open })}
            error={messageFor(ids + 'open', visible.open)}
            disabled={busy}
          />
          <TimeField
            key={'close-' + String(rebuilt[ids + 'close'] ?? 0)}
            id={ids + 'close'}
            label="Closed"
            value={draft.close}
            onCommit={(close) => commit(ids + 'close', { ...draft, close })}
            after={draft.open === null ? undefined : draft.open}
            allowNextDay
            max={SERVICE_LIMITS.minuteMax}
            error={messageFor(ids + 'close', visible.close)}
            disabled={busy}
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <NumberField
            id={ids + 'actual'}
            label="Orders served"
            value={draft.actual}
            onCommit={(actual) => commit(ids + 'actual', { ...draft, actual })}
            integer
            required
            min={SERVICE_LIMITS.actualMin}
            max={SERVICE_LIMITS.actualMax}
            error={messageFor(ids + 'actual', visible.actual)}
            disabled={busy}
          />
          <MoneyField
            id={ids + 'sales'}
            label="Sales (optional)"
            value={draft.sales}
            onCommit={(sales) => commit(ids + 'sales', { ...draft, sales })}
            min={SERVICE_LIMITS.salesMin}
            max={SERVICE_LIMITS.salesMax}
            error={messageFor(ids + 'sales', visible.sales)}
            disabled={busy}
          />
        </div>

        <Toggle
          id={ids + 'sold-out'}
          label="Sold out or at capacity"
          checked={draft.soldOut}
          onChange={(soldOut) => change({ ...draft, soldOut })}
          help="Turn this on if you ran out of food or could not serve everyone. Your count is then treated as a minimum."
          disabled={busy}
        />

        <Field id={ids + 'notes'} label="Notes" error={visible.notes}>
          {(control) => (
            <textarea
              {...control}
              className="textarea text-sm font-semibold"
              style={{ height: 'auto', minHeight: 60 }}
              rows={2}
              maxLength={SERVICE_LIMITS.notesMax}
              placeholder="The weather, a late start, what ran out"
              value={draft.notes}
              disabled={busy}
              onChange={(e) => change({ ...draft, notes: e.target.value })}
            />
          )}
        </Field>

        {/* A bordered box on the card's own surface: the confidence chip keeps its shape on it. */}
        <div className="rounded-lg border bg-white p-3" style={{ borderColor: 'var(--line)' }}>
          {line.kind === 'estimate' ? (
            <>
              <RangeValue
                estimate={line.estimate}
                unit="orders"
                layout="inline"
                label={ESTIMATE_TEXT.caption}
                onWhy={whySubject !== null ? () => setWhyOpen(true) : undefined}
              />
              <p className="mt-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
                {line.help}
              </p>
              {detail !== null && contexts.status === 'degraded' ? (
                <p className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold" style={{ color: 'var(--ink)' }}>
                  <span className="inline-flex items-start gap-1">
                    <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--fresh-aging)' }} />
                    {STRIPS.forecastFailed}
                  </span>
                  <button type="button" className="font-bold underline underline-offset-2 min-h-[44px] md:min-h-0" onClick={contexts.refetch}>
                    {STRIPS.tryAgain}
                  </button>
                </p>
              ) : null}
            </>
          ) : (
            <>
              <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
                {ESTIMATE_TEXT.caption}
              </div>
              {line.kind === 'wait' ? (
                <div aria-busy="true" className="mt-1.5">
                  <div className="skeleton" style={{ height: 18, width: '62%', borderRadius: 4 }} />
                </div>
              ) : (
                <p className={'mt-0.5 text-sm ' + (line.kind === 'hint' ? 'font-semibold' : 'font-bold')} style={{ color: line.kind === 'hint' ? 'var(--body)' : 'var(--ink)' }}>
                  {line.text}
                </p>
              )}
              {line.kind === 'none' && line.help !== null ? (
                <p className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
                  {line.help}
                </p>
              ) : null}
              {line.kind === 'failed' ? (
                <button
                  type="button"
                  className="btn btn-secondary mt-2 h-11 md:h-9 px-3 text-sm"
                  onClick={() => {
                    void accuracy.refetch();
                  }}
                >
                  {STRIPS.tryAgain}
                </button>
              ) : null}
            </>
          )}
        </div>

        {problem !== null ? <Problem text={problem} /> : null}
        {refusal !== null ? <Problem text={refusal} alert /> : null}

        <div className="flex flex-wrap items-center gap-2">
          <button type="submit" className="btn btn-primary h-11 md:h-9 px-4 text-sm w-full @[440px]:w-auto" disabled={busy}>
            {busy ? 'Saving...' : original === null ? 'Save service' : 'Save changes'}
          </button>
          {onCancel !== undefined ? (
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm w-full @[440px]:w-auto" onClick={onCancel} disabled={busy}>
              Cancel
            </button>
          ) : null}
        </div>
      </form>

      <TruckContext.Provider value={whyTruck}>
        <WhyDrawer open={whyOpen && whySubject !== null} onClose={() => setWhyOpen(false)} subject={whySubject} />
      </TruckContext.Provider>
    </>
  );
}

/** A sentence that stops a save, with its icon: never colour alone. */
function Problem({ text, alert = false }: { text: string; alert?: boolean }) {
  return (
    <p role={alert ? 'alert' : undefined} aria-live={alert ? undefined : 'polite'} className="mt-1 flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
      <TriangleAlert size={13} aria-hidden className="mt-px flex-none" />
      <span>{text}</span>
    </p>
  );
}
