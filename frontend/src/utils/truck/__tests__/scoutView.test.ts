// Truck Planner - Scout: what a card and the "Save as spot" dialog decide without the DOM
// (docs/truck-planner/05_FRONTEND.md 4.8 and the row of this file in 8.2).
//
// Written from the specification, not from scoutView.ts: every sentence asserted here is one 4.8
// prints.

import { describe, expect, it } from 'vitest';
import { SEEDS } from '../model';
import type { ScoutResult, SegmentKey } from '../model';
import {
  SAVE_LEAD,
  SCOUT,
  cardView,
  hostFitSentence,
  hostFitWord,
  kitchenSentence,
  leadSpotBody,
  saveLeadErrors,
  saveLeadFields,
  sizeCase,
  sizeSentence,
  sizeUnitPhrase,
  type SaveLeadDraft,
  type ScoutLead,
} from '../scoutView';
import { HOST_SIZE_LABEL, SIZE_UNIT_PHRASE, placeTypeLabel } from '../wording';
import { spec } from './_kitFixtures';

/** A scouted taproom as route 38 answers it: a host with a typical size. */
function resultOf(patch: Partial<ScoutResult> = {}): ScoutResult {
  return {
    place_id: 'w264230766',
    place_type: 'taproom',
    position: 1,
    host_fit: 1.0,
    kitchen: 'no',
    host_segment: 'v_nightlife',
    host_size: 40,
    size_source: 'default',
    best_window: { dow: 5, open_minute: 1020, close_minute: 1200 },
    orders: { value: 39.4, low: 12.1, high: 81.3, confidence: 'very_rough' },
    contribution: { value: 310.2, low: 80.4, high: 690.7, confidence: 'very_rough' },
    round_trip: { minutes: 27, miles: 10.3, cost: 22.6 },
    score: 287.6,
    ...patch,
  };
}

/** The lead of a place: untouched, unless the patch says otherwise. */
function leadOf(patch: Partial<ScoutLead> = {}): ScoutLead {
  return { id: null, place_key: 'w264230766', status: 'new', notes: null, spot_id: null, google: null, ...patch };
}

/** The three size cases of 4.8, each as the model's seeds really produce it. */
const TYPICAL = resultOf(); // host_size above 0
const NEEDED = resultOf({ place_type: 'office_park', host_segment: 'w_office', host_size: 0 }); // host_size 0 with a host_segment
const NO_HOST = resultOf({ place_type: 'farmers_market', host_segment: null, host_size: 0 }); // host_segment null

const NO_TYPICAL_SIZE = 'No typical size for this kind of place. Ranked on the people nearby.';

// -------------------------------------------------------------------------------------------------

describe('host fit as a word', () => {
  it('reads rarely below 0.4, sometimes from 0.4 and often from 0.7', () => {
    expect(spec(hostFitWord(0.39))).toBe('rarely');
    expect(spec(hostFitWord(0.4))).toBe('sometimes');
    expect(spec(hostFitWord(0.69))).toBe('sometimes');
    expect(spec(hostFitWord(0.7))).toBe('often');
    // The ends of the scale.
    expect(hostFitWord(0)).toBe('rarely');
    expect(hostFitWord(1)).toBe('often');
  });

  it('is said as a rule of thumb about the kind of place, in the sentence of 4.8', () => {
    expect(spec(hostFitSentence(0.7))).toBe('Our rule of thumb: this kind of place often hosts trucks.');
    expect(spec(hostFitSentence(0.4))).toBe('Our rule of thumb: this kind of place sometimes hosts trucks.');
    expect(spec(hostFitSentence(0.39))).toBe('Our rule of thumb: this kind of place rarely hosts trucks.');
  });

  it('the seed file gives every kind of place a host fit the words can take', () => {
    for (const kind of SEEDS.place_types.order) {
      const fit = SEEDS.place_types.rows[kind].host_fit;
      expect(['often', 'sometimes', 'rarely'], kind).toContain(hostFitWord(fit));
    }
    // A taproom is the model's most likely host; a transit station one of its least likely.
    expect(hostFitWord(SEEDS.place_types.rows.taproom.host_fit)).toBe('often');
    expect(hostFitWord(SEEDS.place_types.rows.transit_station.host_fit)).toBe('rarely');
  });
});

