import { useEffect } from 'react';
import { Routes, Route, Navigate, useLocation } from 'react-router-dom';
import LoginPage from './components/auth/LoginPage';
import RegisterPage from './components/auth/RegisterPage';
import ForgotPasswordPage from './components/auth/ForgotPasswordPage';
import ResetPasswordPage from './components/auth/ResetPasswordPage';
import VerifyEmailPage from './components/auth/VerifyEmailPage';
import ProtectedRoute from './components/auth/ProtectedRoute';
import AppLayout from './components/layout/AppLayout';
import PricingPage from './components/billing/PricingPage';
import BillingSettings from './components/billing/BillingSettings';
import SettingsLayout from './components/settings/SettingsLayout';
import ProfileSettings from './components/settings/ProfileSettings';
import TeamSettings from './components/settings/TeamSettings';
import IntegrationsSettings from './components/settings/IntegrationsSettings';
import ApiKeySettings from './components/settings/ApiKeySettings';
import WebhookSettings from './components/settings/WebhookSettings';
import SharedProjectPage from './components/share/SharedProjectPage';
import EmbedProjectPage from './components/share/EmbedProjectPage';
import ChangelogPage from './components/marketing/ChangelogPage';
import HomePage from './components/marketing/HomePage';
import BlogPage from './components/marketing/BlogPage';
import DashboardPage from './components/dashboard/DashboardPage';
import ProjectGalleryPage from './components/projects/ProjectGalleryPage';
import RestaurantsPage from './components/restaurants/RestaurantsPage';
import MenuPage from './components/restaurants/MenuPage';
import RestaurantOverviewPage from './components/restaurants/RestaurantOverviewPage';
import RecipesPage from './components/restaurants/RecipesPage';
import GoalsPage from './components/restaurants/GoalsPage';
import CostsPage from './components/restaurants/CostsPage';
import LaborPage from './components/restaurants/LaborPage';
import VendorsPage from './components/vendors/VendorsPage';
import VendorMapPage from './components/vendors/VendorMapPage';
import SavedVendorsPage from './components/vendors/SavedVendorsPage';
// Carafe admin (Vendor-Network seeding) — NOT linked from any global nav.
// Operators reach it by direct URL: /admin/carafe.
import AdminOnlyRoute            from './components/admin/AdminOnlyRoute';
import CarafeAdminLayout         from './components/admin/CarafeAdminLayout';
import CarafeOnboardingGate      from './components/onboarding/CarafeOnboardingGate';
import CarafeAdminHome           from './components/admin/CarafeAdminHome';
import SeedCampaignsListPage     from './components/admin/SeedCampaignsListPage';
import SeedCampaignBuilderPage   from './components/admin/SeedCampaignBuilderPage';
import SeedCampaignDetailPage    from './components/admin/SeedCampaignDetailPage';
import ReviewQueuePage           from './components/admin/ReviewQueuePage';
import CogsHealthPage            from './components/admin/CogsHealthPage';
import ErrorBoundary from './components/ErrorBoundary';
import { useAuthStore } from './stores/authStore';
import { useTheme } from './hooks/useTheme';

