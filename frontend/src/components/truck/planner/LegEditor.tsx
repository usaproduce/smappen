import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import type { DriveLeg } from '../../../api/truck';
import { fmtMoneyCents } from '../../../utils/truck/format';
import type { LatLng } from '../../../utils/truck/model';
import { legEstimateLine } from '../../../utils/truck/planDraft';
import { driveFallbackReason } from '../../../utils/truck/wording';
import { useDeleteLegOverride, useSaveLegOverride } from '../data';
import { Modal, MoneyField, NumberField } from '../ui';

export interface LegEditorProps {
  open: boolean;
  onClose: () => void;
  /** The two ends in words: "base", or the name of a stop. */
  fromName: string;
  toName: string;
  from: LatLng;
  to: LatLng;
  /** The leg as the server sent it, with the owner's correction when there is one; null when it sent none. */
  sent: DriveLeg | null;
  /** The region's traffic table changes nothing: the wording drops the time-of-day adjustment. */
  trafficNeutral: boolean;
}

/**
 * The owner's own time and toll for one drive (docs/truck-planner/05_FRONTEND.md 4.5). The time
 * replaces the estimate at every hour; the toll replaces Google's estimate. Both belong to the pair
 * of places, so the correction reaches every day that uses this drive. "Use the estimate" removes
 * the correction again.
 *
 * Mount it with a key per drive: it reads the correction once, when it opens.
 */
export default function LegEditor({ open, onClose, fromName, toName, from, to, sent, trafficNeutral }: LegEditorProps) {
  const formId = useId();
  const override = sent === null ? null : sent.override;
  const [minutes, setMinutes] = useState<number | null>(override === null ? null : override.minutes);
  const [toll, setToll] = useState<number | null>(override === null ? null : override.toll);
  const save = useSaveLegOverride();
  const remove = useDeleteLegOverride();

  // A correction that was just entered has no id until the server answers: it cannot be removed yet.
  const overrideId = override !== null && override.id !== '' ? override.id : null;
  const reason = sent !== null && sent.source === 'straight_line' ? driveFallbackReason(sent.fallback_reason) : null;
  const googleToll = sent !== null && sent.toll_state === 'estimate' && sent.google_toll !== null ? sent.google_toll : null;

  const dropCorrection = () => {
    if (overrideId !== null) remove.mutate(overrideId);
    onClose();
  };

  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // A field that refused what was typed still shows it: nothing is saved over the owner's head.
    const refused = e.currentTarget.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (refused !== null) {
      refused.focus();
      return;
    }
    const same = override !== null && override.minutes === minutes && override.toll === toll;
    if (same || (override === null && minutes === null && toll === null)) {
      onClose();
      return;
    }
    // Both left empty: there is nothing of the owner's left, so the correction goes.
    if (minutes === null && toll === null) {
      dropCorrection();
      return;
    }
    save.mutate({ from, to, minutes, toll });
    onClose();
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={'Drive from ' + fromName + ' to ' + toName}
      size="sm"
      footer={
        <>
          <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onClose}>
            Cancel
          </button>
          {override !== null ? (
            <button
              type="button"
              className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-50 disabled:cursor-not-allowed"
              onClick={dropCorrection}
              disabled={overrideId === null}
            >
              Use the estimate
            </button>
          ) : null}
          <button type="submit" form={formId} className="btn btn-primary h-11 md:h-9 px-4 text-sm">
            Save
          </button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} className="space-y-4" noValidate>
        <div>
          <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {legEstimateLine(sent, trafficNeutral)}
          </p>
          {reason !== null ? (
            <p className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {reason}
            </p>
          ) : null}
        </div>
        <NumberField
          id={formId + 'minutes'}
          label="It takes me"
          value={minutes}
          onCommit={setMinutes}
          integer
          min={1}
          max={600}
          suffix="min"
          help="Your own time replaces the estimate at every hour."
        />
        <MoneyField
          id={formId + 'toll'}
          label="Toll"
          value={toll}
          onCommit={setToll}
          min={0}
          max={500}
          placeholder={googleToll !== null ? "Google's estimate: " + fmtMoneyCents(googleToll) : undefined}
          help="Google's estimate is used when you leave this empty."
        />
      </form>
    </Modal>
  );
}
