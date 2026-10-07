import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { truckKeys, type HostHint, type Spot } from '../../../api/truck';
import type { LatLng, MapLayer } from '../../../utils/truck/model';
import { fmtCoord } from '../../../utils/truck/format';
import { profileWarningTexts } from '../../../utils/truck/wording';
import {
  CARD_WIDTH,
  cardSide,
  floatLayout,
  hourKeyAction,
  initialCamera,
  legendGivesWay,
  legendStartsOpen,
  mapRegionLabel,
  placePoint,
  pointParam,
  readMapParams,
  writeMapParams,
  type MapParamsPatch,
} from '../../../utils/truck/hourControl';
import { useTruckHourStore } from '../../../stores/truckHourStore';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { useNow, useSaveProfile, useSpots, useTruck } from '../data';
import TruckMap from '../map/TruckMap';
import type { LayerStatus, MapCamera, MapHit, TruckMapHandle } from '../map/types';
import { useCellPack } from '../map/useCellPack';
import BasePin from '../mapui/BasePin';
import HourControl from '../mapui/HourControl';
import HoverHint, { type HoverHintHandle } from '../mapui/HoverHint';
import LayerSwitch from '../mapui/LayerSwitch';
import Legend, { type LegendHandle } from '../mapui/Legend';
import MapStatus from '../mapui/MapStatus';
import MapTools from '../mapui/MapTools';
import PickBanner from '../mapui/PickBanner';
import ScoutDotsLayer from '../mapui/ScoutDotsLayer';
import SpotPins, { SelectedPointPin } from '../mapui/SpotPins';
import SpotCard, { type SpotCardSubject } from '../spot/SpotCard';
import SpotForm from '../spot/SpotForm';
import { Modal } from '../ui';

/** Hour and camera reach the URL and the stored preferences this long after their last change (1.2). */
const WRITE_DELAY_MS = 300;
/** From this zoom every saved spot shows its name. */
const NAMES_FROM_ZOOM = 13;
/** The path of this page: the only address its parameters are ever written to. */
const MAP_PATH = /\/truck\/map\/?$/;

/** Runs when the browser has nothing more urgent to do (soon after, where it cannot say). Returns a cancel. */
function whenIdle(run: () => void): () => void {
  if (typeof window.requestIdleCallback === 'function') {
    const id = window.requestIdleCallback(run, { timeout: 500 });
    return () => window.cancelIdleCallback(id);
  }
  const id = window.setTimeout(run, 60);
  return () => window.clearTimeout(id);
}

/** A camera as the URL would write it: two cameras with the same key are the same place on screen. */
function cameraKey(camera: MapCamera): string {
  return writeMapParams('', { camera });
}

/** True once a Scout answer is in the query cache: only then are there results to put on the map. */
function useScoutAnswerCached(): boolean {
  const cache = useQueryClient().getQueryCache();
  const subscribe = useCallback((onChange: () => void) => cache.subscribe(onChange), [cache]);
  return useSyncExternalStore(subscribe, () =>
    cache.findAll({ queryKey: ['truck', 'scout'] }).some((query) => query.state.data !== undefined),
  );
}

/**
 * The map (docs/truck-planner/05_FRONTEND.md 4.2): where people are and what the truck could
 * expect there, hour by hour, with the spot card one click away.
 *
 * The page fills the box the gate hands it: the map on top, the hour bar docked under it. Cards
 * float over the map and never reach its bottom 28 px, and the spot card takes its room from the
 * page instead of lying over it, so the map's own logo and terms stay in view at every width.
 *
 * What is where:
 * - The hour lives in `truckHourStore`; the map layer listens to it by itself, and nothing in this
 *   component renders on a tick. Layer, camera and preferences live in `truckUiStore`.
 * - The URL carries the same state (1.2). Which card is open and whether a pick is in progress are
 *   read from it on every render; hour and camera are read once and written back, at most every
 *   300 ms and never while the week plays.
 * - A click on the map opens the card for the exact point clicked. The card asks for one `simulate`
 *   and never for a drive time. Nothing ever asks the browser where the device is.
 */
