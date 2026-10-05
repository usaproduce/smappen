import { Info, Loader, TriangleAlert, ZoomIn, type LucideIcon } from 'lucide-react';
import type { RegionInfo } from '../../../api/truck';
import { statusLines, type StatusLine } from '../../../utils/truck/hourControl';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { regionRebuilding, useTruckMapsLoader } from '../data';
import type { LayerStatus } from '../map/types';

export interface MapStatusProps {
  /** The state of the colour layer, as the map reports it. */
  status: LayerStatus;
  region: RegionInfo | null;
  /** The blank base map was asked for (`?tp_basemap=blank`): Google is not part of the picture. */
  blank: boolean;
}

const ICONS: Record<StatusLine['kind'], LucideIcon> = {
  loading: Loader,
  zoom: ZoomIn,
  warning: TriangleAlert,
  info: Info,
};

function Lines({ lines }: { lines: StatusLine[] }) {
  // The live region stays mounted while it is empty, so that a sentence arriving later is announced.
  return (
    <div role="status" className="flex flex-col items-stretch gap-1.5">
      {lines.map((line) => {
        const Icon = ICONS[line.kind];
        return (
          <p
            key={line.text}
            className="bg-white rounded-xl border shadow-float flex items-start gap-2 px-3 py-2 text-[13px] font-bold leading-snug"
            style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
          >
            <Icon
              size={15}
              aria-hidden="true"
              className="mt-px flex-none"
              style={{ color: line.kind === 'warning' ? 'var(--fresh-aging)' : 'var(--body)' }}
            />
            <span>{line.text}</span>
          </p>
        );
      })}
    </div>
  );
}

function WithGoogle({ status, region }: { status: LayerStatus; region: RegionInfo | null }) {
  const { loadError } = useTruckMapsLoader();
  const refused = useTruckUiStore((s) => s.mapsAuthFailed);
  return (
    <Lines
      lines={statusLines({
        status,
        rebuilding: regionRebuilding(region),
        gzBytes: region !== null && region.pack !== null ? region.pack.gz_bytes : null,
        googleFailed: loadError !== undefined || refused,
      })}
    />
  );
}

/**
 * The status chip of the map (docs/truck-planner/05_FRONTEND.md 4.2 and 5.8): nothing while the
 * colours draw, else one sentence with an icon. Whatever it says, clicking the map still opens an
 * estimate: the spot card does not depend on the colour layer.
 *
 * It also says when the Google map itself could not load (the loader failed, or Google refused the
 * key) and the page fell back to the blank base.
 */
export default function MapStatus({ status, region, blank }: MapStatusProps) {
  if (!blank) return <WithGoogle status={status} region={region} />;
  return (
    <Lines
      lines={statusLines({
        status,
        rebuilding: regionRebuilding(region),
        gzBytes: region !== null && region.pack !== null ? region.pack.gz_bytes : null,
        googleFailed: false,
      })}
    />
  );
}
