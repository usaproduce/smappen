import { Component, ReactNode } from 'react';

interface State { hasError: boolean; message?: string; }

export default class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(e: any): State {
    return { hasError: true, message: e?.message ?? 'Unknown error' };
  }

  componentDidCatch(error: any, info: any) {
    console.error('UI error:', error, info);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center px-4">
          <div className="card max-w-md text-center">
            <h2 className="text-xl font-bold mb-2">Something went wrong</h2>
            <p className="text-sm text-slate-500 mb-4">{this.state.message}</p>
            <button className="btn btn-primary" onClick={() => location.reload()}>Reload</button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