describe('the three kitchen sentences', () => {
  const none = kitchenSentence('no', 'no');
  const own = kitchenSentence('yes', 'yes');
  const assumedOwn = kitchenSentence('unknown', 'yes');
  const assumedNone = kitchenSentence('unknown', 'no');

  it("a kitchen the place's record states is said as stated, whatever the estimate assumed", () => {
    expect(kitchenSentence('no', 'yes')).toBe(none);
    expect(kitchenSentence('yes', 'no')).toBe(own);
    expect(none).not.toBe(own);
    expect(none.startsWith('No kitchen of its own')).toBe(true);
    expect(own.startsWith('Has its own kitchen')).toBe(true);
  });

  it('an unknown kitchen says that it is unknown and which way the estimate assumed it', () => {
    expect(assumedOwn.startsWith('Kitchen unknown')).toBe(true);
    expect(assumedNone.startsWith('Kitchen unknown')).toBe(true);
    expect(assumedOwn).not.toBe(assumedNone);
    expect(new Set([none, own, assumedOwn, assumedNone]).size).toBe(4);
    // A record that says nothing at all is an unknown kitchen too.
    expect(kitchenSentence(null, 'yes')).toBe(assumedOwn);
    expect(kitchenSentence(undefined, 'no')).toBe(assumedNone);
  });

  it('no kitchen, in the words of 4.8', () => {
    expect(spec(none)).toBe('No kitchen of its own.');
  });

  it('its own kitchen, in the words of 4.8', () => {
    expect(spec(own)).toBe('Has its own kitchen.');
  });

  it('an unknown kitchen, in the words of 4.8', () => {
    expect(spec(assumedOwn)).toBe('Kitchen unknown. Assumed to have its own, as most places of this kind do.');
    expect(spec(assumedNone)).toBe('Kitchen unknown. Assumed to have none, as most places of this kind do.');
  });
});

describe('the size phrase by segment group', () => {
  it('visitors, workers and residents each have their own (6.8)', () => {
    expect(SEEDS.segments.v_nightlife.group).toBe('visitors');
    expect(SEEDS.segments.w_office.group).toBe('workers');
    expect(SEEDS.segments.res.group).toBe('residents');
    expect(spec(sizeUnitPhrase('v_nightlife'))).toBe('people in its busiest hour');
    expect(spec(sizeUnitPhrase('w_office'))).toBe('people working there');
    expect(spec(sizeUnitPhrase('res'))).toBe('people living there');
  });

  it('every segment of the seed file takes the phrase of its group', () => {
    const keys = Object.keys(SEEDS.segments) as SegmentKey[];
    expect(keys).toHaveLength(16);
    for (const key of keys) expect(sizeUnitPhrase(key), key).toBe(SIZE_UNIT_PHRASE[SEEDS.segments[key].group]);
  });
});

