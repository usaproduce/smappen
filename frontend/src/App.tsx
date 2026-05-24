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
import ErrorBoundary from './components/ErrorBoundary';
import { useTheme } from './hooks/useTheme';

export default function App() {
  useTheme();
  return (
    <ErrorBoundary>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        <Route path="/pricing" element={<PricingPage />} />
        <Route path="/share/:token" element={<SharedProjectPage />} />
        <Route path="/settings" element={<ProtectedRoute><SettingsLayout /></ProtectedRoute>}>
          <Route index element={<Navigate to="profile" replace />} />
          <Route path="profile" element={<ProfileSettings />} />
          <Route path="team" element={<TeamSettings />} />
          <Route path="integrations" element={<IntegrationsSettings />} />
          <Route path="api" element={<ApiKeySettings />} />
          <Route path="webhooks" element={<WebhookSettings />} />
          <Route path="billing" element={<BillingSettings />} />
        </Route>
        <Route path="/*" element={<ProtectedRoute><AppLayout /></ProtectedRoute>} />
      </Routes>
    </ErrorBoundary>
  );
}
