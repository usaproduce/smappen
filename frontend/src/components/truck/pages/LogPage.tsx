import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { NotebookPen, Trash2 } from 'lucide-react';
import type { ServiceLog, ServiceWriteAnswer } from '../../../api/truck';
import { fmtCount, fmtWindow } from '../../../utils/truck/format';
import {
  HISTORY_ID,
  LOG_ENTRY_PARAMS,
  SERVICE_LIMITS,
  defaultHistoryFilter,
  draftFromPrefill,
  draftFromService,
  emptyServiceDraft,
  fillFromStop,
  findPlannedStop,
  historyDate,
  readLogParams,
  resultCardText,
  serviceName,
  serviceWhere,
  spotNamesOf,
  type HistoryFilter,
  type LogTab,
  type ResultText,
  type ServiceDraft,
  type StopFill,
} from '../../../utils/truck/logForm';
import { unloggedStops } from '../../../utils/truck/logView';
import { addDays } from '../../../utils/truck/model';
import type { CalibrationState } from '../../../utils/truck/model';
import { useDeleteService, useNow, usePlans, useServices, useSpots, useTruck } from '../data';
import AccuracyTab from '../log/AccuracyTab';
import HistoryTable from '../log/HistoryTable';
import PendingList from '../log/PendingList';
import QuickEntry from '../log/QuickEntry';
import ResultCard from '../log/ResultCard';
import { EmptyState, Modal, TabPanel, Tabs } from '../ui';

const TABS_LABEL = 'Log';
const TAB_ITEMS = [
  { id: 'services', label: 'Services' },
  { id: 'accuracy', label: 'Accuracy' },
];
const CARD = 'bg-white rounded-xl border p-4 sm:p-5';

/** The quick entry as the page holds it: a new `key` starts its fields afresh. */
interface Entry {
  key: number;
  draft: ServiceDraft;
  /** What the planned stop named by a link may still fill in once the plans have arrived. */
  fill: StopFill | null;
  /** `show`: a link asked for the quick entry. `quiet`: the form after a save, ready for the next service. */
  focus: 'none' | 'quiet' | 'show';
}

/**
 * Log and Accuracy (docs/truck-planner/05_FRONTEND.md 4.7). This is where the owner's own results
 * take over from the model.
 *
 * Services: "Log a service" comes first at every width, then what the last save changed, the
 * planned stops that still wait for their numbers, and the history. Accuracy: how close the
 * estimates have been.
 *
 * The address keeps the tab (`?tab=`). A link that starts an entry (`?new=1`, with `spot`, `date`,
 * `open`, `close` and `stop` to fill it in) is taken once: the page fills the quick entry and
 * removes those parameters, so a reload does not fill it in again.
 */
