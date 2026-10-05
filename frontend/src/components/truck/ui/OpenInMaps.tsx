import { ExternalLink } from 'lucide-react';
import { mapsDirUrl, mapsSearchUrl } from '../../../utils/truck/links';
import type { MapsPoint, MapsRoute } from '../../../utils/truck/links';
import { OPEN_IN_MAPS } from '../../../utils/truck/wording';

export type OpenInMapsProps = (
  | { /** A link the server supplied (Spot.maps_url, Plan.maps_route_url, ScoutCandidate.maps_url). */ href: string }
  | { /** A point the server has not seen. */ point: MapsPoint }
  | { /** A route the server has not seen: an unsaved day, one leg. */ route: MapsRoute }
) & {
  label?: string;
  /** link: an underlined text link. button: a secondary button. primary: the one primary action of a view. */
  variant?: 'link' | 'button' | 'primary';
};

/**
 * "Open in Google Maps" (docs/truck-planner/05_FRONTEND.md 3.14): a plain link to a free Google
 * Maps URL, opened in a new tab. No API is called, and a route always names its origin, so the link
 * never asks Google for the position of the device.
 */
export default function OpenInMaps(props: OpenInMapsProps) {
  let href: string;
  let fallback: string = OPEN_IN_MAPS.point;
  if ('route' in props) {
    href = mapsDirUrl(props.route);
    fallback = OPEN_IN_MAPS.route;
  } else if ('point' in props) {
    href = mapsSearchUrl(props.point);
  } else {
    href = props.href;
  }
  const label = props.label !== undefined && props.label !== '' ? props.label : fallback;
  const button = props.variant === 'button' || props.variant === 'primary';
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className={
        button
          ? 'btn ' + (props.variant === 'primary' ? 'btn-primary' : 'btn-secondary') + ' h-11 md:h-9 px-3 text-sm'
          : 'inline-flex min-h-[44px] md:min-h-0 items-center gap-1.5 text-sm font-bold underline underline-offset-2'
      }
      style={button ? undefined : { color: 'var(--ink)' }}
    >
      <ExternalLink size={14} aria-hidden className="flex-none" />
      {label}
    </a>
  );
}
