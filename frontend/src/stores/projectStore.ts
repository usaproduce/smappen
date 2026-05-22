import { create } from 'zustand';
import type { Project, Area, Folder, ImportedPoint } from '../types';

interface ProjectState {
  currentProject: Project | null;
  areas: Area[];
  folders: Folder[];
  importedPoints: ImportedPoint[];
  setCurrentProject: (p: Project | null) => void;
  setAreas: (a: Area[]) => void;
  addArea: (a: Area) => void;
  updateArea: (a: Area) => void;
  removeArea: (id: string) => void;
  setFolders: (f: Folder[]) => void;
  setImportedPoints: (p: ImportedPoint[]) => void;
}

export const useProjectStore = create<ProjectState>((set) => ({
  currentProject: null,
  areas: [],
  folders: [],
  importedPoints: [],
  setCurrentProject: (currentProject) => set({ currentProject }),
  setAreas: (areas) => set({ areas }),
  addArea: (a) => set((s) => ({ areas: [a, ...s.areas] })),
  updateArea: (a) => set((s) => ({ areas: s.areas.map((x) => (x.id === a.id ? a : x)) })),
  removeArea: (id) => set((s) => ({ areas: s.areas.filter((x) => x.id !== id) })),
  setFolders: (folders) => set({ folders }),
  setImportedPoints: (importedPoints) => set({ importedPoints }),
}));