export default function LogPage() {
  const [params, setParams] = useSearchParams();
  const { counts } = useTruck();
  const now = useNow();
  const today = now.date;
  const minute = now.minute;
  const read = useMemo(() => readLogParams((key) => params.get(key), today), [params, today]);
  const tab: LogTab = read.tab;

  const spotsQuery = useSpots({ archived: true });
  const spots = spotsQuery.data;
  const names = useMemo(() => spotNamesOf(spots ?? []), [spots]);

  const since = useMemo(() => addDays(today, -SERVICE_LIMITS.pendingDays), [today]);
  const plansQuery = usePlans(since, today);
  const plans = plansQuery.data;
  // The server's default list: today and the 90 days before it. The stops that still wait and the
  // history's first view both read it.
  const recentQuery = useServices();
  const recent = recentQuery.data;

  const [entry, setEntry] = useState<Entry>(() => ({ key: 0, draft: emptyServiceDraft(today), fill: null, focus: 'none' }));
  const [result, setResult] = useState<{ key: number; serviceId: string; text: ResultText } | null>(null);
  const [filter, setFilter] = useState<HistoryFilter>(() => defaultHistoryFilter(today));
  const [editing, setEditing] = useState<{ service: ServiceLog; draft: ServiceDraft } | null>(null);
  const [deleting, setDeleting] = useState<ServiceLog | null>(null);
  const remove = useDeleteService();
  const resultCard = useRef<HTMLElement>(null);

  /** One change of the address: the tab. Everything else in it stays. */
  const showTab = (next: LogTab) => {
    setParams(
      (current) => {
        const kept = new URLSearchParams(current);
        if (next === 'services') kept.delete('tab');
        else kept.set('tab', next);
        return kept;
      },
      { replace: true },
    );
  };

  // A link that starts an entry is taken once and then leaves the address.
  useEffect(() => {
    if (!read.wantsNew && read.prefill === null) return;
    const started = draftFromPrefill(read.prefill, today);
    setEntry((current) => ({ key: current.key + 1, draft: started.draft, fill: started.fill, focus: 'show' }));
    setParams(
      (current) => {
        const kept = new URLSearchParams(current);
        for (const key of LOG_ENTRY_PARAMS) kept.delete(key);
        kept.delete('tab');
        return kept;
      },
      { replace: true },
    );
  }, [read, today, setParams]);

  // What the link left open is filled in from the planned stop once the plans are here.
  useEffect(() => {
    if (entry.fill === null || plans === undefined) return;
    const stop = findPlannedStop(plans, entry.fill.stopId);
    const fill = entry.fill;
    setEntry((current) => {
      if (current.fill !== fill) return current;
      return { ...current, draft: stop === null ? current.draft : fillFromStop(current.draft, fill, stop), fill: null };
    });
  }, [entry.fill, plans]);

  // The stops are named after their spots, so the list waits for the spots too.
  const waiting = useMemo(() => {
    if (plans === undefined || recent === undefined || spots === undefined) return undefined;
    return unloggedStops(plans, recent, { date: today, minute }, { spotNames: names, since });
  }, [plans, recent, spots, today, minute, names, since]);

  // After a save the card that says what happened is brought into view. It sits in a live region,
  // so it is read out too; the focus stays with the form.
  const resultKey = result === null ? null : result.key;
  useEffect(() => {
    if (resultKey === null || resultCard.current === null) return;
    if (typeof resultCard.current.scrollIntoView === 'function') resultCard.current.scrollIntoView({ block: 'nearest' });
  }, [resultKey]);

  const showResult = (answer: ServiceWriteAnswer, before: CalibrationState, label: string | null, edited: boolean) => {
    const where = serviceWhere(answer.service, names, label);
    setResult((current) => ({
      key: (current === null ? 0 : current.key) + 1,
      serviceId: answer.service.id,
      text: resultCardText(answer.service, answer.calibration, before, where, edited),
    }));
  };

  const startEntry = () => {
    showTab('services');
    setEntry((current) => ({ ...current, key: current.key + 1, focus: 'show' }));
  };

  const confirmDelete = () => {
    if (deleting === null) return;
    const id = deleting.id;
    remove
      .mutateAsync(id)
      .then(() => {
        setDeleting(null);
        setResult((current) => (current !== null && current.serviceId === id ? null : current));
        // The row and its menu button are about to leave the page: the focus goes to the history,
        // once the dialog has closed and handed it back.
        window.setTimeout(() => {
          const history = document.getElementById(HISTORY_ID);
          if (history !== null) history.focus({ preventScroll: true });
        }, 0);
      })
      .catch(() => {
        // The hook has shown the server's sentence.
      });
  };

  // Nothing logged at all: the count of the truck says so before the list has even arrived. A
  // service that was just entered is in the list at once, so the history takes over with it.
  const nothingLogged = counts.services === 0 && (recent === undefined || recent.length === 0);

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <NotebookPen size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Log
      </h1>

      <Tabs tabs={TAB_ITEMS} value={tab} onChange={(id) => showTab(id === 'accuracy' ? 'accuracy' : 'services')} variant="underline" ariaLabel={TABS_LABEL} />

      <TabPanel tabs={TABS_LABEL} id="services" active={tab === 'services'} className="focus:outline-none">
        <div className="space-y-4">
          <div className="grid gap-4 lg:grid-cols-12 lg:items-start">
            <section className={CARD + ' min-w-0 lg:col-span-5'} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby="tp-log-entry-title">
              <h2 id="tp-log-entry-title" className="mb-3 text-base font-extrabold" style={{ color: 'var(--ink)' }}>
                Log a service
              </h2>
              <QuickEntry
                key={entry.key}
                mode="new"
                draft={entry.draft}
                onChange={(draft) => setEntry((current) => ({ ...current, draft }))}
                spots={spots}
                spotsFailed={spots === undefined && spotsQuery.isError}
                onRetrySpots={() => {
                  void spotsQuery.refetch();
                }}
                plans={plans}
                plansFailed={plans === undefined && plansQuery.isError}
                focus={entry.focus}
                onSaved={(answer, before, label) => {
                  showResult(answer, before, label, false);
                  // The next entry starts empty on the same date: lunch and dinner of one day are logged in a row.
                  setEntry((current) => ({ key: current.key + 1, draft: emptyServiceDraft(answer.service.date), fill: null, focus: 'quiet' }));
                }}
              />
            </section>

            <div className="min-w-0 lg:col-span-7">
              {/* Always on the page, so that what appears in it is read out. Empty, it takes no room. */}
              <div aria-live="polite">
                {result !== null ? (
                  <div className="mb-4">
                    <ResultCard ref={resultCard} key={result.key} text={result.text} onDismiss={() => setResult(null)} onShowAccuracy={() => showTab('accuracy')} />
                  </div>
                ) : null}
              </div>
              <PendingList
                stops={waiting}
                failed={waiting === undefined && (plansQuery.isError || recentQuery.isError || spotsQuery.isError)}
                onRetry={() => {
                  if (plansQuery.isError) void plansQuery.refetch();
                  if (recentQuery.isError) void recentQuery.refetch();
                  if (spotsQuery.isError) void spotsQuery.refetch();
                }}
              />
            </div>
          </div>

          {nothingLogged ? (
            <EmptyState
              icon={NotebookPen}
              title="No services logged yet"
              body="After each service, enter how many orders you served. About ten logged services make the dollar figures worth trusting."
              // The page's one primary button is "Save service": this one leads to it.
              secondary={{ label: 'Log a service', onClick: startEntry }}
            />
          ) : (
            <HistoryTable
              filter={filter}
              onFilter={setFilter}
              today={today}
              spots={spots}
              spotsFailed={spots === undefined && spotsQuery.isError}
              onRetrySpots={() => {
                void spotsQuery.refetch();
              }}
              names={names}
              onEdit={(service) => setEditing({ service, draft: draftFromService(service) })}
              onDelete={setDeleting}
            />
          )}
        </div>
      </TabPanel>

      <TabPanel tabs={TABS_LABEL} id="accuracy" active={tab === 'accuracy'} className="focus:outline-none">
        <AccuracyTab
          names={names}
          namesReady={spots !== undefined}
          namesFailed={spots === undefined && spotsQuery.isError}
          onRetryNames={() => {
            void spotsQuery.refetch();
          }}
          onLogService={startEntry}
        />
      </TabPanel>

      <Modal open={editing !== null} onClose={() => setEditing(null)} title="Edit service" size="md">
        {editing !== null ? (
          <QuickEntry
            key={editing.service.id}
            mode="edit"
            original={editing.service}
            draft={editing.draft}
            onChange={(draft) => setEditing((current) => (current === null ? current : { ...current, draft }))}
            spots={spots}
            spotsFailed={spots === undefined && spotsQuery.isError}
            onRetrySpots={() => {
              void spotsQuery.refetch();
            }}
            onCancel={() => setEditing(null)}
            onSaved={(answer, before, label) => {
              setEditing(null);
              showResult(answer, before, label, true);
            }}
          />
        ) : null}
      </Modal>

      <Modal
        open={deleting !== null}
        onClose={() => {
          if (!remove.isPending) setDeleting(null);
        }}
        title="Delete this service?"
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setDeleting(null)} disabled={remove.isPending}>
              Keep it
            </button>
            <button type="button" className="btn btn-danger h-11 md:h-9 px-3 text-sm" onClick={confirmDelete} disabled={remove.isPending}>
              <Trash2 size={15} aria-hidden /> {remove.isPending ? 'Deleting...' : 'Delete service'}
            </button>
          </>
        }
      >
        {deleting !== null ? (
          <>
            <p>Estimates will stop using it.</p>
            <p className="mt-2 font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
              {historyDate(deleting.date, today)} · {serviceName(deleting, names)} · {fmtWindow(deleting.open_minute, deleting.close_minute)} ·{' '}
              {fmtCount(deleting.actual)} {deleting.actual === 1 ? 'order' : 'orders'}
            </p>
          </>
        ) : null}
      </Modal>
    </div>
  );
}
