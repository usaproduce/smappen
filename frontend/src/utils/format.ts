export const formatNumber = (n: number | null | undefined): string => {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  return new Intl.NumberFormat('en-US').format(n);
};

export const formatCurrency = (n: number | null | undefined): string => {
  if (n === null || n === undefined) return '—';
  return '$' + new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n);
};

export const formatPercent = (n: number | null | undefined, decimals = 1): string => {
  if (n === null || n === undefined) return '—';
  return n.toFixed(decimals) + '%';
};

export const formatCompact = (n: number | null | undefined): string => {
  if (n === null || n === undefined) return '—';
  return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
};

export const formatArea = (sqKm: number, unit: 'km' | 'mi' = 'km'): string => {
  if (unit === 'mi') {
    const sqMi = sqKm * 0.386102;
    return `${sqMi.toFixed(1)} sq mi`;
  }
  return `${sqKm.toFixed(1)} sq km`;
};
