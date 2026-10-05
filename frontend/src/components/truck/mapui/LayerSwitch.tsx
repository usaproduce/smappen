import { MAP_LAYERS } from '../../../utils/truck/model';
import type { MapLayer } from '../../../utils/truck/model';
import { LAYER_LABELS } from '../../../utils/truck/hourControl';
import { Tabs } from '../ui';

export interface LayerSwitchProps {
  layer: MapLayer;
  onChange: (layer: MapLayer) => void;
}

const TABS = MAP_LAYERS.map((id) => ({ id: id as string, label: LAYER_LABELS[id] }));

/**
 * Which layer the map colours show (docs/truck-planner/05_FRONTEND.md 4.2): opportunity, people
 * nearby or competition. A segmented switch in a floating card; it fills the width it is given, so
 * the same component is the 280 px card of a desktop and the full-width control of a phone.
 */
export default function LayerSwitch({ layer, onChange }: LayerSwitchProps) {
  return (
    <div
      className="bg-white rounded-xl border shadow-float p-1 [&>[role=tablist]]:flex [&_[role=tab]]:px-1"
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <Tabs
        tabs={TABS}
        value={layer}
        variant="segmented"
        ariaLabel="Map layer"
        onChange={(id) => {
          for (const known of MAP_LAYERS) if (known === id) onChange(known);
        }}
      />
    </div>
  );
}
