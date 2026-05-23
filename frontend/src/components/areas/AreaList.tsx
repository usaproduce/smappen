import { useState } from 'react';
import { Search, X } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import AreaCard from './AreaCard';

type Sort = 'recent' | 'name' | 'time';

export default function AreaList() {
  const { areas } = useProjectStore();
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<Sort>('recent');

  const filtered = areas
    .filter((a) => !search || a.name.toLowerCase().includes(search.toLowerCase()))
    .sort((a, b) => {
      if (sort === 'name') return a.name.localeCompare(b.name);
      if (sort === 'time') return (a.travel_time_minutes ?? 0) - (b.travel_time_minutes ?? 0);
      return 0; // 'recent' = store insertion order
    });

  return (
    <div className="py-1">
      <div className="px-3 mb-2 relative">
        <Search size={13} className="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
        <input
          className="input pl-7 pr-7 h-9 text-sm"
          placeholder="Search areas or folder…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        {search && (
          <button
            className="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-0.5"
            onClick={() => setSearch('')}
            title="Clear"
          >
            <X size={12} />
          </button>
        )}
      </div>

      <div className="flex items-center gap-1 px-3 pb-2">
        <span className="text-[10px] uppercase tracking-wider font-bold text-slate-400 mr-1">Sort</span>
        {(['recent', 'name', 'time'] as Sort[]).map((s) => (
          <button
            key={s}
            onClick={() => setSort(s)}
            className={`text-[11px] px-2 py-0.5 rounded-full font-semibold capitalize ${
              sort === s ? 'bg-violet-100 text-violet-700' : 'text-slate-500 hover:bg-slate-100'
            }`}
          >
            {s}
          </button>
        ))}
        {filtered.length > 0 && (
          <span className="ml-auto text-[11px] text-slate-400 font-medium">{filtered.length}/{areas.length}</span>
        )}
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
