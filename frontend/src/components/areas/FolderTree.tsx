import type { Folder } from '../../types';

export default function FolderTree({ folders }: { folders: Folder[] }) {
  if (!folders?.length) return null;
  return (
    <ul className="px-3 py-2 space-y-1">
      {folders.map((f) => (
        <li key={f.id} className="text-sm">
          {/* VT14 — left-edge color stripe matching folder color, so a glance
              identifies the folder. Replaces the small color dot for stronger
              visual grouping in deep folder trees. */}
          <div
            className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-50 border-l-[3px] transition-colors"
            style={{ borderLeftColor: f.color || '#7848BB' }}
          >
            <span className="w-2 h-2 rounded-full shrink-0" style={{ background: f.color }} />
            <span className="flex-1 truncate" style={{ color: '#1A1A2E' }}>{f.name}</span>
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
