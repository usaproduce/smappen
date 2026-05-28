import { useEffect } from 'react';
import { X, Keyboard } from 'lucide-react';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';

/**
 * Global keyboard-shortcut cheat sheet. Opened by pressing `?` (when not
 * typing in an input). Mounted once at the AppLayout level so it can sit
 * above any other panel/modal.
 */
const GROUPS: { title: string; rows: { keys: string[]; label: string }[] }[] = [
  {
    title: 'Global',
    rows: [
      { keys: ['?'], label: 'Show this cheat sheet' },
      { keys: ['g', 'p'], label: 'Open project switcher (press in sequence)' },
      { keys: ['Esc'], label: 'Close any open menu / dropdown' },
      { keys: ['⌘/Ctrl', 'Z'], label: 'Undo last action' },
      { keys: ['⇧⌘/Ctrl', 'Z'], label: 'Redo' },
      { keys: ['⌘/Ctrl', 'S'], label: 'Save project snapshot' },
      { keys: ['⇧⌘/Ctrl', 'S'], label: 'Screenshot the map (download PNG)' },
    ],
  },
  {
    title: 'Map / areas',
    rows: [
      { keys: ['Esc'], label: 'Deselect / close panel' },
      { keys: ['D'], label: 'Open area creator' },
      { keys: ['B'], label: 'Toggle population heatmap' },
      { keys: ['F'], label: 'Favorites-only filter' },
      { keys: ['L'], label: 'Toggle polygon labels' },
      { keys: ['Del', 'Backspace'], label: 'Delete selected area' },
      { keys: ['⇧', 'click camera'], label: 'Copy screenshot to clipboard' },
    ],
  },
  {
    title: 'Daypart timeline',
    rows: [
      { keys: ['Space'], label: 'Play / pause' },
      { keys: ['←', '→'], label: 'Previous / next hour' },
      { keys: ['[', ']'], label: 'Speed down / up' },
    ],
  },
];

export default function ShortcutsModal() {
  const open = useUiPrefsStore((s) => s.shortcutsModalOpen);
  const toggle = useUiPrefsStore((s) => s.toggleShortcutsModal);

  // Bind the `?` opener globally. Skips when focus is in an input so help
  // doesn't pop on every question mark someone types in a comment.
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      const el = document.activeElement as HTMLElement | null;
      const isTyping = el?.tagName === 'INPUT' || el?.tagName === 'TEXTAREA' || el?.isContentEditable;
      if (isTyping) return;
      if (e.key === '?') {
        e.preventDefault();
        toggle();
      } else if (open && e.key === 'Escape') {
        toggle();
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, toggle]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center px-4 bg-slate-900/40"
      onClick={toggle}
    >
      <div
        className="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="flex items-center justify-between px-5 py-3 border-b border-slate-200">
          <div className="flex items-center gap-2">
            <Keyboard size={16} style={{ color: '#7848BB' }} />
            <span className="font-bold text-base" style={{ color: '#1A1A2E' }}>Keyboard shortcuts</span>
          </div>
          <button className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-100" onClick={toggle}>
            <X size={16} />
          </button>
        </header>
        <div className="px-5 py-4 space-y-4">
          {GROUPS.map((g) => (
            <section key={g.title}>
              <div className="text-[10px] uppercase font-bold tracking-wider text-slate-500 mb-2">{g.title}</div>
              <ul className="space-y-1.5">
                {g.rows.map((r, i) => (
                  <li key={i} className="flex items-center justify-between text-sm">
                    <span className="text-slate-700">{r.label}</span>
                    <span className="flex items-center gap-1">
                      {r.keys.map((k, j) => (
                        <kbd
                          key={j}
                          className="px-1.5 py-0.5 text-[10px] font-bold rounded border border-slate-200 bg-slate-50 tabular-nums"
                          style={{ color: '#1A1A2E' }}
                        >
                          {k}
                        </kbd>
                      ))}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
        <footer className="px-5 py-2 border-t border-slate-100 text-[11px] text-slate-400 text-center">
          Press <kbd className="bg-slate-100 px-1 rounded">Esc</kbd> or <kbd className="bg-slate-100 px-1 rounded">?</kbd> to close
        </footer>
      </div>
    </div>
  );
}
