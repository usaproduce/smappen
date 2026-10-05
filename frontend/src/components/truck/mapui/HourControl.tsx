import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Pause, Play } from 'lucide-react';
import { useTruckHourStore } from '../../../stores/truckHourStore';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { fmtHowLong, fmtWeekday } from '../../../utils/truck/format';
import { PLAY_SPEEDS, advancePlayback, hourBarMode, howAtHour, howOnDay } from '../../../utils/truck/hourControl';
import DateMode from './DateMode';
import HourStrip from './HourStrip';

export interface HourControlProps {
  /** The current hour of the week in the truck's time zone: where "Now" jumps. */
  nowHow: number;
  /** 24 mean colour bytes of the cells in view for the selected day: the bars over the slider. */
  strip: Uint8Array | null;
}

const DAYS = [0, 1, 2, 3, 4, 5, 6];

/** A manual change of the hour or the day: playback stops first. */
function setHowByHand(next: (how: number) => number): void {
  const state = useTruckHourStore.getState();
  state.setPlaying(false);
  state.setHow(next(state.how));
}

function PlayButton() {
  const playing = useTruckHourStore((s) => s.playing);
  return (
    <button
      type="button"
      aria-label={playing ? 'Pause' : 'Play the week'}
      onClick={() => useTruckHourStore.getState().setPlaying(!playing)}
      className="btn btn-secondary h-11 w-11 flex-none"
      style={{ padding: 0, color: 'var(--ink)' }}
    >
      {playing ? <Pause size={18} aria-hidden="true" /> : <Play size={18} aria-hidden="true" />}
    </button>
  );
}

/** Seven buttons as a radio group. One tab stop: the checked day; arrow keys are the page's (4.2). */
function DayButtons() {
  const dow = useTruckHourStore((s) => (s.how - (s.how % 24)) / 24);
  const group = useRef<HTMLDivElement>(null);

  // While the group has focus, focus follows the checked day (the keys of the page can change it).
  useEffect(() => {
    const el = group.current;
    if (el === null || !el.contains(document.activeElement)) return;
    el.querySelector<HTMLButtonElement>('[aria-checked="true"]')?.focus();
  }, [dow]);

  return (
    <div
      ref={group}
      role="radiogroup"
      aria-label="Day of the week"
      className="flex flex-none items-center gap-0.5 rounded-lg p-0.5"
      style={{ background: 'var(--bg-panel)' }}
    >
      {DAYS.map((day) => {
        const checked = day === dow;
        return (
          <button
            key={day}
            type="button"
            role="radio"
            aria-checked={checked}
            title={fmtWeekday(day, 'long')}
            tabIndex={checked ? 0 : -1}
            onClick={() => setHowByHand((how) => howOnDay(how, day))}
            className={'inline-flex h-9 min-w-[42px] items-center justify-center rounded-md px-2 text-[13px] font-bold' + (checked ? ' bg-white' : '')}
            style={{
              color: checked ? 'var(--ink)' : 'var(--slate)',
              border: checked ? '1px solid var(--line-soft)' : '1px solid transparent',
            }}
          >
            {fmtWeekday(day, 'short')}
          </button>
        );
      })}
    </div>
  );
}

function DaySelect() {
  const dow = useTruckHourStore((s) => (s.how - (s.how % 24)) / 24);
  return (
    <select
      aria-label="Day of the week"
      value={dow}
      onChange={(e) => {
        const day = Number(e.target.value);
        setHowByHand((how) => howOnDay(how, day));
      }}
      className="select h-11! md:h-9! w-auto! flex-none py-0! pl-2.5! pr-1.5! font-bold"
    >
      {DAYS.map((day) => (
        <option key={day} value={day}>
          {fmtWeekday(day, 'short')}
        </option>
      ))}
    </select>
  );
}

function HourSlider({ strip, stripHeight }: { strip: Uint8Array | null; stripHeight: number }) {
  const how = useTruckHourStore((s) => s.how);
  return (
    <div className="min-w-0 flex-1">
      <HourStrip bytes={strip} height={stripHeight} />
      <input
        type="range"
        min={0}
        max={23}
        step={1}
        value={how % 24}
        aria-label="Hour of day"
        aria-valuetext={fmtHowLong(how)}
        onPointerDown={() => useTruckHourStore.getState().setPlaying(false)}
        onChange={(e) => {
          const hour = Number(e.target.value);
          setHowByHand((current) => howAtHour(current, hour));
        }}
        className="block w-full cursor-pointer"
        style={{ accentColor: 'var(--brand)', height: 20, margin: 0 }}
      />
    </div>
  );
}

/** The hour on screen in words. A leaf: it only prints. */
function HourLabel({ className }: { className: string }) {
  const how = useTruckHourStore((s) => s.how);
  const text = fmtHowLong(how);
  const comma = text.indexOf(', ');
  return (
    <p className={'flex flex-wrap gap-x-1 text-sm font-extrabold leading-tight tabular-nums ' + className} style={{ color: 'var(--ink)' }}>
      <span className="whitespace-nowrap">{comma < 0 ? text : text.slice(0, comma + 1)}</span>
      {comma < 0 ? null : <span className="whitespace-nowrap">{text.slice(comma + 2)}</span>}
    </p>
  );
}

