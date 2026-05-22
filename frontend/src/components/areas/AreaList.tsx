import { useState } from 'react';
import { useProjectStore } from '../../stores/projectStore';
import AreaCard from './AreaCard';

export default function AreaList() {
  const { areas } = useProjectStore();
  const [search, setSearch] = useState('');

  const filtered = areas.filter((a) => !search || a.name.toLowerCase().includes(search.toLowerCase()));

  return (
    <div className="px-3 py-2">
      <input
        className="input mb-2"
        placeholder="Search areas…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />
      {filtered.length === 0 ? (
        <div className="text-sm text-slate-400 text-center py-6">
          No areas yet. Click <b>Area</b> to add one.
        </div>
      ) : (
        <div className="space-y-1">
          {filtered.map((a) => <AreaCard key={a.id} area={a} />)}
        </div>
      )}
    </div>
  );
}