describe('the three size cases', () => {
  it('are the cases the seed file produces', () => {
    const rows = SEEDS.place_types.rows;
    expect(rows.taproom.host_segment).toBe('v_nightlife');
    expect(rows.taproom.default_size).toBe(40);
    expect(rows.office_park.host_segment).toBe('w_office');
    expect(rows.office_park.default_size).toBe(0);
    expect(rows.farmers_market.host_segment).toBeNull();
    expect(sizeCase(TYPICAL)).toBe('typical');
    expect(sizeCase(NEEDED)).toBe('needed');
    expect(sizeCase(NO_HOST)).toBe('none');
  });

  describe('on the card', () => {
    it('a host size above 0 is said as an assumed size, with the unit of its segment', () => {
      expect(sizeSentence(TYPICAL).startsWith('Size assumed: 40 people in its busiest hour')).toBe(true);
      const workers = resultOf({ place_type: 'office_park', host_segment: 'w_office', host_size: 1250 });
      expect(sizeSentence(workers).startsWith('Size assumed: 1,250 people working there')).toBe(true);
      const residents = resultOf({ place_type: 'apartment_community', host_segment: 'res', host_size: 300 });
      expect(sizeSentence(residents).startsWith('Size assumed: 300 people living there')).toBe(true);
    });

    // The sentence does not name the kind of place: the card's own line above it does, and a label
    // such as "Events venue" would not take "a" in front of it.
    it('a host size above 0, in the words of 4.8', () => {
      expect(placeTypeLabel(TYPICAL.place_type)).toBe('Brewery or taproom');
      expect(spec(sizeSentence(TYPICAL))).toBe('Size assumed: 40 people in its busiest hour, a typical figure for this kind of place.');
    });

    it('a host size of 0 says there is no typical size and what the place was ranked on', () => {
      expect(spec(sizeSentence(NEEDED))).toBe(NO_TYPICAL_SIZE);
    });

    it('a kind of place without a host says the same: its host size is 0', () => {
      expect(NO_HOST.host_size).toBe(0);
      expect(spec(sizeSentence(NO_HOST))).toBe(NO_TYPICAL_SIZE);
    });
  });

  describe('in the save dialog', () => {
    const draft = (patch: Partial<SaveLeadDraft> = {}): SaveLeadDraft => ({ name: 'Example Brewing', size: null, onlyFood: true, visibility: 'normal', ...patch });

    it('a host size above 0: the size is optional, with the typical size as the placeholder', () => {
      const fields = saveLeadFields(TYPICAL);
      expect(fields.size).toBe('optional');
      expect(fields.typical).toBe(40);
      expect(fields.sizeLabel).toBe(HOST_SIZE_LABEL.visitors);
      expect(fields.onlyFood).toBe(true);
      expect(fields.note).toBeNull();
      // Left empty, nothing holds the save back and no size travels: the typical one stands in.
      expect(saveLeadErrors(draft(), fields)).toEqual({});
      expect(leadSpotBody(draft(), fields)).toEqual({ name: 'Example Brewing', visibility: 'normal', only_food: true });
      // The owner's own figure travels as typed.
      expect(leadSpotBody(draft({ size: 80, onlyFood: false }), fields)).toEqual({ name: 'Example Brewing', visibility: 'normal', host_size: 80, only_food: false });
    });

    it('a host size of 0 with a host segment: the size is required', () => {
      const fields = saveLeadFields(NEEDED);
      expect(fields.size).toBe('required');
      expect(fields.typical).toBeNull();
      expect(fields.sizeLabel).toBe(HOST_SIZE_LABEL.workers);
      expect(fields.onlyFood).toBe(true);
      expect(fields.note).toBeNull();
      expect(saveLeadErrors(draft(), fields).size).toBe(SAVE_LEAD.sizeRequired);
      expect(saveLeadErrors(draft({ size: 600 }), fields)).toEqual({});
      expect(leadSpotBody(draft({ size: 600 }), fields)).toEqual({ name: 'Example Brewing', visibility: 'normal', host_size: 600, only_food: true });
    });

    it('no host segment: neither the size field nor the only-food switch, and the dialog says why', () => {
      const fields = saveLeadFields(NO_HOST);
      expect(fields.size).toBe('none');
      expect(fields.sizeLabel).toBeNull();
      expect(fields.typical).toBeNull();
      expect(fields.onlyFood).toBe(false);
      expect(spec(fields.note)).toBe('Saved without a host. You can describe one on the spot page.');
      // Whatever the draft holds, neither travels.
      expect(saveLeadErrors(draft(), fields)).toEqual({});
      expect(leadSpotBody(draft({ size: 80, onlyFood: true }), fields)).toEqual({ name: 'Example Brewing', visibility: 'normal' });
    });

    it('the switch is "Your truck is the only food here"', () => {
      expect(spec(SAVE_LEAD.onlyFoodLabel)).toBe('Your truck is the only food here');
    });
  });
});

describe('a card whose place is already a saved spot', () => {
  const saved = cardView({ lead: leadOf({ id: 'lead-1', status: 'contacted', spot_id: 'd9b3c1e2-0000-4000-8000-000000000001' }), result: TYPICAL });

  it('shows no estimates, no size line and no "Why this number"', () => {
    expect(saved.saved).toBe(true);
    expect(saved.showEstimates).toBe(false);
    expect(saved.showSize).toBe(false);
    expect(saved.canWhy).toBe(false);
  });

  it('does not offer "Save as spot" again, and says where the estimate is', () => {
    expect(saved.canSave).toBe(false);
    expect(spec(SCOUT.savedLine)).toBe('Saved as a spot. Open it for the estimate that uses your size and your logged results.');
    expect(spec(SCOUT.openSpot)).toBe('Open spot');
  });

  it('a place that is not saved shows all of them', () => {
    expect(cardView({ lead: leadOf(), result: TYPICAL })).toEqual({ saved: false, showEstimates: true, showSize: true, canWhy: true, canSave: true });
    // A lead the owner has touched but not saved is no different.
    expect(cardView({ lead: leadOf({ id: 'lead-2', status: 'shortlisted', notes: 'Call back Tuesday' }), result: TYPICAL }).saved).toBe(false);
  });

  it('"Why this number" needs a best window, saved or not', () => {
    const without = resultOf({ best_window: null });
    expect(cardView({ lead: leadOf(), result: without }).canWhy).toBe(false);
    expect(cardView({ lead: leadOf(), result: without }).canSave).toBe(true);
    expect(cardView({ lead: leadOf({ spot_id: 'd9b3c1e2-0000-4000-8000-000000000001' }), result: without }).canWhy).toBe(false);
  });
});
