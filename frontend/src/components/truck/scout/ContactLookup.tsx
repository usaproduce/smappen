import { useRef } from 'react';
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { ExternalLink, Globe, Info, Phone, Search, TriangleAlert } from 'lucide-react';
import { apiErrorMessage, apiErrorStatus, truckApi } from '../../../api/truck';
import {
  LOOKUP,
  lookupFailure,
  lookupView,
  priorMatchSentence,
  readContactAnswer,
  type ContactAnswer,
  type ScoutCandidate,
  type SessionContact,
} from '../../../utils/truck/scoutView';
import { useNow } from '../data';

export interface ContactLookupProps {
  /** The place to look up. */
  candidate: ScoutCandidate;
}

/**
 * Where an answer of Google is kept: in the query cache, in memory, for as long as the tab lives.
 * It is written to no storage and sent nowhere. Nothing is ever fetched under this key.
 */
function contactKey(placeKey: string) {
  return ['truck', 'contact', placeKey] as const;
}

/** Google's answer for a place in this visit, or null before any lookup. */
function useSessionContact(placeKey: string): SessionContact | null {
  const query = useQuery<SessionContact | null>({
    queryKey: contactKey(placeKey),
    queryFn: () => null,
    enabled: false,
    staleTime: Infinity,
    gcTime: Infinity,
  });
  return query.data ?? null;
}

/** The lead a lookup returned, written into the candidate that carries it in every cached Scout answer. */
function writeLead(qc: QueryClient, placeKey: string, lead: unknown): void {
  if (typeof lead !== 'object' || lead === null) return;
  qc.setQueriesData<{ candidates?: { place?: { place_key?: string }; lead?: unknown }[] }>({ queryKey: ['truck', 'scout'] }, (answer) => {
    if (answer === undefined || !Array.isArray(answer.candidates)) return answer;
    let touched = false;
    const candidates = answer.candidates.map((candidate) => {
      if (candidate.place === undefined || candidate.place.place_key !== placeKey) return candidate;
      touched = true;
      return { ...candidate, lead };
    });
    return touched ? { ...answer, candidates } : answer;
  });
}

/**
 * "Look up phone and website" (docs/truck-planner/05_FRONTEND.md 4.8). One request to Google, sent
 * only when this button is pressed. What Google answers is shown here as Google's, with the name and
 * address it matched so the owner can see it is the right place, and with the time of the lookup.
 * It lives in memory for the visit: the server keeps only Google's id of the place, and this page
 * keeps nothing once the tab is closed. What the owner wants to keep goes into the notes.
 *
 * A server that has no lookup to offer says so in its own sentence, which is shown in place.
 */
export default function ContactLookup({ candidate }: ContactLookupProps) {
  const qc = useQueryClient();
  const now = useNow();
  const clock = useRef(now);
  clock.current = now;
  const placeKey = candidate.place.place_key;
  const session = useSessionContact(placeKey);

  const lookup = useMutation<{ answer: ContactAnswer; lead: unknown }, unknown, { force: boolean }>({
    mutationFn: async ({ force }) => {
      const raw: unknown = await truckApi.lookupContact(placeKey, force);
      const answer = readContactAnswer(raw, placeKey);
      if (answer === null) throw new Error('lookup: the answer carries no contact');
      return { answer, lead: (raw as { lead?: unknown }).lead };
    },
    onSuccess: ({ answer, lead }) => {
      const kept: SessionContact = { contact: answer.contact, at: { date: clock.current.date, minute: clock.current.minute } };
      qc.setQueryData<SessionContact>(contactKey(placeKey), kept);
      writeLead(qc, placeKey, lead);
    },
  });

  const pending = lookup.isPending;
  const failure = lookup.isError ? lookupFailure(apiErrorStatus(lookup.error), apiErrorMessage(lookup.error, LOOKUP.failed)) : null;
  const view = session === null ? null : lookupView(session, now.date);
  const prior = session === null ? priorMatchSentence(candidate.lead.google) : null;

  return (
    <div className="space-y-2">
      <div aria-live="polite" className="space-y-2">
        {view !== null && view.kind === 'found' ? (
          <div className="rounded-lg border p-3" style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)' }}>
            <p className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              {LOOKUP.caption}
            </p>
            {view.phone === null && view.website === null ? (
              <p className="mt-1 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
                {LOOKUP.noDetails}
              </p>
            ) : (
              <ul className="mt-1 space-y-0.5">
                {view.phone !== null ? (
                  <li className="flex items-center gap-1.5 text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                    <Phone size={14} aria-hidden className="flex-none" style={{ color: 'var(--slate)' }} />
                    {view.phone.href !== null ? (
                      <a href={view.phone.href} className="inline-flex min-h-[44px] md:min-h-0 items-center underline underline-offset-2">
                        {view.phone.text}
                      </a>
                    ) : (
                      <span>{view.phone.text}</span>
                    )}
                  </li>
                ) : null}
                {view.website !== null ? (
                  <li className="flex items-center gap-1.5 text-sm font-bold" style={{ color: 'var(--ink)' }}>
                    <Globe size={14} aria-hidden className="flex-none" style={{ color: 'var(--slate)' }} />
                    {view.website.href !== null ? (
                      <a
                        href={view.website.href}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex min-h-[44px] md:min-h-0 min-w-0 items-center break-all underline underline-offset-2"
                      >
                        {view.website.text}
                      </a>
                    ) : (
                      <span className="min-w-0 break-all">{view.website.text}</span>
                    )}
                  </li>
                ) : null}
              </ul>
            )}
            <p className="mt-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
              {view.matched}
            </p>
            {view.mapsHref !== null ? (
              <a
                href={view.mapsHref}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-0.5 inline-flex min-h-[44px] md:min-h-0 items-center gap-1 text-[13px] font-bold underline underline-offset-2"
                style={{ color: 'var(--ink)' }}
              >
                <ExternalLink size={13} aria-hidden className="flex-none" />
                {LOOKUP.openOnGoogle}
              </a>
            ) : null}
            <p className="mt-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {view.when} {LOOKUP.notSaved}
            </p>
            <button
              type="button"
              className="mt-0.5 inline-flex min-h-[44px] md:min-h-[24px] items-center text-xs font-bold underline underline-offset-2 disabled:opacity-50"
              style={{ color: 'var(--body)' }}
              disabled={pending}
              onClick={() => lookup.mutate({ force: true })}
            >
              {LOOKUP.searchAgain}
            </button>
          </div>
        ) : null}

        {view !== null && view.kind === 'not_found' ? (
          <p className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
            <Info size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
            <span>
              {LOOKUP.notFound} {view.when}
            </span>
          </p>
        ) : null}

        {failure !== null && !pending ? (
          <p role="alert" className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
            <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
            <span>{failure}</span>
          </p>
        ) : null}
      </div>

      <button
        type="button"
        className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-60 disabled:cursor-not-allowed"
        disabled={pending}
        onClick={() => lookup.mutate({ force: false })}
      >
        {pending ? <span className="spinner" aria-hidden /> : <Search size={14} aria-hidden />}
        {pending ? LOOKUP.busy : session !== null ? LOOKUP.again : LOOKUP.button}
      </button>

      {session === null && failure === null ? (
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {prior !== null ? prior : LOOKUP.hint}
        </p>
      ) : null}
    </div>
  );
}
