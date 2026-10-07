import { useId, useState, type ReactNode } from 'react';
import { useMutation } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Download, Trash2, TriangleAlert } from 'lucide-react';
import { apiErrorMessage, apiErrorStatus, truckApi, type AttributionRow, type ExportFile, type SourcesAnswer } from '../../../api/truck';
import { DASH, fmtCount } from '../../../utils/truck/format';
import { ATTRIBUTION, OSM_COPYRIGHT_URL, STANDING } from '../../../utils/truck/wording';
import { useDeleteAllData, useSources } from '../data';
import { downloadBlob } from '../sheet/CalendarButton';
import { Field, Modal, QueryError, SkeletonRows } from '../ui';

const CARD = 'bg-white rounded-xl border p-4 sm:p-5';
const TITLE = 'text-base font-extrabold';
const TEXT = 'text-sm font-medium';

const SOURCES_FAILED = 'Could not load where the data comes from.';
const EXPORT_FAILED = 'Could not download your data.';
const EXPORT_INCOMPLETE = 'The download ended early, so the file is not complete. Try again.';
const DELETE_FORBIDDEN = 'Only the account owner or an admin can delete all data.';

/**
 * The attribution strings the Data page prints, by their number in 03_DATA section 14: residents,
 * jobs, places, weather, fuel, boundaries, drive times and traffic. The server leaves out a string
 * it cannot fill, and the traffic line while the traffic table changes nothing.
 */
const SHOWN_IDS: readonly number[] = [3, 4, 5, 6, 7, 8, 9, 11];

/** The strings to print, in id order, as the server sent them. */
function shownAttribution(rows: readonly AttributionRow[]): AttributionRow[] {
  return rows.filter((row) => SHOWN_IDS.indexOf(row.id) >= 0).sort((a, b) => a.id - b.id);
}

function OutLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="underline underline-offset-2">
      {children}
    </a>
  );
}

/**
 * One attribution string, word for word as the server sent it. With a `url` the string is the
 * link; without one, the OpenStreetMap credit inside it links to the copyright page.
 */
function AttributionText({ row }: { row: AttributionRow }) {
  if (row.url !== null && /^https:/i.test(row.url)) return <OutLink href={row.url}>{row.text}</OutLink>;
  const at = row.text.indexOf(ATTRIBUTION.osm);
  if (at < 0) return <>{row.text}</>;
  return (
    <>
      {row.text.slice(0, at)}
      <OutLink href={OSM_COPYRIGHT_URL}>{ATTRIBUTION.osm}</OutLink>
      {row.text.slice(at + ATTRIBUTION.osm.length)}
    </>
  );
}

/** A version as the server sent it; the dash where the dataset's own record lacks it. */
function versionText(value: string | null | undefined): string {
  return value === null || value === undefined || value === '' ? DASH : value;
}

/** "Data version dc-20261003-3fa9c2d1 · pipeline tp-etl-1.0.0 · model tps-0.1.0", and the size of the dataset. */
function datasetLines(sources: SourcesAnswer): { versions: string; totals: string | null } {
  const dataset = sources.dataset;
  if (dataset === null) return { versions: 'No map data for this area. Model ' + sources.model_version, totals: null };
  return {
    versions: 'Data version ' + versionText(dataset.dataset_version) + ' · pipeline ' + versionText(dataset.pipeline_version) + ' · model ' + sources.model_version,
    totals:
      fmtCount(dataset.totals.residents) +
      ' residents, ' +
      fmtCount(dataset.totals.jobs) +
      ' jobs, ' +
      fmtCount(dataset.counts.places) +
      ' places, ' +
      fmtCount(dataset.counts.cells) +
      ' map cells',
  };
}

/**
 * True when the server closed the document early. An export that could not be read to its end is
 * still valid JSON and ends with `"incomplete": true` (04_BACKEND 4.16); only the last bytes are read.
 */
async function endedEarly(blob: Blob): Promise<boolean> {
  try {
    const tail = await blob.slice(blob.size > 64 ? blob.size - 64 : 0).text();
    return /"incomplete"\s*:\s*true\s*\}\s*$/.test(tail);
  } catch {
    return false;
  }
}

// -------------------------------------------------------------------------------------------------

/** "Where the data comes from": the server's attribution strings, then the versions and the size of the dataset. */
function SourcesCard() {
  const id = useId();
  const query = useSources();
  const sources = query.data;
  let body: ReactNode;
  if (sources === undefined) {
    body = query.isError ? (
      <QueryError
        message={apiErrorMessage(query.error, SOURCES_FAILED) ?? SOURCES_FAILED}
        onRetry={() => {
          void query.refetch();
        }}
      />
    ) : (
      <div aria-busy="true">
        <SkeletonRows rows={4} rowHeight={36} />
      </div>
    );
  } else {
    const lines = datasetLines(sources);
    body = (
      <>
        <ul className="space-y-2">
          {shownAttribution(sources.attribution).map((row) => (
            <li key={row.id} className={TEXT + ' break-words'} style={{ color: 'var(--body)' }}>
              <AttributionText row={row} />
            </li>
          ))}
        </ul>
        <div className="space-y-0.5 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
          <p className="break-words text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {lines.versions}
          </p>
          {lines.totals !== null ? (
            <p className="text-sm font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
              {lines.totals}
            </p>
          ) : null}
        </div>
      </>
    );
  }
  return (
    <section className={CARD + ' space-y-3'} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby={id}>
      <h2 id={id} className={TITLE} style={{ color: 'var(--ink)' }}>
        Where the data comes from
      </h2>
      {body}
    </section>
  );
}

