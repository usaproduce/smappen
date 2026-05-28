import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/authStore';
import CarafeFirstRunWizard from './CarafeFirstRunWizard';

/**
 * Decides whether to open the Carafe first-run wizard.
 *
 * Opens when:
 *   - User is authed
 *   - User is on a Carafe surface (any /app/restaurants/* route)
 *   - `onboarding_flags.carafe_wizard_complete` isn't set
 *   - The org has zero restaurants (so we never pop on a real workspace
 *     that just hasn't completed the wizard — invitees and returning
 *     users land on the data directly)
 *
 * Mounted once in App.tsx; renders nothing while idle.
 */

const CARAFE_SURFACE_RE = /^\/app\/restaurants(\/|$)/;

export default function CarafeOnboardingGate() {
  const location = useLocation();
  const token = useAuthStore((s) => s.token);
  const onCarafeSurface = CARAFE_SURFACE_RE.test(location.pathname);
  const [open, setOpen] = useState(false);
  const [checked, setChecked] = useState(false);

  useEffect(() => {
    if (!token || !onCarafeSurface || checked) return;
    let cancelled = false;
    (async () => {
      try {
        const { data } = await api.get('/api/onboarding/state');
        const flags  = data?.data?.flags ?? {};
        const orgRestaurantCount = data?.data?.org_restaurant_count ?? 0;
        if (cancelled) return;
        // Don't pop on a workspace that already has data — invitees and
        // returning users go straight to the restaurant they're viewing.
        if (!flags.carafe_wizard_complete && orgRestaurantCount === 0) {
          setOpen(true);
        }
      } catch {
        // Silent — onboarding state failure shouldn't break navigation.
      } finally {
        if (!cancelled) setChecked(true);
      }
    })();
    return () => { cancelled = true; };
  }, [token, onCarafeSurface, checked]);

  if (!open) return null;
  return (
    <CarafeFirstRunWizard
      onClose={() => setOpen(false)}
      onComplete={() => setChecked(true)}
    />
  );
}
