import type { Folder } from '../../types';

export default function FolderTree({ folders }: { folders: Folder[] }) {
  if (!folders?.length) return null;
  return (
    <ul className="px-3 py-2 space-y-1">
      {folders.map((f) => (
        <li key={f.id} className="text-sm">
          <div className="flex items-center gap-2 px-1 py-1 rounded hover:bg-slate-50">
            <span className="w-2 h-2 rounded-full" style={{ background: f.color }} />
            <span className="flex-1">{f.name}</span>
            <span className="text-xs text-slate-400">{f.area_count ?? 0}</span>
          </div>
          {f.children && f.children.length > 0 && (
            <div className="pl-4"><FolderTree folders={f.children} /></div>
          )}
        </li>
      ))}
    </ul>
  );
}
