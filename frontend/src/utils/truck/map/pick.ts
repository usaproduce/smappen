// Truck Planner map engine - which cell is under a point (docs/truck-planner/05_FRONTEND.md 5.7).
//
// Picking is exact H3 membership: the id of the cell that contains the point, looked up in an index
// of the pack's ids. Nearest-centre picking disagrees with it for about one point in a hundred, near
// cell edges, so it is not used. The lookup takes under a microsecond.
//
// Pure: runs in Node and in the browser. `latLngToCell` comes from the caller (h3-js in the app).

/** The position of every id in the pack. */
export function buildCellIndex(ids: readonly string[]): Map<string, number> {
  const index = new Map<string, number>();
  for (let i = 0; i < ids.length; i++) index.set(ids[i], i);
  return index;
}

/**
 * The index of the pack cell that contains a point, or -1 when the point's cell is not in the pack
 * (outside the region, or a cell the build left out) or the point is not a place on the map.
 */
export function cellAt(
  index: ReadonlyMap<string, number>,
  lat: number,
  lng: number,
  latLngToCell: (lat: number, lng: number, res: number) => string,
  res: number,
): number {
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90) return -1;
  let id: string;
  try {
    id = latLngToCell(lat, lng, res);
  } catch {
    return -1;
  }
  const at = index.get(id);
  return at === undefined ? -1 : at;
}