export default function App() {
  useTheme();
  // Logged-in users hitting `/` go to their dashboard; everyone else sees the
  // marketing homepage. Avoids the "logged-in user lands on marketing copy"
  // confusion and keeps the SEO surface at `/` intact for anonymous visitors.
  const isAuthed = useAuthStore((s) => !!s.token);
  useOrphanOverlayCleanup();
  return (
    <ErrorBoundary>
      <CarafeOnboardingGate />
      <Routes>
        <Route path="/" element={isAuthed ? <Navigate to="/dashboard" replace /> : <HomePage />} />
        <Route path="/blog" element={<BlogPage />} />
        <Route path="/dashboard" element={<ProtectedRoute><DashboardPage /></ProtectedRoute>} />
        <Route path="/projects" element={<ProtectedRoute><ProjectGalleryPage /></ProtectedRoute>} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        <Route path="/pricing" element={<PricingPage />} />
        <Route path="/changelog" element={<ChangelogPage />} />
        <Route path="/share/:token" element={<SharedProjectPage />} />
        <Route path="/embed/:token" element={<EmbedProjectPage />} />
        <Route path="/settings" element={<ProtectedRoute><SettingsLayout /></ProtectedRoute>}>
          <Route index element={<Navigate to="profile" replace />} />
          <Route path="profile" element={<ProfileSettings />} />
          <Route path="team" element={<TeamSettings />} />
          <Route path="integrations" element={<IntegrationsSettings />} />
          <Route path="api" element={<ApiKeySettings />} />
          <Route path="webhooks" element={<WebhookSettings />} />
          <Route path="billing" element={<BillingSettings />} />
        </Route>
        {/* Carafe surfaces — standalone (no map chrome) */}
        <Route path="/app/restaurants"                 element={<ProtectedRoute><RestaurantsPage /></ProtectedRoute>} />
        <Route path="/app/restaurants/:id"             element={<ProtectedRoute><RestaurantOverviewPage /></ProtectedRoute>} />
        <Route path="/app/restaurants/:id/menu"        element={<ProtectedRoute><MenuPage /></ProtectedRoute>} />
        <Route path="/app/restaurants/:id/recipes"     element={<ProtectedRoute><RecipesPage /></ProtectedRoute>} />
        <Route path="/app/restaurants/:id/costs"       element={<ProtectedRoute><CostsPage /></ProtectedRoute>} />
        <Route path="/app/restaurants/:id/labor"       element={<ProtectedRoute><LaborPage /></ProtectedRoute>} />
        <Route path="/app/restaurants/:id/goals"       element={<ProtectedRoute><GoalsPage /></ProtectedRoute>} />
        <Route path="/app/vendors"                     element={<ProtectedRoute><VendorMapPage /></ProtectedRoute>} />
        <Route path="/app/vendors/map"                 element={<ProtectedRoute><VendorMapPage /></ProtectedRoute>} />
        <Route path="/app/vendors/saved"               element={<ProtectedRoute><SavedVendorsPage /></ProtectedRoute>} />
        <Route path="/app/vendors/list"                element={<ProtectedRoute><VendorsPage /></ProtectedRoute>} />
        <Route path="/app/*" element={<ProtectedRoute><AppLayout /></ProtectedRoute>} />
        {/* Carafe Vendor-Network admin — direct URL only, not on navbar */}
        <Route path="/admin/carafe" element={<AdminOnlyRoute><CarafeAdminLayout /></AdminOnlyRoute>}>
          <Route index                                 element={<CarafeAdminHome />} />
          <Route path="campaigns"                      element={<SeedCampaignsListPage />} />
          <Route path="campaigns/new"                  element={<SeedCampaignBuilderPage />} />
          <Route path="campaigns/:id"                  element={<SeedCampaignDetailPage />} />
          <Route path="review"                         element={<ReviewQueuePage />} />
          <Route path="cogs"                           element={<CogsHealthPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </ErrorBoundary>
  );
}

/**
 * Belt-and-braces cleanup: on every navigation, sweep document.body for
 * stuck modal-backdrop elements. React's createPortal should remove its
 * children on unmount, but if a component portal mounts on /app and the
 * user navigates away mid-mount (or React's reconciliation glitches across
 * an effect that ran during the unmount), a fixed-inset-0 div can be left
 * behind in body — visible as a translucent gray sheet that intercepts
 * every click on the next page.
 *
 * This sweep only removes elements that look exactly like leaked modal
 * backdrops: direct child of <body>, position:fixed, inset:0, and a class
 * list that includes the standard backdrop signatures we use across our
 * modal components (bg-black/* or backdrop-blur-*). It will NOT touch
 * react-hot-toast's portal (different className), AreaCard's portal menu
 * (not inset:0), or any other legitimate body-mounted portal.
 */
function useOrphanOverlayCleanup() {
  const location = useLocation();
  useEffect(() => {
    // Run on a microtask so we sweep AFTER React has had a chance to
    // mount/unmount this route's children.
    const rafId = requestAnimationFrame(() => {
      const children = Array.from(document.body.children);
      for (const el of children) {
        if (!(el instanceof HTMLElement)) continue;
        if (el.id === 'root') continue;
        const cs = window.getComputedStyle(el);
        if (cs.position !== 'fixed') continue;
        // inset:0 ⇒ all four offsets are 0px (or auto when not explicitly set)
        const fullCover =
          (cs.top === '0px' || cs.top === 'auto') &&
          (cs.left === '0px' || cs.left === 'auto') &&
          (cs.right === '0px' || cs.right === 'auto') &&
          (cs.bottom === '0px' || cs.bottom === 'auto');
        if (!fullCover) continue;
        const cls = el.className?.toString() || '';
        const looksLikeBackdrop =
          /\bfixed\b/.test(cls) &&
          /\binset-0\b/.test(cls) &&
          (/\bbg-black\b/.test(cls) || /\bbackdrop-blur\b/.test(cls));
        // Final safety check: is anything inside still connected to a
        // React tree? If the element is alive in React, it has children
        // with React internal fiber pointers — we leave those alone.
        // Orphans don't have fiber pointers anywhere in their subtree.
        if (looksLikeBackdrop) {
          const hasReactFiber = !!Array.from(el.querySelectorAll('*'))
            .find((n) => Object.keys(n).some((k) => k.startsWith('__reactFiber$')));
          if (!hasReactFiber) {
            console.warn('[orphan-cleanup] removing stuck overlay:', el);
            el.remove();
          }
        }
      }
    });
    return () => cancelAnimationFrame(rafId);
  }, [location.pathname]);
}
