import { useCallback } from 'react';
import toast from 'react-hot-toast';
import { recommendationsApi } from '../../api/restaurants';
import { useRestaurantStore, type Recommendation } from '../../stores/restaurantStore';
import { useUndoStore } from '../../stores/undoStore';

/**
 * Optimistic accept/dismiss for recommendations.
 *
 * Both calls:
 *   1) flip the rec's status in restaurantStore immediately (UI updates
 *      in well under 100ms — no waiting on the round-trip),
 *   2) fire the server call,
 *   3) on success: push an UndoAction onto undoStore so Cmd+Z works, and
 *      show a 5s toast with an inline Undo button (the prompt asks for
 *      a brief undo affordance and the undoStore pattern),
 *   4) on failure: roll back the optimistic update + surface a toast.
 *
 * The reverse path flips the local status back. There is no server
 * "revert" endpoint yet; the local store is the source of truth until
 * the next fetch reconciles. That matches the existing optimistic flow
 * MenuPage already uses — this hook just wraps it in undoable form.
 */

export function useRecommendationAction() {
  const updateRecStatus = useRestaurantStore((s) => s.updateRecommendationStatus);
  const undoDo = useUndoStore((s) => s.do);

  const accept = useCallback(
    (rec: Recommendation, opts?: { silent?: boolean }) =>
      decide(rec, 'accepted', updateRecStatus, undoDo, opts?.silent),
    [updateRecStatus, undoDo],
  );

  const dismiss = useCallback(
    (rec: Recommendation, opts?: { silent?: boolean }) =>
      decide(rec, 'dismissed', updateRecStatus, undoDo, opts?.silent),
    [updateRecStatus, undoDo],
  );

  return { accept, dismiss };
}

type Status = Recommendation['status'];
type StatusSetter = (id: string, status: Status) => void;
type UndoPush = (action: {
  label: string;
  reverse: () => Promise<void> | void;
  forward: () => Promise<void> | void;
}) => void;

async function decide(
  rec: Recommendation,
  to: 'accepted' | 'dismissed',
  setStatus: StatusSetter,
  undoDo: UndoPush,
  silent: boolean | undefined,
): Promise<void> {
  const prevStatus = rec.status;
  setStatus(rec.id, to);

  const callForward = () =>
    to === 'accepted'
      ? recommendationsApi.accept(rec.id)
      : recommendationsApi.dismiss(rec.id);

  try {
    await callForward();
  } catch (e: any) {
    setStatus(rec.id, prevStatus);
    toast.error(
      e?.response?.data?.error
        ?? (to === 'accepted' ? 'Could not accept' : 'Could not dismiss'),
    );
    return;
  }

  let undone = false;
  const reverse = () => {
    if (undone) return;
    undone = true;
    setStatus(rec.id, prevStatus);
  };
  const forward = async () => {
    undone = false;
    setStatus(rec.id, to);
    try { await callForward(); } catch { /* operator can retry */ }
  };
  undoDo({
    label: to === 'accepted' ? 'Accept recommendation' : 'Dismiss recommendation',
    reverse,
    forward,
  });

  if (silent) return;

  toast.custom(
    (t) => (
      <div
        className={'sm-toast ' + (to === 'accepted' ? 'sm-toast-success' : 'sm-toast-info')}
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 12,
          minWidth: 240,
          opacity: t.visible ? 1 : 0,
          transition: 'opacity 160ms ease',
        }}
      >
        <span style={{ fontWeight: 700 }}>
          {to === 'accepted' ? 'Accepted' : 'Dismissed'}
        </span>
        <button
          type="button"
          onClick={() => {
            reverse();
            toast.dismiss(t.id);
          }}
          style={{
            border: '1px solid var(--line)',
            background: 'transparent',
            color: 'var(--brand)',
            cursor: 'pointer',
            marginLeft: 'auto',
            fontWeight: 700,
            fontSize: 12,
            padding: '0 10px',
            height: 32,
            borderRadius: 6,
          }}
        >
          Undo
        </button>
      </div>
    ),
    { duration: 5000 },
  );
}
