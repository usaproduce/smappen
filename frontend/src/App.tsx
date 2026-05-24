import { Routes, Route, Navigate } from 'react-router-dom';
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
import ErrorBoundary from './components/ErrorBoundary';
import { useAuthStore } from './stores/authStore';
import { useTheme } from './hooks/useTheme';

export default function App() {
  useTheme();
  // Logged-in users hitting `/` go to their dashboard; everyone else sees the
  // marketing homepage. Avoids the "logged-in user lands on marketing copy"
  // confusion and keeps the SEO surface at `/` intact for anonymous visitors.
  const isAuthed = useAuthStore((s) => !!s.token);
  return (
    <ErrorBoundary>
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
        <Route path="/app/*" element={<ProtectedRoute><AppLayout /></ProtectedRoute>} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </ErrorBoundary>
  );
}
