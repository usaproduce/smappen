import { useState } from 'react';
import { Search } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import AreaCard from './AreaCard';

export default function AreaList() {
  const { areas } = useProjectStore();
  const [search, setSearch] = useState('');

  const filtered = areas.filter((a) => !search || a.name.toLowerCase().includes(search.toLowerCase()));

  return (
    <div className="py-1">
      <div className="px-3 mb-2 relative">
        <Search size={13} className="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
        <input
          className="input pl-7 h-9 text-sm"
          placeholder="Search areas…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>
      {filtered.length === 0 ? (
        <div className="text-sm text-slate-400 text-center py-8 px-4">
          {search
            ? <>No areas match <b>"{search}"</b>.</>
            : <>No areas yet. Click <b>Create new area</b> above.</>}
        </div>
      ) : (
        <div>
          {filtered.map((a) => <AreaCard key={a.id} area={a} />)}
        </div>
      )}
    </div>
  );
}
