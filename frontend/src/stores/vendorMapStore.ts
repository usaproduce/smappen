import { create } from 'zustand';
import type { VendorPin, VendorServesRow } from '../api/vendorMap';

/**
 * Vendor-map UI state — filters, current pin, last "who serves me"
 * result. Lives alongside (not inside) the existing `mapStore` so the
 * existing map app doesn't get a Carafe vendor coupling.
 */

export type VendorTypeFilter = '' | 'broadline' | 'warehouse' | 'produce' | 'protein' | 'seafood' | 'specialty' | 'grocery' | 'bakery_dairy_beverage';
export type VendorCategoryFilter = '' | 'produce' | 'meat' | 'poultry' | 'seafood' | 'dairy' | 'dry_goods' | 'frozen' | 'bakery' | 'beverage' | 'paper_disposables' | 'cleaning_chemical' | 'specialty_imported';

interface VendorMapState {
  // Filters
  q: string;
  type: VendorTypeFilter;
  category: VendorCategoryFilter;
  minRating: number; // 0..5
  affiliatedOnly: boolean;

  // Drop-a-pin state
  servesPin: { lat: number; lng: number } | null;
  servesResults: VendorServesRow[];
  servesLoading: boolean;

  // Pin layer
  pins: VendorPin[];
  selectedVendorId: string | null;

  setFilter: <K extends keyof Pick<VendorMapState, 'q' | 'type' | 'category' | 'minRating' | 'affiliatedOnly'>>(k: K, v: VendorMapState[K]) => void;
  setPins: (pins: VendorPin[]) => void;
  setServes: (pin: { lat: number; lng: number } | null, rows: VendorServesRow[]) => void;
  setServesLoading: (b: boolean) => void;
  selectVendor: (id: string | null) => void;
}

export const useVendorMapStore = create<VendorMapState>((set) => ({
  q: '',
  type: '',
  category: '',
  minRating: 0,
  affiliatedOnly: false,
  servesPin: null,
  servesResults: [],
  servesLoading: false,
  pins: [],
  selectedVendorId: null,

  setFilter: (k, v) => set({ [k]: v } as any),
  setPins: (pins) => set({ pins }),
  setServes: (servesPin, servesResults) => set({ servesPin, servesResults }),
  setServesLoading: (servesLoading) => set({ servesLoading }),
  selectVendor: (selectedVendorId) => set({ selectedVendorId }),
}));
