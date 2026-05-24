/**
 * VT4 — smooth combined pan + zoom for Google Maps. Google's native
 * `panTo` is fine but `setZoom` snaps instantly. Animating both together
 * over ~350ms produces a much more cinematic transition (see Mapbox /
 * deck.gl for the same idiom).
 *
 * Skips animation when prefers-reduced-motion is set.
 */
export function smoothFlyTo(
  map: google.maps.Map | null,
  to: { lat: number; lng: number; zoom?: number },
  durationMs = 350
) {
  if (!map) return;
  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    map.panTo({ lat: to.lat, lng: to.lng });
    if (to.zoom != null) map.setZoom(to.zoom);
    return;
  }
  const fromCenter = map.getCenter();
  if (!fromCenter) {
    map.panTo({ lat: to.lat, lng: to.lng });
    if (to.zoom != null) map.setZoom(to.zoom);
    return;
  }
  const fromLat = fromCenter.lat();
  const fromLng = fromCenter.lng();
  const fromZoom = map.getZoom() ?? 10;
  const toZoom = to.zoom ?? fromZoom;
  const start = performance.now();
  function tick(now: number) {
    const t = Math.min(1, (now - start) / durationMs);
    // ease-in-out cubic — slow start, fast middle, slow finish.
    const eased = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    map!.setCenter({
      lat: fromLat + (to.lat - fromLat) * eased,
      lng: fromLng + (to.lng - fromLng) * eased,
    });
    map!.setZoom(fromZoom + (toZoom - fromZoom) * eased);
    if (t < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}
