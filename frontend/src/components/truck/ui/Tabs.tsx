import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { fmtCount } from '../../../utils/truck/format';
import { tabDomIds, tabKeyTarget } from './kit';

export interface TabItem {
  id: string;
  label: string;
  /** A count shown after the label. */
  count?: number;
}

export interface TabsProps {
  tabs: TabItem[];
  value: string;
  onChange: (id: string) => void;
  variant: 'underline' | 'segmented';
  ariaLabel: string;
}

/**
 * A tab list (docs/truck-planner/05_FRONTEND.md 3.8). Roving tab index: Left and Right move, Home
 * and End jump, and moving selects. "segmented" is a track with one white pill; "underline" puts a
 * 2 px brand line under the selected label. The panel a tab controls is a TabPanel with the same
 * `tabs` label and id.
 */
export default function Tabs({ tabs, value, onChange, variant, ariaLabel }: TabsProps) {
  const list = useRef<HTMLDivElement>(null);
  const [panelExists, setPanelExists] = useState(false);

  // aria-controls may only name an element that is on the page: a tab list used as a plain switch
  // (a map layer, a window length) has no panels.
  useEffect(() => {
    setPanelExists(document.getElementById(tabDomIds(ariaLabel, value).panel) !== null);
  });

  const onKeyDown = (e: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const to = tabKeyTarget(e.key, index, tabs.length);
    if (to === null) return;
    e.preventDefault();
    const next = tabs[to];
    if (next.id !== value) onChange(next.id);
    const buttons = list.current === null ? [] : list.current.querySelectorAll<HTMLButtonElement>('[role="tab"]');
    if (buttons[to] !== undefined) buttons[to].focus();
  };

  const segmented = variant === 'segmented';

  return (
    <div
      ref={list}
      role="tablist"
      aria-label={ariaLabel}
      className={segmented ? 'inline-flex max-w-full items-center gap-0.5 rounded-lg p-0.5' : 'flex items-end gap-1 border-b overflow-x-auto scroll-x'}
      style={segmented ? { background: 'var(--bg-panel)' } : { borderColor: 'var(--line-soft)' }}
    >
      {tabs.map((tab, index) => {
        const selected = tab.id === value;
        const ids = tabDomIds(ariaLabel, tab.id);
        const count =
          tab.count === undefined ? null : (
            <span className="tabular-nums text-[11px] font-bold" style={{ color: 'var(--body)' }}>
              {fmtCount(tab.count)}
            </span>
          );
        if (segmented) {
          return (
            <button
              key={tab.id}
              id={ids.tab}
              type="button"
              role="tab"
              aria-selected={selected}
              aria-controls={selected && panelExists ? ids.panel : undefined}
              tabIndex={selected ? 0 : -1}
              onClick={() => {
                if (!selected) onChange(tab.id);
              }}
              onKeyDown={(e) => onKeyDown(e, index)}
              className={'inline-flex h-11 md:h-9 min-w-0 flex-auto items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-3 text-[13px] font-bold' + (selected ? ' bg-white' : '')}
              style={{ color: selected ? 'var(--ink)' : 'var(--slate)', border: selected ? '1px solid var(--line-soft)' : '1px solid transparent' }}
            >
              {tab.label}
              {count}
            </button>
          );
        }
        return (
          <button
            key={tab.id}
            id={ids.tab}
            type="button"
            role="tab"
            aria-selected={selected}
            aria-controls={selected && panelExists ? ids.panel : undefined}
            tabIndex={selected ? 0 : -1}
            onClick={() => {
              if (!selected) onChange(tab.id);
            }}
            onKeyDown={(e) => onKeyDown(e, index)}
            className="-mb-px inline-flex h-11 md:h-10 flex-none items-center gap-1.5 whitespace-nowrap px-3 text-sm font-bold"
            style={{ color: selected ? 'var(--ink)' : 'var(--slate)', borderBottom: selected ? '2px solid var(--brand)' : '2px solid transparent' }}
          >
            {tab.label}
            {count}
          </button>
        );
      })}
    </div>
  );
}

export interface TabPanelProps {
  /** The ariaLabel of the Tabs this panel belongs to. */
  tabs: string;
  /** The id of the tab that shows this panel. */
  id: string;
  /** False keeps the panel on the page but hidden. */
  active?: boolean;
  children: ReactNode;
  className?: string;
}

/** The panel of one tab: labelled by its tab, hidden while another tab is selected. */
export function TabPanel({ tabs, id, active = true, children, className }: TabPanelProps) {
  const ids = tabDomIds(tabs, id);
  return (
    <div role="tabpanel" id={ids.panel} aria-labelledby={ids.tab} hidden={!active} tabIndex={0} className={className}>
      {active ? children : null}
    </div>
  );
}