export default function MapPage() {
  const { region, profile, A, cal } = useTruck();
  const now = useNow();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [searchParams] = useSearchParams();
  const search = searchParams.toString();
  const params = useMemo(() => readMapParams(search), [search]);

  // Once, before anything subscribes: what the link says wins over what was stored (1.2).
  const [boot] = useState(() => {
    const first = readMapParams(search);
    const ui = useTruckUiStore.getState();
    if (first.layer !== null && first.layer !== ui.mapLayer) ui.patch({ mapLayer: first.layer });
    if (first.scout && !ui.showScoutDots) ui.patch({ showScoutDots: true });
    const hours = useTruckHourStore.getState();
    hours.setPlaying(false);
    hours.setHow(first.how !== null ? first.how : ui.lastHow !== null ? ui.lastHow : now.how);
    const known = first.spot === null ? undefined : qc.getQueryData<Spot[]>(truckKeys.spots(false));
    const linked = known === undefined ? undefined : known.find((spot) => spot.id === first.spot);
    const camera = initialCamera(first, ui.mapCamera, profile.base, region !== null ? region.center : null, linked === undefined ? null : linked.point);
    // An hour or a camera named by the link is a choice like one made by hand: it is the last one used.
    // The current hour, taken when nothing names one, is not kept: the next visit starts on its own "now".
    if (first.how !== null && first.how !== ui.lastHow) ui.patch({ lastHow: first.how });
    const withCamera = first.lat !== null && first.lng !== null;
    if (withCamera) ui.patch({ mapCamera: camera });
    // The place the link opens the card for, when the map already shows it as it should: the link
    // names a camera of its own, or the map starts on the place.
    let placed: string | null = null;
    if (first.pick === null) {
      if (first.spot !== null && (withCamera || linked !== undefined)) placed = 'spot:' + first.spot;
      else if (first.spot === null && first.pt !== null) placed = 'pt:' + pointParam(first.pt);
    }
    return { camera, placed };
  });

  const layer = useTruckUiStore((s) => s.mapLayer);
  const showSpots = useTruckUiStore((s) => s.showSpotPins);
  const showScout = useTruckUiStore((s) => s.showScoutDots);
  const scoutCached = useScoutAnswerCached();
  const spotsQuery = useSpots({ archived: false });
  const spots = spotsQuery.data;
  const refetchSpots = spotsQuery.refetch;
  const { pack, state: packState } = useCellPack(region);
  const saveProfile = useSaveProfile();

  const root = useRef<HTMLDivElement>(null);
  const area = useRef<HTMLDivElement>(null);
  const map = useRef<TruckMapHandle>(null);
  const hint = useRef<HoverHintHandle>(null);
  const legend = useRef<LegendHandle>(null);
  const alive = useRef(true);
  const placed = useRef<string | null>(boot.placed);
  const cardWasOpen = useRef(false);
  const rested = useRef(cameraKey(boot.camera));

  const [status, setStatus] = useState<LayerStatus>(region !== null && region.usable && region.pack !== null ? 'loading' : 'no-region');
  const [side, setSide] = useState(() => cardSide(window.innerWidth, window.innerHeight));
  const [float, setFloat] = useState(() => floatLayout(window.innerWidth));
  const [short, setShort] = useState(false);
  const [legendOpen, setLegendOpen] = useState(() => legendStartsOpen(window.innerWidth));
  // The owner's choice for as long as the card stands beside a narrow map: closed until they open it.
  const [legendBesideCard, setLegendBesideCard] = useState(false);
  const [namesOn, setNamesOn] = useState(boot.camera.zoom >= NAMES_FROM_ZOOM);
  const [strip, setStrip] = useState<Uint8Array | null>(null);
  const [basePick, setBasePick] = useState<LatLng | null>(null);
  const [form, setForm] = useState<{ point: LatLng; hosts: HostHint[] } | null>(null);
  const [justSaved, setJustSaved] = useState<Spot | null>(null);

  const inputs = useMemo(() => ({ A, profile, cal }), [A, profile, cal]);

  // ---- writing the URL (1.2): always replace, always from what the address bar holds now ----------
  const write = useCallback(
    (patch: MapParamsPatch) => {
      // Never after the page has gone, and never into the address of another page (a write that was
      // waiting when the owner moved on).
      if (!alive.current || !MAP_PATH.test(window.location.pathname)) return;
      const current = window.location.search;
      const next = writeMapParams(current, patch);
      if (next !== current) navigate({ search: next }, { replace: true });
    },
    [navigate],
  );

  // Hour and camera change continuously: they are written together, after they have come to rest.
  const pending = useRef<{ how: boolean; camera: MapCamera | null; timer: number | undefined }>({
    how: false,
    camera: null,
    timer: undefined,
  });
  const flush = useCallback(
    (toUrl: boolean) => {
      const waiting = pending.current;
      if (waiting.timer !== undefined) {
        window.clearTimeout(waiting.timer);
        waiting.timer = undefined;
      }
      const hours = useTruckHourStore.getState();
      if (hours.playing) return; // written when playback stops
      const patch: MapParamsPatch = {};
      if (waiting.how) {
        waiting.how = false;
        patch.how = hours.how;
        useTruckUiStore.getState().patch({ lastHow: hours.how });
      }
      if (waiting.camera !== null) {
        patch.camera = waiting.camera;
        useTruckUiStore.getState().patch({ mapCamera: waiting.camera });
        waiting.camera = null;
      }
      if (toUrl && (patch.how !== undefined || patch.camera !== undefined)) write(patch);
    },
    [write],
  );
  const schedule = useCallback(() => {
    const waiting = pending.current;
    if (waiting.timer !== undefined) window.clearTimeout(waiting.timer);
    waiting.timer = window.setTimeout(() => flush(true), WRITE_DELAY_MS);
  }, [flush]);

  // ---- the hour strip: asked for when the map has come to rest, and when the day or the layer changes --
  const stripJob = useRef<(() => void) | null>(null);
  const requestStrip = useCallback(() => {
    if (stripJob.current !== null) return;
    stripJob.current = whenIdle(() => {
      stripJob.current = null;
      const handle = map.current;
      if (handle === null || !alive.current) return;
      const how = useTruckHourStore.getState().how;
      handle.hourStrip((how - (how % 24)) / 24, (bytes) => {
        if (alive.current) setStrip(Uint8Array.from(bytes));
      });
    });
  }, []);

  useEffect(() => {
    alive.current = true;
    const waiting = pending.current;
    const unsubscribe = useTruckHourStore.subscribe((state, previous) => {
      if (state.how !== previous.how) {
        waiting.how = true;
        if (!state.playing) schedule();
        if (state.how - (state.how % 24) !== previous.how - (previous.how % 24)) requestStrip();
      }
      if (previous.playing && !state.playing) flush(true);
    });
    return () => {
      unsubscribe();
      // Leaving the page: keep the hour and the camera for the next visit, and leave the URL alone
      // (it belongs to the next page by now).
      useTruckHourStore.getState().setPlaying(false);
      flush(false);
      alive.current = false;
      if (stripJob.current !== null) stripJob.current();
      stripJob.current = null;
    };
  }, [schedule, flush, requestStrip]);

  useEffect(requestStrip, [requestStrip, layer, status]);

  // A link inside the app may name another layer while the page is open.
  useEffect(() => {
    if (params.layer !== null && params.layer !== useTruckUiStore.getState().mapLayer) {
      useTruckUiStore.getState().patch({ mapLayer: params.layer });
    }
  }, [params.layer]);

  // ---- layout: by the window (where the card goes) and by the room the map has (how the cards sit) ----
  useLayoutEffect(() => {
    const measure = () => {
      setSide(cardSide(window.innerWidth, window.innerHeight));
      const el = area.current;
      if (el === null) return;
      setFloat(floatLayout(el.clientWidth));
      setShort(el.clientHeight < 340);
    };
    measure();
    window.addEventListener('resize', measure);
    const el = area.current;
    const observer = typeof ResizeObserver === 'undefined' || el === null ? null : new ResizeObserver(measure);
    if (observer !== null && el !== null) observer.observe(el);
    return () => {
      window.removeEventListener('resize', measure);
      if (observer !== null) observer.disconnect();
    };
  }, []);

  // ---- what is selected (read from the URL) --------------------------------------------------------
  const picking = params.pick;
  const selectedSpot = useMemo(() => {
    if (params.spot === null) return null;
    const found = spots === undefined ? undefined : spots.find((spot) => spot.id === params.spot);
    if (found !== undefined) return found;
    return justSaved !== null && justSaved.id === params.spot ? justSaved : null;
  }, [params.spot, spots, justSaved]);

  const listPending = spots === undefined || spotsQuery.isFetching;
  const listFailed = spotsQuery.isError && spots === undefined;
  const subject = useMemo((): SpotCardSubject | null => {
    if (picking !== null) return null;
    if (params.spot !== null) {
      if (selectedSpot !== null) return { kind: 'spot', spot: selectedSpot };
      if (listFailed) return { kind: 'failed', onRetry: () => void refetchSpots() };
      return listPending ? { kind: 'loading' } : { kind: 'missing' };
    }
    if (params.pt !== null) return { kind: 'point', lat: params.pt.lat, lng: params.pt.lng };
    return null;
  }, [picking, params.spot, params.pt, selectedSpot, listFailed, listPending, refetchSpots]);

  // When the card opens it takes room from the map, so the map centres on the place the card is
  // about: the ring or the pin is then in view at every width. While the card stays open the map is
  // left alone: another click is on a place already in view.
  const focusLat = subject === null ? null : subject.kind === 'point' ? subject.lat : subject.kind === 'spot' ? subject.spot.point.lat : null;
  const focusLng = subject === null ? null : subject.kind === 'point' ? subject.lng : subject.kind === 'spot' ? subject.spot.point.lng : null;
  const focusKey =
    subject === null ? null : subject.kind === 'point' ? 'pt:' + pointParam(subject) : subject.kind === 'spot' ? 'spot:' + subject.spot.id : null;
  const cardIsOpen = subject !== null;
  useEffect(() => {
    if (!cardIsOpen) {
      cardWasOpen.current = false;
      placed.current = null;
      return;
    }
    if (focusKey === null || focusLat === null || focusLng === null) return; // a saved spot still on its way
    if (cardWasOpen.current) return;
    cardWasOpen.current = true;
    const already = placed.current === focusKey;
    placed.current = null;
    if (!already) map.current?.flyTo({ lat: focusLat, lng: focusLng });
  }, [cardIsOpen, focusKey, focusLat, focusLng]);

  // ---- actions ---------------------------------------------------------------------------------------
  const setLayer = useCallback(
    (next: MapLayer) => {
      useTruckUiStore.getState().patch({ mapLayer: next });
      write({ layer: next });
    },
    [write],
  );

  const closeCard = useCallback(() => write({ pt: null, spot: null }), [write]);

  const leavePick = useCallback(() => {
    setBasePick(null);
    setForm(null);
    const back = readMapParams(window.location.search).returnTo;
    if (back !== null) navigate(back);
    else write({ pick: null, returnTo: null });
  }, [navigate, write]);

  /** A place was chosen: by a click on the map, on a pin, or as the centre of the map. */
  const choose = useCallback(
    (point: LatLng, spot: Spot | null) => {
      // One precision for every place the page hands on: the six decimals `pt` has in the URL.
      const at = placePoint(point);
      const mode = readMapParams(window.location.search).pick;
      if (mode === 'base') {
        setBasePick(at);
      } else if (mode === 'spot') {
        setForm({ point: at, hosts: [] });
      } else if (spot !== null) {
        write({ spot: spot.id, pt: null });
      } else {
        write({ pt: at, spot: null });
      }
    },
    [write],
  );

  const onMapClick = useCallback((point: { lat: number; lng: number }) => choose(point, null), [choose]);
  const onOpenSpot = useCallback((spot: Spot) => choose(spot.point, spot), [choose]);
  const onScoutPick = useCallback((place: { lat: number; lng: number }) => choose(place, null), [choose]);
  const base = profile.base;
  const onOpenBase = useCallback(() => choose({ lat: base.lat, lng: base.lng }, null), [choose, base.lat, base.lng]);
  const chooseCentre = useCallback(() => {
    const handle = map.current;
    if (handle !== null) choose(handle.getCenter(), null);
  }, [choose]);
  const goToBase = useCallback(() => {
    map.current?.flyTo({ lat: base.lat, lng: base.lng });
  }, [base.lat, base.lng]);

  const mapBounds = useCallback(() => (area.current === null ? null : area.current.getBoundingClientRect()), []);

  const onHover = useCallback((hit: MapHit | null) => {
    hint.current?.show(hit);
    legend.current?.setMarker(hit === null ? null : hit.byte);
  }, []);

  const onCamera = useCallback(
    (camera: MapCamera) => {
      setNamesOn(camera.zoom >= NAMES_FROM_ZOOM);
      requestStrip();
      // The map also reports where it came to rest when nothing moved (its first frame): only a
      // camera that reads differently in the URL is a change worth writing.
      const key = cameraKey(camera);
      if (key === rested.current) return;
      rested.current = key;
      pending.current.camera = { lat: camera.lat, lng: camera.lng, zoom: camera.zoom };
      if (!useTruckHourStore.getState().playing) schedule();
    },
    [requestStrip, schedule],
  );

  const onSaveAsSpot = useCallback((point: LatLng, hosts: HostHint[]) => setForm({ point, hosts }), []);

  const onSpotSaved = (spot: Spot) => {
    setForm(null);
    const url = readMapParams(window.location.search);
    if (url.pick !== null && url.returnTo !== null) {
      navigate(url.returnTo);
      return;
    }
    setJustSaved(spot);
    write({ spot: spot.id, pt: null, pick: null, returnTo: null });
  };

  const confirmBase = () => {
    if (basePick === null || saveProfile.isPending) return;
    saveProfile
      .mutateAsync({ base: { lat: basePick.lat, lng: basePick.lng, address: '' } })
      .then((answer) => {
        for (const text of profileWarningTexts(answer.warnings)) toast(text, { duration: 8000 });
        // The owner may have left the page while the save was on its way: then there is nowhere to go back to.
        if (alive.current) leavePick();
      })
      .catch(() => {
        // The hook has already shown the server's sentence.
      });
  };

  // ---- keys (4.2, interactions 3 and 4) ---------------------------------------------------------------
  const live = useRef({ cardOpen: false, picking: false, nowHow: now.how, closeCard, leavePick });
  live.current = { cardOpen: subject !== null, picking: picking !== null, nowHow: now.how, closeCard, leavePick };
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.defaultPrevented) return;
      const target = e.target instanceof Element ? e.target : document.body;
      const onPage = target === document.body || target === document.documentElement || target.id === 'main-content';
      const here = root.current !== null && root.current.contains(target);
      if (e.key === 'Escape') {
        if (!onPage && !here) return;
        if (target.closest('[data-tp-escape]') !== null) return; // a field or a hint takes this one
        if (live.current.picking) live.current.leavePick();
        else if (live.current.cardOpen) live.current.closeCard();
        return;
      }
      const action = hourKeyAction(
        e.key,
        {
          onPage,
          inHourBar: target.closest('[data-tp-hourbar]') !== null,
          tag: target.tagName.toLowerCase(),
          inputType: target instanceof HTMLInputElement ? target.type : '',
          editable: target instanceof HTMLElement && target.isContentEditable,
        },
        e.ctrlKey || e.metaKey || e.altKey,
      );
      if (action === null) return;
      e.preventDefault();
      const hours = useTruckHourStore.getState();
      if (action.kind === 'toggle-play') hours.setPlaying(!hours.playing);
      else if (action.kind === 'now') hours.setHow(live.current.nowHow);
      else {
        hours.setPlaying(false);
        hours.step(action.delta);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  // ---- the cards over the map --------------------------------------------------------------------------
  // Beside a map the card has left narrow, the open legend would cover the place the card is about:
  // it closes to its button for that time and comes back when the card goes.
  const legendWaits = legendGivesWay(float, subject !== null && side === 'right');
  useEffect(() => {
    if (!legendWaits) setLegendBesideCard(false);
  }, [legendWaits]);
  const switcher = <LayerSwitch layer={layer} onChange={setLayer} />;
  const legendCard = (
    <Legend
      ref={legend}
      layer={layer}
      vintages={region !== null ? region.vintages : null}
      capacity={profile.capacity_orders_per_hour}
      open={legendWaits ? legendBesideCard : legendOpen}
      onToggle={() => (legendWaits ? setLegendBesideCard((v) => !v) : setLegendOpen((v) => !v))}
    />
  );
  const tools = (
    <MapTools
      variant={float === 'narrow' || float === 'tight' ? 'menu' : 'card'}
      showSpots={showSpots}
      onShowSpots={(on) => useTruckUiStore.getState().patch({ showSpotPins: on })}
      scoutAvailable={scoutCached || params.scout || showScout}
      showScout={showScout}
      onShowScout={(on) => {
        useTruckUiStore.getState().patch({ showScoutDots: on });
        write({ scout: on });
      }}
      onEstimateCentre={chooseCentre}
      onGoToBase={goToBase}
    />
  );
  const notices = (
    <div className="flex flex-col gap-1.5">
      {picking !== null ? <PickBanner mode={picking} onUseCentre={chooseCentre} onCancel={leavePick} /> : null}
      <MapStatus status={status} region={region} blank={params.blank} />
    </div>
  );

  let floating: ReactNode;
  if (float === 'wide') {
    floating = (
      <div className="flex h-full items-start gap-3">
        <div className="pointer-events-auto flex max-h-full w-[280px] flex-none flex-col gap-2">
          {switcher}
          {legendCard}
        </div>
        <div className="flex min-w-0 flex-1 justify-center">
          <div className="pointer-events-auto min-w-0 max-w-[440px]">{notices}</div>
        </div>
        <div className="pointer-events-auto flex-none">{tools}</div>
      </div>
    );
  } else if (float === 'medium' || float === 'tight') {
    floating = (
      <div className="flex h-full items-start justify-between gap-3">
        <div className="pointer-events-auto flex max-h-full w-[280px] flex-none flex-col gap-2">
          {switcher}
          {legendCard}
        </div>
        <div className="pointer-events-auto flex min-w-0 max-w-[260px] flex-col items-end gap-2">
          {tools}
          {notices}
        </div>
      </div>
    );
  } else {
    floating = (
      <div className="flex h-full flex-col gap-2">
        {short ? null : <div className="pointer-events-auto flex-none">{switcher}</div>}
        <div className="flex min-h-0 items-stretch gap-2">
          <div className="pointer-events-auto flex min-h-0 min-w-0 flex-1 flex-col">{legendCard}</div>
          <div className="pointer-events-auto flex-none self-start">{tools}</div>
        </div>
        <div className="pointer-events-auto flex-none">{notices}</div>
      </div>
    );
  }

  const cardOpen = subject !== null;
  return (
    <div
      ref={root}
      className={'absolute inset-0 flex flex-col' + (cardOpen && side === 'bottom' ? ' pb-[45dvh]' : '')}
      style={cardOpen && side === 'right' ? { paddingRight: CARD_WIDTH } : undefined}
    >
      <h1 className="sr-only">Map</h1>

      <div ref={area} className="relative min-h-0 flex-1">
        <section aria-label={mapRegionLabel(layer)} className="absolute inset-0 isolate">
          {/* The map keeps its own error boundary (5.8): a crash in it leaves the page and the card up. */}
          <TruckMap
            ref={map}
            region={region}
            initialCamera={boot.camera}
            layer={layer}
            pack={pack}
            packState={packState}
            inputs={inputs}
            forceBlank={params.blank}
            cursor={picking !== null ? 'crosshair' : 'default'}
            hostNotice={false}
            onHover={onHover}
            onClick={onMapClick}
            onCamera={onCamera}
            onStatus={setStatus}
          >
            {showSpots && spots !== undefined ? (
              <SpotPins spots={spots} selectedId={params.spot} showNames={namesOn} onOpen={onOpenSpot} />
            ) : null}
            <ScoutDotsLayer enabled={showScout} onPick={onScoutPick} />
            {subject !== null && subject.kind === 'point' ? <SelectedPointPin lat={subject.lat} lng={subject.lng} onClose={closeCard} /> : null}
            {basePick !== null ? <SelectedPointPin lat={basePick.lat} lng={basePick.lng} onClose={() => setBasePick(null)} /> : null}
            <BasePin lat={base.lat} lng={base.lng} onOpen={onOpenBase} />
          </TruckMap>
        </section>

        {/* Cards float over the map and stop 28 px above its bottom edge, where the map prints its logo and terms. */}
        <div className="pointer-events-none absolute inset-x-0 bottom-7 top-0 z-20 overflow-hidden p-3">{floating}</div>
      </div>

      <HourControl nowHow={now.how} strip={strip} />

      <HoverHint ref={hint} layer={layer} enabled={picking === null} bounds={mapBounds} />

      <SpotCard subject={subject} side={side} onClose={closeCard} onSaveAsSpot={onSaveAsSpot} />

      <Modal
        open={basePick !== null}
        onClose={() => setBasePick(null)}
        title="Set your base here?"
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 text-sm" onClick={() => setBasePick(null)}>
              Cancel
            </button>
            <button type="button" className="btn btn-primary h-11 md:h-9 text-sm" disabled={saveProfile.isPending} onClick={confirmBase}>
              Set base
            </button>
          </>
        }
      >
        {basePick !== null ? (
          <>
            <p className="text-base font-extrabold tabular-nums" style={{ color: 'var(--ink)' }}>
              {fmtCoord(basePick.lat, basePick.lng)}
            </p>
            <p className="mt-1.5 text-sm font-medium" style={{ color: 'var(--body)' }}>
              Where the truck starts and ends its day. It is only used for drive times and weather.
            </p>
          </>
        ) : null}
      </Modal>

      <Modal open={form !== null} onClose={() => setForm(null)} title="Add a spot" size="lg">
        {form !== null ? (
          <SpotForm
            mode="create"
            initial={{ point: form.point }}
            nearbyHosts={form.hosts}
            presentation="modal"
            onSaved={onSpotSaved}
            onCancel={() => setForm(null)}
          />
        ) : null}
      </Modal>
    </div>
  );
}
