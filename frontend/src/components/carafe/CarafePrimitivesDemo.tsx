import { useState } from 'react';
import { Wallet, TrendingUp, TrendingDown } from 'lucide-react';
import { MoneyStat, DollarDelta, FreshnessChip } from './index';

/**
 * Scratch demo route for the Carafe design-system primitives.
 * Not linked from any nav; reach via /dev/carafe-primitives.
 */

export default function CarafePrimitivesDemo() {
  const [seed, setSeed] = useState(0);
  const now = Date.now();
  const ts = {
    fresh: now - 12 * 60_000,
    aging: now - 14 * 60 * 60_000,
    stale: now - 4 * 24 * 60 * 60_000,
  };

  return (
    <div className="min-h-screen p-6 md:p-10" style={{ background: 'var(--bg)' }}>
      <div className="max-w-5xl mx-auto space-y-8">
        <header className="flex items-center justify-between flex-wrap gap-3">
          <div>
            <h1 className="text-2xl font-extrabold" style={{ color: 'var(--ink)' }}>
              Carafe primitives
            </h1>
            <p className="text-sm mt-1" style={{ color: 'var(--body)' }}>
              MoneyStat · DollarDelta · FreshnessChip. Toggle theme + re-animate to inspect every state.
            </p>
          </div>
          <div className="flex gap-2">
            <button className="btn btn-secondary" onClick={() => setSeed((s) => s + 1)}>Re-animate</button>
            <button className="btn btn-secondary" onClick={toggleTheme}>Toggle theme</button>
          </div>
        </header>

        <Section title="MoneyStat">
          <Grid>
            <Card>
              <MoneyStat
                key={`a-${seed}`}
                label="Found this month"
                value={4280}
                tone="positive"
                size="xl"
                timestamp={ts.fresh}
                freshnessLabel="synced"
                icon={<Wallet size={11} strokeWidth={2.5} />}
                footer={<DollarDelta value={1240} comparison="vs last month" />}
              />
            </Card>
            <Card>
              <MoneyStat
                key={`b-${seed}`}
                label="Over-budget today"
                value={186}
                tone="negative"
                size="lg"
                timestamp={ts.aging}
                freshnessLabel="POS"
                footer={<DollarDelta value={186} goodWhen="down" comparison="vs goal" />}
              />
            </Card>
            <Card>
              <MoneyStat
                key={`c-${seed}`}
                label="Projected covers × avg"
                value={18450}
                tone="neutral"
                size="lg"
                timestamp={ts.stale}
                freshnessLabel="USDA prices"
                footer={<span style={{ color: 'var(--slate)' }}>14-day rolling</span>}
              />
            </Card>
            <Card>
              <MoneyStat
                key={`d-${seed}`}
                label="Top recipe margin"
                value={12.4}
                precision="cents"
                tone="positive"
                size="md"
                freshnessState="fresh"
                freshnessText="manual entry"
                footer={<span style={{ color: 'var(--slate)' }}>Cacio e Pepe · 78% GM</span>}
              />
            </Card>
            <Card>
              <MoneyStat
                key={`e-${seed}`}
                label="Awaiting price refresh"
                value={null}
                size="md"
                freshnessState="stale"
                freshnessText="USDA 4d ago — refresh"
              />
            </Card>
            <Card>
              <MoneyStat
                key={`f-${seed}`}
                label="Saved by Top Moves"
                value={920}
                tone="positive"
                size="md"
                icon={<TrendingUp size={11} strokeWidth={2.5} />}
                footer={<DollarDelta value={120} comparison="vs last week" size="sm" />}
              />
            </Card>
          </Grid>
        </Section>

        <Section title="DollarDelta">
          <Card>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
              <Row label="Revenue up (positive)"><DollarDelta value={1240} comparison="vs last week" /></Row>
              <Row label="Revenue down (negative)"><DollarDelta value={-340} comparison="vs last week" /></Row>
              <Row label="Cost down (good, goodWhen=down)"><DollarDelta value={-120} goodWhen="down" comparison="food cost" /></Row>
              <Row label="Cost up (bad, goodWhen=down)"><DollarDelta value={86} goodWhen="down" comparison="food cost" /></Row>
              <Row label="Neutral zero"><DollarDelta value={0} comparison="no change" /></Row>
              <Row label="Cents precision"><DollarDelta value={12.5} precision="cents" comparison="margin" /></Row>
              <Row label="Small / no icon"><DollarDelta value={86} size="sm" hideIcon /></Row>
              <Row label="Large"><DollarDelta value={4280} size="lg" comparison="month-to-date" /></Row>
            </div>
          </Card>
        </Section>

        <Section title="FreshnessChip">
          <Card>
            <div className="flex flex-wrap gap-2 items-center">
              <FreshnessChip timestamp={ts.fresh} label="synced" />
              <FreshnessChip timestamp={ts.aging} label="POS" />
              <FreshnessChip timestamp={ts.stale} label="USDA prices" />
              <FreshnessChip state="fresh" text="just synced" />
              <FreshnessChip state="aging" text="draft saved" />
              <FreshnessChip state="stale" text="needs refresh" />
              <FreshnessChip state="fresh" text="xs size" size="xs" />
            </div>
          </Card>
        </Section>

        <Section title="Tokens">
          <Card>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
              <Swatch name="--money-positive" />
              <Swatch name="--money-negative" />
              <Swatch name="--money-neutral" />
              <Swatch name="--carafe-accent" />
              <Swatch name="--carafe-accent-dark" />
              <Swatch name="--carafe-accent-light" />
              <Swatch name="--fresh-fresh" />
              <Swatch name="--fresh-aging" />
              <Swatch name="--fresh-stale" />
            </div>
          </Card>
        </Section>

        <footer className="pt-4 pb-12 text-xs flex items-center gap-2" style={{ color: 'var(--slate)' }}>
          <TrendingDown size={12} />
          No purple→pink gradients, no glassmorphism, no 24px+ radii — honest dollar numbers only.
        </footer>
      </div>
    </div>
  );
}

function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  try { localStorage.setItem('gd_theme', next); } catch {}
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="text-sm font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {title}
      </h2>
      {children}
    </section>
  );
}

function Grid({ children }: { children: React.ReactNode }) {
  return <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">{children}</div>;
}

function Card({ children }: { children: React.ReactNode }) {
  return (
    <div
      className="rounded-xl p-5"
      style={{ background: 'var(--bg-panel)', border: '1px solid var(--line-soft)' }}
    >
      {children}
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div
      className="flex items-center justify-between gap-3 py-1 border-b"
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <span className="text-xs" style={{ color: 'var(--slate)' }}>{label}</span>
      {children}
    </div>
  );
}

function Swatch({ name }: { name: string }) {
  return (
    <div className="flex items-center gap-2">
      <span
        className="inline-block rounded"
        style={{ width: 24, height: 24, background: `var(${name})`, border: '1px solid var(--line-soft)' }}
      />
      <code style={{ color: 'var(--body)' }}>{name}</code>
    </div>
  );
}
