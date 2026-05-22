import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { User } from '../types';
import { authApi } from '../api/auth';

interface AuthState {
  user: User | null;
  token: string | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (payload: { email: string; password: string; name: string; organization_name?: string }) => Promise<void>;
  logout: () => void;
  loadUser: () => Promise<void>;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isLoading: false,
      isAuthenticated: false,
      async login(email, password) {
        set({ isLoading: true });
        try {
          const r = await authApi.login(email, password);
          set({ user: r.user, token: r.token, isAuthenticated: true, isLoading: false });
        } catch (e) {
          set({ isLoading: false });
          throw e;
        }
      },
      async register(payload) {
        set({ isLoading: true });
        try {
          const r = await authApi.register(payload);
          set({ user: r.user, token: r.token, isAuthenticated: true, isLoading: false });
        } catch (e) {
          set({ isLoading: false });
          throw e;
        }
      },
      logout() {
        set({ user: null, token: null, isAuthenticated: false });
      },
      async loadUser() {
        if (!get().token) return;
        try {
          const user = await authApi.me();
          set({ user, isAuthenticated: true });
        } catch {
          set({ user: null, token: null, isAuthenticated: false });
        }
      },
    }),
    {
      name: 'smappen-auth',
      partialize: (s) => ({ token: s.token }),
    }
  )
);
