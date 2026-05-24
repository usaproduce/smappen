/**
 * VT20 — dependency-free confetti. Spawns a one-off canvas overlay,
 * shoots ~80 colored particles outward from a focal point, animates
 * them with gravity + drag, then removes itself. Total cost: ~30KB
 * runtime memory, ~1s of RAF, then GC'd.
 *
 * Use sparingly — meant for one-off "you did the thing!" moments
 * (territory generation, big imports). NOT for routine activity.
 * Honors prefers-reduced-motion by returning immediately.
 */
export function fireConfetti(opts: {
  x?: number; // px from left of viewport (default: center)
  y?: number; // px from top (default: a third of the way down)
  particles?: number;
  colors?: string[];
} = {}) {
  if (typeof window === 'undefined') return;
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

  const W = window.innerWidth;
  const H = window.innerHeight;
  const cx = opts.x ?? W / 2;
  const cy = opts.y ?? H / 3;
  const count = opts.particles ?? 80;
  const colors = opts.colors ?? ['#7848BB', '#E53935', '#F57C00', '#1D9E75', '#1E88E5', '#FFB300'];

  const canvas = document.createElement('canvas');
  canvas.width = W * devicePixelRatio;
  canvas.height = H * devicePixelRatio;
  canvas.style.cssText = `position:fixed;inset:0;width:${W}px;height:${H}px;pointer-events:none;z-index:10000`;
  document.body.appendChild(canvas);
  const ctx = canvas.getContext('2d')!;
  ctx.scale(devicePixelRatio, devicePixelRatio);

  type P = { x: number; y: number; vx: number; vy: number; size: number; rot: number; vr: number; color: string; life: number };
  const particles: P[] = [];
  for (let i = 0; i < count; i++) {
    const angle = (Math.PI * 2 * i) / count + (Math.random() - 0.5) * 0.3;
    const speed = 3 + Math.random() * 5;
    particles.push({
      x: cx,
      y: cy,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed - 2,
      size: 3 + Math.random() * 5,
      rot: Math.random() * Math.PI * 2,
      vr: (Math.random() - 0.5) * 0.3,
      color: colors[Math.floor(Math.random() * colors.length)],
      life: 1,
    });
  }

  const start = performance.now();
  function tick(now: number) {
    const elapsed = now - start;
    ctx.clearRect(0, 0, W, H);
    let alive = 0;
    for (const p of particles) {
      // Gravity + drag.
      p.vy += 0.18;
      p.vx *= 0.99;
      p.vy *= 0.99;
      p.x += p.vx;
      p.y += p.vy;
      p.rot += p.vr;
      p.life = 1 - Math.min(1, elapsed / 1500);
      if (p.y < H + 20 && p.life > 0) {
        alive++;
        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate(p.rot);
        ctx.globalAlpha = p.life;
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
        ctx.restore();
      }
    }
    if (alive > 0 && elapsed < 2500) requestAnimationFrame(tick);
    else canvas.remove();
  }
  requestAnimationFrame(tick);
}
