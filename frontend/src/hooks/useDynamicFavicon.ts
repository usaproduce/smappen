import { useEffect } from 'react';
import { useProjectStore } from '../stores/projectStore';

/**
 * VT22 — dynamic favicon. Stamps the current project's area count into the
 * favicon as a small badge so the tab title is informative at a glance
 * (works great when users have 4+ Smappen tabs open).
 *
 * Repaints whenever the area count changes. Falls back silently if canvas
 * isn't available (older browsers / SSR).
 */
export function useDynamicFavicon() {
  const areas = useProjectStore((s) => s.areas);
  useEffect(() => {
    if (typeof document === 'undefined') return;
    const count = areas?.length ?? 0;
    try {
      const canvas = document.createElement('canvas');
      canvas.width = 64; canvas.height = 64;
      const ctx = canvas.getContext('2d');
      if (!ctx) return;
      // Base — the same brand-gradient tile shown in the header logo.
      const grad = ctx.createLinearGradient(0, 0, 64, 64);
      grad.addColorStop(0,   '#F57C00');
      grad.addColorStop(0.5, '#E53935');
      grad.addColorStop(1,   '#7848BB');
      ctx.fillStyle = grad;
      // Rounded square.
      const r = 12;
      ctx.beginPath();
      ctx.moveTo(r, 0);
      ctx.lineTo(64 - r, 0); ctx.quadraticCurveTo(64, 0, 64, r);
      ctx.lineTo(64, 64 - r); ctx.quadraticCurveTo(64, 64, 64 - r, 64);
      ctx.lineTo(r, 64);     ctx.quadraticCurveTo(0, 64, 0, 64 - r);
      ctx.lineTo(0, r);      ctx.quadraticCurveTo(0, 0, r, 0);
      ctx.closePath();
      ctx.fill();
      // The "S" wordmark.
      ctx.fillStyle = '#fff';
      ctx.font = 'bold 38px Nunito, system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText('S', 32, 34);
      // Badge — only when there's something to count.
      if (count > 0) {
        ctx.fillStyle = '#dc2626';
        ctx.beginPath();
        ctx.arc(50, 14, 13, 0, Math.PI * 2);
        ctx.closePath();
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 16px Nunito, system-ui, sans-serif';
        const label = count > 99 ? '99+' : String(count);
        ctx.fillText(label, 50, 15);
      }
      const url = canvas.toDataURL('image/png');
      // Remove the old static favicon link(s) and install a new one.
      const existing = document.querySelectorAll('link[rel*="icon"]');
      existing.forEach((n) => n.parentNode?.removeChild(n));
      const link = document.createElement('link');
      link.rel = 'icon';
      link.type = 'image/png';
      link.href = url;
      document.head.appendChild(link);
    } catch {
      // Canvas unavailable / quota — just leave the default favicon.
    }
  }, [areas?.length]);
}
