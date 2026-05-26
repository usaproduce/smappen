import toast from 'react-hot-toast';
import { restaurantsApi } from '../api/restaurants';
import { projectsApi } from '../api/projects';
import { areasApi } from '../api/areas';
import { isochroneApi } from '../api/isochrone';
import { useProjectStore } from '../stores/projectStore';
import { useMapStore } from '../stores/mapStore';

/**
 * Spin up a 15-min drive-time isochrone centered on a restaurant and drop
 * the user on the map with that area selected. Called when an operator
 * clicks "Study your trade area" from anywhere inside a restaurant
 * workspace — without this, /app loads empty and they have to re-enter
 * the address Carafe already knows.
 *
 * Reuses an existing area if one with the same center + travel time is
 * already in the store. Falls back to creating one (and a project, if the
 * user has none yet) otherwise.
 *
 * Returns the area id on success — callers can then `navigate('/app')`.
 */
export async function studyTradeAreaForRestaurant(restaurantId: string): Promise<string | null> {
  const toastId = toast.loading('Building your trade area…');
  try {
    const r = await restaurantsApi.show(restaurantId);
    if (r.lat == null || r.lng == null) {
      toast.error('This restaurant has no pin. Add an address first.', { id: toastId });
      return null;
    }

    const store = useProjectStore.getState();
    let project = store.currentProject;
    if (!project) {
      const list = await projectsApi.list({ per_page: 1 });
      if (list.data.length > 0) {
        project = list.data[0];
      } else {
        project = await projectsApi.create({ name: r.name });
      }
      store.setCurrentProject(project);
    }

    // Dedupe against already-loaded areas — cheap and avoids cluttering
    // the project with identical 15-min isochrones on repeated clicks.
    const dupe = store.areas.find((a: any) =>
      typeof a.center_lat === 'number' &&
      typeof a.center_lng === 'number' &&
      Math.abs(a.center_lat - (r.lat as number)) < 1e-4 &&
      Math.abs(a.center_lng - (r.lng as number)) < 1e-4 &&
      a.travel_time_minutes === 15 &&
      a.travel_mode === 'driving-car',
    );

    let areaId: string;
    if (dupe) {
      areaId = dupe.id;
    } else {
      const iso = await isochroneApi.calculate({
        lat: r.lat, lng: r.lng, time_minutes: 15, travel_mode: 'driving-car',
      });
      const area = await areasApi.create(project.id, {
        name: `${r.name} – 15 min drive`,
        area_type: 'isochrone',
        center_lat: r.lat,
        center_lng: r.lng,
        center_address: r.address ?? null,
        travel_mode: 'driving-car',
        travel_time_minutes: 15,
        fill_color: '#7848BB',
        stroke_color: '#7848BB',
        geometry: iso.geojson,
      } as any);
      store.addArea({ ...area, geometry: iso.geojson } as any);
      areaId = area.id;
    }

    useMapStore.getState().selectArea(areaId);
    toast.success('Opening your trade area', { id: toastId });
    return areaId;
  } catch (e: any) {
    toast.error(e?.response?.data?.error ?? 'Could not build trade area', { id: toastId });
    return null;
  }
}
