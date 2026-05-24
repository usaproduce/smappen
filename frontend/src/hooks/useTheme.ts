import { useEffect } from 'react';
import { useAuthStore } from '../stores/authStore';

type Theme = 'light' | 'dark' | 'auto';

/**
 * Single source of truth for the document's data-theme attribute.
 * Resolution order, highest priority first:
 *   1. user.theme from the auth store (set in Profile settings)
 *   2. localStorage('smappen-theme')
 *   3. system preference via prefers-color-scheme
 *
 * Wired once in App.tsx (via this hook running on mount). Updates whenever
 * the user object changes or the OS theme flips while the tab is open.
 */
export function useTheme() {
  const user = useAuthStore((s) => s.user) as any;

  useEffect(() => {
    const stored = (localStorage.getItem('smappen-theme') as Theme | null);
    const fromUser = (user?.theme as Theme | undefined);
    const choice: Theme = fromUser ?? stored ?? 'light';

    const apply = (mode: Theme) => {
      const isDark = mode === 'dark' || (mode === 'auto' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);
      document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    };
    apply(choice);
    if (fromUser) localStorage.setItem('smappen-theme', fromUser);

    if (choice === 'auto') {
      const mq = window.matchMedia('(prefers-color-scheme: dark)');
      const handler = () => apply('auto');
      mq.addEventListener('change', handler);
      return () => mq.removeEventListener('change', handler);
    }
  }, [user?.theme]);
}
