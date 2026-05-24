import { describe, it, expect } from 'vitest';
import { formatNumber, formatCurrency, formatPercent, formatCompact, formatArea } from '../format';

describe('format helpers', () => {
  it('handles nullish and NaN', () => {
    expect(formatNumber(null)).toBe('—');
    expect(formatNumber(undefined)).toBe('—');
    expect(formatNumber(NaN)).toBe('—');
    expect(formatCurrency(null)).toBe('—');
    expect(formatPercent(undefined)).toBe('—');
    expect(formatCompact(null)).toBe('—');
  });

  it('formats numbers with thousands separator', () => {
    expect(formatNumber(1234567)).toBe('1,234,567');
  });

  it('prefixes dollar sign without decimals', () => {
    expect(formatCurrency(85000)).toBe('$85,000');
  });

  it('compacts large values', () => {
    expect(formatCompact(1500000)).toMatch(/1\.5M/);
  });

  it('converts km² to sq mi when asked', () => {
    expect(formatArea(100, 'mi')).toMatch(/38\.6 sq mi/);
    expect(formatArea(100)).toBe('100.0 sq km');
  });
});
