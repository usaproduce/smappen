import { api } from './client';
import type { PlanLimitsResponse } from '../types';

export const billingApi = {
  async createCheckout(plan: 'starter' | 'pro' | 'business') {
    const { data } = await api.post('/api/billing/checkout', { plan });
    return data.data as { checkout_url: string };
  },
  async subscription() {
    const { data } = await api.get('/api/billing/subscription');
    return data.data as PlanLimitsResponse;
  },
  async portal() {
    const { data } = await api.post('/api/billing/portal', {});
    return data.data as { portal_url: string };
  },
  async cancel() {
    const { data } = await api.post('/api/billing/cancel', {});
    return data;
  },
};
