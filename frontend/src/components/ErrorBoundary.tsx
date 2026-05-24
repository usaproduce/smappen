import { Component, ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';

interface State { hasError: boolean; message?: string; recovering?: boolean; }
interface Props {
  children: ReactNode;
  /** Compact label shown when this boundary fires — defaults to "this panel" */
  scope?: string;
  /** When set, render a small inline panel instead of the full-screen card. */
  inline?: boolean;
  onReset?: () => void;
}

/** Recognize the family of stale-chunk errors that React.lazy throws when
 *  a previously-built JS chunk is no longer on the server. The window-level
 *  unhandledrejection handler in main.tsx normally catches these, but with
 *  React 18's Suspense + lazy the boundary "consumes" the throw before it
 *  reaches window, so we have to detect + recover here too. */
function isStaleChunkError(message?: string): boolean {
  if (!message) return false;
  return /Failed to fetch dynamically imported module/i.test(message)
    || /Importing a module script failed/i.test(message)
    || /error loading dynamically imported module/i.test(message)
    || /ChunkLoadError/i.test(message);
}

export default class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(e: any): State {
    return { hasError: true, message: e?.message ?? 'Unknown error' };
  }

  componentDidCatch(error: any, info: any) {
    console.error('UI error:', error, info);
    // Auto-recover from stale-chunk errors — purge SW caches, unregister
    // the SW, then reload once. The sessionStorage guard from main.tsx
    // (sm_chunk_reload_done) is reused so we never loop.
    const msg = (error?.message as string) ?? '';
    if (isStaleChunkError(msg)) {
      if (sessionStorage.getItem('sm_chunk_reload_done') === '1') {
        // Already attempted this session — stop the loop and show the user.
        return;
      }
      sessionStorage.setItem('sm_chunk_reload_done', '1');
      this.setState({ recovering: true });
      console.warn('[ErrorBoundary] stale chunk — recovering');
      (async () => {
        try {
          if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map((k) => caches.delete(k)));
          }
          if ('serviceWorker' in navigator) {
            const regs = await navigator.serviceWorker.getRegistrations();
            await Promise.all(regs.map((r) => r.unregister()));
          }
        } catch (_) {}
        location.reload();
      })();
    }
  }

  reset = () => {
    this.setState({ hasError: false, message: undefined });
    this.props.onReset?.();
  };

  render() {
    if (this.state.hasError) {
      // Stale-chunk recovery in progress — show a calm "updating" state
      // instead of the alarming red "crashed" card. The reload will happen
      // within a few hundred ms.
      if (this.state.recovering) {
        if (this.props.inline) {
          return (
            <div className="m-3 p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm flex items-center gap-2 text-slate-600">
              <span className="spinner" style={{ width: 12, height: 12, borderWidth: 1.5 }} />
              Updating to latest version…
            </div>
          );
        }
        return (
          <div className="min-h-screen flex items-center justify-center px-4">
            <div className="text-center">
              <div className="text-sm text-slate-500 font-semibold">Updating to latest version…</div>
            </div>
          </div>
        );
      }
      if (this.props.inline) {
        return (
          <div className="m-3 p-3 bg-rose-50 border border-rose-200 rounded-lg text-sm">
            <div className="flex items-center gap-2 font-semibold text-rose-800 mb-1">
              <AlertTriangle size={14} /> {this.props.scope ?? 'This panel'} crashed
            </div>
            <p className="text-xs text-rose-700/80 mb-2 break-words">{this.state.message}</p>
            <button className="text-xs font-semibold text-rose-700 hover:underline" onClick={this.reset}>
              Try again
            </button>
          </div>
        );
      }
      return (
        <div className="min-h-screen flex items-center justify-center px-4 bg-slate-50">
          <div className="card max-w-md text-center">
            <h2 className="text-xl font-bold mb-2">Something went wrong</h2>
            <p className="text-sm text-slate-500 mb-4 break-words">{this.state.message}</p>
            <div className="flex items-center justify-center gap-2">
              <button className="btn btn-secondary" onClick={this.reset}>Try again</button>
              <button className="btn btn-primary" onClick={() => location.reload()}>Reload</button>
            </div>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
