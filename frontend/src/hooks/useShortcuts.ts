import { useEffect } from 'react';
import toast from 'react-hot-toast';
import { useMapStore } from '../stores/mapStore';

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
        e.preventDefault();
        if (onSaveSnapshot) onSaveSnapshot();
        else toast('Snapshot shortcut not wired here');
        return;
      }
      if (!cmd && (e.key === 'd' || e.key === 'D')) {
        if (onCreateArea) { e.preventDefault(); onCreateArea(); }
        return;
      }
      if (!cmd && (e.key === 'Delete' || e.key === 'Backspace')) {
        if (selectedAreaId) {
          e.preventDefault();
          if (confirm('Delete selected area?')) {
            // Defer deletion to area-row context — emit a custom event so any
            // component that owns the action can listen.
            document.dispatchEvent(new CustomEvent('smappen:delete-selected-area', { detail: selectedAreaId }));
          }
        }
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [selectedAreaId, selectArea, onCreateArea, onSaveSnapshot]);
}