function NowButton({ nowHow }: { nowHow: number }) {
  return (
    <button
      type="button"
      onClick={() => useTruckHourStore.getState().setHow(nowHow)}
      className="btn btn-secondary h-11 md:h-9 flex-none text-sm"
      style={{ padding: '0 12px' }}
    >
      Now
    </button>
  );
}

function SpeedSelect() {
  const speed = useTruckUiStore((s) => s.playSpeedMs);
  return (
    <select
      aria-label="Playback speed"
      value={speed}
      onChange={(e) => {
        const ms = Number(e.target.value);
        for (const option of PLAY_SPEEDS) if (option.ms === ms) useTruckUiStore.getState().patch({ playSpeedMs: option.ms });
      }}
      className="select h-11! md:h-9! w-auto! flex-none py-0! pl-2.5! pr-1.5! font-semibold"
    >
      {PLAY_SPEEDS.map((option) => (
        <option key={option.ms} value={option.ms}>
          {option.label}
        </option>
      ))}
    </select>
  );
}

/**
 * The hour bar under the map (docs/truck-planner/05_FRONTEND.md 4.2): play, the day, the hour
 * slider with the hour strip over it, the hour in words, "Now" and the playback speed. It is docked
 * below the map, never over it, so it can not cover the map's own credits.
 *
 * The hour lives in `truckHourStore`. Each control here is a leaf that selects only what it prints,
 * and the map layer listens to the store by itself: a tick causes no render of this component.
 *
 * Playback runs on animation frames with an elapsed-time accumulator (`advancePlayback`): it steps
 * hour by hour, wraps from Sunday night to Monday morning, and stops when the tab is hidden, when
 * the slider or a day is touched, and when the page goes away.
 */
export default function HourControl({ nowHow, strip }: HourControlProps) {
  const root = useRef<HTMLDivElement>(null);
  const [mode, setMode] = useState(() => hourBarMode(window.innerWidth));

  // The bar adapts to its own width: the spot card takes room from it when it opens at the side.
  useLayoutEffect(() => {
    const el = root.current;
    if (el === null) return undefined;
    const measure = () => setMode(hourBarMode(el.clientWidth));
    measure();
    if (typeof ResizeObserver === 'undefined') return undefined;
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    let frame = 0;
    let last: number | null = null;
    let carry = 0;

    const tick = (time: number) => {
      const state = useTruckHourStore.getState();
      if (!state.playing) {
        frame = 0;
        return;
      }
      if (last !== null) {
        const moved = advancePlayback(carry, time - last, useTruckUiStore.getState().playSpeedMs, true);
        carry = moved.carryMs;
        if (moved.steps > 0) state.step(moved.steps);
      }
      last = time;
      frame = window.requestAnimationFrame(tick);
    };
    const start = () => {
      if (frame !== 0) return;
      last = null;
      carry = 0;
      frame = window.requestAnimationFrame(tick);
    };

    const unsubscribe = useTruckHourStore.subscribe((state, previous) => {
      if (state.playing && !previous.playing) start();
    });
    if (useTruckHourStore.getState().playing) start();

    const onVisibility = () => {
      if (document.visibilityState !== 'visible') useTruckHourStore.getState().setPlaying(false);
    };
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
      unsubscribe();
      document.removeEventListener('visibilitychange', onVisibility);
      if (frame !== 0) window.cancelAnimationFrame(frame);
      frame = 0;
      useTruckHourStore.getState().setPlaying(false);
    };
  }, []);

  const stacked = mode === 'stacked';
  return (
    <div
      ref={root}
      data-tp-hourbar=""
      role="group"
      aria-label="Hour of the week"
      className="bg-white flex flex-none flex-col justify-center border-t"
      style={{ borderColor: 'var(--line-soft)', minHeight: stacked ? 104 : 76 }}
    >
      {stacked ? (
        <div className="flex flex-col gap-[3px] px-3 py-1.5">
          <div className="flex items-center gap-2">
            <PlayButton />
            <DaySelect />
            <HourLabel className="min-w-0 flex-1" />
          </div>
          <div className="flex items-center gap-2">
            <HourSlider strip={strip} stripHeight={14} />
            <NowButton nowHow={nowHow} />
            <SpeedSelect />
          </div>
        </div>
      ) : (
        <div className="flex items-center gap-3 px-3 lg:px-4">
          <PlayButton />
          {mode === 'wide' ? <DayButtons /> : <DaySelect />}
          <HourSlider strip={strip} stripHeight={18} />
          <HourLabel className="w-[204px] flex-none" />
          <NowButton nowHow={nowHow} />
          <SpeedSelect />
        </div>
      )}
      {/* From wave 2: the switch between a typical week and a picked date. It brings its own row. */}
      <div className="px-3 empty:hidden lg:px-4">
        <DateMode />
      </div>
    </div>
  );
}
