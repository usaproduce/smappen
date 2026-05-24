import { useEffect } from 'react';
import { useMapStore } from '../stores/mapStore';
import { useUiPrefsStore } from '../stores/uiPrefsStore';

interface Opts {
  onCreateArea?: () => void;
  onSaveSnapshot?: () => void;
}

/**
 * App-wide keyboard shortcuts (#33). All disabled while focus is in an
 * input/textarea so typing never triggers a shortcut.
 *
 *   Esc        — deselect the current area
 *   D          — open the area creator
 *   Cmd/Ctrl+S — save a project snapshot (versioning)
 *   Del / ⌫    — delete the selected area (with confirm)
 */
export function useShortcuts({ onCreateArea, onSaveSnapshot }: Opts = {}) {
  const { selectedAreaId, selectArea } = useMapStore();

  useEffect(() => {
    const isTyping = () => {
      const el = document.activeElement as HTMLElement | null;
      if (!el) return false;
      const tag = el.tagName;
      return tag === 'INPUT' || tag === 'TEXTAREA' || el.isContentEditable;
    };

    const handler = (e: KeyboardEvent) => {
      if (isTyping()) return;
      const cmd = e.metaKey || e.ctrlKey;

      if (e.key === 'Escape') {
        if (selectedAreaId) selectArea(null);
        return;
      }
      if (cmd && e.key.toLowerCase() === 's') {
        // Always preventDefault, even when no snapshot callback is wired —
        // otherwise the browser's "save page" dialog pops up over the app.
        // Silent no-op when un-wired is better UX than a "not configured" toast.
        e.preventDefault();
        onSaveSnapshot?.();
        return;
      }
      if (!cmd && (e.key === 'd' || e.key === 'D')) {
        if (onCreateArea) { e.preventDefault(); onCreateArea(); }
        return;
      }
      // OP7 — additional shortcuts. Each leaves Escape, ⌘S, D, Delete intact.
      if (!cmd && (e.key === 'n' || e.key === 'N')) {
        // n = new area (alias of d)
        if (onCreateArea) { e.preventDefault(); onCreateArea(); }
        return;
      }
      if (!cmd && (e.key === 'b' || e.key === 'B')) {
        // b = toggle population heatmap
        e.preventDefault();
        useMapStore.getState().toggleHeatmap();
        return;
      }
      if (!cmd && (e.key === 'f' || e.key === 'F')) {
        // f = favorites-only filter
        e.preventDefault();
        useMapStore.getState().toggleFavoritesOnly();
        return;
      }
      if (!cmd && (e.key === 'l' || e.key === 'L')) {
        // l = toggle polygon labels
        e.preventDefault();
        useUiPrefsStore.getState().togglePolygonLabels();
        return;
      }
      if (!cmd && e.key === '?') {
        // ? = open shortcuts modal
        e.preventDefault();
        useUiPrefsStore.getState().toggleShortcutsModal();
        return;
      }
      if (!cmd && (e.key === 'Delete' || e.key === 'Backspace')) {
        // Only intercept when an area is selected — otherwise Backspace
        // should still work for browser-back navigation on certain UAs.
        if (!selectedAreaId) return;
        e.preventDefault();
        if (confirm('Delete selected area?')) {
          // Defer deletion to area-row context — emit a custom event so any
          // component that owns the action can listen.
          document.dispatchEvent(new CustomEvent('smappen:delete-selected-area', { detail: selectedAreaId }));
        }
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [selectedAreaId, selectArea, onCreateArea, onSaveSnapshot]);
}