/** "Export": everything the owner entered as one JSON file, saved under the name the server gives it. */
function ExportCard() {
  const id = useId();
  const download = useMutation<ExportFile, unknown, void>({
    mutationFn: () => truckApi.exportAll(),
    onSuccess: async (file) => {
      downloadBlob(file.blob, file.filename);
      if (await endedEarly(file.blob)) toast.error(EXPORT_INCOMPLETE);
    },
    onError: (e) => {
      const message = apiErrorMessage(e, EXPORT_FAILED);
      if (message) toast.error(message);
    },
  });
  return (
    <section className={CARD + ' space-y-3'} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby={id}>
      <h2 id={id} className={TITLE} style={{ color: 'var(--ink)' }}>
        Export
      </h2>
      <p className={TEXT} style={{ color: 'var(--body)' }}>
        Everything you entered: truck, spots, plans, logged services, drive-time corrections and Scout notes. Place names carry the OpenStreetMap credit.
      </p>
      <button
        type="button"
        className="btn btn-primary h-11 md:h-9 px-4 text-sm disabled:cursor-not-allowed"
        disabled={download.isPending}
        onClick={() => download.mutate()}
      >
        <Download size={14} aria-hidden="true" /> {download.isPending ? 'Preparing the file...' : 'Download my data (JSON)'}
      </button>
    </section>
  );
}

/**
 * "Delete all Truck Planner data": the button opens a dialog whose own button stays off until the
 * phrase is typed. Deleting ends on the first-run step (`useDeleteAllData` reads the bootstrap
 * answer again). Only the account owner or an admin may delete: the server answers anyone else with
 * a 403, which the card says in its own words.
 */
function DeleteCard() {
  const id = useId();
  const fieldId = useId();
  const remove = useDeleteAllData();
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState('');
  const [forbidden, setForbidden] = useState(false);
  const matches = typed.trim() === STANDING.deletePhrase;
  const busy = remove.isPending;

  const close = () => {
    if (busy) return;
    setOpen(false);
    setTyped('');
  };

  const confirm = () => {
    if (!matches || busy) return;
    remove.mutate(undefined, {
      onError: (e) => {
        if (apiErrorStatus(e) !== 403) return; // any other failure has been shown; the dialog stays for another try
        setForbidden(true);
        setOpen(false);
        setTyped('');
      },
    });
  };

  return (
    <section className={CARD + ' space-y-3'} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby={id}>
      <h2 id={id} className={TITLE} style={{ color: 'var(--ink)' }}>
        Delete all Truck Planner data
      </h2>
      <p className={TEXT} style={{ color: 'var(--body)' }}>
        {STANDING.deleteEverything}
      </p>
      {forbidden ? (
        <p role="alert" className="flex items-start gap-1.5 text-sm font-bold" style={{ color: 'var(--ink)' }}>
          <TriangleAlert size={15} aria-hidden="true" className="mt-0.5 flex-none" style={{ color: 'var(--money-negative)' }} />
          <span>{DELETE_FORBIDDEN}</span>
        </p>
      ) : null}
      <button
        type="button"
        className="btn btn-secondary h-11 md:h-9 px-3 text-sm"
        style={{ color: 'var(--money-negative)' }}
        onClick={() => {
          setForbidden(false);
          setOpen(true);
        }}
      >
        <Trash2 size={14} aria-hidden="true" /> Delete everything
      </button>

      <Modal
        open={open}
        onClose={close}
        title="Delete all Truck Planner data?"
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={close} disabled={busy}>
              Keep my data
            </button>
            <button
              type="button"
              className="btn btn-danger h-11 md:h-9 px-3 text-sm disabled:opacity-50 disabled:cursor-not-allowed"
              disabled={!matches || busy}
              onClick={confirm}
            >
              <Trash2 size={15} aria-hidden="true" /> {busy ? 'Deleting...' : 'Delete everything'}
            </button>
          </>
        }
      >
        <p>{STANDING.deleteEverything}</p>
        {/* The phrase is printed in body text, exactly as it has to be typed: a field label is set in capitals. */}
        <p className="mt-3">
          To confirm, type{' '}
          <strong className="font-extrabold" style={{ color: 'var(--ink)' }}>
            {STANDING.deletePhrase}
          </strong>{' '}
          below.
        </p>
        <form
          className="mt-2"
          onSubmit={(e) => {
            e.preventDefault();
            confirm();
          }}
        >
          <Field id={fieldId} label="Confirmation phrase">
            {(control) => (
              <input
                {...control}
                type="text"
                className="input text-sm"
                value={typed}
                onChange={(e) => setTyped(e.target.value)}
                autoComplete="off"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                disabled={busy}
              />
            )}
          </Field>
        </form>
      </Modal>
    </section>
  );
}

/**
 * "Data and export" (docs/truck-planner/05_FRONTEND.md 4.9): where the data comes from, in the
 * server's own words; the export of everything the owner entered; and the way to delete it all.
 */
export default function DataTab() {
  return (
    <div className="max-w-3xl space-y-4">
      <SourcesCard />
      <ExportCard />
      <DeleteCard />
    </div>
  );
}
