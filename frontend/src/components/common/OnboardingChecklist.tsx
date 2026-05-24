import { useEffect, useState } from 'react';
import { Check, X } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';

/**
 * OP24 — dismissible card showing "5 steps to first useful map" for new
 * accounts. Auto-completes steps as the user makes progress (e.g., creates
 * an area). Stays hidden once the user dismisses it.
 */
export default function OnboardingChecklist() {
  const { areas } = useProjectStore() as any;
  const onboardingCompleted = useUiPrefsStore((s) => s.onboardingCompleted);
  const setOnboardingCompleted = useUiPrefsStore((s) => s.setOnboardingCompleted);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setOpen(!onboardingCompleted);
  }, [onboardingCompleted]);

  if (!open) return null;

  const hasArea       = (areas?.length ?? 0) > 0;
  const hasDemoArea   = (areas ?? []).some((a: any) => a.demographics_cache);
  const hasFavorite   = (areas ?? []).some((a: any) => a.is_favorite);
  const hasTwoAreas   = (areas?.length ?? 0) >= 2;
  const hasFiveAreas  = (areas?.length ?? 0) >= 5;

  const steps = [
    { done: hasArea,      label: 'Create your first area' },
    { done: hasDemoArea,  label: 'Open demographics for an area' },
    { done: hasFavorite,  label: 'Mark an area as favorite' },
    { done: hasTwoAreas,  label: 'Add a second area' },
    { done: hasFiveAreas, label: 'Build out 5 areas' },
  ];
  const doneCount = steps.filter((s) => s.done).length;

  return (
    <div className="absolute bottom-4 right-32 z-20 bg-white rounded-xl shadow-float border border-violet-200 w-[280px] panel-slide-up">
      <div className="flex items-center justify-between px-3 py-2 border-b border-slate-100">
        <div>
          <div className="text-[10px] uppercase tracking-wider font-bold text-violet-700">Getting started</div>
          <div className="text-xs text-slate-500">{doneCount} of {steps.length} done</div>
        </div>
        <button
          onClick={() => { setOnboardingCompleted(true); setOpen(false); }}
          className="text-slate-400 hover:text-slate-700 p-1"
          title="Dismiss"
        >
          <X size={14} />
        </button>
      </div>
      <div className="h-1 bg-slate-100">
        <div className="h-full bg-violet-500 transition-all" style={{ width: `${(doneCount / steps.length) * 100}%` }} />
      </div>
      <ul className="p-2 space-y-1">
        {steps.map((s) => (
          <li key={s.label} className="flex items-center gap-2 px-2 py-1.5 text-xs rounded">
            <span className={`w-4 h-4 rounded-full flex items-center justify-center shrink-0 ${
              s.done ? 'bg-emerald-500 text-white' : 'bg-slate-100 border border-slate-300'
            }`}>
              {s.done && <Check size={10} />}
            </span>
            <span className={s.done ? 'text-slate-400 line-through' : 'text-slate-700 font-medium'}>{s.label}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
