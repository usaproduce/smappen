import { Component, ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';

interface State { hasError: boolean; message?: string; }
interface Props {
  children: ReactNode;
  /** Compact label shown when this boundary fires — defaults to "this panel" */
  scope?: string;
  /** When set, render a small inline panel instead of the full-screen card. */
  inline?: boolean;
  onReset?: () => void;
}

export default class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(e: any): State {
    return { hasError: true, message: e?.message ?? 'Unknown error' };
  }

  componentDidCatch(error: any, info: any) {
    // Forward to console in dev; in prod the SW + logging middleware will
    // pick this up from window.onerror. Keep this lightweight — heavy
    // serialization here can itself throw and double-fault.
    console.error('UI error:', error, info);
  }

  reset = () => {
    this.setState({ hasError: false, message: undefined });
    this.props.onReset?.();
  };

  render() {
    if (this.state.hasError) {
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
