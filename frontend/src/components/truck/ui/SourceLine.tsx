import type { ReactNode } from 'react';
import type { RegionInfo } from '../../../api/truck';
import { ATTRIBUTION, LEHD_URL, OSM_COPYRIGHT_URL, mapSourceParts, vintagesParts } from '../../../utils/truck/wording';

export type SourceKind = 'map' | 'osm' | 'osm_sentence' | 'vintages' | 'drive';

export interface SourceLineProps {
  /** Which attribution strings to print, in this order. */
  kinds: SourceKind[];
  /** The vintages of the region's data: needed by "map" and "vintages", which are left out without them. */
  vintages?: RegionInfo['vintages'];
  className?: string;
}

function OutLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="underline underline-offset-2">
      {children}
    </a>
  );
}

/**
 * Where the data comes from (docs/truck-planner/05_FRONTEND.md 3.12, strings of 6.4). The
 * OpenStreetMap text is always a link to the copyright page. A string whose placeholder cannot be
 * filled is left out.
 */
export default function SourceLine({ kinds, vintages, className }: SourceLineProps) {
  const parts: { key: string; node: ReactNode }[] = [];
  for (const kind of kinds) {
    if (kind === 'osm') {
      parts.push({ key: kind, node: <OutLink href={OSM_COPYRIGHT_URL}>{ATTRIBUTION.osm}</OutLink> });
    } else if (kind === 'osm_sentence') {
      const at = ATTRIBUTION.osmSentence.indexOf(ATTRIBUTION.osm);
      parts.push({
        key: kind,
        node: (
          <>
            {ATTRIBUTION.osmSentence.slice(0, at)}
            <OutLink href={OSM_COPYRIGHT_URL}>{ATTRIBUTION.osm}</OutLink>
            {ATTRIBUTION.osmSentence.slice(at + ATTRIBUTION.osm.length)}
          </>
        ),
      });
    } else if (kind === 'drive') {
      parts.push({ key: kind, node: ATTRIBUTION.drive });
    } else if (kind === 'map') {
      const p = mapSourceParts(vintages);
      if (p !== null) {
        parts.push({
          key: kind,
          node: (
            <>
              {p.lead}
              <OutLink href={OSM_COPYRIGHT_URL}>{p.linked}</OutLink>
            </>
          ),
        });
      }
    } else if (kind === 'vintages' && vintages !== null && vintages !== undefined) {
      const p = vintagesParts(vintages);
      parts.push({
        key: kind,
        node: (
          <>
            {p.residents} <OutLink href={LEHD_URL}>{p.jobs}</OutLink> {p.placesLead}
            <OutLink href={OSM_COPYRIGHT_URL}>{p.placesLinked}</OutLink>
            {p.placesTail}
          </>
        ),
      });
    }
  }
  if (parts.length === 0) return null;
  return (
    <p className={'text-xs font-semibold' + (className !== undefined ? ' ' + className : '')} style={{ color: 'var(--body)' }}>
      {parts.map((part, i) => (
        <span key={part.key}>
          {i > 0 ? ' ' : null}
          {part.node}
        </span>
      ))}
    </p>
  );
}
