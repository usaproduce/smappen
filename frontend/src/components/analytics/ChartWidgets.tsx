import { BarChart, Bar, XAxis, YAxis, ResponsiveContainer, Cell, PieChart, Pie, Tooltip } from 'recharts';

const COLORS = ['#6B4EFF', '#3b82f6', '#22c55e', '#f59e0b', '#ec4899', '#06b6d4', '#ef4444'];

function HorizontalBarChart({ data }: { data: { name: string; value: number }[] }) {
  const total = data.reduce((s, d) => s + (d.value ?? 0), 0);
  return (
    <ResponsiveContainer width="100%" height={Math.max(120, data.length * 28)}>
      <BarChart data={data} layout="vertical" margin={{ left: 10, right: 30 }}>
        <YAxis dataKey="name" type="category" width={70} tick={{ fontSize: 11 }} />
        <XAxis type="number" hide />
        <Tooltip formatter={(v: number) => `${v.toLocaleString()}${total > 0 ? ` (${((v / total) * 100).toFixed(1)}%)` : ''}`} />
        <Bar dataKey="value" radius={[4, 4, 4, 4]}>
          {data.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}

function DonutChart({ data, total }: { data: { name: string; value: number; color?: string }[]; total?: number }) {
  const sum = total ?? data.reduce((s, d) => s + d.value, 0);
  return (
    <div className="relative" style={{ width: '100%', height: 200 }}>
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={data} innerRadius={60} outerRadius={80} dataKey="value">
            {data.map((d, i) => <Cell key={i} fill={d.color ?? COLORS[i % COLORS.length]} />)}
          </Pie>
          <Tooltip />
        </PieChart>
      </ResponsiveContainer>
      <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
        <div className="text-center">
          <div className="text-2xl font-bold">{sum.toLocaleString()}</div>
        </div>
      </div>
    </div>
  );
}

function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="card">
      <div className="text-xs text-slate-500 uppercase font-semibold">{label}</div>
      <div className="text-2xl font-bold" style={{ color: '#1e3a5f' }}>{value}</div>
      {hint && <div className="text-xs text-slate-500 mt-1">{hint}</div>}
    </div>
  );
}

const ChartWidgets = { HorizontalBarChart, DonutChart, StatCard };
export default ChartWidgets;
