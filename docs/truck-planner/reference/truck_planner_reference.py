#!/usr/bin/env python3
"""Truck Planner - reference implementation of the model (tps-0.1.0).

This file is the definition of the math. docs/truck-planner/02_MODEL.md explains it in words and the two
must say the same thing: change one, change the other, regenerate the golden cases, commit all three
together (see README.md next to this file).

What is in here, in the order of the document:

    1    conventions      vocabulary, integer helpers, the one rounding helper, ranking keys
    2    seeds            tp_seeds.json (read from this directory), seed(), validate_overrides()
    3    data shapes      the Estimate helpers
    4    functions        4.1 dates ... 4.17 map fast path, under their canonical names
    8    golden cases     the case list, the self-test, the golden-file writer and checker

Rules the model functions obey (document 1.5): plain Python 3.10+, standard library only; no clock, no
random numbers, no locale, no environment, no time zone; civil dates are computed by hand; sums are plain
left-to-right additions (never the built-in sum(), which is compensated from Python 3.12); every ordering
goes through qkey(); rounding only through round_half_away().

Command line:

    python truck_planner_reference.py --self-test            every golden case + property checks
    python truck_planner_reference.py --write-golden PATH    write the golden file (refuses on any problem; names
                                                             every id of the replaced file that moved)
    python truck_planner_reference.py --check-golden PATH    regenerate in memory and compare with the file
    python truck_planner_reference.py --reach                which lines and branches of the model the cases reach
                                                             (Python 3.11+; branches need 3.12+)
"""

import json
import math
import os
import sys

MODEL_VERSION = "tps-0.1.0"


# =================================================================================================
# 1. Conventions
# =================================================================================================

# 1.1 Fixed vocabulary, in index order. The seed-integrity check compares these with seeds.vocabulary.
SEGMENTS = ["res", "w_office", "w_health", "w_edu", "w_retail", "w_industrial", "w_hospitality", "w_public",
            "v_nightlife", "v_shopping", "v_leisure", "v_campus", "v_hospital", "v_transit", "v_events", "v_lodging"]
REGIMES = ["day", "eve"]
RIVAL_KINDS = ["quick", "full", "cafe", "bar", "convenience"]
DAY_TYPES = ["weekday", "saturday", "sunday"]
DOW_KEYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]           # dow 0 = Monday
DAYPARTS = ["breakfast", "lunch", "dinner", "late"]
VISIBILITY_LEVELS = ["hidden", "normal", "prominent"]
CONFIDENCE_LABELS = ["very_rough", "rough", "fair", "good", "fixed"]     # weakest first
NSEG = 16

# 1.2 Constants, written as literals (never a runtime constant such as math.pi). Also in the seed file
# under constants.*; the seed-integrity check asserts that the two agree.
EARTH_RADIUS_M = 6371008.8
PI = 3.141592653589793
LN2 = 0.6931471805599453
Z80 = 1.2815515655446004
METERS_PER_MILE = 1609.344
ROUND_HALF = 0.500000001
QKEY_SCALE = 1000000.0

# 1.2 "the runtime's double-precision functions". Model code calls them only through these names, so the
# self-test can swap in slightly different ones and prove that no golden case hangs on their last bit (8.1).
exp = math.exp
ln = math.log
sqrt = math.sqrt
sin = math.sin
cos = math.cos
asin = math.asin
floor = math.floor


class ModelError(Exception):
    """An error the document names: invalid_date, invalid_window, missing_context."""

    def __init__(self, code):
        Exception.__init__(self, code)
        self.code = code


def floor_div(a, b):
    """1.2: floor(a / b) for integers, b > 0, without reals (rounds toward minus infinity)."""
    return a // b


def mod_floor(a, b):
    """1.2: a - b * floor_div(a, b); always in 0 .. b-1."""
    return a - b * floor_div(a, b)


def clamp(x, lo, hi):
    """1.2: lo if x < lo, hi if x > hi, else x."""
    if x < lo:
        return lo
    if x > hi:
        return hi
    return x


# 1.4 Rounding ------------------------------------------------------------------------------------

POW10 = [1.0, 10.0, 100.0, 1000.0, 10000.0, 100000.0, 1000000.0, 10000000.0, 100000000.0, 1000000000.0]


def round_half_away(x, decimals):
    """1.4: the only rounding helper. Half away from zero, with a 1e-9 nudge so that decimal halves that
    binary cannot represent (2.675) round the way a person expects. Never returns negative zero."""
    p = POW10[decimals]
    a = abs(x) * p
    n = floor(a + 0.500000001)
    r = n / p
    if x < 0 and r != 0:
        return -r
    return r


def qkey(x):
    """1.4: ranking key in whole millionths (an integer). Every comparison that decides an order or a tie
    uses it; raw reals are never compared for ordering."""
    return floor(x * 1000000.0 + 0.5)


# =================================================================================================
# 2. Seeds
# =================================================================================================

SEEDS_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tp_seeds.json")


def load_seeds(path=SEEDS_PATH):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


SEEDS = load_seeds()

REGION_DC = {"id": "dc", "traffic_matrix": "dc", "flags": {"inauguration_day": True}}
REGION_NONE = {"id": "none", "traffic_matrix": "us_mean", "flags": {"inauguration_day": False}}


def make_assumptions(overrides=None, region=None):
    """3: an Assumptions record around the seed file. With no arguments this is A_dc of the document."""
    return {"model_version": MODEL_VERSION, "seeds_revision": SEEDS["seeds_revision"], "seeds": SEEDS,
            "overrides": {} if overrides is None else overrides,
            "region": REGION_DC if region is None else region}


def seed(A, path):
    """2.1: read a seed by its dot-separated path. An override under exactly that path wins."""
    if path in A["overrides"]:
        return A["overrides"][path]
    node = A["seeds"]
    for key in path.split("."):
        node = node[key]                      # a missing key is a programming error
    if isinstance(node, dict) and "value" in node:
        return node["value"]
    return node


def _is_number(x):
    return isinstance(x, (int, float)) and not isinstance(x, bool)


def _is_finite_number(x):
    """A number with a finite binary64 value. An integer too large for a double (a JSON literal of 310
    digits, which the other runtimes read as infinity) is not one."""
    if not _is_number(x):
        return False
    try:
        return math.isfinite(float(x))
    except OverflowError:
        return False


def require_number(x, name):
    """7 "A missing number": stop on a value that is not a number (null, a boolean, a string). A
    programming error of the caller, not a model error: it has no code and no golden case."""
    if not _is_number(x):
        raise TypeError(name + " must be a number")
    return x


def _same_shape(value, target):
    """2.2 step 5: number for number (finite), string for string, array of the same length whose elements
    have the same type as the seed's."""
    if _is_number(target):
        return _is_finite_number(value)
    if isinstance(target, str):
        return isinstance(value, str)
    if isinstance(target, bool):
        return isinstance(value, bool)
    if isinstance(target, list):
        if not isinstance(value, list) or len(value) != len(target):
            return False
        for k in range(len(target)):
            if isinstance(target[k], (list, dict)) or not _same_shape(value[k], target[k]):
                return False
        return True
    return False


def _override_error(seeds, path, value):
    structural = seeds["vocabulary"]["structural_keys"]
    keys = path.split(".")
    node = seeds
    inherited = {}                            # scope, min, max, allowed of the nearest enclosing object
    for key in keys:
        if not isinstance(node, dict) or key not in node:
            return "unknown_path"                                              # step 1
        for name in ("scope", "min", "max", "allowed"):
            if name in node:
                inherited[name] = node[name]
        node = node[key]
    if isinstance(node, dict):
        for name in ("scope", "min", "max", "allowed"):
            if name in node:
                inherited[name] = node[name]
    if keys[len(keys) - 1] in structural:
        return "not_a_seed"                                                    # step 2
    if inherited.get("scope") != "owner":
        return "not_overridable"                                               # step 3
    target = node["value"] if isinstance(node, dict) and "value" in node else node
    if isinstance(target, dict):
        return "not_a_leaf"                                                    # step 4
    if not _same_shape(value, target):
        return "wrong_shape"                                                   # step 5
    items = value if isinstance(value, list) else [value]
    for x in items:                                                            # step 6
        if _is_number(x):
            # As binary64 values on both sides: a whole number beyond 2^53 is the double it is read as,
            # not the integer Python would otherwise compare exactly.
            if "min" in inherited and float(x) < float(inherited["min"]):
                return "out_of_bounds"
            if "max" in inherited and float(x) > float(inherited["max"]):
                return "out_of_bounds"
    for x in items:                                                            # step 7
        if isinstance(x, str) and "allowed" in inherited and x not in inherited["allowed"]:
            return "not_allowed"
    return None


def validate_overrides(seeds, overrides):
    """2.2: the problems of a sparse override map, one per offending path, ascending path. Empty = valid."""
    problems = []
    for path in sorted(overrides.keys()):
        error = _override_error(seeds, path, overrides[path])
        if error is not None:
            problems.append({"path": path, "error": error})
    return problems


# =================================================================================================
# 3. Data shapes: helpers on Estimate
# =================================================================================================

def est_fixed(x):
    return {"value": x, "low": x, "high": x, "confidence": "fixed"}


def est_levels(v, l, h, c):
    """An Estimate from three levels that may arrive in any order (a cost line falls when orders rise)."""
    return {"value": v, "low": min(min(v, l), h), "high": max(max(v, l), h), "confidence": c}


def weakest(labels):
    """The label earliest in CONFIDENCE_LABELS; "fixed" for an empty list."""
    best = len(CONFIDENCE_LABELS) - 1
    for label in labels:
        i = CONFIDENCE_LABELS.index(label)
        if i < best:
            best = i
    return CONFIDENCE_LABELS[best]


def est_sum(estimates):
    """Lows add to lows and highs to highs, in list order: stops are treated as moving together."""
    value = 0.0
    low = 0.0
    high = 0.0
    labels = []
    for e in estimates:
        value += e["value"]
        low += e["low"]
        high += e["high"]
        labels.append(e["confidence"])
    return {"value": value, "low": low, "high": high, "confidence": weakest(labels)}


# =================================================================================================
# 4.1 Dates
# =================================================================================================

def days_from_civil(y, m, d):
    """Days since 1970-01-01 of a proleptic Gregorian civil date. Integers only."""
    y2 = y - 1 if m <= 2 else y
    era = floor_div(y2, 400)
    yoe = y2 - era * 400
    mp = mod_floor(m + 9, 12)                 # March = 0 ... February = 11
    doy = floor_div(153 * mp + 2, 5) + d - 1
    doe = yoe * 365 + floor_div(yoe, 4) - floor_div(yoe, 100) + doy
    return era * 146097 + doe - 719468


def civil_from_days(z):
    """The inverse of days_from_civil: (y, m, d)."""
    z = z + 719468
    era = floor_div(z, 146097)
    doe = z - era * 146097
    yoe = floor_div(doe - floor_div(doe, 1460) + floor_div(doe, 36524) - floor_div(doe, 146096), 365)
    y = yoe + era * 400
    doy = doe - (365 * yoe + floor_div(yoe, 4) - floor_div(yoe, 100))
    mp = floor_div(5 * doy + 2, 153)
    d = doy - floor_div(153 * mp + 2, 5) + 1
    m = mp + 3 if mp < 10 else mp - 9
    return (y + 1 if m <= 2 else y, m, d)


def parse_date(s):
    """Exactly "YYYY-MM-DD" in ASCII digits, a real date, 1970 <= y <= 2199; otherwise invalid_date."""
    ok = isinstance(s, str) and len(s) == 10 and s[4] == "-" and s[7] == "-"
    if ok:
        for i in (0, 1, 2, 3, 5, 6, 8, 9):
            if not ("0" <= s[i] <= "9"):
                ok = False
    if not ok:
        raise ModelError("invalid_date")
    y = int(s[0:4])
    m = int(s[5:7])
    d = int(s[8:10])
    if y < 1970 or y > 2199 or civil_from_days(days_from_civil(y, m, d)) != (y, m, d):
        raise ModelError("invalid_date")
    return (y, m, d)


def format_date(y, m, d):
    return "%04d-%02d-%02d" % (y, m, d)


def _day_number(date):
    (y, m, d) = parse_date(date)
    return days_from_civil(y, m, d)


def _date_of_day(z):
    (y, m, d) = civil_from_days(z)
    return format_date(y, m, d)


def day_of_week(date):
    """0 = Monday ... 6 = Sunday. The only source of a day of the week in the model."""
    return mod_floor(_day_number(date) + 3, 7)


def add_days(date, n):
    return _date_of_day(_day_number(date) + n)


# Federal holidays ----------------------------------------------------------------------------------

def _nth_weekday_day(year, month, dow, n):
    first = days_from_civil(year, month, 1)
    return first + mod_floor(dow - mod_floor(first + 3, 7), 7) + 7 * (n - 1)


def _last_weekday_day(year, month, dow):
    next_first = days_from_civil(year + 1, 1, 1) if month == 12 else days_from_civil(year, month + 1, 1)
    last = next_first - 1
    return last - mod_floor(mod_floor(last + 3, 7) - dow, 7)


def nth_weekday(year, month, dow, n):
    """The n-th (1-based) given weekday of a month, as a date."""
    return _date_of_day(_nth_weekday_day(year, month, dow, n))


def last_weekday(year, month, dow):
    """The last given weekday of a month, as a date."""
    return _date_of_day(_last_weekday_day(year, month, dow))


def federal_holidays(year, flags):
    """Holidays whose actual date is in `year`, sorted by (date, rule order). Works on day numbers, so it
    accepts any year (holiday_on asks for the year after the date's)."""
    found = []
    order = 0
    for rule in SEEDS["holidays"]["rules"]:
        order += 1                                                  # rule order 1..12 = position in the file
        if "from_year" in rule and year < rule["from_year"]:
            continue
        if "region_flag" in rule and flags.get(rule["region_flag"]) is not True:
            continue
        kind = rule["rule"]
        if kind == "fixed":
            day = days_from_civil(year, rule["month"], rule["day"])
            dow = mod_floor(day + 3, 7)
            observed = day - 1 if dow == 5 else (day + 1 if dow == 6 else day)
        elif kind == "nth_weekday":
            day = _nth_weekday_day(year, rule["month"], rule["dow"], rule["n"])
            observed = day
        elif kind == "last_weekday":
            day = _last_weekday_day(year, rule["month"], rule["dow"])
            observed = day
        else:                                                       # "inauguration"
            if year < 1969 or mod_floor(year - 1965, 4) != 0:
                continue
            day = days_from_civil(year, rule["month"], rule["day"])
            dow = mod_floor(day + 3, 7)
            observed = day + 1 if dow == 6 else (None if dow == 5 else day)     # no day in lieu of a Saturday
        found.append((day, order, {"id": rule["id"], "name": rule["name"], "class": rule["class"],
                                   "date": _date_of_day(day),
                                   "observed": None if observed is None else _date_of_day(observed)}))
    found.sort(key=lambda item: (item[0], item[1]))
    return [item[2] for item in found]


def _rule_order(holiday_id):
    order = 0
    for rule in SEEDS["holidays"]["rules"]:
        order += 1
        if rule["id"] == holiday_id:
            return order
    return order + 1


def holiday_on(date, flags):
    """The federal holiday observed on `date`, or None. New Year's Day can be observed on 31 December of
    the year before, so two years are searched. Major beats minor, then the earlier rule."""
    (y, m, d) = parse_date(date)
    best = None
    best_key = None
    for year in (y, y + 1):
        for h in federal_holidays(year, flags):
            if h["observed"] == date:
                key = (0 if h["class"] == "major" else 1, _rule_order(h["id"]))
                if best is None or key < best_key:
                    best = h
                    best_key = key
    return best


# Day context ---------------------------------------------------------------------------------------

def make_context(A, date, dow, eff_dow, cls, hol, treat_as, forecast, fuel_price_per_gal, fuel_price_source,
                 typical):
    base_type = "weekday" if eff_dow <= 4 else ("saturday" if eff_dow == 5 else "sunday")
    day_type = []
    dow_factor = []
    for s in range(NSEG):
        t = base_type
        if cls is not None:
            t = seed(A, "segments." + SEGMENTS[s] + ".holiday_day_type." + cls)
            if t == "weekday":
                t = base_type
        day_type.append(t)
        dow_factor.append(seed(A, "segments." + SEGMENTS[s] + ".dow_factor")[eff_dow] if t == "weekday" else 1.0)
    return {"date": date, "typical": typical, "dow": dow, "eff_dow": eff_dow,
            "holiday": hol,                                         # kept even when treat_as suppresses its effect
            "holiday_class": cls, "treat_as": treat_as,
            "day_type": day_type, "dow_factor": dow_factor,
            "traffic_dow": 6 if cls == "major" else eff_dow,
            "forecast": forecast, "fuel_price_per_gal": fuel_price_per_gal, "fuel_price_source": fuel_price_source}


def day_context(A, date, treat_as, forecast, fuel_price_per_gal, fuel_price_source):
    """The context of one civil date: day types and Monday-Friday factors per segment, the holiday, the
    traffic row, and the forecast and fuel price handed through unchanged."""
    dow = day_of_week(date)
    hol = holiday_on(date, A["region"]["flags"])
    eff_dow = dow
    cls = hol["class"] if hol is not None else None
    if treat_as in DOW_KEYS:
        eff_dow = DOW_KEYS.index(treat_as)                          # behave like that day of the week
        cls = None
    elif treat_as == "holiday":
        cls = "major"
    elif treat_as == "normal":
        cls = None
    return make_context(A, date, dow, eff_dow, cls, hol, treat_as, forecast, fuel_price_per_gal,
                        fuel_price_source, False)


def typical_context(A, dow):
    """A typical week: no date, no holiday, no weather, no fuel price."""
    return make_context(A, None, dow, dow, None, None, None, None, None, None, True)


# =================================================================================================
# 4.2 Curves
# =================================================================================================

_HOUR_WEIGHTS_MEMO = {}


def hour_weights(A, ctx):
    """presence[s][h] and intent[s][h] for the 24 clock hours of one context. The Monday-Friday factor
    multiplies presence only. The result depends on the seeds, the overrides and two context arrays and
    on nothing else, so it is computed once per such combination (4.7) and must not be modified."""
    key = None
    if A["seeds"] is SEEDS:
        key = (json.dumps(A["overrides"], sort_keys=True), tuple(ctx["day_type"]), tuple(ctx["dow_factor"]))
        if key in _HOUR_WEIGHTS_MEMO:
            return _HOUR_WEIGHTS_MEMO[key]
    presence = []
    intent = []
    for s in range(NSEG):
        name = SEGMENTS[s]
        p = seed(A, "segments." + name + ".presence." + ctx["day_type"][s])
        q = seed(A, "segments." + name + ".intent." + ctx["day_type"][s])
        presence.append([p[h] * ctx["dow_factor"][s] for h in range(24)])
        intent.append([q[h] for h in range(24)])
    w = {"presence": presence, "intent": intent}
    if key is not None:
        _HOUR_WEIGHTS_MEMO[key] = w
    return w


def expand_curves(A):
    """A typical week: presence[s][how] and intent[s][how] for how = dow * 24 + hour."""
    presence = [[0.0] * 168 for s in range(NSEG)]
    intent = [[0.0] * 168 for s in range(NSEG)]
    for dow in range(7):
        w = hour_weights(A, typical_context(A, dow))
        for s in range(NSEG):
            for h in range(24):
                presence[s][dow * 24 + h] = w["presence"][s][h]
                intent[s][dow * 24 + h] = w["intent"][s][h]
    return {"presence": presence, "intent": intent}


# =================================================================================================
# 4.3 Geometry
# =================================================================================================

def haversine_m(lat1, lng1, lat2, lng2):
    """Metres on a sphere of radius 6371008.8. The only distance function in the model."""
    PI = 3.141592653589793
    R = 6371008.8
    p1 = lat1 * PI / 180.0
    p2 = lat2 * PI / 180.0
    dp = (lat2 - lat1) * PI / 180.0
    dl = (lng2 - lng1) * PI / 180.0
    sp = sin(dp / 2.0)
    sl = sin(dl / 2.0)
    a = sp * sp + cos(p1) * cos(p2) * sl * sl
    a = clamp(a, 0.0, 1.0)
    return 2.0 * R * asin(sqrt(a))


def walk_weight(A, d):
    """f(d): how much a person d metres away counts. exp(-d / 400), zero beyond 1,200 m (inclusive cutoff)."""
    if d < 0 or d > seed(A, "kernel.walk_cutoff_m"):
        return 0.0
    return exp(-d / seed(A, "kernel.walk_decay_m"))


# =================================================================================================
# 4.4 Capture: the three location vectors
# =================================================================================================

def _by_id(items):
    """1.5: source points and outlets are processed in ascending id (byte-wise for ASCII ids)."""
    return sorted(items, key=lambda item: item["id"])


def rivals_at_origin(A, lat, lng, outlets):
    """The pull of food outlets on people standing at (lat, lng), per competition regime."""
    out = {"day": 0.0, "eve": 0.0}
    for o in _by_id(outlets):
        f = walk_weight(A, haversine_m(lat, lng, o["lat"], o["lng"]))
        if f == 0.0:
            continue
        w = seed(A, "kernel.rival_weight." + o["kind"])
        out["day"] += w["day"] * f
        out["eve"] += w["eve"] * f
    return out


def host_exclusion(A, host):
    """What a declared host removes from the catchment so that its people are not counted twice:
    (1) the linked place's own source point; (2) for workers and residents, up to host.size units of the
    same segment from the nearest points. The removed people come back through the host term (4.5)."""
    if host is None:
        return {"point_ids": [], "segment": None, "amount": 0.0}
    ids = [host["point_id"]] if host.get("point_id") is not None else []
    group = A["seeds"]["segments"][host["segment"]]["group"]
    if group == "workers" or group == "residents":
        return {"point_ids": ids, "segment": host["segment"], "amount": host["size"]}
    return {"point_ids": ids, "segment": None, "amount": 0.0}


def host_link_point(A, lat, lng, host, sources):
    """For a venue host that carries no link: the nearest source point holding the host's segment within
    host.venue_link_radius_m (nearest by whole millimetres, ties to the smaller id), or None."""
    si = SEGMENTS.index(host["segment"])
    best_id = None
    best_key = None
    for c in _by_id(sources):
        if c["base"][si] <= 0:
            continue
        d = haversine_m(lat, lng, c["lat"], c["lng"])
        if d > seed(A, "host.venue_link_radius_m"):
            continue
        key = floor(d * 1000.0 + 0.5)
        if best_key is None or key < best_key:
            best_id = c["id"]
            best_key = key
    return best_id


def capture_at_point(A, lat, lng, visibility, sources, outlets, exclusion):
    """The location vectors of a truck parked at (lat, lng).

    capture[regime][s]  units of segment-s base the truck would win per unit of presence and intent
    nearby[s]           distance-weighted base within walking distance
    within[s]           base within the cutoff, after exclusion, not distance-weighted
    rivals[regime]      pull of food outlets at the truck's own position
    """
    V = seed(A, "kernel.visibility." + visibility)
    A0 = seed(A, "kernel.outside_option_a0")
    rows = []
    for c in _by_id(sources):
        if c["id"] in exclusion["point_ids"]:
            continue
        d = haversine_m(lat, lng, c["lat"], c["lng"])
        f = walk_weight(A, d)
        if f == 0.0:
            continue
        rows.append({"id": c["id"], "d": d, "f": f, "base": list(c["base"]), "rivals": c["rivals"]})
    taken = 0.0
    if exclusion["segment"] is not None and exclusion["amount"] > 0:
        si = SEGMENTS.index(exclusion["segment"])
        remaining = exclusion["amount"]
        radius = seed(A, "host.exclusion_radius_m")
        near = [r for r in rows if r["d"] <= radius]
        near.sort(key=lambda r: (floor(r["d"] * 1000.0 + 0.5), r["id"]))        # nearest first, ties by id
        for r in near:
            if remaining <= 0:
                break
            take = min(r["base"][si], remaining)
            r["base"][si] -= take
            remaining -= take
            taken += take
    capture = {"day": [0.0] * NSEG, "eve": [0.0] * NSEG}
    nearby = [0.0] * NSEG
    within = [0.0] * NSEG
    for r in rows:                                                              # still ascending id
        for regime in REGIMES:
            share = r["f"] * V / (A0 + r["f"] * V + r["rivals"][regime])
            for s in range(NSEG):
                capture[regime][s] += r["base"][s] * share
        for s in range(NSEG):
            nearby[s] += r["base"][s] * r["f"]
            within[s] += r["base"][s]
    return {"capture": capture, "nearby": nearby, "within": within,
            "rivals": rivals_at_origin(A, lat, lng, outlets),
            "visibility": visibility,
            "in_region": True, "region_id": None,                   # the backend sets these two and dataset_version
            "exclusion": exclusion, "excluded_amount": taken, "points_used": len(rows),
            "dataset_version": None, "model_version": MODEL_VERSION}


# =================================================================================================
# 4.5 Host term
# =================================================================================================

def host_capture(A, host, visibility, rivals_here):
    """The host's own people the truck would win per unit of presence and intent, per regime.

    captive (v_nightlife, v_events): people inside a venue; a flat share, the truck being the only food
    or not. open (everything else): the host's people are a source at distance zero, with the on-site
    kitchen (if any) as an extra rival of weight K."""
    if host is None or host["size"] <= 0:
        return {"day": 0.0, "eve": 0.0, "share": {"day": 0.0, "eve": 0.0}, "mode": None}
    mode = A["seeds"]["segments"][host["segment"]]["host_mode"]
    share = {}
    if mode == "captive":
        sh = seed(A, "host.captive_share") if host["only_food"] else seed(A, "host.shared_kitchen_share")
        share["day"] = sh
        share["eve"] = sh
    else:
        V = seed(A, "kernel.visibility." + visibility)
        A0 = seed(A, "kernel.outside_option_a0")
        K = 0.0 if host["only_food"] else seed(A, "host.onsite_kitchen_weight")
        for regime in REGIMES:
            share[regime] = V / (A0 + V + rivals_here[regime] + K)
    return {"day": host["size"] * share["day"], "eve": host["size"] * share["eve"], "share": share, "mode": mode}


# =================================================================================================
# 4.6 Weather
# =================================================================================================

def ascii_lower(text):
    """Only A-Z become a-z; every other character is unchanged (no locale, no Unicode case mapping)."""
    out = []
    for ch in text:
        out.append(chr(ord(ch) + 32) if "A" <= ch <= "Z" else ch)
    return "".join(out)


def _band(A, table, key, x, setting):
    """Step-function lookup: the first band whose upper bound is absent or above x."""
    for band_id in seed(A, "weather." + table + ".order"):
        upper = seed(A, "weather." + table + ".rows." + band_id + "." + key)
        if upper is None or x < upper:
            return (band_id, seed(A, "weather." + table + ".rows." + band_id + "." + setting))
    return (None, 1.0)                                              # unreachable: the last band has no bound


def weather_multiplier(A, fc, setting):
    """Multiplier on walk-up demand for one forecast hour. setting is "open" (people outdoors or walking
    over) or "captive" (people already inside a venue). The class of precipitation says how bad it is if
    it does precipitate; the forecast probability says how likely that is."""
    if fc is None or (fc.get("temp_f") is None and fc.get("precip_prob") is None
                      and fc.get("short_forecast") is None and fc.get("wind_mph") is None):
        return {"multiplier": 1.0, "missing": True, "temp": 1.0, "precip": 1.0, "wind": 1.0,
                "temp_band": None, "precip_class": None, "precip_p": None, "wind_band": None}
    temp = 1.0
    temp_band = None
    if fc.get("temp_f") is not None:
        (temp_band, temp) = _band(A, "temperature_bands", "upper_f", fc["temp_f"], setting)
    wind = 1.0
    wind_band = None
    if fc.get("wind_mph") is not None:
        (wind_band, wind) = _band(A, "wind_bands", "upper_mph", fc["wind_mph"], setting)
    cls = "dry"
    if fc.get("short_forecast") is not None:
        text = ascii_lower(fc["short_forecast"])
        for class_id in seed(A, "weather.precip_classes.order"):    # listed order, first hit wins
            hit = False
            for needle in seed(A, "weather.precip_classes.rows." + class_id + ".match"):
                if needle in text:
                    hit = True
            if hit:
                cls = class_id
                break
    m = seed(A, "weather.precip_classes.rows." + cls + "." + setting)
    if cls == "dry":
        p = 0.0
    elif fc.get("precip_prob") is None:
        p = seed(A, "weather.pop_when_missing")
    else:
        p = clamp(fc["precip_prob"] / 100.0, 0.0, 1.0)
    precip = 1.0 - p * (1.0 - m)
    multiplier = max(seed(A, "weather.floor"), temp * precip * wind)
    return {"multiplier": multiplier, "missing": False, "temp": temp, "precip": precip, "wind": wind,
            "temp_band": temp_band, "precip_class": cls, "precip_p": p, "wind_band": wind_band}


# =================================================================================================
# 4.7 Demand and orders
# =================================================================================================

def calibration_factor(cal, spot_id):
    """(truck_factor, spot_factor): what the owner's logged services say (4.13). (1.0, 1.0) without logs."""
    if cal is None:
        return (1.0, 1.0)
    if spot_id is not None and spot_id in cal["spots"]:
        return (cal["truck_factor"], cal["spots"][spot_id]["factor"])
    return (cal["truck_factor"], 1.0)


def hourly_orders(A, profile, terms, vectors, cal, ctx, hour):
    """Expected orders in one clock hour of one context, with every step of the breakdown.

    demand_raw   before weather and calibration
    demand_adj   after them, before the capacity cap
    orders       after the cap, applied once to the hour's total; demand above capacity is lost
    """
    w = hour_weights(A, ctx)
    regime = seed(A, "hours.regime_of_hour")[hour]
    daypart = seed(A, "hours.daypart_of_hour")[hour]
    fit = profile["daypart_fit"][daypart]
    if ctx["typical"]:
        wx_open = 1.0
        wx_cap = 1.0
        weather_state = "typical"
        detail_open = None
        detail_cap = None
    else:
        fc = ctx["forecast"][hour] if ctx["forecast"] is not None else None
        detail_open = weather_multiplier(A, fc, "open")
        detail_cap = weather_multiplier(A, fc, "captive")
        wx_open = detail_open["multiplier"]
        wx_cap = detail_cap["multiplier"]
        weather_state = "missing" if detail_open["missing"] else "forecast"
    (tf, sf) = calibration_factor(cal, terms["spot_id"])
    calib = tf * sf

    within = vectors.get("within")                                 # null when decoded from 50 stored numbers
    demand_raw = 0.0
    demand_adj = 0.0
    weak = 0.0
    default_part = 0.0
    segments = []
    for s in range(NSEG):
        presence = w["presence"][s][hour]
        intent = w["intent"][s][hour]
        raw_s = vectors["capture"][regime][s] * presence * intent * fit
        adj_s = raw_s * wx_open * calib
        demand_raw += raw_s
        demand_adj += adj_s
        if A["seeds"]["segments"][SEGMENTS[s]]["weak"]:
            weak += adj_s
        segments.append({"segment": SEGMENTS[s],
                         "nearby_present": vectors["nearby"][s] * presence,
                         "within_present": within[s] * presence if within is not None else None,
                         "capture": vectors["capture"][regime][s], "presence": presence, "intent": intent,
                         "demand_raw": raw_s, "before_cap": adj_s, "orders": 0.0})

    host_row = None
    host = terms["host"]
    if host is not None and host["size"] > 0:
        hc = host_capture(A, host, terms["visibility"], vectors["rivals"])
        hs = SEGMENTS.index(host["segment"])
        presence = w["presence"][hs][hour]
        intent = w["intent"][hs][hour]
        raw_h = hc[regime] * presence * intent * fit
        wx_h = wx_cap if hc["mode"] == "captive" else wx_open
        adj_h = raw_h * wx_h * calib
        demand_raw += raw_h                                         # the host is added after the 16 segments
        demand_adj += adj_h
        if A["seeds"]["segments"][host["segment"]]["weak"]:
            weak += adj_h
        if host["size_source"] == "default":
            default_part = adj_h
        host_row = {"segment": host["segment"], "mode": hc["mode"], "size": host["size"],
                    "share": hc["share"][regime], "people_present": host["size"] * presence,
                    "presence": presence, "intent": intent, "demand_raw": raw_h, "weather": wx_h,
                    "before_cap": adj_h, "orders": 0.0}

    capacity = profile["capacity_orders_per_hour"]
    orders = min(demand_adj, capacity)
    capped = demand_adj > capacity
    scale = orders / demand_adj if demand_adj > 0 else 0.0
    for row in segments:                                            # who the customers would be, after the cap
        row["orders"] = row["before_cap"] * scale
    if host_row is not None:
        host_row["orders"] = host_row["before_cap"] * scale

    return {"date": ctx["date"], "hour": hour, "how": ctx["dow"] * 24 + hour, "regime": regime, "daypart": daypart,
            "segments": segments, "host": host_row,
            "factors": {"menu_fit": fit, "weather_open": wx_open, "weather_captive": wx_cap,
                        "weather_state": weather_state, "weather_detail": detail_open,
                        "weather_detail_captive": detail_cap, "truck_factor": tf, "spot_factor": sf},
            "demand_raw": demand_raw, "demand_adj": demand_adj, "capacity": capacity, "orders": orders,
            "capped": capped, "weak_part": weak, "default_size_part": default_part}


def vectors_match(A, terms, vectors):
    """Were these vectors built for these terms (same visibility, same host exclusion)? day_plan warns
    stale_vectors when not."""
    e = host_exclusion(A, terms["host"])
    x = vectors["exclusion"]
    return (vectors["visibility"] == terms["visibility"]
            and x["point_ids"] == e["point_ids"]                    # the same ids in the same order
            and x["segment"] == e["segment"] and x["amount"] == e["amount"])


def clock_hours(open, close):
    """The loop of window_orders: every clock hour overlapping [open, close), as
    (day_index, hour, start, end, fraction). Nothing when open == close."""
    out = []
    if open == close:
        return out
    h_abs = floor_div(open, 60)
    while h_abs * 60 < close:
        start = max(h_abs * 60, open)
        end = min((h_abs + 1) * 60, close)
        day_index = floor_div(h_abs, 24)
        out.append((day_index, h_abs - 24 * day_index, start, end, (end - start) / 60.0))
        h_abs += 1
    return out


def window_orders(A, profile, terms, vectors, cal, ctx, ctx_next, open, close):
    """Expected orders over a service window [open, close) in minutes from local midnight of ctx.date.
    Hours at or after 1440 belong to the next civil date and use ctx_next. A partial hour contributes
    its fraction of that hour's capped orders."""
    if not (0 <= open and open <= close and close <= 2880):
        raise ModelError("invalid_window")
    adj_total = 0.0
    weak = 0.0
    dflt = 0.0
    cap_total = 0.0
    host_orders = 0.0
    by_segment = [0.0] * NSEG
    capped_hours = 0
    hours = []
    d = []
    c = []
    for (day_index, hour, start, end, fraction) in clock_hours(open, close):
        cx = ctx if day_index == 0 else ctx_next
        if cx is None:
            raise ModelError("missing_context")
        r = hourly_orders(A, profile, terms, vectors, cal, cx, hour)
        d.append(r["demand_adj"] * fraction)
        c.append(r["capacity"] * fraction)
        adj_total += r["demand_adj"] * fraction
        cap_total += r["capacity"] * fraction
        weak += r["weak_part"] * fraction
        dflt += r["default_size_part"] * fraction
        for s in range(NSEG):
            by_segment[s] += r["segments"][s]["orders"] * fraction
        if r["host"] is not None:
            host_orders += r["host"]["orders"] * fraction
        if r["capped"]:
            capped_hours += 1
        hours.append({"day_index": day_index, "hour": hour, "fraction": fraction, "result": r})
    evidence = evidence_from(cal, terms["spot_id"])                 # 4.8
    evidence["weak_share"] = weak / adj_total if adj_total > 0 else 0.0
    evidence["default_size_share"] = dflt / adj_total if adj_total > 0 else 0.0
    (orders, spread) = interval_capped(A, d, c, evidence)           # 4.8
    return {"date": ctx["date"], "open_minute": open, "close_minute": close, "minutes": close - open,
            "hours": hours, "orders": orders, "by_segment": by_segment, "host_orders": host_orders,
            "demand_adj": adj_total, "capacity_total": cap_total, "capped_hours": capped_hours,
            "evidence": evidence, "spread": spread}


def week_strip(A, profile, terms, vectors, cal):
    """Expected orders for each of the 168 hours of a typical week (no date, no weather)."""
    out = [0.0] * 168
    for dow in range(7):
        cx = typical_context(A, dow)
        for hour in range(24):
            out[dow * 24 + hour] = hourly_orders(A, profile, terms, vectors, cal, cx, hour)["orders"]
    return out


def best_windows(values, length, top_n, circular, allowed=None):
    """Greedy: the best run of `length` consecutive values, then the best that does not overlap it, and
    so on. Ties go to the earlier start. Windows whose total rounds to zero millionths are never returned."""
    n = len(values)
    if length > n:
        return []
    candidates = []
    last_start = n - 1 if circular else n - length
    for start in range(0, last_start + 1):
        total = 0.0
        ok = True
        for k in range(length):
            i = start + k
            if i >= n:
                i -= n
            if allowed is not None and not allowed[i]:
                ok = False
                break
            total += values[i]
        if ok and qkey(total) > 0:
            candidates.append({"start": start, "total": total})
    candidates.sort(key=lambda cand: (-qkey(cand["total"]), cand["start"]))
    picked = []
    used = [False] * n
    for cand in candidates:
        if len(picked) == top_n:
            break
        indexes = []
        for k in range(length):
            i = cand["start"] + k
            if i >= n:
                i -= n
            indexes.append(i)
        clash = False
        for i in indexes:
            if used[i]:
                clash = True
        if clash:
            continue
        for i in indexes:
            used[i] = True
        picked.append({"start": cand["start"], "length": length, "total": cand["total"]})
    return picked


# =================================================================================================
# 4.8 Ranges and confidence
# =================================================================================================

def evidence_from(cal, spot_id):
    """What the logged services say about how far to trust an estimate at this spot."""
    base = {"truck_weight": 0.0, "spot_weight": 0.0, "resid_sd": None, "resid_weight": 0.0,
            "weak_share": 0.0, "default_size_share": 0.0, "event": False, "fixed": False}
    if cal is None:
        return base
    base["truck_weight"] = cal["truck_weight"]
    base["resid_sd"] = cal["resid_sd"]
    base["resid_weight"] = cal["resid_weight"]
    if spot_id is not None and spot_id in cal["spots"]:
        base["spot_weight"] = cal["spots"][spot_id]["weight"]
    return base


def interval(A, mean, ev):
    """An 80 % interval around an expected demand: (Estimate, spread).

    Demand is log-normal with the given mean; low and high are its 10th and 90th percentiles. The spread
    combines model uncertainty (truck, spot, day, weak seeds, default host size, event), which shrinks
    with the owner's logged services, and the counting noise of a finite number of orders. The label
    depends on the model part only."""
    if ev["fixed"]:
        return ({"value": mean, "low": mean, "high": mean, "confidence": "fixed"},
                {"sigma_model": 0.0, "sigma": 0.0, "v_truck": 0.0, "v_spot": 0.0, "v_day": 0.0,
                 "v_weak": 0.0, "v_size": 0.0, "v_event": 0.0, "v_count": 0.0})
    kt = seed(A, "calibration.k_truck")
    ks = seed(A, "calibration.k_spot")
    n0 = seed(A, "uncertainty.resid_prior_weight")
    sd_truck = seed(A, "uncertainty.sd_truck")
    sd_spot = seed(A, "uncertainty.sd_spot")
    sd_day = seed(A, "uncertainty.sd_day")
    sd_weak = seed(A, "uncertainty.sd_weak")
    sd_default_size = seed(A, "uncertainty.sd_default_size")
    sd_event = seed(A, "uncertainty.sd_event")

    shrink = ks / (ks + ev["spot_weight"])
    v_truck = (sd_truck * sd_truck) * kt / (kt + ev["truck_weight"])
    v_spot = (sd_spot * sd_spot) * shrink
    if ev["resid_sd"] is None:
        v_day = sd_day * sd_day
    else:
        v_day = ((n0 * (sd_day * sd_day) + ev["resid_weight"] * (ev["resid_sd"] * ev["resid_sd"]))
                 / (n0 + ev["resid_weight"]))
    weak_sd = ev["weak_share"] * sd_weak
    v_weak = (weak_sd * weak_sd) * shrink
    size_sd = ev["default_size_share"] * sd_default_size
    v_size = (size_sd * size_sd) * shrink
    v_event = sd_event * sd_event if ev["event"] else 0.0
    v_model = v_truck + v_spot + v_day + v_weak + v_size + v_event              # added in this order
    sigma_model = sqrt(v_model)

    if sigma_model < seed(A, "uncertainty.label_good_below"):
        confidence = "good"
    elif sigma_model < seed(A, "uncertainty.label_fair_below"):
        confidence = "fair"
    elif sigma_model < seed(A, "uncertainty.label_rough_below"):
        confidence = "rough"
    else:
        confidence = "very_rough"

    spread = {"sigma_model": sigma_model, "sigma": sigma_model, "v_truck": v_truck, "v_spot": v_spot,
              "v_day": v_day, "v_weak": v_weak, "v_size": v_size, "v_event": v_event, "v_count": 0.0}
    if mean <= 0:
        return ({"value": 0.0, "low": 0.0, "high": 0.0, "confidence": confidence}, spread)
    v_count = ln(1.0 + seed(A, "uncertainty.count_dispersion") / mean)
    sigma = sqrt(v_model + v_count)
    low = mean * exp(-0.5 * sigma * sigma - Z80 * sigma)
    high = mean * exp(-0.5 * sigma * sigma + Z80 * sigma)
    if high < mean:
        high = mean
    spread["sigma"] = sigma
    spread["v_count"] = v_count
    return ({"value": mean, "low": low, "high": high, "confidence": confidence}, spread)


def interval_capped(A, d, c, ev):
    """The interval of a window whose hours have capacities: (Estimate, spread).

    d[k], c[k]: demand and capacity of loop hour k, each already multiplied by that hour's fraction.
    The demand of every hour moves by one factor (k_low on a weak day, k_high on a strong one) and each
    hour's cap is applied again. value is the orders at expected demand."""
    D = 0.0
    value = 0.0
    for k in range(len(d)):
        D += d[k]
        value += min(d[k], c[k])
    (e, spread) = interval(A, D, ev)                                # log-normal on demand, before the cap
    if D <= 0:
        return ({"value": 0.0, "low": 0.0, "high": 0.0, "confidence": e["confidence"]}, spread)
    k_low = e["low"] / D
    k_high = e["high"] / D
    low = 0.0
    high = 0.0
    for k in range(len(d)):
        low += min(k_low * d[k], c[k])
        high += min(k_high * d[k], c[k])
    return ({"value": value, "low": min(low, value), "high": max(high, value), "confidence": e["confidence"]},
            spread)


# =================================================================================================
# 4.9 Money
# =================================================================================================

MONEY_LINES = ["orders", "sales", "food_cost", "packaging", "card_fees", "spot_fee", "tips", "contribution"]


def stop_money_at(profile, terms, orders):
    """The money lines of one stop at one number of orders. The spot fee is fee_flat + fee_pct * sales with
    fee_min as a floor on the total. contribution is what the stop leaves before the day's own costs."""
    sales = orders * profile["avg_ticket"]
    food_cost = sales * profile["food_cost_pct"]
    packaging = orders * profile["packaging_per_order"]
    card_fees = (sales * profile["card_share"] * profile["card_fee_pct"]
                 + orders * profile["card_share"] * profile["card_fee_fixed"])
    spot_fee = max(terms["fee_min"], terms["fee_flat"] + terms["fee_pct"] * sales)
    tips = sales * profile["card_share"] * profile["tips_pct_of_card_sales"] if profile["tips_include"] else 0.0
    contribution = sales - food_cost - packaging - card_fees - spot_fee + tips
    return {"orders": orders, "sales": sales, "food_cost": food_cost, "packaging": packaging,
            "card_fees": card_fees, "spot_fee": spot_fee, "tips": tips, "contribution": contribution}


def unit_margins(profile, terms):
    """Contribution per extra order: while the minimum fee is what is paid, and once flat + percentage is."""
    tip = profile["card_share"] * profile["tips_pct_of_card_sales"] if profile["tips_include"] else 0.0
    base = (profile["avg_ticket"] * (1.0 - profile["food_cost_pct"] - profile["card_share"] * profile["card_fee_pct"] + tip)
            - profile["packaging_per_order"] - profile["card_share"] * profile["card_fee_fixed"])
    return {"at_minimum": base, "at_percentage": base - profile["avg_ticket"] * terms["fee_pct"]}


def stop_money(profile, terms, orders):
    """StopMoney for an orders Estimate: every line at value, low and high."""
    v = stop_money_at(profile, terms, orders["value"])
    l = stop_money_at(profile, terms, orders["low"])
    h = stop_money_at(profile, terms, orders["high"])
    out = {}
    for line in MONEY_LINES:
        out[line] = est_levels(v[line], l[line], h[line], orders["confidence"])
    out["unit_margin"] = unit_margins(profile, terms)
    return out


def break_even_orders(profile, terms, fixed_costs):
    """Orders at which a stop's contribution equals fixed_costs (a real; show it rounded up), or None
    when no number of orders gets there."""
    m = unit_margins(profile, terms)
    if m["at_minimum"] <= 0:
        return None
    x = (fixed_costs + terms["fee_min"]) / m["at_minimum"]                     # the minimum fee is what is paid
    if terms["fee_flat"] + terms["fee_pct"] * profile["avg_ticket"] * x <= terms["fee_min"]:
        return max(x, 0.0)
    if m["at_percentage"] <= 0:
        return None
    return max((fixed_costs + terms["fee_flat"]) / m["at_percentage"], 0.0)    # flat + percentage is what is paid


def day_costs(profile, timeline, fuel_price_per_gal):
    """The day's own costs. Paid crew are paid from the start of prep to "done", except gaps marked
    unpaid. The owner's own time is not a cost. One fuel price covers truck and generator; it must be a
    number (a context without one is refused, not read as zero)."""
    require_number(fuel_price_per_gal, "fuel_price_per_gal")
    paid_hours = timeline["paid_minutes"] / 60.0
    labour = paid_hours * profile["paid_crew"] * profile["wage_per_hour"] * (1.0 + profile["payroll_burden_pct"])
    drive_gallons = timeline["miles"] / profile["mpg"]
    generator_gallons = timeline["generator_minutes"] / 60.0 * profile["generator_gal_per_hour"]
    fuel = (drive_gallons + generator_gallons) * fuel_price_per_gal
    tolls = timeline["tolls"]
    fixed = profile["fixed_cost_per_service_day"] if len(timeline["stops"]) > 0 else 0.0
    total = labour + fuel + tolls + fixed
    return {"labour": labour, "fuel": fuel, "tolls": tolls, "fixed": fixed, "total": total,
            "paid_hours": paid_hours, "drive_gallons": drive_gallons, "generator_gallons": generator_gallons}


# =================================================================================================
# 4.10 Driving
# =================================================================================================

def fallback_leg(A, lat1, lng1, lat2, lng2):
    """A labelled straight-line estimate for when no routed leg exists: road distance = straight line x
    detour factor; the first local miles at the local speed, the rest at the trunk speed; free-flow."""
    road_m = haversine_m(lat1, lng1, lat2, lng2) * seed(A, "drive_fallback.detour_factor")
    miles = road_m / 1609.344
    local = min(miles, seed(A, "drive_fallback.local_miles"))
    ff_min = (local / seed(A, "drive_fallback.local_mph") * 60.0
              + (miles - local) / seed(A, "drive_fallback.trunk_mph") * 60.0)
    return {"source": "fallback", "distance_m": road_m, "duration_s": ff_min * 60.0,
            "override_minutes": None, "toll": 0.0}


def traffic_factor(A, ctx, minute):
    """(factor, dow, hour): travel time over free-flow time for the clock hour containing `minute`. On
    the service date the context's traffic_dow applies; a minute before that date's midnight or after
    its end belongs to a neighbouring civil date and uses that date's real day of the week."""
    day_shift = floor_div(minute, 1440)
    m = minute - 1440 * day_shift
    dow = ctx["traffic_dow"] if day_shift == 0 else mod_floor(ctx["dow"] + day_shift, 7)
    hour = floor_div(m, 60)
    return (seed(A, "traffic." + A["region"]["traffic_matrix"])[dow][hour], dow, hour)


def leg_minutes(A, profile, leg, ctx, lookup_minute):
    """Drive minutes of one leg for a departure in the clock hour of lookup_minute.

    fallback leg: free-flow minutes x table factor. google leg: Google's traffic-unaware duration already
    holds average traffic, so it is scaled by factor / the table's typical value. The owner's override
    replaces the whole computation. Whole minutes; at least 1 for any real distance."""
    base_minutes = leg["duration_s"] / 60.0
    (factor, dow, hour) = traffic_factor(A, ctx, lookup_minute)
    if leg["source"] == "google":
        time_factor = factor / seed(A, "traffic." + A["region"]["traffic_matrix"] + "_typical")
    else:
        time_factor = factor
    raw = base_minutes * time_factor * profile["truck_time_factor"]
    if leg["override_minutes"] is not None:
        minutes = leg["override_minutes"]
        source = "override"
    else:
        minutes = int(round_half_away(raw, 0))
        if minutes < 1 and leg["distance_m"] > 0:
            minutes = 1
        source = leg["source"]
    miles = leg["distance_m"] / 1609.344
    return {"from_id": None, "to_id": None, "source": source, "distance_m": leg["distance_m"], "miles": miles,
            "base_minutes": base_minutes, "depart_minute": None, "traffic_lookup_minute": lookup_minute,
            "traffic_dow": dow, "traffic_hour": hour, "traffic_factor": factor, "time_factor": time_factor,
            "truck_time_factor": profile["truck_time_factor"], "raw_minutes": raw, "minutes": minutes,
            "toll": leg["toll"]}


# =================================================================================================
# 4.11 Timeline
# =================================================================================================

def required_leg_keys(stops):
    """Every leg key day_plan can look up: the plan as ordered, then the legs that appear when one stop is
    left out. No duplicates."""
    if len(stops) == 0:
        return []
    ids = ["base"] + [s["id"] for s in stops] + ["base"]
    keys = []
    for i in range(0, len(ids) - 1):
        keys.append(ids[i] + ">" + ids[i + 1])
    if len(stops) >= 2:
        for i in range(1, len(stops) + 1):
            keys.append(ids[i - 1] + ">" + ids[i + 1])
    return keys


def _empty_timeline():
    return {"events": [], "stops": [], "legs": [],
            "start_prep": None, "leave_base": None, "back_at_base": None, "done": None,
            "day_minutes": 0, "paid_minutes": 0, "unpaid_gap_minutes": 0, "drive_minutes": 0,
            "service_minutes": 0, "generator_minutes": 0, "miles": 0.0, "tolls": 0.0}


def build_timeline(A, profile, ctx, stops, legs):
    """The day's clock, in whole minutes from local midnight of the service date.

    The first stop is worked backward from its opening time (the truck leaves base just in time); every
    later stop forward. The owner's opening and closing times are never moved: a truck that cannot be
    set up in time is late and serves from effective_open. Stops are never reordered. A leg missing from
    `legs` is a fallback_leg between the two points."""
    if len(stops) == 0:
        return _empty_timeline()

    def point_of(stop_id):
        if stop_id == "base":
            return profile["base"]
        for s in stops:
            if s["id"] == stop_id:
                return s["point"]

    def leg_input(from_id, to_id):
        key = from_id + ">" + to_id
        if key in legs:
            return legs[key]
        p1 = point_of(from_id)
        p2 = point_of(to_id)
        return fallback_leg(A, p1["lat"], p1["lng"], p2["lat"], p2["lng"])

    def setup(s):
        return s["setup_minutes"] if s.get("setup_minutes") is not None else profile["setup_minutes"]

    def teardown(s):
        return s["teardown_minutes"] if s.get("teardown_minutes") is not None else profile["teardown_minutes"]

    events = []

    def emit(kind, minute, stop_index):
        if len(events) > 0 and minute < events[len(events) - 1]["minute"]:
            minute = events[len(events) - 1]["minute"]              # time never runs backwards
        events.append({"kind": kind, "minute": minute, "stop_index": stop_index})

    # 1. First stop: work backward from its opening time.
    s0 = stops[0]
    arrive = s0["open_minute"] - setup(s0)
    L0 = leg_input("base", s0["id"])
    lookup = arrive - floor(L0["duration_s"] / 60.0 * profile["truck_time_factor"])     # departure guess
    leg0 = leg_minutes(A, profile, L0, ctx, lookup)
    leg0["from_id"] = "base"
    leg0["to_id"] = s0["id"]
    leave_base = arrive - leg0["minutes"]
    leg0["depart_minute"] = leave_base
    start_prep = leave_base - profile["prep_minutes"]
    emit("start_prep", start_prep, None)
    emit("leave_base", leave_base, None)
    out_legs = [leg0]

    # 2. Each stop in order, forward.
    out_stops = []
    unpaid = 0
    service_minutes = 0
    generator_minutes = 0
    prev_leave = None
    for i in range(len(stops)):
        s = stops[i]
        if i > 0:
            leg = leg_minutes(A, profile, leg_input(stops[i - 1]["id"], s["id"]), ctx, prev_leave)
            leg["from_id"] = stops[i - 1]["id"]
            leg["to_id"] = s["id"]
            leg["depart_minute"] = prev_leave
            out_legs.append(leg)
            arrive = prev_leave + leg["minutes"]
        gap = (s["open_minute"] - setup(s)) - arrive
        late = 0
        if gap < 0:
            late = -gap
            gap = 0
        setup_start = arrive + gap
        effective_open = min(setup_start + setup(s), s["close_minute"])
        gap_unpaid = bool(s["gap_before_unpaid"]) and i > 0
        if gap_unpaid:
            unpaid += gap
        leave = max(s["close_minute"] + teardown(s), setup_start)
        emit("arrive", arrive, i)
        emit("setup_start", setup_start, i)
        emit("open", effective_open, i)
        emit("close", s["close_minute"], i)
        emit("leave", leave, i)
        service_minutes += s["close_minute"] - effective_open
        generator_minutes += leave - setup_start                   # setup start to the end of teardown
        out_stops.append({"stop_index": i, "arrive": arrive, "setup_start": setup_start,
                          "open": s["open_minute"], "effective_open": effective_open, "close": s["close_minute"],
                          "leave": leave, "gap_before_minutes": gap, "gap_unpaid": gap_unpaid,
                          "late_minutes": late})
        prev_leave = leave

    # 3. Back to base.
    last = stops[len(stops) - 1]
    legN = leg_minutes(A, profile, leg_input(last["id"], "base"), ctx, prev_leave)
    legN["from_id"] = last["id"]
    legN["to_id"] = "base"
    legN["depart_minute"] = prev_leave
    out_legs.append(legN)
    back_at_base = prev_leave + legN["minutes"]
    done = back_at_base + profile["closeout_minutes"]
    emit("back_at_base", back_at_base, None)
    emit("done", done, None)

    drive_minutes = 0
    miles = 0.0
    tolls = 0.0
    for leg in out_legs:
        drive_minutes += leg["minutes"]
        miles += leg["miles"]
        tolls += leg["toll"]
    day_minutes = done - start_prep
    return {"events": events, "stops": out_stops, "legs": out_legs,
            "start_prep": start_prep, "leave_base": leave_base, "back_at_base": back_at_base, "done": done,
            "day_minutes": day_minutes, "paid_minutes": day_minutes - unpaid, "unpaid_gap_minutes": unpaid,
            "drive_minutes": drive_minutes, "service_minutes": service_minutes,
            "generator_minutes": generator_minutes, "miles": miles, "tolls": tolls}


# =================================================================================================
# 4.12 Day plan
# =================================================================================================

def evaluate(A, profile, plan, ctx, ctx_next, legs, cal):
    """Internal: one whole day as planned. Timeline, each stop's orders and money, the day's costs and
    totals. day_plan calls it for the plan, for the plan without each stop and for the unpaid-gap
    alternative; suggest_day calls it for every candidate plan."""
    T = build_timeline(A, profile, ctx, plan["stops"], legs)
    stops = []
    for i in range(len(plan["stops"])):
        s = plan["stops"][i]
        effective_open = T["stops"][i]["effective_open"]
        window = None
        event = None
        if s["kind"] == "spot":
            window = window_orders(A, profile, s["terms"], s["vectors"], cal, ctx, ctx_next,
                                   effective_open, s["close_minute"])
            orders = window["orders"]
            money = stop_money(profile, s["terms"], orders)
        elif s["kind"] == "event":
            E = event_orders(A, profile, s["event"], cal, ctx, ctx_next, effective_open, s["close_minute"])
            orders = E["orders"]
            money = stop_money(profile, s["terms"], orders)
            event = {"buyers": E["buyers"], "demand": E["demand"], "hours": E["hours"], "spread": E["spread"]}
        else:                                                       # catering
            money = catering_money(profile, s["catering"])
            orders = money["orders"]
        stops.append({"stop_index": i, "id": s["id"], "kind": s["kind"], "spot_id": s.get("spot_id"),
                      "window": window, "event": event, "orders": orders, "money": money})

    C = day_costs(profile, T, ctx["fuel_price_per_gal"])
    totals = {"orders": est_sum([st["orders"] for st in stops])}
    for (name, line) in (("sales", "sales"), ("food_cost", "food_cost"), ("packaging", "packaging"),
                         ("card_fees", "card_fees"), ("spot_fees", "spot_fee"), ("tips", "tips"),
                         ("contribution", "contribution")):
        totals[name] = est_sum([st["money"][line] for st in stops])
    totals["labour"] = est_fixed(C["labour"])
    totals["fuel"] = est_fixed(C["fuel"])
    totals["tolls"] = est_fixed(C["tolls"])
    totals["fixed_cost"] = est_fixed(C["fixed"])
    contribution = totals["contribution"]
    take_home = {"value": contribution["value"] - C["total"], "low": contribution["low"] - C["total"],
                 "high": contribution["high"] - C["total"], "confidence": contribution["confidence"]}
    work_hours = (T["day_minutes"] - T["unpaid_gap_minutes"]) / 60.0
    per_hour = {"value": take_home["value"] / work_hours if work_hours > 0 else 0.0,
                "low": take_home["low"] / work_hours if work_hours > 0 else 0.0,
                "high": take_home["high"] / work_hours if work_hours > 0 else 0.0,
                "confidence": take_home["confidence"]}
    totals["take_home"] = take_home
    totals["take_home_per_hour"] = per_hour
    totals["day_hours"] = T["day_minutes"] / 60.0
    totals["paid_hours"] = C["paid_hours"]
    totals["work_hours"] = work_hours
    totals["unpaid_gap_hours"] = T["unpaid_gap_minutes"] / 60.0
    totals["drive_minutes"] = T["drive_minutes"]
    totals["miles"] = T["miles"]
    totals["drive_gallons"] = C["drive_gallons"]
    totals["generator_gallons"] = C["generator_gallons"]
    return {"timeline": T, "stops": stops, "costs": C, "totals": totals,
            "take_home": take_home, "take_home_per_hour": per_hour, "work_hours": work_hours}


def _warning(code, level, stop_index, data):
    return {"code": code, "level": level, "stop_index": stop_index, "data": data}


def _missing_forecast_hours(A, ctx, ctx_next, open, close):
    """How many clock hours of [open, close) have no usable forecast, each in the context of its own
    civil date. An hour whose context is typical is not counted."""
    count = 0
    for (day_index, hour, start, end, fraction) in clock_hours(open, close):
        cx = ctx if day_index == 0 else ctx_next
        if cx is None or cx["typical"]:
            continue
        fc = cx["forecast"][hour] if cx["forecast"] is not None else None
        if weather_multiplier(A, fc, "open")["missing"]:
            count += 1
    return count


def day_plan(A, profile, plan, ctx, ctx_next, legs, cal):
    """Evaluate a day as the owner ordered it: timeline, each stop's orders and money, what each stop
    adds, the day's costs and take-home, the unpaid-gap alternative and the warnings."""
    stops_in = plan["stops"]
    n = len(stops_in)

    # A plan with an invalid window or overlapping stops is not evaluated.
    blocking = []
    for i in range(n):
        s = stops_in[i]
        if s["open_minute"] < 0 or s["close_minute"] > 2880 or s["close_minute"] <= s["open_minute"]:
            blocking.append(_warning("invalid_window", "error", i,
                                     {"open_minute": s["open_minute"], "close_minute": s["close_minute"]}))
    for i in range(1, n):
        s = stops_in[i]
        if s["open_minute"] < stops_in[i - 1]["close_minute"]:
            blocking.append(_warning("stops_overlap", "error", i,
                                     {"open_minute": s["open_minute"],
                                      "previous_close_minute": stops_in[i - 1]["close_minute"]}))
    if len(blocking) > 0:
        zero = est_fixed(0.0)
        totals = {}
        for name in ("orders", "sales", "food_cost", "packaging", "card_fees", "spot_fees", "tips", "contribution",
                     "labour", "fuel", "tolls", "fixed_cost", "take_home", "take_home_per_hour"):
            totals[name] = dict(zero)
        for name in ("day_hours", "paid_hours", "work_hours", "unpaid_gap_hours"):
            totals[name] = 0.0
        totals["drive_minutes"] = 0
        totals["miles"] = 0.0
        totals["drive_gallons"] = 0.0
        totals["generator_gallons"] = 0.0
        return {"model_version": MODEL_VERSION, "seeds_revision": A["seeds_revision"], "date": plan["date"],
                "timeline": _empty_timeline(), "stops": [], "totals": totals,
                "unpaid_gap_alternative": None, "warnings": blocking}

    R = evaluate(A, profile, plan, ctx, ctx_next, legs, cal)
    T = R["timeline"]

    # What each stop adds: the whole day with the stop minus the whole day without it.
    day_stops = []
    for i in range(n):
        s = stops_in[i]
        without = {"date": plan["date"], "stops": stops_in[:i] + stops_in[i + 1:]}
        R_i = evaluate(A, profile, without, ctx, ctx_next, legs, cal)
        add_take_home = est_levels(R["take_home"]["value"] - R_i["take_home"]["value"],
                                   R["take_home"]["low"] - R_i["take_home"]["low"],
                                   R["take_home"]["high"] - R_i["take_home"]["high"],
                                   R["stops"][i]["orders"]["confidence"])
        add_hours = R["work_hours"] - R_i["work_hours"]
        add_per_hour = None
        if add_hours > 0:
            add_per_hour = est_levels(add_take_home["value"] / add_hours, add_take_home["low"] / add_hours,
                                      add_take_home["high"] / add_hours, add_take_home["confidence"])
        added_costs = R["costs"]["total"] - R_i["costs"]["total"]
        break_even = None
        if s["kind"] == "spot" or s["kind"] == "event":
            break_even = break_even_orders(profile, s["terms"], added_costs)
        uses_fallback = False
        for leg in R_i["timeline"]["legs"]:
            if leg["source"] == "fallback":
                uses_fallback = True
        st = dict(R["stops"][i])
        st["adds"] = {"take_home": add_take_home, "hours": add_hours, "per_hour": add_per_hour,
                      "added_costs": added_costs, "break_even_orders": break_even,
                      "uses_fallback_leg": uses_fallback}
        day_stops.append(st)

    # What the day would clear if every paid gap were an unpaid break.
    alternative = None
    has_paid_gap = False
    for ts in T["stops"]:
        if ts["stop_index"] >= 1 and ts["gap_before_minutes"] > 0 and not ts["gap_unpaid"]:
            has_paid_gap = True
    if has_paid_gap:
        unpaid_plan = {"date": plan["date"], "stops": [dict(s, gap_before_unpaid=True) for s in stops_in]}
        R_u = evaluate(A, profile, unpaid_plan, ctx, ctx_next, legs, cal)
        alternative = {"take_home": R_u["take_home"], "take_home_per_hour": R_u["take_home_per_hour"],
                       "work_hours": R_u["work_hours"],
                       "labour_saved": R["costs"]["labour"] - R_u["costs"]["labour"]}

    # Warnings, in the order of the table in 4.12; stops in index order within a code.
    warnings = []
    if n > 0:
        for i in range(n):
            ts = T["stops"][i]
            if ts["effective_open"] >= ts["close"]:
                warnings.append(_warning("stop_unreachable", "error", i,
                                         {"arrive": ts["arrive"], "effective_open": ts["effective_open"],
                                          "close_minute": ts["close"]}))
        for i in range(n):
            ts = T["stops"][i]
            if ts["late_minutes"] > 0 and not (ts["effective_open"] >= ts["close"]):
                warnings.append(_warning("late_arrival", "warn", i,
                                         {"late_minutes": ts["late_minutes"], "effective_open": ts["effective_open"]}))
        for i in range(n):
            s = stops_in[i]
            if s["kind"] == "spot" and not s["vectors"]["in_region"]:
                warnings.append(_warning("outside_region", "warn", i, {}))
        for i in range(n):
            s = stops_in[i]
            if s["kind"] == "spot" and not vectors_match(A, s["terms"], s["vectors"]):
                warnings.append(_warning("stale_vectors", "error", i, {}))
        for i in range(n):
            s = stops_in[i]
            if s["kind"] == "spot" and s["terms"].get("allowed") is not None:
                allowed = s["terms"]["allowed"]
                if (not allowed["days"][ctx["dow"]] or s["open_minute"] < allowed["open_minute"]
                        or s["close_minute"] > allowed["close_minute"]):
                    warnings.append(_warning("outside_allowed_hours", "warn", i,
                                             {"dow": ctx["dow"], "open_minute": s["open_minute"],
                                              "close_minute": s["close_minute"]}))
        fallback_keys = []
        for leg in T["legs"]:
            if leg["source"] == "fallback":
                fallback_keys.append(leg["from_id"] + ">" + leg["to_id"])
        if len(fallback_keys) > 0:
            warnings.append(_warning("fallback_drive_time", "warn", None, {"legs": fallback_keys}))
        for i in range(n):
            ts = T["stops"][i]
            if ts["gap_before_minutes"] >= seed(A, "timeline.long_gap_minutes") and not ts["gap_unpaid"]:
                warnings.append(_warning("long_gap", "warn", i, {"gap_before_minutes": ts["gap_before_minutes"]}))
        if T["day_minutes"] > seed(A, "timeline.long_day_minutes"):
            warnings.append(_warning("long_day", "warn", None, {"day_minutes": T["day_minutes"]}))
        for i in range(n):
            money = day_stops[i]["money"]
            if (money["sales"]["value"] > 0
                    and money["spot_fee"]["value"] > seed(A, "money.fee_warn_share") * money["sales"]["value"]):
                warnings.append(_warning("fee_high", "warn", i,
                                         {"spot_fee": money["spot_fee"]["value"], "sales": money["sales"]["value"]}))
        for i in range(n):
            add = day_stops[i]["adds"]["take_home"]
            if add["value"] < 0:
                warnings.append(_warning("below_break_even", "warn", i, {"take_home": add["value"]}))
        for i in range(n):
            s = stops_in[i]
            if s["kind"] == "event":
                per_vendor = (s["event"]["attendance"] * seed(A, "events.attendance_haircut")
                              / max(1, s["event"]["vendors"]))
                if per_vendor < seed(A, "events.min_attendees_per_vendor"):
                    warnings.append(_warning("event_thin_crowd", "warn", i, {"attendees_per_vendor": per_vendor}))
        for i in range(n):
            add = day_stops[i]["adds"]["take_home"]
            if add["value"] >= 0 and add["low"] < 0:
                warnings.append(_warning("weak_day_loss", "info", i, {"take_home_low": add["low"]}))
        for i in range(n):
            st = day_stops[i]
            capped_hours = 0
            if st["kind"] == "spot":
                capped_hours = st["window"]["capped_hours"]
            elif st["kind"] == "event":
                for eh in st["event"]["hours"]:
                    if eh["demand"] > eh["capacity"]:
                        capped_hours += 1
            if capped_hours > 0:
                warnings.append(_warning("capacity_bound", "info", i, {"capped_hours": capped_hours}))
        if T["start_prep"] < seed(A, "timeline.early_start_minute"):
            warnings.append(_warning("early_start", "info", None, {"start_prep": T["start_prep"]}))
        if T["done"] > 1440:
            warnings.append(_warning("ends_after_midnight", "info", None, {"done": T["done"]}))
        if not ctx["typical"]:
            missing = 0
            for i in range(n):
                s = stops_in[i]
                if s["kind"] == "spot" or s["kind"] == "event":
                    missing += _missing_forecast_hours(A, ctx, ctx_next, T["stops"][i]["effective_open"],
                                                       s["close_minute"])
            if missing > 0:
                warnings.append(_warning("no_forecast", "info", None, {"hours": missing}))
        if ctx["holiday_class"] is not None:
            warnings.append(_warning("holiday", "info", None,
                                     {"holiday_id": ctx["holiday"]["id"] if ctx["holiday"] is not None else None,
                                      "holiday_class": ctx["holiday_class"]}))
        for i in range(n):
            st = day_stops[i]
            if st["kind"] == "spot" and st["window"]["evidence"]["weak_share"] >= 0.5:
                warnings.append(_warning("weak_seed", "info", i, {"weak_share": st["window"]["evidence"]["weak_share"]}))
        for i in range(n):
            s = stops_in[i]
            st = day_stops[i]
            if (s["kind"] == "spot" and s["terms"]["host"] is not None
                    and s["terms"]["host"]["size_source"] == "default" and st["window"]["host_orders"] > 0):
                warnings.append(_warning("default_host_size", "info", i, {"size": s["terms"]["host"]["size"]}))

    return {"model_version": MODEL_VERSION, "seeds_revision": A["seeds_revision"], "date": plan["date"],
            "timeline": T, "stops": day_stops, "totals": R["totals"],
            "unpaid_gap_alternative": alternative, "warnings": warnings}


# =================================================================================================
# 4.13 Calibration and accuracy
# =================================================================================================

def calibrate(A, services, as_of):
    """What the owner's logged services say: one factor for the truck, one per spot.

    Shrinkage on log ratios of actual to predicted orders, weighted by recency. A sold-out service is a
    lower bound on demand: it is used only if it says more than the other logs say about its own spot.
    Ordinary statistics; nothing here is learned by a model."""
    k_truck = seed(A, "calibration.k_truck")
    k_spot = seed(A, "calibration.k_spot")
    half_life = seed(A, "calibration.half_life_days")
    ln_ratio = ln(seed(A, "calibration.ratio_clamp"))
    ln_spot_ratio = ln(seed(A, "calibration.spot_ratio_clamp"))
    min_predicted = seed(A, "calibration.min_predicted")
    min_actual = seed(A, "calibration.min_actual")
    as_of_day = _day_number(as_of)

    rows = []
    for sv in sorted(services, key=lambda item: (item["date"], item["service_id"])):
        if sv["kind"] != "spot" or sv["spot_id"] is None or not (sv["predicted_raw"] > 0):
            continue
        age = as_of_day - _day_number(sv["date"])
        if age < 0:
            continue
        w = exp(-LN2 * age / half_life)
        R = ln(max(sv["actual"], min_actual) / max(sv["predicted_raw"], min_actual))
        rows.append({"spot_id": sv["spot_id"], "sold_out": bool(sv["sold_out"]), "w": w,
                     "Lt": clamp(R, -ln_ratio, ln_ratio),            # what the service tells the truck factor
                     "Ls": clamp(R, -ln_spot_ratio, ln_spot_ratio),  # what it tells its own spot
                     "in_truck": sv["predicted_raw"] >= min_predicted})
    spot_ids = sorted(set([r["spot_id"] for r in rows]))

    # Pass A: services that were not sold out.
    num = 0.0
    den = 0.0
    for r in rows:
        if r["in_truck"] and not r["sold_out"]:
            num += r["w"] * r["Lt"]
            den += r["w"]
    m_a = num / (k_truck + den)
    s_a = {}
    for spot_id in spot_ids:
        num = 0.0
        den = 0.0
        for r in rows:
            if r["spot_id"] == spot_id and not r["sold_out"]:
                num += r["w"] * (r["Ls"] - m_a)
                den += r["w"]
        s_a[spot_id] = num / (k_spot + den)
    for r in rows:
        r["used"] = (not r["sold_out"]) or (r["Ls"] > m_a + s_a[r["spot_id"]])

    # Truck factor, from used rows with in_truck.
    num = 0.0
    truck_weight = 0.0
    truck_n = 0
    for r in rows:
        if r["used"] and r["in_truck"]:
            num += r["w"] * r["Lt"]
            truck_weight += r["w"]
            truck_n += 1
    truck_log_factor = num / (k_truck + truck_weight)

    # Spot factors, from every used row of the spot.
    spots = {}
    for spot_id in spot_ids:
        num = 0.0
        weight = 0.0
        count = 0
        for r in rows:
            if r["used"] and r["spot_id"] == spot_id:
                num += r["w"] * (r["Ls"] - truck_log_factor)
                weight += r["w"]
                count += 1
        if count == 0:
            continue
        log_factor = num / (k_spot + weight)
        spots[spot_id] = {"factor": exp(log_factor), "log_factor": log_factor, "n": count, "weight": weight}

    # Residual spread, from used rows with in_truck that were not sold out.
    num = 0.0
    resid_weight = 0.0
    resid_n = 0
    for r in rows:
        if r["used"] and r["in_truck"] and not r["sold_out"]:
            resid = r["Ls"] - truck_log_factor - spots[r["spot_id"]]["log_factor"]
            num += r["w"] * resid * resid
            resid_weight += r["w"]
            resid_n += 1
    resid_sd = sqrt(num / resid_weight) if resid_n >= seed(A, "calibration.min_resid_n") else None

    # The mean of log ratios estimates a geometric mean; the correction turns the truck factor into a mean.
    bias_log = 0.5 * resid_sd * resid_sd * truck_weight / (k_truck + truck_weight) if resid_sd is not None else 0.0
    truck_factor = exp(truck_log_factor + bias_log)
    return {"model_version": A["model_version"], "seeds_revision": A["seeds_revision"], "as_of": as_of,
            "truck_factor": truck_factor, "truck_log_factor": truck_log_factor, "bias_log": bias_log,
            "truck_n": truck_n, "truck_weight": truck_weight, "spots": spots,
            "resid_sd": resid_sd, "resid_n": resid_n, "resid_weight": resid_weight}


def _accuracy_block(rows):
    scored = [e for e in sorted(rows, key=lambda item: (item["date"], item["service_id"])) if not e["sold_out"]]
    block = {"n_total": len(rows), "n_scored": len(scored), "n_sold_out": len(rows) - len(scored),
             "bias": None, "mape": None, "coverage": None, "raw_bias": None, "raw_mape": None}
    if len(scored) == 0:
        return block
    sum_actual = 0.0
    sum_diff = 0.0
    sum_ape = 0.0
    raw_diff = 0.0
    raw_ape = 0.0
    inside = 0
    for e in scored:
        sum_actual += e["actual"]
        sum_diff += e["predicted"] - e["actual"]
        sum_ape += abs(e["predicted"] - e["actual"]) / max(e["actual"], 1.0)
        raw_diff += e["predicted_raw"] - e["actual"]
        raw_ape += abs(e["predicted_raw"] - e["actual"]) / max(e["actual"], 1.0)
        if e["low"] <= e["actual"] and e["actual"] <= e["high"]:
            inside += 1
    if sum_actual != 0:
        block["bias"] = sum_diff / sum_actual                       # > 0: the model predicted too much
        block["raw_bias"] = raw_diff / sum_actual
    block["mape"] = sum_ape / len(scored)
    block["raw_mape"] = raw_ape / len(scored)
    block["coverage"] = inside / len(scored)
    return block


def accuracy_report(entries):
    """How the estimates did against the logged services. Sold-out services are counted, not scored."""
    report = _accuracy_block(entries)
    by_spot = []
    for spot_id in sorted(set([e["spot_id"] for e in entries if e["spot_id"] is not None])):
        block = _accuracy_block([e for e in entries if e["spot_id"] == spot_id])
        block["spot_id"] = spot_id
        by_spot.append(block)
    report["by_spot"] = by_spot
    return report


# =================================================================================================
# 4.14 Events and catering
# =================================================================================================

def event_orders(A, profile, ev, cal, ctx, ctx_next, open, close):
    """An attendance-based estimate. It replaces the map-based one entirely: the crowd is the
    organiser's, not the neighbourhood's. Demand is spread evenly over the window and capped hour by
    hour; menu fit is not applied; vendors counts every food vendor including this truck."""
    if not (0 <= open and open <= close and close <= 2880):
        raise ModelError("invalid_window")
    buyers = ev["attendance"] * seed(A, "events.attendance_haircut") * seed(A, "events.p_buy." + ev["event_type"])
    demand = buyers / max(1, ev["vendors"]) * (cal["truck_factor"] if cal is not None else 1.0)
    minutes = close - open
    hours = []
    d = []
    c = []
    for (day_index, hour, start, end, fraction) in clock_hours(open, close):
        cx = ctx if day_index == 0 else ctx_next
        if cx is None:
            raise ModelError("missing_context")
        if cx["typical"]:
            wx = 1.0
        else:
            fc = cx["forecast"][hour] if cx["forecast"] is not None else None
            wx = weather_multiplier(A, fc, "open")["multiplier"]
        d_h = demand * (end - start) / minutes * wx
        cap_h = profile["capacity_orders_per_hour"] * fraction
        d.append(d_h)
        c.append(cap_h)
        hours.append({"day_index": day_index, "hour": hour, "fraction": fraction, "demand": d_h,
                      "capacity": cap_h, "weather": wx, "orders": min(d_h, cap_h)})
    evidence = evidence_from(cal, None)                             # events use the truck factor only
    evidence["event"] = True
    (orders, spread) = interval_capped(A, d, c, evidence)
    return {"orders": orders, "buyers": buyers, "demand": demand, "hours": hours, "spread": spread}


def catering_money(profile, ct):
    """A guaranteed-fee stop: revenue is contracted, so every line is fixed and adds nothing to the width
    of the day's range. It still takes time, fuel and labour through the timeline."""
    price = ct["price_per_head"] if ct.get("price_per_head") is not None else 0.0
    guarantee = ct["guarantee"] if ct.get("guarantee") is not None else 0.0
    sales = max(ct["headcount"] * price, guarantee)
    orders = ct["headcount"] * 1.0
    food_cost = ct["food_cost"] if ct.get("food_cost") is not None else sales * profile["food_cost_pct"]
    packaging = ct["headcount"] * profile["packaging_per_order"]
    contribution = sales - food_cost - packaging
    return {"orders": est_fixed(orders), "sales": est_fixed(sales), "food_cost": est_fixed(food_cost),
            "packaging": est_fixed(packaging), "card_fees": est_fixed(0.0), "spot_fee": est_fixed(0.0),
            "tips": est_fixed(0.0), "contribution": est_fixed(contribution),
            "unit_margin": {"at_minimum": 0.0, "at_percentage": 0.0}}       # not used for a contracted stop


# =================================================================================================
# 4.15 Suggestions
# =================================================================================================

def _option(options, name, default):
    if options is not None and options.get(name) is not None:
        return options[name]
    return default


def suggest_day(A, profile, ctx, ctx_next, spots, legs, cal, options):
    """The best day plans from the owner's saved spots for one date, ranked by expected take-home.
    Exhaustive within exact limits: candidate windows per spot, kept per daypart, then every ordered
    subset of 1..max_stops candidates that can be driven without arriving late."""
    service = _option(options, "service_minutes", seed(A, "suggest.service_minutes"))     # a multiple of 60
    max_stops = _option(options, "max_stops_per_day", seed(A, "suggest.max_stops_per_day"))
    limit = _option(options, "limit", seed(A, "suggest.day_results"))
    L = floor_div(service, 60)
    first = floor_div(seed(A, "suggest.earliest_open_minute"), 60)
    last = floor_div(seed(A, "suggest.latest_close_minute"), 60)                         # hours [first, last)
    daypart_of_hour = seed(A, "hours.daypart_of_hour")

    def plan_of(cands):
        stops = []
        for cand in cands:
            spot = cand["spot"]
            stops.append({"id": spot["spot_id"], "kind": "spot", "spot_id": spot["spot_id"], "point": spot["point"],
                          "open_minute": cand["open"], "close_minute": cand["close"], "gap_before_unpaid": False,
                          "setup_minutes": None, "teardown_minutes": None,
                          "terms": spot["terms"], "vectors": spot["vectors"], "event": None, "catering": None})
        return {"date": ctx["date"], "stops": stops}

    # 1. Candidates: the best windows of each spot.
    candidates = []
    for spot in sorted(spots, key=lambda item: item["spot_id"]):
        values = []
        allowed = []
        rule = spot["terms"].get("allowed")
        for k in range(last - first):
            hour = first + k
            values.append(hourly_orders(A, profile, spot["terms"], spot["vectors"], cal, ctx, hour)["orders"])
            if rule is None:
                allowed.append(True)
            else:
                allowed.append(bool(rule["days"][ctx["dow"]]) and hour * 60 >= rule["open_minute"]
                               and (hour + 1) * 60 <= rule["close_minute"])
        for b in best_windows(values, L, seed(A, "suggest.windows_per_spot"), False, allowed):
            if b["total"] < seed(A, "suggest.min_stop_orders"):
                continue
            cand = {"spot": spot, "spot_id": spot["spot_id"], "open": (first + b["start"]) * 60}
            cand["close"] = cand["open"] + service
            cand["single"] = evaluate(A, profile, plan_of([cand]), ctx, ctx_next, legs, cal)["take_home"]["value"]
            candidates.append(cand)
    candidates.sort(key=lambda cand: (-qkey(cand["single"]), cand["spot_id"], cand["open"]))
    max_candidates = seed(A, "suggest.max_candidates")
    per_daypart = floor_div(max_candidates, 4)
    keep = [False] * len(candidates)
    kept = 0
    for part in DAYPARTS:                                           # so lunch cannot crowd out the evening
        got = 0
        for j in range(len(candidates)):
            if got == per_daypart:
                break
            if not keep[j] and daypart_of_hour[floor_div(candidates[j]["open"], 60)] == part:
                keep[j] = True
                got += 1
                kept += 1
    for j in range(len(candidates)):
        if kept >= max_candidates:
            break
        if not keep[j]:
            keep[j] = True
            kept += 1
    pool = [candidates[j] for j in range(len(candidates)) if keep[j]]
    pool.sort(key=lambda cand: (cand["open"], cand["spot_id"]))

    # 2. Plans: every subset of 1..max_stops candidates, in (open, spot_id) order.
    feasible = []

    def consider(chosen):
        for a in range(len(chosen)):
            for b in range(a + 1, len(chosen)):
                if chosen[a]["spot_id"] == chosen[b]["spot_id"]:
                    return                                          # a spot appears at most once per day
        for a in range(1, len(chosen)):
            if chosen[a]["open"] < chosen[a - 1]["close"]:
                return
        plan = plan_of(chosen)
        R = evaluate(A, profile, plan, ctx, ctx_next, legs, cal)
        for ts in R["timeline"]["stops"]:
            if ts["late_minutes"] != 0:
                return
        if R["timeline"]["day_minutes"] > seed(A, "suggest.max_day_minutes"):
            return
        feasible.append({"plan": plan, "take_home": R["take_home"]["value"],
                         "day_minutes": R["timeline"]["day_minutes"],
                         "key": [(cand["spot_id"], cand["open"]) for cand in chosen]})

    def extend(start, chosen):
        if len(chosen) > 0:
            consider(chosen)
        if len(chosen) == max_stops:
            return
        for j in range(start, len(pool)):
            extend(j + 1, chosen + [pool[j]])

    extend(0, [])

    # 3. Rank.
    feasible.sort(key=lambda item: (-qkey(item["take_home"]), len(item["key"]), item["day_minutes"], item["key"]))
    out = []
    for item in feasible[:limit]:
        result = day_plan(A, profile, item["plan"], ctx, ctx_next, legs, cal)
        out.append({"date": ctx["date"], "position": len(out) + 1,
                    "stops": [{"spot_id": s["spot_id"], "open_minute": s["open_minute"], "close_minute": s["close_minute"]}
                              for s in item["plan"]["stops"]],
                    "take_home": result["totals"]["take_home"], "orders": result["totals"]["orders"],
                    "day_minutes": result["timeline"]["day_minutes"], "result": result})
    return out


def suggest_week(A, profile, week_start, contexts, spots, legs, cal, options):
    """The best week from the day suggestions: which days to work and which plan on each, under a limit
    on working days and on visits per spot. Exhaustive (at most 6^7 leaves). Among equal totals the first
    one found wins: earlier days prefer higher-ranked plans and working over resting."""
    day_options = {"service_minutes": _option(options, "service_minutes", None),
                   "max_stops_per_day": _option(options, "max_stops_per_day", None),
                   "limit": seed(A, "suggest.week_day_options")}
    opts = []
    for d in range(7):
        plans = suggest_day(A, profile, contexts[d], contexts[d + 1], spots, legs, cal, day_options)
        opts.append([p for p in plans if p["take_home"]["value"] > seed(A, "suggest.min_day_take_home")])
    max_days = _option(options, "max_days_per_week", seed(A, "suggest.max_days_per_week"))
    max_visits = _option(options, "max_visits_per_spot_per_week", seed(A, "suggest.max_visits_per_spot_per_week"))

    state = {"best_total": None, "best_picks": None, "leaves": 0}
    visits = {}

    def search(d, total, picks, days_used):
        if d == 7:
            state["leaves"] += 1
            if state["best_total"] is None or qkey(total) > qkey(state["best_total"]):
                state["best_total"] = total
                state["best_picks"] = list(picks)
            return
        for idx in range(len(opts[d])):                             # work options first, best first
            plan = opts[d][idx]
            ok = days_used < max_days
            for stop in plan["stops"]:
                if visits.get(stop["spot_id"], 0) + 1 > max_visits:
                    ok = False
            if not ok:
                continue
            for stop in plan["stops"]:
                visits[stop["spot_id"]] = visits.get(stop["spot_id"], 0) + 1
            search(d + 1, total + plan["take_home"]["value"], picks + [idx], days_used + 1)
            for stop in plan["stops"]:
                visits[stop["spot_id"]] -= 1
        search(d + 1, total, picks + [None], days_used)             # then the day off

    search(0, 0.0, [], 0)

    days = []
    chosen = []
    counts = {}
    for d in range(7):
        idx = state["best_picks"][d]
        suggestion = None if idx is None else opts[d][idx]
        if suggestion is not None:
            chosen.append(suggestion["take_home"])
            for stop in suggestion["stops"]:
                counts[stop["spot_id"]] = counts.get(stop["spot_id"], 0) + 1
        days.append({"date": add_days(week_start, d), "suggestion": suggestion})
    return {"week_start": week_start, "days": days, "total_take_home": est_sum(chosen),
            "visits": counts, "leaves_visited": state["leaves"]}


# =================================================================================================
# 4.16 Scouting
# =================================================================================================

def strip_from_rows(A, profile, terms, vectors, rows):
    """The week strip from the weight rows of 4.17 in one pass (truck factor only, no spot factor). Equal
    to week_strip to the tolerance of 1.4 when terms.spot_id is null."""
    regime_of_hour = seed(A, "hours.regime_of_hour")
    host = terms["host"]
    has_host = host is not None and host["size"] > 0
    if has_host:
        hc = host_capture(A, host, terms["visibility"], vectors["rivals"])
        hs = SEGMENTS.index(host["segment"])
    out = [0.0] * 168
    for how in range(168):
        regime = regime_of_hour[mod_floor(how, 24)]
        o = 0.0
        for s in range(NSEG):
            o += vectors["capture"][regime][s] * rows["w_opp"][how][s]
        if has_host:
            o += hc[regime] * rows["w_opp"][how][hs]
        out[how] = min(o, profile["capacity_orders_per_hour"])
    return out


def scout_estimate(A, profile, place, legs, cal, fuel_price_per_gal):
    """One candidate host: its best three hours in a typical week, what they would leave, the cost of
    driving there and back, and a score that ranks (it is not shown as money). None for a place type that
    does not host trucks. The fuel price must be a number, whether or not this place ends up using it."""
    require_number(fuel_price_per_gal, "fuel_price_per_gal")
    row = seed(A, "place_types.rows." + place["place_type"])
    if row["host_fit"] <= 0:
        return None
    kitchen = row["kitchen_default"] if place.get("kitchen") is None or place["kitchen"] == "unknown" else place["kitchen"]
    host = None
    if row["host_segment"] is not None and place["size_default"] > 0:
        host = {"segment": row["host_segment"], "size": place["size_default"], "size_source": "default",
                "only_food": kitchen == "no", "point_id": place.get("point_id"), "place_type": place["place_type"]}
    terms = {"spot_id": None, "visibility": "normal", "host": host,
             "fee_flat": 0.0, "fee_pct": 0.0, "fee_min": 0.0, "allowed": None}
    rows = map_weight_rows(A, profile, cal)                         # 4.17; the same for every place of a request
    strip = strip_from_rows(A, profile, terms, place["vectors"], rows)
    window_minutes = seed(A, "scout.window_minutes")
    b = best_windows(strip, floor_div(window_minutes, 60), 1, True)
    result = {"place_id": place["place_id"], "place_type": place["place_type"], "position": 0,
              "host_fit": row["host_fit"], "kitchen": kitchen, "host_segment": row["host_segment"],
              "host_size": place["size_default"] if host is not None else 0.0, "size_source": "default"}
    if len(b) == 0:
        (z, spread) = interval(A, 0.0, evidence_from(cal, None))
        result["best_window"] = None
        result["orders"] = {"value": 0.0, "low": 0.0, "high": 0.0, "confidence": z["confidence"]}
        result["contribution"] = {"value": 0.0, "low": 0.0, "high": 0.0, "confidence": z["confidence"]}
        result["round_trip"] = {"minutes": 0, "miles": 0.0, "cost": 0.0}
        result["score"] = 0.0
        return result
    dow = floor_div(b[0]["start"], 24)
    open_minute = mod_floor(b[0]["start"], 24) * 60
    close_minute = open_minute + window_minutes
    ctx = typical_context(A, dow)
    W = window_orders(A, profile, terms, place["vectors"], cal, ctx, typical_context(A, mod_floor(dow + 1, 7)),
                      open_minute, close_minute)
    money = stop_money(profile, terms, W["orders"])
    stop = {"id": place["place_id"], "kind": "spot", "spot_id": None, "point": place["point"],
            "open_minute": open_minute, "close_minute": close_minute, "gap_before_unpaid": False,
            "setup_minutes": None, "teardown_minutes": None}
    T = build_timeline(A, profile, ctx, [stop], legs)               # legs "base><place_id>", "<place_id>>base"
    cost = (T["drive_minutes"] / 60.0 * profile["paid_crew"] * profile["wage_per_hour"] * (1.0 + profile["payroll_burden_pct"])
            + T["miles"] / profile["mpg"] * fuel_price_per_gal + T["tolls"])
    result["best_window"] = {"dow": dow, "open_minute": open_minute, "close_minute": close_minute}
    result["orders"] = W["orders"]
    result["contribution"] = money["contribution"]
    result["round_trip"] = {"minutes": T["drive_minutes"], "miles": T["miles"], "cost": cost}
    result["score"] = row["host_fit"] * money["contribution"]["value"] - cost
    return result


def scout_rank(results):
    """Best score first, ties by place_id; numbered from 1; at most scout.max_results."""
    ranked = sorted(results, key=lambda r: (-qkey(r["score"]), r["place_id"]))
    out = []
    for r in ranked[:SEEDS["scout"]["max_results"]["value"]]:
        numbered = dict(r)
        numbered["position"] = len(out) + 1
        out.append(numbered)
    return out


# =================================================================================================
# 4.17 Map fast path
# =================================================================================================

def map_weight_rows(A, profile, cal):
    """Per hour of the week and segment: w_opp turns a capture vector into expected orders, w_people a
    nearby vector into people present. The map never applies weather, spot factors or hosts."""
    E = expand_curves(A)
    tf = cal["truck_factor"] if cal is not None else 1.0
    daypart_of_hour = seed(A, "hours.daypart_of_hour")
    w_opp = []
    w_people = []
    for how in range(168):
        fit = profile["daypart_fit"][daypart_of_hour[mod_floor(how, 24)]]
        w_opp.append([E["presence"][s][how] * E["intent"][s][how] * fit * tf for s in range(NSEG)])
        w_people.append([E["presence"][s][how] for s in range(NSEG)])
    return {"w_opp": w_opp, "w_people": w_people}


def cell_scores(features, n, w_opp_row, w_people_row, regime, capacity):
    """Score n map cells for one hour. features holds 50 numbers per cell: capture.day[16],
    capture.eve[16], nearby[16], rivals.day, rivals.eve."""
    off = 0 if regime == "day" else 16
    ri = 48 if regime == "day" else 49
    opportunity = [0.0] * n
    people = [0.0] * n
    competition = [0.0] * n
    for cell in range(n):
        b = 50 * cell
        o = 0.0
        for s in range(NSEG):
            o += features[b + off + s] * w_opp_row[s]
        opportunity[cell] = min(o, capacity)
        p = 0.0
        for s in range(NSEG):
            p += features[b + 32 + s] * w_people_row[s]
        people[cell] = p
        competition[cell] = features[b + ri]
    return {"opportunity": opportunity, "people": people, "competition": competition}


def score_byte(x, hi):
    """Map colour byte 0..255 on a square-root scale with a fixed top."""
    t = clamp(x / hi, 0.0, 1.0)
    return floor(255.0 * sqrt(t) + 0.5)


# =================================================================================================
# 8. Golden cases
#
# Everything below is test support: the fixtures the cases are built on, the case list, the self-test
# and the golden-file writer and checker. The model above never calls into it.
# =================================================================================================

FAMILY_NAMES = {
    "g01": "rounding", "g02": "dates", "g03": "holidays", "g04": "day context", "g05": "curves",
    "g06": "geometry", "g07": "rivals", "g08": "capture", "g09": "host", "g10": "weather",
    "g11": "hourly orders", "g12": "window orders", "g13": "week and windows", "g14": "ranges",
    "g15": "money", "g16": "driving", "g17": "timeline", "g18": "day plan", "g19": "calibration",
    "g20": "events and catering", "g21": "suggestions", "g22": "scouting", "g23": "fast path",
    "g24": "seeds and anchors",
}

WARNING_CODES = ["invalid_window", "stops_overlap", "stop_unreachable", "late_arrival", "outside_region",
                 "stale_vectors", "outside_allowed_hours", "fallback_drive_time", "long_gap", "long_day",
                 "fee_high", "below_break_even", "event_thin_crowd", "weak_day_loss", "capacity_bound",
                 "early_start", "ends_after_midnight", "no_forecast", "holiday", "weak_seed",
                 "default_host_size"]

OVERRIDE_ERRORS = ["unknown_path", "not_a_seed", "not_overridable", "not_a_leaf", "wrong_shape",
                   "out_of_bounds", "not_allowed"]

TRUCK_LAT = 38.96                                 # the truck position of the 4.4 layout (anchor A1)
TRUCK_LNG = -77.36
FUEL = 4.195                                      # money.fuel_price_fallback.gasoline.R1Z


class _CaseList:
    """The golden cases in their fixed order, the documented numbers they must reproduce, the anchors."""

    def __init__(self):
        self.cases = []
        self.counts = {}
        self.documented = []                      # (case id, path, documented value, decimals or None)
        self.anchors = []

    def add(self, family, function, args):
        self.counts[family] = self.counts.get(family, 0) + 1
        case_id = "%s-%03d" % (family, self.counts[family])
        self.cases.append({"id": case_id, "family": FAMILY_NAMES[family], "function": function, "args": args})
        return case_id

    def doc(self, case_id, path, want, decimals=None):
        """The document prints this number for this case. decimals = the digits it prints (None: exact)."""
        self.documented.append((case_id, path, want, decimals))

    def calc(self, case_id, label, function, want, decimals=None):
        """The document prints a number derived from this case's result (a ratio, a product)."""
        self.documented.append((case_id, (label, function), want, decimals))

    def known(self, case_id, path, want, decimals=None):
        """A value worked out by hand for an edge case: asserted like a documented number, whether or not
        the document prints it. It says what the case is there to pin down."""
        self.documented.append((case_id, path, want, decimals))


def _a(overrides=None, region=None):
    """The golden `A` argument: { overrides, region }; the seed file is implied (8.1)."""
    return {"overrides": {} if overrides is None else overrides, "region": REGION_DC if region is None else region}


def _assume(a):
    return make_assumptions(a["overrides"], a["region"])


def _north(metres):
    """Latitude `metres` due north of the 4.4 truck position, evaluated left to right as the document says."""
    return 38.96 + metres / 6371008.8 * 180.0 / 3.141592653589793


def _base(**amounts):
    base = [0.0] * NSEG
    for name in amounts:
        base[SEGMENTS.index(name)] = amounts[name]
    return base


def _no_exclusion():
    return {"point_ids": [], "segment": None, "amount": 0.0}


def _zero_vectors(visibility="normal", exclusion=None, in_region=True):
    return {"capture": {"day": [0.0] * NSEG, "eve": [0.0] * NSEG}, "nearby": [0.0] * NSEG, "within": [0.0] * NSEG,
            "rivals": {"day": 0.0, "eve": 0.0}, "visibility": visibility, "in_region": in_region, "region_id": None,
            "exclusion": _no_exclusion() if exclusion is None else exclusion, "excluded_amount": 0.0,
            "points_used": 0, "dataset_version": None, "model_version": MODEL_VERSION}


def _stored(vectors):
    """Vectors as decoded from 50 stored numbers (the cell pack, tp_places.host_vec): no within, no count."""
    out = dict(vectors)
    out["within"] = None
    out["points_used"] = None
    return out


def _profile(**changes):
    """The default truck of the seed file, based in Sterling, Virginia."""
    defaults = SEEDS["profile_defaults"]
    p = {"name": "Reference truck", "region_id": "dc",
         "base": {"lat": 39.003, "lng": -77.405, "address": "Sterling, VA"},
         "fuel_price_override": None, "licence_counties": []}
    for field in defaults:
        if field == "daypart_fit":
            p[field] = dict((part, defaults[field][part]) for part in DAYPARTS)
        else:
            p[field] = defaults[field]["value"]
    for name in changes:
        p[name] = changes[name]
    return p


def _host(segment, size, size_source="owner", only_food=True, point_id=None, place_type=None):
    return {"segment": segment, "size": size, "size_source": size_source, "only_food": only_food,
            "point_id": point_id, "place_type": place_type}


def _terms(spot_id=None, visibility="normal", host=None, fee_flat=0.0, fee_pct=0.0, fee_min=0.0, allowed=None):
    return {"spot_id": spot_id, "visibility": visibility, "host": host,
            "fee_flat": fee_flat, "fee_pct": fee_pct, "fee_min": fee_min, "allowed": allowed}


def _fc(hour, temp_f, precip_prob, short_forecast, wind_mph):
    return {"hour": hour, "temp_f": temp_f, "precip_prob": precip_prob, "short_forecast": short_forecast,
            "wind_mph": wind_mph}


def _forecast(records):
    out = [None] * 24
    for r in records:
        out[r["hour"]] = r
    return out


def _fixed_leg(minutes, miles, toll=0.0):
    """A leg whose minutes the owner has set (the day sheet of the blueprint gives minutes, not roads)."""
    return {"source": "google", "distance_m": miles * 1609.344, "duration_s": 0.0, "override_minutes": minutes,
            "toll": toll}


def _stop(stop_id, open_minute, close_minute, terms=None, vectors=None, kind="spot", spot_id=None, point=None,
          gap_before_unpaid=False, setup_minutes=None, teardown_minutes=None, event=None, catering=None):
    return {"id": stop_id, "kind": kind, "spot_id": spot_id,
            "point": {"lat": TRUCK_LAT, "lng": TRUCK_LNG} if point is None else point,
            "open_minute": open_minute, "close_minute": close_minute, "gap_before_unpaid": gap_before_unpaid,
            "setup_minutes": setup_minutes, "teardown_minutes": teardown_minutes,
            "terms": terms, "vectors": vectors, "event": event, "catering": catering}


def _evidence(**changes):
    ev = {"truck_weight": 0.0, "spot_weight": 0.0, "resid_sd": None, "resid_weight": 0.0,
          "weak_share": 0.0, "default_size_share": 0.0, "event": False, "fixed": False}
    for name in changes:
        ev[name] = changes[name]
    return ev


def _service(service_id, spot_id, date, actual, predicted_raw, sold_out=False, kind="spot"):
    (e, spread) = interval(make_assumptions(), predicted_raw, _evidence())
    return {"service_id": service_id, "kind": kind, "spot_id": spot_id, "date": date,
            "open_minute": 1020, "close_minute": 1200, "actual": actual, "sold_out": sold_out,
            "predicted_raw": predicted_raw, "predicted": predicted_raw, "low": e["low"], "high": e["high"]}


class _Fixtures:
    """The small synthetic worlds the cases share."""

    def __init__(self):
        A = make_assumptions()
        self.A = A
        self.profile = _profile()

        # The 4.4 layout (anchor A1): eight points of 250 office jobs 75..425 m due north of the truck,
        # two quick-service outlets 340 m and 360 m due south.
        self.outlets = [{"id": "o1", "lat": _north(-340.0), "lng": TRUCK_LNG, "kind": "quick"},
                        {"id": "o2", "lat": _north(-360.0), "lng": TRUCK_LNG, "kind": "quick"}]
        self.sources = []
        for k in range(8):
            lat = _north(75.0 + 50.0 * k)
            self.sources.append({"id": "b%d" % (k + 1), "lat": lat, "lng": TRUCK_LNG, "base": _base(w_office=250.0),
                                 "rivals": rivals_at_origin(A, lat, TRUCK_LNG, self.outlets)})
        self.vec = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", self.sources, self.outlets, _no_exclusion())
        self.vec_hidden = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "hidden", self.sources, self.outlets, _no_exclusion())
        self.vec_prominent = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "prominent", self.sources, self.outlets,
                                              _no_exclusion())
        self.excl_600 = {"point_ids": [], "segment": "w_office", "amount": 600.0}
        self.vec_host600 = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "prominent", self.sources, self.outlets,
                                            self.excl_600)
        self.sources_no_rivals = [dict(s, rivals={"day": 0.0, "eve": 0.0}) for s in self.sources]
        self.vec_no_rivals = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", self.sources_no_rivals, [],
                                              _no_exclusion())

        # A venue point 10 m north of the truck (the host-link example of 4.4), and the same 90 m away.
        self.pw1 = [{"id": "pw1", "lat": _north(10.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=40.0),
                     "rivals": {"day": 0.0, "eve": 0.0}}]
        self.pw1_far = [dict(self.pw1[0], lat=_north(90.0))]
        self.excl_pw1 = {"point_ids": ["pw1"], "segment": None, "amount": 0.0}
        self.vec_pw1_linked = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", self.pw1, [], self.excl_pw1)
        self.vec_pw1_unlinked = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", self.pw1, [], _no_exclusion())

        # A mixed block-and-venue neighbourhood with outlets of every kind (coordinates as data would
        # give them: six decimals). Truck at (38.9, -77.03).
        self.mix_lat = 38.9
        self.mix_lng = -77.03
        self.mix_outlets = [
            {"id": "o10", "lat": 38.9004, "lng": -77.0296, "kind": "quick"},
            {"id": "o11", "lat": 38.899, "lng": -77.0288, "kind": "full"},
            {"id": "o12", "lat": 38.902, "lng": -77.0318, "kind": "cafe"},
            {"id": "o13", "lat": 38.9033, "lng": -77.0322, "kind": "bar"},
            {"id": "o14", "lat": 38.895, "lng": -77.034, "kind": "convenience"},
            {"id": "o15", "lat": 38.915, "lng": -77.03, "kind": "quick"},
        ]
        raw = [
            ("b100", 38.9008, -77.0291, _base(res=180.0, w_retail=40.0, w_hospitality=25.0)),
            ("b200", 38.9001, -77.0335, _base(w_office=900.0, w_public=150.0)),
            ("b300", 38.8943, -77.0302, _base(res=420.0, w_health=310.0, w_edu=60.0)),
            ("b400", 38.9003, -77.0174, _base(res=12.0, w_industrial=75.0)),
            ("b500", 38.914, -77.03, _base(res=5000.0)),                       # beyond the cutoff
            ("p10", 38.9006, -77.0304, _base(v_nightlife=40.0)),
            ("p20", 38.903, -77.0265, _base(v_shopping=150.0)),
            ("p30", 38.8945, -77.024, _base(v_campus=400.0)),
            ("p40", 38.8985, -77.0312, _base(v_transit=300.0)),
            ("p50", 38.908, -77.026, _base(v_hospital=120.0)),
            ("p60", 38.9012, -77.0281, _base(v_leisure=38.0, v_events=80.0, v_lodging=90.0)),
        ]
        self.mix_sources = []
        for (pid, lat, lng, base) in raw:
            self.mix_sources.append({"id": pid, "lat": lat, "lng": lng, "base": base,
                                     "rivals": rivals_at_origin(A, lat, lng, self.mix_outlets)})
        self.vec_mix = capture_at_point(A, self.mix_lat, self.mix_lng, "normal", self.mix_sources, self.mix_outlets,
                                        _no_exclusion())

        # A campus point 60 m north of the truck: all demand comes from a weak-seed segment.
        self.campus_sources = [{"id": "pc1", "lat": _north(60.0), "lng": TRUCK_LNG, "base": _base(v_campus=400.0),
                                "rivals": {"day": 0.0, "eve": 0.0}}]
        self.vec_campus = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", self.campus_sources, [], _no_exclusion())

        # Terms.
        self.terms_open = _terms()
        self.tap_host = _host("v_nightlife", 120.0)                             # anchor A2
        self.terms_tap = _terms(host=self.tap_host)
        self.office_host = _host("w_office", 600.0)
        self.zero = _zero_vectors()

        # Contexts for the week of 2026-10-05 (no forecast), with and without a fuel price.
        self.ctx = {}
        self.ctx_fuel = {}
        for d in range(8):
            date = add_days("2026-10-05", d)
            self.ctx[date] = day_context(A, date, None, None, None, None)
            self.ctx_fuel[date] = day_context(A, date, None, None, FUEL, "seed")
        self.thu = self.ctx["2026-10-08"]
        self.fri = self.ctx["2026-10-09"]
        self.sat = self.ctx["2026-10-10"]
        self.sun = self.ctx["2026-10-11"]
        self.rain = _forecast([_fc(11, 55, 40, "Chance Rain Showers", 8), _fc(12, 56, 70, "Rain Showers Likely", 9),
                               _fc(13, 57, 30, "Chance Rain Showers", 9)])

        # The legs of the blueprint day sheet: 11, 10 and 1 minutes.
        self.legs = {"base>office": _fixed_leg(11, 4.85), "office>taproom": _fixed_leg(10, 4.85),
                     "taproom>base": _fixed_leg(1, 0.2), "office>base": _fixed_leg(11, 4.85),
                     "base>taproom": _fixed_leg(1, 0.2), "taproom>office": _fixed_leg(10, 4.85)}

        # The service log of 4.13 and what it calibrates to.
        self.services = [
            _service("s1", "A", "2026-06-06", 52.0, 60.0),
            _service("s2", "A", "2026-07-11", 70.0, 62.0),
            _service("s3", "B", "2026-08-01", 30.0, 39.4),
            _service("s4", "B", "2026-08-22", 45.0, 39.4, True),
            _service("s5", "A", "2026-09-12", 66.0, 60.5),
            _service("s6", "C", "2026-09-26", 2.0, 2.1),
            _service("s7", "B", "2026-10-03", 28.0, 41.0),
        ]
        self.cal = calibrate(A, self.services, "2026-10-04")


def _g01_rounding(c):
    for (x, decimals, want) in [(2.675, 2, 2.68), (1.005, 2, 1.01), (2.5, 0, 3.0), (-2.5, 0, -3.0),
                                (-0.004, 2, 0.0), (65.533, 0, 66.0), (0.4999999995, 0, 1.0),
                                (1234.5678, 1, 1234.6), (-17.345, 2, -17.35)]:
        c.doc(c.add("g01", "round_half_away", {"x": x, "decimals": decimals}), "", want)
    for (x, decimals) in [(0.0, 0), (3.14159265, 3), (3.14159265, 4), (3.14159265, 5), (-123.4567894, 6),
                          (-0.05, 1), (987654.321, 0), (12.6403, 0), (0.1078, 0)]:
        c.add("g01", "round_half_away", {"x": x, "decimals": decimals})
    c.doc(c.add("g01", "qkey", {"x": 25.776}), "", 25776000)
    c.doc(c.add("g01", "qkey", {"x": 0.0000004}), "", 0)
    for x in [0.0, -1.5, 1234.5678914, 0.0000006, 482.2030590342359, -42.64396267755217]:
        c.add("g01", "qkey", {"x": x})
    # Halves and the reach of the nudge; negative zero; decimals 7 to 9.
    for (x, decimals, want) in [(0.5, 0, 1.0), (-0.5, 0, -1.0), (1.5, 0, 2.0), (-0.0, 2, 0.0), (0.4999999989, 0, 0.0),
                                (3.14159265358979, 7, 3.1415927), (3.14159265358979, 8, 3.14159265),
                                (3.14159265358979, 9, 3.141592654), (0.0000000005, 9, 0.000000001),
                                (-0.0000000005, 9, -0.000000001), (1000000000000000.5, 0, 1000000000000001.0)]:
        c.doc(c.add("g01", "round_half_away", {"x": x, "decimals": decimals}), "", want)
    c.add("g01", "round_half_away", {"x": 123456789.123456789, "decimals": 9})
    for (x, want) in [(0.0000005, 1), (-0.0000005, 0), (-0.00000050000001, -1), (1000000000000.0, 1000000000000000000),
                      (-1000000000000.0, -1000000000000000000)]:
        c.doc(c.add("g01", "qkey", {"x": x}), "", want)


def _g02_dates(c):
    for (y, m, d, want) in [(1970, 1, 1, 0), (2000, 2, 29, 11016), (2026, 10, 8, 20734), (2028, 2, 29, 21243),
                            (2100, 3, 1, 47541), (2199, 12, 31, 84005)]:
        c.doc(c.add("g02", "days_from_civil", {"y": y, "m": m, "d": d}), "", want)
    for (y, m, d) in [(2100, 2, 28), (1969, 12, 31), (2027, 1, 1), (2026, 12, 31)]:
        c.add("g02", "days_from_civil", {"y": y, "m": m, "d": d})
    for z in [0, 11016, 47540, 47541, 84005, -1, 20818, 20819]:
        c.add("g02", "civil_from_days", {"z": z})
    for (date, want) in [("1970-01-01", 3), ("2000-02-29", 1), ("2026-10-08", 3), ("2028-02-29", 1),
                         ("2100-03-01", 0), ("2199-12-31", 1)]:
        c.doc(c.add("g02", "day_of_week", {"date": date}), "", want)
    c.add("g02", "day_of_week", {"date": "2026-10-11"})
    c.add("g02", "day_of_week", {"date": "2026-02-29"})                         # not a real date
    for (date, n, want) in [("2026-12-30", 3, "2027-01-02"), ("2028-03-01", -1, "2028-02-29"),
                            ("2026-03-01", -1, "2026-02-28"), ("2026-10-08", 0, "2026-10-08")]:
        c.doc(c.add("g02", "add_days", {"date": date, "n": n}), "", want)
    for (date, n) in [("2100-02-28", 1), ("2027-01-01", -1), ("2026-10-08", 365), ("2028-02-28", 2),
                      ("2026-10-05", 7)]:
        c.add("g02", "add_days", {"date": date, "n": n})
    for s in ["2026-10-08", "1970-01-01", "2199-12-31", "2026-02-30", "1969-12-31", "2200-01-01", "2026-1-05",
              "2026-13-01", "2026-00-10", "2026/10/08", "2026-10-08T00:00", "\uff12\uff10\uff12\uff16-10-08", ""]:
        c.add("g02", "parse_date", {"s": s})
    for (y, m, d) in [(2026, 3, 5), (1970, 1, 1), (2199, 12, 31)]:
        c.add("g02", "format_date", {"y": y, "m": m, "d": d})
    for (year, month, dow, n) in [(2026, 11, 3, 4), (2026, 1, 0, 3), (2026, 9, 0, 1), (2026, 10, 0, 2),
                                  (2027, 2, 0, 3)]:
        c.add("g02", "nth_weekday", {"year": year, "month": month, "dow": dow, "n": n})
    for (year, month, dow) in [(2026, 5, 0), (2027, 5, 0), (2026, 12, 6), (2028, 2, 1)]:
        c.add("g02", "last_weekday", {"year": year, "month": month, "dow": dow})
    # Day numbers outside the valid range of dates: negative years and day numbers (floor_div and mod_floor
    # on negative values), months and days that are not normalised.
    for (y, m, d, want) in [(0, 3, 1, -719468), (0, 1, 1, -719528), (-1, 3, 1, -719834), (-400, 2, 29, -865566),
                            (1600, 2, 29, -135081), (9999, 12, 31, 2932896), (2026, 13, 1, 20819), (2026, 0, 1, 20423),
                            (2026, -10, 1, 20485), (2026, 1, 0, 20453), (2026, 14, 31, 20880)]:
        c.doc(c.add("g02", "days_from_civil", {"y": y, "m": m, "d": d}), "", want)
    for (z, want) in [(-719468, [0, 3, 1]), (-719469, [0, 2, 29]), (-719529, [-1, 12, 31]), (-1000000, [-768, 2, 4]),
                      (-865566, [-400, 2, 29]), (2932896, [9999, 12, 31]), (3000000, [10183, 9, 21]), (146096, [2369, 12, 31]),
                      (146097, [2370, 1, 1])]:
        c.doc(c.add("g02", "civil_from_days", {"z": z}), "", want)
    # parse_date: not a string at all; a character below "0" and one above "9" in a digit position; the
    # two separators; length; digits outside the Basic Multilingual Plane (ten characters, twenty-two bytes).
    for s in [None, 20261008, True, ["2026-10-08"], {"date": "2026-10-08"}, "2026-10-0 ", "2026-10-0/", "2026-10-0:",
              "+026-10-08", "2026x10-08", "2026-10x08", " 2026-10-08", "2026-10-08 ", "2026-10-8", "2026-10-080",
              "\U0001d7ee\U0001d7ec\U0001d7ee\U0001d7f2-10-08", "2100-02-29", "2026-04-31", "2026-12-32", "1970-01-00"]:
        c.doc(c.add("g02", "parse_date", {"s": s}), "error", "invalid_date")
    for (s, want) in [("2000-02-29", [2000, 2, 29]), ("2028-02-29", [2028, 2, 29]), ("2026-12-31", [2026, 12, 31])]:
        c.doc(c.add("g02", "parse_date", {"s": s}), "", want)
    for date in [None, 20261008, "1969-12-31", "2200-01-01"]:
        c.doc(c.add("g02", "day_of_week", {"date": date}), "error", "invalid_date")
    c.doc(c.add("g02", "add_days", {"date": None, "n": 1}), "error", "invalid_date")
    c.doc(c.add("g02", "add_days", {"date": "2200-01-01", "n": -1}), "error", "invalid_date")
    # add_days and format_date do not check their result: it may leave 1970..2199. Zero-padded to four
    # digits with the sign inside the four; longer years are written out.
    for (date, n, want) in [("1970-01-01", -1, "1969-12-31"), ("2199-12-31", 1, "2200-01-01"), ("1970-01-01", -719162, "0001-01-01"),
                            ("1970-01-01", -719163, "0000-12-31"), ("1970-01-01", -719600, "-001-10-21"),
                            ("1970-01-01", -1000000, "-768-02-04"), ("2199-12-31", 3000000, "10413-09-20")]:
        c.doc(c.add("g02", "add_days", {"date": date, "n": n}), "", want)
    for (y, m, d, want) in [(0, 1, 1, "0000-01-01"), (5, 5, 5, "0005-05-05"), (-1, 12, 31, "-001-12-31"), (-123, 5, 6, "-123-05-06"),
                            (-1234, 5, 6, "-1234-05-06"), (99999, 1, 1, "99999-01-01"), (2026, 13, 45, "2026-13-45"),
                            (2026, -3, 7, "2026--3-07"), (2026, 100, 100, "2026-100-100")]:
        c.doc(c.add("g02", "format_date", {"y": y, "m": m, "d": d}), "", want)
    # A fifth weekday that the month does not have runs into the next month; n = 0 is the week before.
    for (year, month, dow, n, want) in [(2026, 2, 6, 5, "2026-03-01"), (2026, 2, 6, 4, "2026-02-22"), (2026, 3, 6, 0, "2026-02-22"),
                                        (2026, 12, 0, 1, "2026-12-07"), (2024, 2, 3, 5, "2024-02-29"), (1970, 1, 3, 1, "1970-01-01")]:
        c.doc(c.add("g02", "nth_weekday", {"year": year, "month": month, "dow": dow, "n": n}), "", want)
    for (year, month, dow, want) in [(2026, 12, 3, "2026-12-31"), (2024, 2, 3, "2024-02-29"), (2024, 2, 4, "2024-02-23"),
                                     (2199, 12, 1, "2199-12-31"), (1970, 1, 5, "1970-01-31")]:
        c.doc(c.add("g02", "last_weekday", {"year": year, "month": month, "dow": dow}), "", want)


def _g03_holidays(c):
    on = {"inauguration_day": True}
    off = {"inauguration_day": False}
    for year in (2025, 2026, 2027, 2028, 2029, 2030):                           # against the OPM lists
        c.add("g03", "federal_holidays", {"year": year, "flags": on})
    c.add("g03", "federal_holidays", {"year": 2029, "flags": off})              # Inauguration Day, flag off
    c.add("g03", "federal_holidays", {"year": 2041, "flags": on})               # Inauguration Day on a Sunday
    c.add("g03", "federal_holidays", {"year": 2020, "flags": off})              # before Juneteenth
    c.add("g03", "federal_holidays", {"year": 2021, "flags": on})               # Juneteenth's first year
    c.doc(c.add("g03", "holiday_on", {"date": "2025-01-20", "flags": on}), "id", "mlk")
    c.doc(c.add("g03", "holiday_on", {"date": "2026-07-04", "flags": on}), "", None)
    c.doc(c.add("g03", "holiday_on", {"date": "2026-07-03", "flags": on}), "id", "independence")
    c.doc(c.add("g03", "holiday_on", {"date": "2027-12-31", "flags": on}), "date", "2028-01-01")
    c.doc(c.add("g03", "holiday_on", {"date": "2026-11-26", "flags": on}), "id", "thanksgiving")
    c.doc(c.add("g03", "holiday_on", {"date": "2029-01-20", "flags": on}), "", None)      # a Saturday: no day in lieu
    c.add("g03", "holiday_on", {"date": "2029-01-19", "flags": on})
    c.add("g03", "holiday_on", {"date": "2041-01-21", "flags": on})             # mlk and inauguration: mlk
    c.add("g03", "holiday_on", {"date": "2025-01-20", "flags": off})
    c.add("g03", "holiday_on", {"date": "2033-01-20", "flags": on})             # a Thursday
    c.add("g03", "holiday_on", {"date": "2033-01-20", "flags": off})
    c.add("g03", "holiday_on", {"date": "2026-10-08", "flags": on})
    c.add("g03", "holiday_on", {"date": "2021-12-31", "flags": on})             # New Year's Day 2022 observed
    c.add("g03", "holiday_on", {"date": "2199-12-31", "flags": on})             # looks at the year 2200
    c.add("g03", "holiday_on", {"date": "2026-02-30", "flags": on})             # invalid_date
    # Inauguration Day: before 1969 (1965 would fit the four-year cycle), its first year, a year off the cycle.
    for (year, flags, want) in [(1965, on, False), (1961, on, False), (1969, on, True), (1970, on, False), (2033, on, True),
                                (2033, {}, False), (2033, {"inauguration_day": 1}, False), (2033, {"other_flag": True}, False),
                                (2200, on, False)]:
        cid = c.add("g03", "federal_holidays", {"year": year, "flags": flags})
        c.calc(cid, "has inauguration", lambda r: len([h for h in r if h["id"] == "inauguration"]) == 1, want)
    c.known(c.add("g03", "federal_holidays", {"year": 1969, "flags": off}), "0.date", "1969-01-01")
    c.known(c.add("g03", "holiday_on", {"date": "2033-01-20", "flags": {}}), "", None)                   # a flag that is absent is off
    c.known(c.add("g03", "holiday_on", {"date": "2033-01-20", "flags": {"inauguration_day": 1}}), "", None)   # only true is on
    c.known(c.add("g03", "holiday_on", {"date": "2028-01-01", "flags": on}), "", None)                   # observed the day before
    c.known(c.add("g03", "holiday_on", {"date": "1970-01-01", "flags": on}), "id", "new_year")            # the first valid date
    c.known(c.add("g03", "holiday_on", {"date": "2027-07-05", "flags": on}), "id", "independence")        # a Sunday holiday, observed on Monday
    c.known(c.add("g03", "holiday_on", {"date": "2027-07-04", "flags": on}), "", None)
    for date in [None, 20290120, "1969-12-31", "2200-01-01"]:
        c.known(c.add("g03", "holiday_on", {"date": date, "flags": on}), "error", "invalid_date")


def _g04_day_context(c, fx):
    def ctx_args(date, treat_as=None, forecast=None, fuel=None, source=None, a=None):
        return {"A": _a() if a is None else a, "date": date, "treat_as": treat_as, "forecast": forecast,
                "fuel_price_per_gal": fuel, "fuel_price_source": source}

    rows = [("2026-10-08", None, 3, 3, None, "weekday", 1.08, "weekday", 1.0, "weekday", "weekday", 3),
            ("2026-10-12", None, 0, 0, "minor", "weekday", 0.9, "weekday", 0.5, "saturday", "sunday", 0),
            ("2026-11-26", None, 3, 3, "major", "sunday", 1.0, "saturday", 1.0, "saturday", "sunday", 6),
            ("2026-07-03", None, 4, 4, "major", "sunday", 1.0, "saturday", 1.0, "saturday", "sunday", 6),
            ("2026-10-08", "sat", 3, 5, None, "saturday", 1.0, "saturday", 1.0, "saturday", "saturday", 5)]
    for (date, treat_as, dow, eff_dow, cls, office_type, office_f, night_type, night_f, shop_type, public_type,
         traffic_dow) in rows:
        cid = c.add("g04", "day_context", ctx_args(date, treat_as))
        for (path, want) in [("dow", dow), ("eff_dow", eff_dow), ("holiday_class", cls), ("day_type.1", office_type),
                             ("dow_factor.1", office_f), ("day_type.8", night_type), ("dow_factor.8", night_f),
                             ("day_type.9", shop_type), ("day_type.7", public_type), ("traffic_dow", traffic_dow)]:
            c.doc(cid, path, want)
    c.add("g04", "day_context", ctx_args("2026-10-10"))                         # Saturday
    c.add("g04", "day_context", ctx_args("2026-10-11"))                         # Sunday
    c.add("g04", "day_context", ctx_args("2026-11-26", "normal"))               # ignore the holiday
    c.add("g04", "day_context", ctx_args("2026-10-08", "holiday"))              # an ordinary day as a major holiday
    c.add("g04", "day_context", ctx_args("2026-10-10", "holiday"))              # ... on a Saturday
    c.add("g04", "day_context", ctx_args("2026-11-26", "mon"))                  # Thanksgiving as a Monday
    c.add("g04", "day_context", ctx_args("2026-10-12", "holiday"))              # a minor holiday as a major one
    c.add("g04", "day_context", ctx_args("2026-10-11", "fri"))
    c.add("g04", "day_context", ctx_args("2026-10-12", None, None, None, None,
                                         _a({"segments.w_office.holiday_day_type.minor": "sunday"})))
    c.add("g04", "day_context", ctx_args("2026-10-08", None, fx.rain, FUEL, "eia"))       # handed through unchanged
    c.add("g04", "day_context", ctx_args("2025-01-20", None, None, None, None, _a(None, REGION_NONE)))
    c.add("g04", "day_context", ctx_args("2025-01-20"))                         # the same day in the dc region
    c.add("g04", "day_context", ctx_args("2026-02-30"))                         # invalid_date
    for dow in (0, 3, 4, 5, 6):
        c.add("g04", "typical_context", {"A": _a(), "dow": dow})
    c.add("g04", "typical_context", {"A": _a({"segments.v_nightlife.dow_factor": [1.0, 1.0, 1.0, 1.0, 2.0]}), "dow": 4})
    for dow in (1, 2):
        c.add("g04", "typical_context", {"A": _a(), "dow": dow})
    # A treat_as value outside the vocabulary changes nothing ("sunday" is not the key "sun"); the date is
    # parsed like everywhere else.
    cid = c.add("g04", "day_context", ctx_args("2026-11-26", "sunday"))
    for (path, want) in [("eff_dow", 3), ("holiday_class", "major"), ("treat_as", "sunday"), ("traffic_dow", 6)]:
        c.known(cid, path, want)
    c.add("g04", "day_context", ctx_args("2026-10-08", ""))
    c.known(c.add("g04", "day_context", ctx_args(None)), "error", "invalid_date")
    c.known(c.add("g04", "day_context", ctx_args(20261008)), "error", "invalid_date")
    c.known(c.add("g04", "day_context", ctx_args("2199-12-31")), "dow", 1)      # the last valid date; its holiday search reads the year 2200
    c.add("g04", "day_context", ctx_args("1970-01-01"))                         # the first valid date: New Year's Day
    c.add("g04", "day_context", ctx_args("2027-12-31"))                         # New Year's Day 2028, observed in the year before
    c.add("g04", "day_context", ctx_args("2026-10-11", "holiday"))              # a Sunday as a major holiday
    c.add("g04", "day_context", ctx_args("2026-10-08", None, [], 0, "owner"))   # an empty forecast list and a price of 0 are handed through
    c.add("g04", "day_context", ctx_args("2026-11-26", None, None, None, None,
                                         _a({"segments.w_office.holiday_day_type.major": "weekday",
                                             "segments.v_nightlife.holiday_day_type.major": "sunday"})))
    c.add("g04", "day_context", ctx_args("2026-10-10", "holiday", None, None, None,
                                         _a({"segments.w_office.holiday_day_type.major": "weekday"})))    # "weekday" on a Saturday is saturday

    # make_context itself, with combinations day_context never produces.
    def mk(date, dow, eff_dow, cls, hol, treat_as, typical, a=None, fuel=None, source=None):
        return c.add("g04", "make_context", {"A": _a() if a is None else a, "date": date, "dow": dow, "eff_dow": eff_dow, "cls": cls,
                                             "hol": hol, "treat_as": treat_as, "forecast": None, "fuel_price_per_gal": fuel,
                                             "fuel_price_source": source, "typical": typical})

    cid = mk("2026-10-08", 3, 3, None, None, None, False)
    c.known(cid, "day_type.1", "weekday")
    c.known(cid, "dow_factor.1", 1.08)
    cid = mk(None, 3, 5, "major", None, None, True)                             # a typical Saturday read as a major holiday
    c.known(cid, "day_type.1", "sunday")
    c.known(cid, "traffic_dow", 6)
    c.known(cid, "typical", True)
    cid = mk("2026-10-11", 6, 6, "minor", None, "normal", False, None, 4.195, "seed")     # a Sunday with a minor class: "weekday" stays sunday
    c.known(cid, "day_type.1", "sunday")
    c.known(cid, "day_type.9", "saturday")
    c.known(cid, "traffic_dow", 6)
    mk("2026-10-09", 4, 0, "minor", {"id": "columbus", "name": "Columbus Day", "class": "minor", "date": "2026-10-12", "observed": "2026-10-12"},
       "mon", False, _a({"segments.w_office.dow_factor": [0.5, 1.0, 1.0, 1.0, 1.0]}))


def _g05_curves(c, fx):
    A = fx.A
    c.add("g05", "hour_weights", {"A": _a(), "ctx": fx.thu})
    c.add("g05", "hour_weights", {"A": _a(), "ctx": fx.fri})
    c.add("g05", "hour_weights", {"A": _a(), "ctx": fx.sat})
    c.add("g05", "hour_weights", {"A": _a(), "ctx": day_context(A, "2026-11-26", None, None, None, None)})
    c.add("g05", "hour_weights", {"A": _a(), "ctx": typical_context(A, 6)})
    curve = [0.0] * 24
    for h in range(16, 24):
        curve[h] = 0.125 * (h - 15)
    a_curve = _a({"segments.v_nightlife.presence.weekday": curve})
    c.add("g05", "hour_weights", {"A": a_curve, "ctx": day_context(_assume(a_curve), "2026-10-08", None, None, None, None)})
    a_flat = _a({"segments.w_office.dow_factor": [1.0, 1.0, 1.0, 1.0, 1.0]})
    c.add("g05", "hour_weights", {"A": a_flat, "ctx": day_context(_assume(a_flat), "2026-10-09", None, None, None, None)})
    cid = c.add("g05", "expand_curves", {"A": _a()})
    c.doc(cid, "presence.1.34", 0.43911)
    c.doc(cid, "presence.1.108", 0.24321)
    c.doc(cid, "presence.8.114", 0.9299999999999999)
    c.doc(cid, "presence.8.136", 1.0)
    c.doc(cid, "intent.1.132", 0.153)
    c.doc(cid, "presence.10.17", 1.15)
    c.add("g05", "expand_curves", {"A": _a({"segments.w_office.dow_factor": [1.0, 1.0, 1.0, 1.0, 1.0],
                                              "segments.res.intent.sunday": [0.01] * 24})})


def _g06_geometry(c):
    def hav(lat1, lng1, lat2, lng2):
        return c.add("g06", "haversine_m", {"lat1": lat1, "lng1": lng1, "lat2": lat2, "lng2": lng2})

    c.doc(hav(38.96, -77.36, 38.9696, -77.3861), "", 2496.298749, 6)
    c.doc(hav(38.96, -77.36, 38.9635972815, -77.36), "", 400.000005, 6)
    c.doc(hav(38.96, -77.36, 38.96, -77.36), "", 0.0)
    c.doc(hav(38.96, -77.36, _north(400.0), -77.36), "", 400.0, 9)
    hav(38.96, -77.36, _north(-340.0), -77.36)
    c.doc(hav(39.003, -77.405, 38.96, -77.36), "", 6163.71, 2)                  # base to the office park
    hav(38.9696, -77.3861, 38.96, -77.36)                                       # the first pair, reversed
    hav(89.9, 0.0, 89.9, 180.0)                                                 # across the pole
    hav(0.0, 0.0, 0.0, 1.0)                                                     # one degree of equator
    hav(0.0, 0.0, 0.0, 90.0)                                                    # a quarter of the equator
    hav(-33.8688, 151.2093, 51.5074, -0.1278)
    hav(38.96, 179.9999, 38.96, -179.9999)                                      # across the date line
    for (d, want) in [(0.0, 1.0), (50.0, 0.8824969025845955), (400.0, 0.36787944117144233),
                      (1200.0, 0.049787068367863944), (1200.01, 0.0)]:
        c.doc(c.add("g06", "walk_weight", {"A": _a(), "d": d}), "", want, 9)
    for d in [-1.0, 1199.99, 2500.0, 75.0]:
        c.add("g06", "walk_weight", {"A": _a(), "d": d})
    # Whole-number arguments (a JSON 39 is a real 39.0) and a distance of exactly the cutoff written as an integer.
    c.known(hav(39, -77, 39, -77), "", 0.0)
    hav(39, -77, 38, -78)
    c.known(c.add("g06", "walk_weight", {"A": _a(), "d": 1200}), "", 0.049787068367863944, 9)
    c.known(c.add("g06", "walk_weight", {"A": _a(), "d": 1201}), "", 0.0)
    c.known(c.add("g06", "walk_weight", {"A": _a(), "d": 0}), "", 1.0)


def _g07_rivals(c, fx):
    def riv(lat, lng, outlets):
        return c.add("g07", "rivals_at_origin", {"A": _a(), "lat": lat, "lng": lng, "outlets": outlets})

    cid = riv(_north(75.0), TRUCK_LNG, fx.outlets)                              # origin b1
    c.doc(cid, "day", 0.691398, 6)
    c.doc(cid, "eve", 0.691398, 6)
    c.doc(riv(TRUCK_LAT, TRUCK_LNG, fx.outlets), "day", 0.833985, 6)
    riv(fx.mix_lat, fx.mix_lng, fx.mix_outlets)                                 # every kind; o15 beyond the cutoff
    riv(fx.mix_lat, fx.mix_lng, list(reversed(fx.mix_outlets)))                 # order of the list is irrelevant
    riv(TRUCK_LAT, TRUCK_LNG, [])
    riv(38.9033, -77.0322, fx.mix_outlets)                                      # standing at the bar: f = 1
    riv(TRUCK_LAT, TRUCK_LNG, [{"id": "far", "lat": _north(1300.0), "lng": TRUCK_LNG, "kind": "quick"},
                               {"id": "near", "lat": _north(1100.0), "lng": TRUCK_LNG, "kind": "convenience"}])
    for kind in RIVAL_KINDS:
        riv(TRUCK_LAT, TRUCK_LNG, [{"id": "k", "lat": _north(200.0), "lng": TRUCK_LNG, "kind": kind}])
    # One outlet of every kind at the origin itself (f = 1 exactly): the plain sums of the two weight columns.
    here = [{"id": "h%d" % k, "lat": TRUCK_LAT, "lng": TRUCK_LNG, "kind": RIVAL_KINDS[k]} for k in range(5)]
    cid = riv(TRUCK_LAT, TRUCK_LNG, here)
    c.known(cid, "day", 2.7, 9)
    c.known(cid, "eve", 3.2, 9)
    # Ids that sort byte-wise: "" first, digits before capitals before "_" before small letters, "10" before "9";
    # two outlets under one id keep the order they came in.
    odd = [("b", 50.0, "full"), ("a", 100.0, "quick"), ("a", 200.0, "bar"), ("A", 300.0, "cafe"), ("", 20.0, "convenience"),
           ("10", 500.0, "quick"), ("9", 600.0, "full"), ("o_1", 800.0, "bar"), ("o-1", 700.0, "cafe"), ("O1", 900.0, "convenience"),
           ("o1", 1000.0, "quick"), ("o10", 1100.0, "full"), ("o2", 1150.0, "cafe"), ("~", 1199.0, "bar"), (" ", 1.0, "cafe")]
    odd_outlets = [{"id": i, "lat": _north(d), "lng": TRUCK_LNG, "kind": kind} for (i, d, kind) in odd]
    riv(TRUCK_LAT, TRUCK_LNG, odd_outlets)
    riv(TRUCK_LAT, TRUCK_LNG, odd_outlets[7:] + odd_outlets[:7])
    riv(39, -77, [{"id": "c", "lat": 39, "lng": -77, "kind": "quick"}, {"id": "d", "lat": 39, "lng": -77, "kind": "bar"}])     # whole-number coordinates


def _g08_capture(c, fx):
    def cap(lat, lng, visibility, sources, outlets, exclusion):
        return c.add("g08", "capture_at_point", {"A": _a(), "lat": lat, "lng": lng, "visibility": visibility,
                                                 "sources": sources, "outlets": outlets, "exclusion": exclusion})

    def table(cid, day, eve, nearby, within, rivals_day):
        c.doc(cid, "capture.day.1", day, 6)
        c.doc(cid, "capture.eve.1", eve, 6)
        c.doc(cid, "nearby.1", nearby, 6)
        c.doc(cid, "within.1", within, 6)
        c.doc(cid, "rivals.day", rivals_day, 6)

    # The 4.4 layout.
    cid = cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets, _no_exclusion())
    table(cid, 417.134520, 417.134520, 1114.962841, 2000.0, 0.833985)
    c.calc(cid, "capture / within", lambda r: r["capture"]["day"][1] / r["within"][1], 0.208567, 6)
    c.calc(cid, "capture / nearby", lambda r: r["capture"]["day"][1] / r["nearby"][1], 0.374124, 6)
    table(cap(TRUCK_LAT, TRUCK_LNG, "hidden", fx.sources, fx.outlets, _no_exclusion()),
          273.891217, 273.891217, 1114.962841, 2000.0, 0.833985)
    table(cap(TRUCK_LAT, TRUCK_LNG, "prominent", fx.sources, fx.outlets, _no_exclusion()),
          509.479077, 509.479077, 1114.962841, 2000.0, 0.833985)
    table(cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets,
              {"point_ids": ["b1"], "segment": None, "amount": 0.0}),
          350.714987, 350.714987, 907.705562, 1750.0, 0.833985)
    cid = cap(TRUCK_LAT, TRUCK_LNG, "prominent", fx.sources, fx.outlets, fx.excl_600)
    table(cid, 326.105662, 326.105662, 660.236802, 1400.0, 0.833985)
    c.doc(cid, "excluded_amount", 600.0)
    cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources_no_rivals, [], _no_exclusion())
    cap(TRUCK_LAT, TRUCK_LNG, "normal", list(reversed(fx.sources)), list(reversed(fx.outlets)), _no_exclusion())
    # Exclusion larger than what is within the radius: b1..b4 lie within 250 m.
    cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets, {"point_ids": [], "segment": "w_office", "amount": 5000.0})
    # Exclusion of a segment the points do not hold.
    cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets, {"point_ids": [], "segment": "res", "amount": 100.0})
    # Both rules at once.
    cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets, {"point_ids": ["b2"], "segment": "w_office", "amount": 300.0})
    # Equidistant points: 100 m north and 100 m south; the smaller id gives first.
    twins = [{"id": "t2", "lat": _north(100.0), "lng": TRUCK_LNG, "base": _base(w_office=250.0), "rivals": {"day": 0.2, "eve": 0.1}},
             {"id": "t1", "lat": _north(-100.0), "lng": TRUCK_LNG, "base": _base(w_office=250.0), "rivals": {"day": 0.4, "eve": 0.3}}]
    cap(TRUCK_LAT, TRUCK_LNG, "normal", twins, [], {"point_ids": [], "segment": "w_office", "amount": 300.0})
    # No sources; a source exactly at the truck; sources at 1,199 m and 1,201 m.
    cap(TRUCK_LAT, TRUCK_LNG, "normal", [], fx.outlets, _no_exclusion())
    cap(TRUCK_LAT, TRUCK_LNG, "prominent", [{"id": "here", "lat": TRUCK_LAT, "lng": TRUCK_LNG,
                                             "base": _base(res=100.0, w_retail=20.0), "rivals": {"day": 1.5, "eve": 0.5}}],
        [], _no_exclusion())
    cap(TRUCK_LAT, TRUCK_LNG, "normal",
        [{"id": "in", "lat": _north(1199.0), "lng": TRUCK_LNG, "base": _base(res=1000.0), "rivals": {"day": 0.0, "eve": 0.0}},
         {"id": "out", "lat": _north(1201.0), "lng": TRUCK_LNG, "base": _base(res=1000.0), "rivals": {"day": 0.0, "eve": 0.0}}],
        [], _no_exclusion())
    # The mixed neighbourhood: every segment, every rival kind, day and evening differ.
    cap(fx.mix_lat, fx.mix_lng, "normal", fx.mix_sources, fx.mix_outlets, _no_exclusion())
    cap(fx.mix_lat, fx.mix_lng, "hidden", fx.mix_sources, fx.mix_outlets, _no_exclusion())
    cap(fx.mix_lat, fx.mix_lng, "prominent", fx.mix_sources, fx.mix_outlets,
        {"point_ids": [], "segment": "res", "amount": 300.0})                   # only 180 residents within 250 m
    cap(fx.mix_lat, fx.mix_lng, "normal", fx.mix_sources, fx.mix_outlets,
        {"point_ids": ["p10"], "segment": None, "amount": 0.0})                 # a host linked to the taproom
    cap(fx.mix_lat, fx.mix_lng, "normal", fx.mix_sources, fx.mix_outlets,
        {"point_ids": ["p10", "b100"], "segment": "w_office", "amount": 250.0})
    # The venue point 10 m from the truck: linked (removed) and unlinked (still in the catchment).
    cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.pw1, [], fx.excl_pw1)
    c.doc(cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.pw1, [], _no_exclusion()), "capture.eve.8", 15.148622, 6)

    c.add("g08", "host_exclusion", {"A": _a(), "host": None})
    cid = c.add("g08", "host_exclusion", {"A": _a(), "host": fx.office_host})
    c.doc(cid, "segment", "w_office")
    c.doc(cid, "amount", 600.0)
    cid = c.add("g08", "host_exclusion", {"A": _a(), "host": _host("v_nightlife", 120.0, point_id="p77", place_type="taproom")})
    c.doc(cid, "point_ids.0", "p77")
    c.doc(cid, "segment", None)
    c.add("g08", "host_exclusion", {"A": _a(), "host": _host("res", 500.0, point_id="b100", place_type="apartment_community")})
    c.add("g08", "host_exclusion", {"A": _a(), "host": _host("v_shopping", 200.0, "default", False)})
    c.add("g08", "host_exclusion", {"A": _a(), "host": _host("w_industrial", 80.0, only_food=False)})

    def link(sources, host):
        return c.add("g08", "host_link_point", {"A": _a(), "lat": TRUCK_LAT, "lng": TRUCK_LNG, "host": host,
                                                "sources": sources})

    c.doc(link(fx.pw1, fx.tap_host), "", "pw1")
    c.doc(link(fx.pw1_far, fx.tap_host), "", None)                              # 90 m: beyond the radius
    link(fx.pw1, _host("v_events", 80.0))                                       # the point holds another segment
    link([{"id": "n2", "lat": _north(30.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=45.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "n1", "lat": _north(-30.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=40.0), "rivals": {"day": 0.0, "eve": 0.0}}],
         fx.tap_host)                                                           # same millimetre distance: tie by id
    link([{"id": "n1", "lat": _north(60.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=40.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "n2", "lat": _north(-20.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=45.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "n0", "lat": _north(5.0), "lng": TRUCK_LNG, "base": _base(res=300.0), "rivals": {"day": 0.0, "eve": 0.0}}],
         fx.tap_host)                                                           # the nearest of its own segment
    link([], fx.tap_host)

    def match(terms, vectors, want):
        c.doc(c.add("g08", "vectors_match", {"A": _a(), "terms": terms, "vectors": vectors}), "", want)

    linked = _host("v_nightlife", 120.0, point_id="pw1")
    match(_terms(), fx.vec, True)
    match(_terms(visibility="prominent"), fx.vec, False)
    match(_terms(visibility="prominent", host=fx.office_host), fx.vec_host600, True)
    match(_terms(visibility="prominent", host=_host("w_office", 1000.0)), fx.vec_host600, False)
    match(_terms(host=linked), fx.vec_pw1_linked, True)
    match(_terms(host=linked), fx.vec_pw1_unlinked, False)
    match(_terms(host=fx.tap_host), _stored(fx.zero), True)                     # stored vectors compare the same way
    # Each part of the comparison on its own: the segment, the amount (as doubles: 600 equals 600.0), the
    # number of ids, their order.
    match(_terms(host=fx.office_host), _zero_vectors(exclusion={"point_ids": [], "segment": "w_public", "amount": 600.0}), False)
    match(_terms(host=_host("w_office", 600)), _zero_vectors(exclusion={"point_ids": [], "segment": "w_office", "amount": 600.0}), True)
    match(_terms(host=_host("w_office", 0.1 + 0.2)), _zero_vectors(exclusion={"point_ids": [], "segment": "w_office", "amount": 0.3}), False)
    match(_terms(host=linked), _zero_vectors(exclusion={"point_ids": ["pw1", "pw2"], "segment": None, "amount": 0.0}), False)
    match(_terms(), _zero_vectors(exclusion={"point_ids": ["pw1"], "segment": None, "amount": 0.0}), False)
    match(_terms(host=_host("v_campus", 600.0)), _zero_vectors(exclusion={"point_ids": [], "segment": None, "amount": 600.0}), False)
    match(_terms(host=_host("w_office", 0.0)), _zero_vectors(), False)          # a worker host of size 0 still names its segment
    match(_terms(host=_host("res", 40.0, point_id="b7")), _zero_vectors(exclusion={"point_ids": ["b7"], "segment": "res", "amount": 40.0}), True)
    match(_terms(visibility="hidden"), _zero_vectors("hidden"), True)

    # A segment to take from but nothing to take; excluded ids that no point has; an amount below zero.
    for (exclusion, taken, within) in [({"point_ids": [], "segment": "w_office", "amount": 0.0}, 0.0, 2000.0),
                                       ({"point_ids": [], "segment": "w_office", "amount": -50.0}, 0.0, 2000.0),
                                       ({"point_ids": ["nowhere", "b3", "b3"], "segment": None, "amount": 75.0}, 0.0, 1750.0),
                                       ({"point_ids": [], "segment": "w_office", "amount": 250.0}, 250.0, 1750.0),
                                       ({"point_ids": [], "segment": "w_office", "amount": 250.5}, 250.5, 1749.5)]:
        cid = cap(TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets, exclusion)
        c.known(cid, "excluded_amount", taken)
        c.known(cid, "within.1", within)
    # Points on either side of the exclusion radius (250 m) and of the cutoff, and one at the truck itself.
    ring = [{"id": "e249", "lat": _north(249.9), "lng": TRUCK_LNG, "base": _base(w_office=100.0), "rivals": {"day": 0.0, "eve": 0.0}},
            {"id": "e251", "lat": _north(-250.1), "lng": TRUCK_LNG, "base": _base(w_office=100.0), "rivals": {"day": 0.0, "eve": 0.0}},
            {"id": "c1199", "lat": _north(1199.9), "lng": TRUCK_LNG, "base": _base(w_office=100.0), "rivals": {"day": 0.0, "eve": 0.0}},
            {"id": "c1201", "lat": _north(-1200.1), "lng": TRUCK_LNG, "base": _base(w_office=100.0), "rivals": {"day": 0.0, "eve": 0.0}},
            {"id": "z", "lat": TRUCK_LAT, "lng": TRUCK_LNG, "base": _base(w_office=7.0), "rivals": {"day": 0.0, "eve": 0.0}}]
    cid = cap(TRUCK_LAT, TRUCK_LNG, "normal", ring, [], {"point_ids": [], "segment": "w_office", "amount": 500.0})
    c.known(cid, "excluded_amount", 107.0)                                      # z and e249; e251 is beyond the radius
    c.known(cid, "points_used", 4)
    c.known(cid, "within.1", 200.0)
    # Two points under one id are both removed by point_ids and both give to an exclusion, in the order they came.
    dup = [{"id": "s", "lat": _north(100.0), "lng": TRUCK_LNG, "base": _base(w_office=100.0), "rivals": {"day": 0.1, "eve": 0.2}},
           {"id": "s", "lat": _north(120.0), "lng": TRUCK_LNG, "base": _base(w_office=200.0), "rivals": {"day": 0.3, "eve": 0.4}},
           {"id": "S", "lat": _north(50.0), "lng": TRUCK_LNG, "base": _base(res=50.0, w_office=10.0), "rivals": {"day": 0.5, "eve": 0.6}},
           {"id": "r", "lat": _north(-100.0), "lng": TRUCK_LNG, "base": _base(w_office=300.0, v_campus=5.0), "rivals": {"day": 0.0, "eve": 0.9}}]
    cap(TRUCK_LAT, TRUCK_LNG, "normal", dup, [], _no_exclusion())
    cap(TRUCK_LAT, TRUCK_LNG, "normal", list(reversed(dup)), [], _no_exclusion())
    c.known(cap(TRUCK_LAT, TRUCK_LNG, "normal", dup, [], {"point_ids": ["s"], "segment": None, "amount": 0.0}), "points_used", 2)
    cid = cap(TRUCK_LAT, TRUCK_LNG, "prominent", dup, [], {"point_ids": [], "segment": "w_office", "amount": 360.0})
    c.known(cid, "excluded_amount", 360.0)                                      # S (50 m) 10, r and the first s (both 100 m, "r" < "s") 300 and 50
    c.known(cid, "within.1", 250.0)
    # Whole-number coordinates, bases and rivals.
    cap(39, -77, "normal", [{"id": "i", "lat": 39, "lng": -77, "base": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16], "rivals": {"day": 1, "eve": 2}}],
        [{"id": "o", "lat": 39, "lng": -77, "kind": "bar"}], {"point_ids": [], "segment": "w_health", "amount": 2})
    # The order of the sums. Three points at the truck itself (f = 1 exactly) with rivals 1.4, so each share
    # is exactly 0.25; two of the bases cancel (not a real neighbourhood: it makes the order of addition
    # visible). In ascending id the two large ones cancel first and the small one survives; in the order
    # given here, or in descending id, the small one would be absorbed and lost.
    order = [{"id": "k3", "lat": TRUCK_LAT, "lng": TRUCK_LNG, "base": _base(res=1.0), "rivals": {"day": 1.4, "eve": 1.4}},
             {"id": "k2", "lat": TRUCK_LAT, "lng": TRUCK_LNG, "base": _base(res=-1.0e16), "rivals": {"day": 1.4, "eve": 1.4}},
             {"id": "k1", "lat": TRUCK_LAT, "lng": TRUCK_LNG, "base": _base(res=1.0e16), "rivals": {"day": 1.4, "eve": 1.4}}]
    cid = cap(TRUCK_LAT, TRUCK_LNG, "normal", order, [], _no_exclusion())
    c.known(cid, "capture.day.0", 0.25)
    c.known(cid, "capture.eve.0", 0.25)
    c.known(cid, "nearby.0", 1.0)
    c.known(cid, "within.0", 1.0)

    c.known(c.add("g08", "host_exclusion", {"A": _a(), "host": _host("w_public", 0.0)}), "segment", "w_public")
    c.known(c.add("g08", "host_exclusion", {"A": _a(), "host": _host("v_events", 80.0, point_id="")}), "point_ids.0", "")   # an empty id is an id
    link([{"id": "n3", "lat": _north(74.9), "lng": TRUCK_LNG, "base": _base(v_nightlife=1.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "n4", "lat": _north(75.1), "lng": TRUCK_LNG, "base": _base(v_nightlife=1.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "n0", "lat": _north(1.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=0.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "n1", "lat": _north(2.0), "lng": TRUCK_LNG, "base": _base(v_nightlife=-1.0), "rivals": {"day": 0.0, "eve": 0.0}}],
         fx.tap_host)                                                           # a base of 0 or less does not hold the segment; 75.1 m is too far
    link([{"id": "w", "lat": TRUCK_LAT, "lng": TRUCK_LNG - 0.0005, "base": _base(res=1.0), "rivals": {"day": 0.0, "eve": 0.0}},
          {"id": "e", "lat": TRUCK_LAT, "lng": TRUCK_LNG + 0.0005, "base": _base(res=1.0), "rivals": {"day": 0.0, "eve": 0.0}}],
         _host("res", 10.0))                                                    # east and west at the same distance: the smaller id


def _g09_host(c, fx):
    def hc(host, visibility, rivals_here, a=None):
        return c.add("g09", "host_capture", {"A": _a() if a is None else a, "host": host, "visibility": visibility,
                                             "rivals_here": rivals_here})

    none = {"day": 0.0, "eve": 0.0}
    rows = [(fx.tap_host, "normal", none, "captive", 0.75, 0.75, 90.0, 90.0),
            (_host("v_nightlife", 120.0, only_food=False), "normal", none, "captive", 0.3, 0.3, 36.0, 36.0),
            (fx.office_host, "prominent", fx.vec["rivals"], "open", 0.348154, 0.348154, 208.8921, 208.8921),
            (_host("w_office", 600.0, only_food=False), "prominent", fx.vec["rivals"], "open", 0.226718, 0.226718, 136.0311, 136.0311),
            (_host("res", 500.0), "normal", none, "open", 0.384615, 0.384615, 192.3077, 192.3077),
            (_host("res", 500.0), "prominent", none, "open", 0.448276, 0.448276, 224.1379, 224.1379)]
    for (host, visibility, rivals_here, mode, share_day, share_eve, day, eve) in rows:
        cid = hc(host, visibility, rivals_here)
        c.doc(cid, "mode", mode)
        c.doc(cid, "share.day", share_day, 6)
        c.doc(cid, "share.eve", share_eve, 6)
        c.doc(cid, "day", day, 4)
        c.doc(cid, "eve", eve, 4)
    hc(None, "normal", none)
    hc(_host("v_nightlife", 0.0), "normal", none)                               # size 0
    hc(_host("v_shopping", 200.0, "default", False), "hidden", fx.vec_mix["rivals"])     # day and evening differ
    hc(_host("v_events", 80.0, "default"), "prominent", fx.vec_mix["rivals"])   # captive: rivals and visibility ignored
    hc(fx.tap_host, "normal", none, _a({"host.captive_share": 0.6}))
    hc(_host("v_nightlife", 120.0, only_food=False), "normal", none, _a({"host.shared_kitchen_share": 0.2}))
    hc(_host("w_office", 600.0, only_food=False), "normal", fx.vec["rivals"], _a({"host.onsite_kitchen_weight": 4.0}))
    cid = hc(_host("v_nightlife", -5.0), "normal", none)                        # a size below zero is no host
    c.known(cid, "mode", None)
    c.known(cid, "eve", 0.0)
    cid = hc(_host("res", 500), "hidden", {"day": 0, "eve": 3})                 # whole-number size and rivals
    c.known(cid, "share.day", 0.6 / (1.6 + 0.6 + 0.0 + 0.0), 9)
    c.known(cid, "share.eve", 0.6 / (1.6 + 0.6 + 3.0 + 0.0), 9)
    # Every segment once as an open-table host with a kitchen and as the only food: the two captive ones
    # (v_nightlife, v_events) take the flat shares, the fourteen others the kernel at distance zero.
    for s in range(NSEG):
        for only_food in (True, False):
            cid = hc(_host(SEGMENTS[s], 77.0, only_food=only_food), "prominent", {"day": 0.4, "eve": 2.2})
            c.known(cid, "mode", "captive" if SEGMENTS[s] in ("v_nightlife", "v_events") else "open")


def _g10_weather(c, fx):
    def wx(fc, setting, a=None):
        return c.add("g10", "weather_multiplier", {"A": _a() if a is None else a, "fc": fc, "setting": setting})

    table = [((62, 0, "Cloudy", 2), "60_79", "dry", 0.0, "calm", (1.0, 1.0, 1.0), 1.0, 1.0),
             ((55, 40, "Chance Rain Showers", 8), "50_59", "rain", 0.4, "calm", (0.9, 0.82, 1.0), 0.738, 0.8924),
             ((45, 80, "Rain", 22), "40_49", "rain", 0.8, "windy", (0.78, 0.64, 0.85), 0.42432, 0.74214),
             ((88, 60, "Showers And Thunderstorms Likely", 12), "80_89", "storm", 0.6, "calm", (0.95, 0.58, 1.0), 0.551, 0.76),
             ((28, 90, "Heavy Snow", 31), "20_31", "heavy_snow", 0.9, "very_windy", (0.55, 0.325, 0.6), 0.15, 0.374),
             ((97, None, "Sunny", 5), "95_up", "dry", 0.0, "calm", (0.7, 1.0, 1.0), 0.7, 0.9),
             ((50, None, "Light Rain Likely", None), "50_59", "light_rain", 0.5, None, (0.9, 0.9, 1.0), 0.81, 0.9312),
             ((34, 70, "Rain And Snow", 10), "32_39", "snow", 0.7, "calm", (0.66, 0.65, 1.0), 0.429, 0.726),
             ((60, 30, "Patchy Fog", 20), "60_79", "dry", 0.0, "windy", (1.0, 1.0, 0.85), 0.85, 0.95)]
    for (inputs, temp_band, cls, p, wind_band, parts, open_m, captive_m) in table:
        fc = _fc(12, inputs[0], inputs[1], inputs[2], inputs[3])
        cid = wx(fc, "open")
        c.doc(cid, "temp_band", temp_band)
        c.doc(cid, "precip_class", cls)
        c.doc(cid, "precip_p", p, 9)
        c.doc(cid, "wind_band", wind_band)
        c.doc(cid, "temp", parts[0], 9)
        c.doc(cid, "precip", parts[1], 4)
        c.doc(cid, "wind", parts[2], 9)
        c.doc(cid, "multiplier", open_m, 6)
        c.doc(wx(fc, "captive"), "multiplier", captive_m, 6)
    c.doc(wx(None, "open"), "missing", True)
    wx(None, "captive")
    wx(_fc(12, None, None, None, None), "open")                                 # a record with nothing in it
    for temp in (19, 20, 31, 32, 39, 40, 49, 50, 59, 60, 79, 80, 89, 90, 94, 95, 19.99, -5, 110):
        wx(_fc(12, temp, None, None, None), "open")                             # every band boundary
    wx(_fc(12, 31.5, None, None, None), "captive")
    for wind in (0, 19, 20, 29, 30, 45):
        wx(_fc(12, None, None, None, wind), "open")
    wx(_fc(12, None, None, None, 25), "captive")
    for text in ("Thunderstorms", "Blizzard", "Freezing Rain", "Heavy Rain", "Snow Showers", "Drizzle", "Showers",
                 "Mostly Sunny", "Chance T-storms", "Wintry Mix", "Sleet", "Snow Squall Possible", "Flurries",
                 "Light Rain And Snow", "Heavy Rain And Thunder", "Torrential Downpour", "Light Showers",
                 "Tropical Storm Conditions", "Areas Of Smoke"):
        wx(_fc(12, None, 60, text, None), "open")                               # every class; first hit wins
    wx(_fc(12, None, 60, "HEAVY RAIN", None), "open")                           # upper case
    wx(_fc(12, None, 60, "sLiGhT cHaNcE tHuNdEr", None), "captive")             # mixed case
    wx(_fc(12, None, 60, "SPRIN\u212aLES", None), "open")                       # KELVIN SIGN is not an ASCII K: dry
    wx(_fc(12, None, 250, "Rain", None), "open")                                # probability clamps to 1
    wx(_fc(12, None, -20, "Rain", None), "open")                                # ... and to 0
    wx(_fc(12, None, 0, "Rain", None), "open")
    wx(_fc(12, None, None, "Rain Likely", None), "captive")                     # no probability: pop_when_missing
    wx(_fc(12, 70, 55, None, 10), "open")                                       # no text: dry whatever the probability
    wx(_fc(12, 15, 100, "Blizzard", 40), "open")                                # the floor
    wx(_fc(12, 15, 100, "Blizzard", 40), "captive")
    wx(_fc(12, 15, 100, "Blizzard", 40), "open", _a({"weather.floor": 0.05}))
    wx(_fc(12, 55, 40, "Chance Rain Showers", 8), "open",
       _a({"weather.temperature_bands.rows.50_59.open": 0.8, "weather.precip_classes.rows.rain.open": 0.7}))
    wx(_fc(12, 50, None, "Light Rain Likely", 22), "open",
       _a({"weather.pop_when_missing": 0.9, "weather.wind_bands.rows.windy.open": 0.5}))
    # Values that are present but empty or zero are not "missing": a text of "", 0 degrees, 0 mph, 0 %.
    for setting in ("open", "captive"):
        cid = wx(_fc(12, None, None, "", None), setting)
        for (path, want) in [("missing", False), ("precip_class", "dry"), ("precip_p", 0.0), ("temp_band", None), ("multiplier", 1.0)]:
            c.known(cid, path, want)
    cid = wx(_fc(12, 0, None, None, None), "open")
    c.known(cid, "temp_band", "below_20")
    c.known(cid, "missing", False)
    cid = wx(_fc(12, None, 0, None, None), "open")                              # only a probability, of zero
    c.known(cid, "missing", False)
    c.known(cid, "multiplier", 1.0)
    c.known(wx(_fc(12, None, None, None, 0), "captive"), "wind_band", "calm")
    wx(_fc(12, None, 100, None, None), "open")                                  # only a probability: no text, so dry
    wx(_fc(12, 0, 0, "", 0), "open")
    # The needles are plain substrings: "rain" is found inside "Brainerd Fog" and "ice storm" needs both words.
    for (text, want) in [("Brainerd Fog", "rain"), ("Ice", "dry"), ("Icy Roads", "dry"), ("Ice Storm Warning", "ice"),
                         ("Storm", "dry"), ("Dust Storm", "dry"), ("T-Storms Likely", "storm"), ("Tstorms", "storm"),
                         ("Heavyrain", "rain"), ("Heavy  Rain", "rain"), ("Showery", "rain"), ("Light Snow", "snow"),
                         ("Freezing Drizzle Then Light Rain", "ice"), ("Rain Then Heavy Snow", "heavy_snow"),
                         ("Hurricane Conditions", "storm"), ("Tornado Watch", "storm"), ("Ice Pellets", "ice"),
                         ("Sprinkles", "light_rain"), ("RA\U00000130N", "dry"), ("\U0000017fNOW", "dry"),
                         ("Rain \U0001f327", "rain"), ("\U000096e8", "dry")]:
        c.known(wx(_fc(12, None, 60, text, None), "open"), "precip_class", want)
    # Probabilities and temperatures with a fraction; the probability clamps just outside 0..100.
    for (prob, want) in [(99.5, 0.995), (100.0001, 1.0), (-0.0001, 0.0), (0.5, 0.005), (33.3333, 0.333333)]:
        c.known(wx(_fc(12, None, prob, "Rain", None), "captive"), "precip_p", want, 9)
    for (temp, band) in [(19.999999, "below_20"), (31.999, "20_31"), (59.999999999, "50_59"), (79.5, "60_79"), (94.99, "90_94")]:
        c.known(wx(_fc(12, temp, None, None, None), "captive"), "temp_band", band)
    for (wind, band) in [(19.999, "calm"), (29.5, "windy"), (30.0001, "very_windy")]:
        c.known(wx(_fc(12, None, None, None, wind), "open"), "wind_band", band)
    wx(_fc(12, 70, 80, "Sunny", 0), "open", _a({"weather.precip_classes.rows.dry.open": 0.5}))       # the dry row is read, the probability is not
    cid = wx(_fc(12, 70, 0, "Sunny", 0), "open", _a({"weather.floor": 1}))                           # a floor equal to the product
    c.known(cid, "multiplier", 1.0)
    cid = wx(_fc(12, 15, 100, "Blizzard", 40), "open", _a({"weather.floor": 0}))                     # no floor at all
    c.known(cid, "multiplier", 0.4 * 0.25 * 0.6, 9)
    wx(_fc(12, 70, None, "Rain", 0), "open", _a({"weather.pop_when_missing": 0}))
    wx(_fc(12, 70, None, "Rain", 0), "captive", _a({"weather.pop_when_missing": 1}))


def _g11_hourly(c, fx):
    def hour(terms, vectors, ctx, h, profile=None, cal=None, a=None):
        return c.add("g11", "hourly_orders", {"A": _a() if a is None else a, "profile": fx.profile if profile is None else profile,
                                              "terms": terms, "vectors": vectors, "cal": cal, "ctx": ctx, "hour": h})

    # Anchor A1 hour by hour.
    c.doc(hour(fx.terms_open, fx.vec, fx.thu, 11), "orders", 13.119, 3)
    cid = hour(fx.terms_open, fx.vec, fx.thu, 12)
    for (path, want, decimals) in [("regime", "day", None), ("daypart", "lunch", None), ("factors.menu_fit", 1.0, None),
                                   ("segments.1.capture", 417.134520, 6), ("segments.1.presence", 0.39204, 9),
                                   ("segments.1.intent", 0.18, None), ("segments.1.demand_raw", 29.436015, 6),
                                   ("demand_adj", 29.436015, 6), ("orders", 29.436015, 6),
                                   ("segments.1.nearby_present", 437.11, 4), ("segments.1.within_present", 784.08, 4),
                                   ("how", 84, None)]:
        c.doc(cid, path, want, decimals)
    c.doc(hour(fx.terms_open, fx.vec, fx.thu, 13), "orders", 17.939, 3)
    # Anchor A2 hour by hour.
    c.doc(hour(fx.terms_tap, fx.zero, fx.thu, 17), "orders", 10.8, 3)
    cid = hour(fx.terms_tap, fx.zero, fx.thu, 18)
    for (path, want, decimals) in [("regime", "eve", None), ("daypart", "dinner", None), ("host.mode", "captive", None),
                                   ("host.share", 0.75, None), ("host.presence", 0.62, None), ("host.intent", 0.28, None),
                                   ("host.people_present", 74.4, 9), ("host.demand_raw", 15.624, 6), ("orders", 15.624, 6)]:
        c.doc(cid, path, want, decimals)
    c.doc(hour(fx.terms_tap, fx.zero, fx.thu, 19), "orders", 12.96, 3)
    # Host plus catchment: 600 office workers declared, their jobs taken out of the nearest points.
    hour(_terms(visibility="prominent", host=fx.office_host), fx.vec_host600, fx.thu, 12)
    hour(_terms(visibility="prominent", host=_host("w_office", 600.0, only_food=False)), fx.vec_host600, fx.thu, 12)
    # A captive host in rain: the catchment takes the open table, the host the captive one.
    wet = day_context(fx.A, "2026-10-08", None, _forecast([_fc(18, 45, 80, "Rain", 22)]), None, None)
    hour(_terms(host=fx.tap_host), fx.vec_mix, wet, 18)
    hour(_terms(host=fx.tap_host), fx.vec_mix, wet, 19)                         # no record for this hour: missing
    # Calibration factors.
    hour(_terms(spot_id="B", host=fx.tap_host), fx.zero, fx.thu, 18, None, fx.cal)
    hour(_terms(spot_id="elsewhere"), fx.vec, fx.thu, 12, None, fx.cal)         # truck factor only
    # The capacity cap, with segment rows scaled by the same factor.
    hour(_terms(host=_host("v_nightlife", 400.0)), fx.vec_mix, fx.sat, 18)
    hour(fx.terms_open, fx.vec, fx.thu, 12, _profile(capacity_orders_per_hour=20.0))
    hour(fx.terms_open, fx.vec, fx.thu, 12, _profile(capacity_orders_per_hour=0.0))
    # Menu fit 0 and the breakfast fit.
    hour(fx.terms_open, fx.vec, fx.thu, 12, _profile(daypart_fit={"breakfast": 0.3, "lunch": 0.0, "dinner": 1.0, "late": 0.8}))
    hour(fx.terms_open, fx.vec_mix, fx.thu, 8)
    # Weak-seed and default-size parts.
    hour(_terms(host=_host("v_nightlife", 40.0, "default", place_type="taproom")), fx.vec_mix, fx.thu, 18)
    hour(fx.terms_open, fx.vec_campus, fx.thu, 12)
    # Stored vectors (within is null), a typical context, the small hours, an override.
    hour(fx.terms_open, _stored(fx.vec), fx.thu, 12)
    hour(fx.terms_open, fx.vec, typical_context(fx.A, 3), 12)
    hour(_terms(host=fx.tap_host), fx.vec_mix, fx.sat, 0)
    hour(fx.terms_open, fx.vec_mix, fx.sun, 23)
    a_over = _a({"segments.w_office.intent.weekday": [0.0] * 11 + [0.1, 0.2, 0.1] + [0.0] * 10, "host.captive_share": 0.6})
    hour(_terms(host=fx.tap_host), fx.vec, day_context(_assume(a_over), "2026-10-08", None, None, None, None), 12, None, None, a_over)
    # A host of size 0 or less is no host: no host row, and its default size does not count.
    for size in (0.0, -10.0):
        cid = hour(_terms(host=_host("v_nightlife", size, "default")), fx.vec, fx.thu, 12)
        c.known(cid, "host", None)
        c.known(cid, "default_size_part", 0.0)
        c.known(cid, "orders", 29.436015, 6)
    # Demand exactly equal to capacity is not capped; one part in 10^9 less capacity is.
    exact = hourly_orders(fx.A, fx.profile, fx.terms_tap, fx.zero, None, fx.thu, 18)["demand_adj"]
    cid = hour(fx.terms_tap, fx.zero, fx.thu, 18, _profile(capacity_orders_per_hour=exact))
    c.known(cid, "capped", False)
    c.known(cid, "host.orders", exact)
    cid = hour(fx.terms_tap, fx.zero, fx.thu, 18, _profile(capacity_orders_per_hour=exact * (1.0 - 1e-9)))
    c.known(cid, "capped", True)
    # Spot ids are plain map keys: an id that names a member of every object ("toString", "constructor",
    # "__proto__") has no factor unless the log holds it; digits are not numbers ("12", "012", "1e1").
    odd_cal = dict(fx.cal, spots=dict(fx.cal["spots"]))
    for (k, spot_id) in enumerate(["12", "0", "007", "", "__proto__", "1e1", "-1", "1.0"]):
        odd_cal["spots"][spot_id] = {"factor": 1.5 + 0.25 * k, "log_factor": 0.1 * (k + 1), "n": k + 1, "weight": 0.5 * (k + 1)}
    for (spot_id, want) in [("12", 1.5), ("", 2.25), ("__proto__", 2.5), ("012", 1.0), ("toString", 1.0)]:
        cid = hour(_terms(spot_id=spot_id), fx.vec, fx.thu, 12, None, odd_cal)
        c.known(cid, "factors.spot_factor", want)
        c.known(cid, "factors.truck_factor", fx.cal["truck_factor"])
    # Every clock hour of a wet day with a host of each mode (the two weather tables side by side), a
    # forecast that leaves out fields, and hours whose record is absent.
    wet_all = _forecast([_fc(h, 30 + 3 * h, 5 * h, ["Sunny", "Rain", "Snow", "Thunderstorms", None][h % 5], 2 * h) for h in range(0, 24, 2)])
    wet_day = day_context(fx.A, "2026-10-08", None, wet_all, None, None)
    for h in (0, 6, 12, 16, 23):
        hour(_terms(host=_host("v_nightlife", 60.0)), fx.vec_mix, wet_day, h)
        hour(_terms(host=_host("v_shopping", 60.0, only_food=False)), fx.vec_mix, wet_day, h)
    hour(fx.terms_open, fx.vec_mix, day_context(fx.A, "2026-10-08", None, [None] * 24, None, None), 12)
    hour(fx.terms_open, fx.vec_mix, day_context(fx.A, "2026-10-08", None, _forecast([_fc(12, None, None, None, None)]), None, None), 12)
    hour(_terms(spot_id="A"), fx.vec, typical_context(fx.A, 5), 12, _profile(capacity_orders_per_hour=10), fx.cal)       # a whole-number capacity
    hour(fx.terms_open, fx.vec, fx.thu, 12, _profile(daypart_fit={"breakfast": 0, "lunch": 1, "dinner": 1, "late": 0}))  # whole-number fits


def _g12_window(c, fx):
    def win(terms, vectors, ctx, ctx_next, open_minute, close_minute, profile=None, cal=None, a=None):
        return c.add("g12", "window_orders",
                     {"A": _a() if a is None else a, "profile": fx.profile if profile is None else profile, "terms": terms,
                      "vectors": vectors, "cal": cal, "ctx": ctx, "ctx_next": ctx_next, "open": open_minute,
                      "close": close_minute})

    def row(cid, by_hour, value, low, high, confidence):
        for k in range(len(by_hour)):
            c.doc(cid, "hours.%d.result.orders" % k, by_hour[k], 3)
        c.doc(cid, "orders.value", value, 4)
        c.doc(cid, "orders.low", low, 2)
        c.doc(cid, "orders.high", high, 2)
        c.doc(cid, "orders.confidence", confidence)

    # Anchor A1 through the week (Thursday itself is in g24).
    row(win(fx.terms_open, fx.vec, fx.ctx["2026-10-06"], None, 660, 840), [14.455, 32.434, 19.766], 66.6553, 36.60, 97.93, "rough")
    row(win(fx.terms_open, fx.vec, fx.fri, None, 660, 840), [8.138, 18.261, 11.129], 37.5286, 19.69, 59.41, "rough")
    row(win(fx.terms_open, fx.vec, fx.sat, None, 660, 840), [0.766, 1.723, 1.053], 3.5421, 1.00, 7.04, "rough")
    # Variations of the Thursday window.
    c.doc(win(fx.terms_open, fx.vec_no_rivals, fx.thu, None, 660, 840), "orders.value", 73.81, 2)
    c.doc(win(_terms(visibility="hidden"), fx.vec_hidden, fx.thu, None, 660, 840), "orders.value", 39.72, 2)
    c.doc(win(_terms(visibility="prominent"), fx.vec_prominent, fx.thu, None, 660, 840), "orders.value", 73.89, 2)
    cid = win(_terms(visibility="prominent", host=fx.office_host), fx.vec_host600, fx.thu, None, 660, 840)
    c.doc(cid, "orders.value", 77.59, 2)
    c.doc(cid, "host_orders", 30.29, 2)
    cid = win(_terms(visibility="prominent", host=_host("w_office", 600.0, only_food=False)), fx.vec_host600, fx.thu, None, 660, 840)
    c.doc(cid, "orders.value", 67.02, 2)
    c.doc(cid, "host_orders", 19.73, 2)
    # With a forecast.
    cid = win(fx.terms_open, fx.vec, day_context(fx.A, "2026-10-08", None, fx.rain, None, None), None, 660, 840)
    for (path, want, decimals) in [("hours.0.result.factors.weather_open", 0.738, 4), ("hours.1.result.factors.weather_open", 0.6165, 4),
                                   ("hours.2.result.factors.weather_open", 0.7785, 4), ("hours.0.result.orders", 9.682, 3),
                                   ("hours.1.result.orders", 18.147, 3), ("hours.2.result.orders", 13.966, 3),
                                   ("orders.value", 41.79, 2), ("orders.low", 22.16, 2), ("orders.high", 65.82, 2)]:
        c.doc(cid, path, want, decimals)
    # Anchor A2 and its variations.
    row(win(fx.terms_tap, fx.zero, fx.ctx["2026-10-05"], None, 1020, 1200), [5.4, 7.812, 6.48], 19.692, 9.49, 32.47, "rough")
    row(win(fx.terms_tap, fx.zero, fx.sat, None, 1020, 1200), [21.6, 23.94, 19.008], 64.548, 35.37, 99.95, "rough")
    row(win(_terms(host=_host("v_nightlife", 120.0, only_food=False)), fx.zero, fx.thu, None, 1020, 1200),
        [4.32, 6.25, 5.184], 15.7536, 7.28, 26.45, "rough")
    row(win(_terms(host=_host("v_nightlife", 40.0, "default", place_type="taproom")), fx.zero, fx.thu, None, 1020, 1200),
        [3.6, 5.208, 4.32], 13.128, 3.44, 26.54, "very_rough")
    cid = win(fx.terms_tap, fx.zero, fx.fri, fx.sat, 1290, 1500)                # Friday 21:30 to Saturday 01:00
    row(cid, [4.455, 1.555, 0.691, 0.691], 5.1651, 1.72, 9.79, "rough")
    c.doc(cid, "hours.0.fraction", 0.5)
    c.doc(cid, "hours.3.day_index", 1)
    row(win(_terms(host=_host("v_nightlife", 400.0)), fx.zero, fx.sat, None, 1020, 1200), [45.0, 45.0, 45.0], 135.0, 122.63, 135.0, "rough")
    cid = win(fx.terms_tap, fx.zero, fx.thu, None, 1020, 1020)                  # zero length
    row(cid, [], 0.0, 0.0, 0.0, "rough")
    c.doc(cid, "capacity_total", 0.0)
    win(fx.terms_tap, fx.zero, fx.thu, None, 1030, 1030)                        # zero length inside an hour: no hours either
    # The host-link example of 4.4: linked, and left unlinked.
    c.doc(win(_terms(host=_host("v_nightlife", 120.0, point_id="pw1")), fx.vec_pw1_linked, fx.thu, None, 1020, 1200), "orders.value", 39.38, 2)
    c.doc(win(_terms(host=fx.tap_host), fx.vec_pw1_unlinked, fx.thu, None, 1020, 1200), "orders.value", 46.01, 2)
    # Calibrated: anchor A2 saved as spot B of the log in 4.13.
    cid = win(_terms(spot_id="B", host=fx.tap_host), fx.zero, fx.thu, None, 1020, 1200, None, fx.cal)
    for (path, want, decimals) in [("orders.value", 35.592, 3), ("orders.low", 20.60, 2), ("orders.high", 53.53, 2),
                                   ("orders.confidence", "fair", None), ("spread.sigma_model", 0.2902, 4)]:
        c.doc(cid, path, want, decimals)
    # Partial hours, a window inside one hour, a whole day, two whole days.
    win(fx.terms_open, fx.vec, fx.thu, None, 690, 825)
    win(fx.terms_open, fx.vec, fx.thu, None, 730, 760)
    win(fx.terms_open, fx.vec_mix, fx.thu, None, 0, 1440)
    win(_terms(host=fx.tap_host), fx.vec_mix, fx.sat, fx.sun, 1380, 1620)      # Saturday 23:00 to Sunday 03:00
    win(fx.terms_tap, fx.zero, fx.sat, fx.sun, 2820, 2880)                      # the last hour a window may reach
    # Outside the region: the flag changes nothing in the estimate.
    outside = dict(fx.vec)
    outside["in_region"] = False
    win(fx.terms_open, outside, fx.thu, None, 660, 840)
    # Weak seeds; stored vectors; a typical context; an override.
    win(fx.terms_open, fx.vec_campus, fx.thu, None, 660, 840)
    win(fx.terms_open, _stored(fx.vec_mix), fx.thu, None, 660, 840)
    win(fx.terms_tap, fx.zero, typical_context(fx.A, 4), typical_context(fx.A, 5), 1290, 1500)
    a_over = _a({"segments.v_nightlife.dow_factor": [0.5, 0.54, 0.79, 1.2, 1.5]})
    win(fx.terms_tap, fx.zero, day_context(_assume(a_over), "2026-10-08", None, None, None, None), None, 1020, 1200, None, None, a_over)
    # Errors.
    win(fx.terms_open, fx.vec, fx.thu, None, 840, 660)                          # invalid_window
    win(fx.terms_open, fx.vec, fx.thu, None, -60, 120)                          # invalid_window
    win(fx.terms_open, fx.vec, fx.thu, fx.fri, 1380, 2940)                      # invalid_window
    win(fx.terms_tap, fx.zero, fx.fri, None, 1290, 1500)                        # missing_context
    # The ends of the allowed range, one minute either side of midnight, and where the next day's context
    # is needed: a window that ends at 1440 or is empty at 1440 does not need it, one minute more does.
    mix_tap = _terms(host=_host("v_nightlife", 90.0, "default"))
    cid = win(mix_tap, fx.vec_mix, fx.thu, fx.fri, 1380, 1500)                  # the last hour of Thursday and the first of Friday
    c.known(cid, "minutes", 120)
    c.calc(cid, "day indexes", lambda r: [h["day_index"] for h in r["hours"]], [0, 1])
    c.calc(cid, "date of the hour after midnight", lambda r: r["hours"][1]["result"]["date"], "2026-10-09")
    cid = win(mix_tap, fx.vec_mix, fx.thu, fx.fri, 1439, 1441)                  # one minute of Thursday, one of Friday
    c.calc(cid, "hours and fractions", lambda r: [[h["day_index"], h["hour"]] for h in r["hours"]], [[0, 23], [1, 0]])
    c.known(cid, "hours.0.fraction", 1.0 / 60.0, 9)
    cid = win(mix_tap, fx.vec_mix, fx.thu, None, 1380, 1440)                    # ends at midnight: no next context needed
    c.calc(cid, "number of hours", lambda r: len(r["hours"]), 1)
    cid = win(mix_tap, fx.vec_mix, fx.thu, None, 1440, 1440)                    # empty, at midnight
    c.known(cid, "hours", [])
    c.known(cid, "orders.value", 0.0)
    c.known(win(mix_tap, fx.vec_mix, fx.thu, None, 1439, 1441), "error", "missing_context")
    c.known(win(mix_tap, fx.vec_mix, fx.thu, None, 1440, 1441), "error", "missing_context")
    c.known(win(mix_tap, fx.vec_mix, fx.thu, fx.fri, 2880, 2880), "minutes", 0)
    c.known(win(mix_tap, fx.vec_mix, fx.thu, fx.fri, 0, 0), "capped_hours", 0)
    c.known(win(mix_tap, fx.vec_mix, fx.thu, fx.fri, 0, 1), "hours.0.fraction", 1.0 / 60.0, 9)
    for (o, cl_) in [(-1, 0), (0, 2881), (2881, 2881), (-5, -5), (1441, 1440)]:
        c.known(win(mix_tap, fx.vec_mix, fx.thu, fx.fri, o, cl_), "error", "invalid_window")
    c.known(win(mix_tap, fx.vec_mix, fx.thu, None, 1500, 1440), "error", "invalid_window")      # the window is checked before the context
    # A dated day running into a typical one and the reverse: each hour takes the weather rule of its own context.
    wet = day_context(fx.A, "2026-10-08", None, _forecast([_fc(h, 45, 80, "Rain", 22) for h in range(24)]), None, None)
    cid = win(fx.terms_tap, fx.zero, wet, typical_context(fx.A, 4), 1320, 1560)
    c.calc(cid, "weather states", lambda r: [h["result"]["factors"]["weather_state"] for h in r["hours"]], ["forecast", "forecast", "typical", "typical"])
    cid = win(fx.terms_tap, fx.zero, typical_context(fx.A, 2), wet, 1320, 1560)
    c.calc(cid, "weather states", lambda r: [h["result"]["factors"]["weather_state"] for h in r["hours"]], ["typical", "typical", "forecast", "forecast"])
    c.calc(cid, "date", lambda r: r["date"], None)
    # No capacity at all; every hour capped; a spot the log does not hold; default host size on a weak seed.
    cid = win(fx.terms_open, fx.vec, fx.thu, None, 660, 840, _profile(capacity_orders_per_hour=0.0))
    c.known(cid, "orders.value", 0.0)
    c.known(cid, "orders.high", 0.0)
    c.known(cid, "capped_hours", 3)
    win(_terms(spot_id="not-logged"), fx.vec, fx.thu, None, 660, 840, None, fx.cal)
    cid = win(_terms(host=_host("v_campus", 500.0, "default", False)), fx.vec_campus, fx.thu, None, 600, 900)
    c.known(cid, "evidence.weak_share", 1.0, 9)
    c.known(cid, "orders.confidence", "very_rough")
    cid = win(_terms(host=_host("v_nightlife", 40.0, "default")), fx.vec, fx.thu, None, 420, 600)       # the host is there but nobody is in it
    c.known(cid, "host_orders", 0.0)
    c.known(cid, "evidence.default_size_share", 0.0)


def _g13_week(c, fx):
    def best(values, length, top_n, circular, allowed=None):
        return c.add("g13", "best_windows", {"values": values, "length": length, "top_n": top_n, "circular": circular,
                                             "allowed": allowed})

    def strip(terms, vectors, profile=None, cal=None):
        return c.add("g13", "week_strip", {"A": _a(), "profile": fx.profile if profile is None else profile,
                                           "terms": terms, "vectors": vectors, "cal": cal})

    cid = strip(fx.terms_tap, fx.zero)                                          # anchor A2
    c.doc(cid, "137", 21.6, 3)
    c.doc(cid, "138", 23.94, 3)
    c.doc(cid, "139", 19.008, 3)
    strip(fx.terms_open, fx.vec)                                                # anchor A1
    strip(_terms(spot_id="B", host=fx.tap_host), fx.vec_mix, _profile(capacity_orders_per_hour=12.0), fx.cal)
    tap_strip = week_strip(fx.A, fx.profile, fx.terms_tap, fx.zero, None)
    office_strip = week_strip(fx.A, fx.profile, fx.terms_open, fx.vec, None)
    cid = best(tap_strip, 3, 3, True)
    for (k, start, total) in [(0, 137, 64.548), (1, 113, 59.076), (2, 89, 39.384)]:
        c.doc(cid, "%d.start" % k, start)
        c.doc(cid, "%d.total" % k, total, 4)
    cid = best(office_strip, 3, 3, True)
    for (k, start, total) in [(0, 35, 66.6553), (1, 59, 64.9749), (2, 83, 60.4938)]:
        c.doc(cid, "%d.start" % k, start)
        c.doc(cid, "%d.total" % k, total, 4)
    cid = best([1.0, 5.0, 5.0, 1.0, 5.0, 5.0, 1.0], 2, 2, False)                # an exact tie: earlier start first
    for (path, want) in [("0.start", 1), ("0.total", 10.0), ("1.start", 4), ("1.total", 10.0)]:
        c.doc(cid, path, want)
    c.doc(best([0.0, 0.0, 0.0], 2, 2, False), "", [])
    best([9.0, 1.0, 1.0, 1.0, 1.0, 8.0], 2, 2, True)                            # the best window wraps around
    best([9.0, 1.0, 1.0, 1.0, 1.0, 8.0], 2, 2, False)
    best([1.0, 5.0, 5.0, 1.0, 5.0, 5.0, 1.0], 2, 3, False, [True, True, False, True, True, True, True])
    best([1.0, 5.0, 5.0, 1.0, 5.0, 5.0, 1.0], 3, 5, False)                      # top_n larger than what fits
    best([2.0, 3.0], 3, 1, True)                                                # longer than the list
    best([2.0, 3.0, 4.0], 3, 2, True)                                           # the whole circle: start 0
    best([2.0, 3.0, 4.0], 1, 0, False)                                          # top_n 0
    best([0.0000002, 0.0000002, 4.0, 0.0], 2, 3, False)                         # a total that rounds to zero millionths
    best(tap_strip, 3, 2, True, [h // 24 != 5 for h in range(168)])             # never on a Saturday
    c.known(best([], 1, 1, False), "", [])                                      # nothing to choose from
    c.known(best([], 1, 1, True), "", [])
    c.known(best([7.0], 1, 3, True), "", [{"start": 0, "length": 1, "total": 7.0}])
    c.known(best([2.0, 3.0, 4.0], 2, 3, True, [False, False, False]), "", [])   # everything masked
    cid = best([2.0] * 9, 2, 9, True)                                           # nine equal windows on a circle: every second start, then nothing fits
    c.calc(cid, "starts", lambda r: [w["start"] for w in r], [0, 2, 4, 6])
    cid = best([2.0] * 9, 2, 9, False)
    c.calc(cid, "starts", lambda r: [w["start"] for w in r], [0, 2, 4, 6])
    cid = best([5.00000001, 5.00000002, 5.0, 4.99999999], 1, 4, False)          # equal in whole millionths: the earlier start, not the larger value
    c.calc(cid, "starts", lambda r: [w["start"] for w in r], [0, 1, 2, 3])
    cid = best([1.0000001, 1.0000004, 1.0000006, 1.0000016], 1, 4, False)       # keys 1000000, 1000000, 1000001, 1000002
    c.calc(cid, "starts", lambda r: [w["start"] for w in r], [3, 2, 0, 1])
    cid = best([0.0000004, 0.0000006, 0.0000014], 1, 3, False)                  # keys 0, 1, 1: the first is never returned
    c.calc(cid, "starts", lambda r: [w["start"] for w in r], [1, 2])
    cid = best([1, 2, 3, 2, 1], 2, 2, False)                                    # whole numbers
    c.calc(cid, "starts and totals", lambda r: [[w["start"], w["total"]] for w in r], [[1, 5.0], [3, 3.0]])
    cid = best([3.0, 1.0, 4.0, 1.0, 5.0, 9.0, 2.0, 6.0, 5.0, 3.0, 5.0, 8.0], 5, 3, True)       # the second window wraps; a third does not fit
    c.calc(cid, "starts", lambda r: [w["start"] for w in r], [4, 10])
    cid = best([3.0, 1.0, 4.0, 1.0, 5.0, 9.0, 2.0, 6.0, 5.0, 3.0, 5.0, 8.0], 11, 2, True)      # one index short of the whole circle
    c.calc(cid, "number of windows", lambda r: len(r), 1)
    strip(_terms(spot_id="toString"), fx.vec_mix, None, fx.cal)                 # an id the log does not hold: truck factor only
    strip(fx.terms_open, fx.vec_mix, _profile(capacity_orders_per_hour=0), None)


def _g14_ranges(c, fx):
    def inter(mean, ev):
        return c.add("g14", "interval", {"A": _a(), "mean": mean, "ev": ev})

    def capped(d, cap, ev):
        return c.add("g14", "interval_capped", {"A": _a(), "d": d, "c": cap, "ev": ev})

    logged = _evidence(truck_weight=10.0, spot_weight=5.0, resid_sd=0.2, resid_weight=9.0)
    rows = [(60.0, _evidence(), 0.36565, 0.40803, 32.7265, 93.1316, "rough"),
            (5.0, _evidence(), 0.36565, 0.68569, 1.6415, 9.5172, "rough"),
            (0.0, _evidence(), 0.36565, 0.36565, 0.0, 0.0, "rough"),
            (60.0, _evidence(truck_weight=10.0), 0.30423, 0.35404, 35.7999, 88.7121, "fair"),
            (60.0, logged, 0.26457, 0.3206, 37.7916, 85.9545, "fair"),
            (60.0, dict(logged, resid_sd=0.3), 0.31622, 0.3644, 35.197, 89.5632, "rough"),
            (60.0, _evidence(truck_weight=24.0, spot_weight=12.0, resid_sd=0.18, resid_weight=22.0), 0.22243, 0.28682, 39.8704, 83.1618, "good"),
            (60.0, _evidence(weak_share=1.0), 0.54194, 0.57139, 24.5038, 105.9926, "very_rough"),
            (13.128, _evidence(default_size_share=1.0), 0.70264, 0.79718, 3.4396, 26.5393, "very_rough"),
            (100.0, _evidence(event=True), 0.61944, 0.63522, 36.2107, 184.4695, "very_rough"),
            (500.0, _evidence(fixed=True), 0.0, 0.0, 500.0, 500.0, "fixed")]
    for (mean, ev, sigma_model, sigma, low, high, label) in rows:
        cid = inter(mean, ev)
        c.doc(cid, "1.sigma_model", sigma_model, 5)
        c.doc(cid, "1.sigma", sigma, 5)
        c.doc(cid, "0.low", low, 4)
        c.doc(cid, "0.high", high, 4)
        c.doc(cid, "0.confidence", label)
    # Each label boundary (4.8): the weakest evidence that still earns the better label, and one step less.
    c.doc(inter(60.0, _evidence(weak_share=0.464)), "0.confidence", "very_rough")
    c.doc(inter(60.0, _evidence(weak_share=0.463)), "0.confidence", "rough")
    c.doc(inter(60.0, _evidence(default_size_share=0.31)), "0.confidence", "very_rough")
    c.doc(inter(60.0, _evidence(default_size_share=0.309)), "0.confidence", "rough")
    c.doc(inter(60.0, _evidence(truck_weight=7.53)), "0.confidence", "fair")
    c.doc(inter(60.0, _evidence(truck_weight=7.51)), "0.confidence", "rough")
    c.doc(inter(60.0, _evidence(truck_weight=1e9, resid_sd=0.13, resid_weight=1e9)), "0.confidence", "fair")
    c.doc(inter(60.0, _evidence(truck_weight=1e9, resid_sd=0.12, resid_weight=1e9)), "0.confidence", "good")
    inter(-3.0, _evidence())                                                    # a mean below zero is zero
    inter(0.2, _evidence())                                                     # a very small count
    inter(100000.0, logged)

    table = [([10.0, 20.0, 10.0], [45.0, 45.0, 45.0], 21.1189, 63.1239, 40.0, 21.1189, 63.1239),
             ([20.0, 40.0, 20.0], [45.0, 45.0, 45.0], 44.3801, 123.0843, 80.0, 44.3801, 106.5422),
             ([30.0, 60.0, 30.0], [45.0, 45.0, 45.0], 67.7385, 182.9292, 105.0, 67.7385, 135.0),
             ([45.0, 45.0, 45.0], [45.0, 45.0, 45.0], 76.5063, 205.3612, 135.0, 76.5063, 135.0),
             ([10.0, 50.0], [22.5, 45.0], 32.7265, 93.1316, 55.0, 32.7265, 60.5219)]
    for (d, cap, e_low, e_high, value, low, high) in table:
        cid = capped(d, cap, _evidence())
        c.doc(cid, "0.value", value, 4)
        c.doc(cid, "0.low", low, 4)
        c.doc(cid, "0.high", high, 4)
        cid = inter(d[0] + d[1] + (d[2] if len(d) > 2 else 0.0), _evidence())  # the interval on demand, before the cap
        c.doc(cid, "0.low", e_low, 4)
        c.doc(cid, "0.high", e_high, 4)
    big = window_orders(fx.A, fx.profile, _terms(host=_host("v_nightlife", 400.0)), fx.zero, None, fx.sat, None, 1020, 1200)
    d = [h["result"]["demand_adj"] * h["fraction"] for h in big["hours"]]
    cid = capped(d, [45.0, 45.0, 45.0], _evidence())                            # the size-400 taproom of 4.7
    c.doc(cid, "0.value", 135.0, 4)
    c.doc(cid, "0.low", 122.6273, 4)
    c.doc(cid, "0.high", 135.0, 4)
    c.doc(capped([0.0, 0.0], [45.0, 45.0], _evidence()), "0.high", 0.0)
    capped([], [], _evidence())
    capped([30.0, 60.0, 30.0], [45.0, 45.0, 45.0], _evidence(fixed=True))
    capped([112.5, 225.0, 225.0, 112.5], [22.5, 45.0, 45.0, 22.5], _evidence(event=True))
    capped([20.0, 40.0, 20.0], [45.0, 45.0, 45.0], logged)

    rough = {"value": 60.0, "low": 33.0, "high": 93.0, "confidence": "rough"}
    c.add("g14", "est_sum", {"estimates": [rough, est_fixed(1120.0), {"value": 13.0, "low": 3.5, "high": 26.5, "confidence": "very_rough"}]})
    c.add("g14", "est_sum", {"estimates": [rough, {"value": 35.5, "low": 20.5, "high": 53.5, "confidence": "fair"}]})
    c.add("g14", "est_sum", {"estimates": []})
    c.add("g14", "est_levels", {"v": 5.0, "l": 3.0, "h": 9.0, "c": "rough"})
    c.add("g14", "est_levels", {"v": -100.0, "l": -40.0, "h": -200.0, "c": "rough"})      # a line that falls as orders rise
    c.add("g14", "est_levels", {"v": 0.0, "l": 0.0, "h": 0.0, "c": "fixed"})
    c.add("g14", "est_fixed", {"x": 12.5})
    c.add("g14", "weakest", {"labels": ["good", "fair", "fixed"]})
    c.add("g14", "weakest", {"labels": []})
    c.add("g14", "weakest", {"labels": ["fixed", "good", "fair", "rough", "very_rough"]})
    c.add("g14", "evidence_from", {"cal": None, "spot_id": None})
    c.add("g14", "evidence_from", {"cal": fx.cal, "spot_id": "B"})
    c.add("g14", "evidence_from", {"cal": fx.cal, "spot_id": "not-logged"})
    c.add("g14", "evidence_from", {"cal": fx.cal, "spot_id": None})
    # A count so small that the 90th percentile of the log-normal falls below its mean: high is the mean.
    for mean in (0.0001, 0.000000001):
        cid = inter(mean, _evidence())
        c.known(cid, "0.high", mean)
        c.known(cid, "0.value", mean)
    cid = inter(0.0001, _evidence(event=True))
    c.known(cid, "0.high", 0.0001)
    c.known(cid, "0.confidence", "very_rough")
    # A logged residual spread of exactly 0.0 is a spread, not "no spread": it is blended with the prior.
    cid = inter(60.0, _evidence(truck_weight=10.0, spot_weight=5.0, resid_sd=0.0, resid_weight=9.0))
    c.known(cid, "1.v_day", 6.0 * 0.04 / 15.0, 9)
    cid = inter(60.0, _evidence(resid_sd=0.5, resid_weight=0.0))                # a spread with no weight behind it: the prior
    c.known(cid, "1.v_day", 0.04, 9)
    inter(60.0, _evidence(weak_share=0.5, default_size_share=0.5, event=True))  # every part at once
    inter(60, _evidence(truck_weight=4, spot_weight=3))                         # whole numbers
    cid = inter(0.0, _evidence(fixed=True))
    c.known(cid, "0.confidence", "fixed")
    c.known(cid, "0.high", 0.0)
    cid = inter(-7.5, _evidence(fixed=True))                                    # a fixed amount is handed through, below zero too
    c.known(cid, "0.value", -7.5)
    c.known(cid, "0.low", -7.5)
    cid = capped([0.0, 0.0], [45.0, 45.0], _evidence(fixed=True))
    c.known(cid, "0.confidence", "fixed")
    c.known(cid, "0.value", 0.0)
    cid = capped([30.0], [30.0], _evidence())                                   # one hour, demand equal to capacity
    c.known(cid, "0.value", 30.0)
    c.known(cid, "0.high", 30.0)
    cid = capped([5.0, 0.0, 5.0], [0.0, 45.0, 45.0], _evidence())               # an hour with no capacity, an hour with no demand
    c.known(cid, "0.value", 5.0)
    capped([0.0001, 0.0001], [45.0, 45.0], _evidence())                         # high held at the mean inside interval
    capped([10, 20], [45, 45], _evidence())                                     # whole numbers
    # Lists are added from the first item on: the small items survive only in this order.
    cancel = [{"value": x, "low": x, "high": x, "confidence": label}
              for (x, label) in [(1.0e16, "fair"), (1.0, "good"), (-1.0e16, "rough"), (1.0, "fixed")]]
    cid = c.add("g14", "est_sum", {"estimates": cancel})
    c.known(cid, "value", 1.0)
    c.known(cid, "low", 1.0)
    c.known(cid, "high", 1.0)
    c.known(cid, "confidence", "rough")
    c.known(c.add("g14", "est_sum", {"estimates": list(reversed(cancel))}), "value", 0.0)
    c.add("g14", "est_sum", {"estimates": [{"value": 1, "low": 0, "high": 2, "confidence": "good"}, {"value": 2, "low": 1, "high": 3, "confidence": "good"}]})
    for (v, l, h, low, high) in [(2.0, 3.0, 1.0, 1.0, 3.0), (2.0, 1.0, 3.0, 1.0, 3.0), (3.0, 2.0, 1.0, 1.0, 3.0), (1.0, 2.0, 3.0, 1.0, 3.0),
                                 (1.0, 3.0, 2.0, 1.0, 3.0), (3.0, 1.0, 2.0, 1.0, 3.0), (-1.0, -2.0, -3.0, -3.0, -1.0), (5, 5, 5, 5, 5)]:
        cid = c.add("g14", "est_levels", {"v": v, "l": l, "h": h, "c": "fair"})
        c.known(cid, "low", low)
        c.known(cid, "high", high)
        c.known(cid, "value", v)
    c.known(c.add("g14", "est_fixed", {"x": 0}), "confidence", "fixed")
    c.known(c.add("g14", "est_fixed", {"x": -3.25}), "low", -3.25)
    c.known(c.add("g14", "weakest", {"labels": ["fixed"]}), "", "fixed")
    c.known(c.add("g14", "weakest", {"labels": ["rough", "rough"]}), "", "rough")
    c.known(c.add("g14", "weakest", {"labels": ["good", "very_rough", "fixed", "very_rough"]}), "", "very_rough")
    odd_cal = dict(fx.cal, spots=dict(fx.cal["spots"]))
    for (k, spot_id) in enumerate(["12", "0", "", "__proto__"]):
        odd_cal["spots"][spot_id] = {"factor": 1.5, "log_factor": 0.4, "n": 2, "weight": 0.5 * (k + 1)}
    for (spot_id, want) in [("12", 0.5), ("0", 1.0), ("", 1.5), ("__proto__", 2.0), ("toString", 0.0), ("constructor", 0.0), ("012", 0.0), ("1", 0.0)]:
        c.known(c.add("g14", "evidence_from", {"cal": odd_cal, "spot_id": spot_id}), "spot_weight", want)
    c.known(c.add("g14", "evidence_from", {"cal": calibrate(fx.A, [], "2026-10-04"), "spot_id": "A"}), "resid_sd", None)


def _g15_money(c, fx):
    P = fx.profile
    fee = _terms(fee_pct=0.1, fee_min=75.0)
    a1 = window_orders(fx.A, P, fx.terms_open, fx.vec, None, fx.thu, None, 660, 840)["orders"]
    cid = c.add("g15", "stop_money", {"profile": P, "terms": fx.terms_open, "orders": a1})
    for (line, value, low, high) in [("orders", 60.49, 33.01, 93.19), ("sales", 907.41, 495.21, 1397.91),
                                     ("food_cost", 272.22, 148.56, 419.37), ("packaging", 30.25, 16.51, 46.6),
                                     ("card_fees", 27.77, 15.15, 42.78), ("spot_fee", 0.0, 0.0, 0.0),
                                     ("tips", 0.0, 0.0, 0.0), ("contribution", 577.17, 314.99, 889.17)]:
        c.doc(cid, line + ".value", value, 2)
        c.doc(cid, line + ".low", low, 2)
        c.doc(cid, line + ".high", high, 2)
    c.add("g15", "stop_money", {"profile": P, "terms": fee, "orders": a1})      # the minimum at low, the percentage at high
    c.add("g15", "stop_money", {"profile": _profile(tips_include=True), "terms": _terms(fee_flat=50.0, fee_pct=0.05, fee_min=100.0), "orders": a1})
    lossy = _profile(food_cost_pct=0.95, packaging_per_order=1.5)               # every order loses money
    c.add("g15", "stop_money", {"profile": lossy, "terms": fx.terms_open, "orders": a1})
    c.add("g15", "stop_money", {"profile": P, "terms": fx.terms_open, "orders": est_fixed(80.0)})
    c.add("g15", "stop_money", {"profile": P, "terms": fee, "orders": {"value": 0.0, "low": 0.0, "high": 0.0, "confidence": "rough"}})
    for (orders, sales, food, packaging, card, spot_fee, contribution) in [(20.0, 300.0, 90.0, 10.0, 9.18, 75.0, 115.82),
                                                                         (50.0, 750.0, 225.0, 25.0, 22.95, 75.0, 402.05),
                                                                         (80.0, 1200.0, 360.0, 40.0, 36.72, 120.0, 643.28)]:
        cid = c.add("g15", "stop_money_at", {"profile": P, "terms": fee, "orders": orders})
        for (line, want) in [("sales", sales), ("food_cost", food), ("packaging", packaging), ("card_fees", card),
                             ("spot_fee", spot_fee), ("contribution", contribution)]:
            c.doc(cid, line, want, 2)
    c.add("g15", "stop_money_at", {"profile": _profile(tips_include=True, card_share=1.0), "terms": _terms(fee_flat=40.0), "orders": 33.5})
    c.doc(c.add("g15", "unit_margins", {"profile": P, "terms": fx.terms_open}), "at_minimum", 9.541, 3)
    c.doc(c.add("g15", "unit_margins", {"profile": P, "terms": fee}), "at_percentage", 8.041, 3)
    c.add("g15", "unit_margins", {"profile": _profile(tips_include=True), "terms": fee})
    c.add("g15", "unit_margins", {"profile": lossy, "terms": fx.terms_open})
    flat = _terms(fee_flat=100.0)
    low_min = _terms(fee_pct=0.1, fee_min=30.0)
    for (fixed_costs, none, pct75, flat100, pct30) in [(200.0, 20.9622, 28.823, 31.4432, 24.8725),
                                                        (400.0, 41.9243, 49.7851, 52.4054, 49.7451),
                                                        (0.0, 0.0, 7.8608, 10.4811, 3.1443)]:
        for (terms, want) in [(fx.terms_open, none), (fee, pct75), (flat, flat100), (low_min, pct30)]:
            c.doc(c.add("g15", "break_even_orders", {"profile": P, "terms": terms, "fixed_costs": fixed_costs}), "", want, 4)
    c.doc(c.add("g15", "break_even_orders", {"profile": lossy, "terms": fx.terms_open, "fixed_costs": 200.0}), "", None)
    c.doc(c.add("g15", "break_even_orders", {"profile": P, "terms": _terms(fee_pct=0.7, fee_min=10.0), "fixed_costs": 500.0}), "", None)
    c.add("g15", "break_even_orders", {"profile": P, "terms": fx.terms_open, "fixed_costs": -50.0})       # never below zero
    c.add("g15", "break_even_orders", {"profile": P, "terms": _terms(fee_flat=50.0, fee_pct=0.05, fee_min=100.0), "fixed_costs": 240.74})
    c.add("g15", "break_even_orders", {"profile": _profile(tips_include=True), "terms": fee, "fixed_costs": 229.99})

    stops = [_stop("office", 660, 840), _stop("taproom", 1020, 1200)]
    two = build_timeline(fx.A, P, fx.thu, stops, fx.legs)
    one = build_timeline(fx.A, P, fx.thu, stops[:1], fx.legs)
    cid = c.add("g15", "day_costs", {"profile": P, "timeline": two, "fuel_price_per_gal": FUEL})
    for (path, want, decimals) in [("labour", 446.82, 2), ("fuel", 23.91, 2), ("drive_gallons", 1.1, 3), ("generator_gallons", 4.6, 3)]:
        c.doc(cid, path, want, decimals)
    cid = c.add("g15", "day_costs", {"profile": P, "timeline": one, "fuel_price_per_gal": FUEL})
    for (path, want, decimals) in [("labour", 215.82, 2), ("fuel", 14.17, 2), ("drive_gallons", 1.078, 3),
                                   ("generator_gallons", 2.3, 3), ("total", 229.99, 2)]:
        c.doc(cid, path, want, decimals)
    c.add("g15", "day_costs", {"profile": _profile(paid_crew=0), "timeline": two, "fuel_price_per_gal": FUEL})
    c.add("g15", "day_costs", {"profile": _profile(generator_gal_per_hour=0.0, fixed_cost_per_service_day=85.0, mpg=7.5),
                               "timeline": two, "fuel_price_per_gal": 5.953})
    c.add("g15", "day_costs", {"profile": _profile(fixed_cost_per_service_day=85.0),
                               "timeline": build_timeline(fx.A, P, fx.thu, [], fx.legs), "fuel_price_per_gal": FUEL})
    tolled = dict(fx.legs)
    tolled["base>office"] = _fixed_leg(11, 4.85, 3.5)
    tolled["office>taproom"] = _fixed_leg(10, 4.85, 1.25)
    unpaid = [stops[0], dict(stops[1], gap_before_unpaid=True)]
    c.add("g15", "day_costs", {"profile": P, "timeline": build_timeline(fx.A, P, fx.thu, unpaid, tolled), "fuel_price_per_gal": FUEL})
    # A unit margin of exactly zero never breaks even: at the minimum fee, and once the percentage is paid.
    even = _profile(avg_ticket=10.0, food_cost_pct=0.5, card_share=0.0, packaging_per_order=5.0)
    c.known(c.add("g15", "unit_margins", {"profile": even, "terms": fx.terms_open}), "at_minimum", 0.0)
    c.known(c.add("g15", "break_even_orders", {"profile": even, "terms": fx.terms_open, "fixed_costs": 100.0}), "", None)
    half = _profile(avg_ticket=10.0, food_cost_pct=0.0, card_share=0.0, packaging_per_order=5.0)
    half_terms = _terms(fee_flat=10.0, fee_pct=0.5)
    cid = c.add("g15", "unit_margins", {"profile": half, "terms": half_terms})
    c.known(cid, "at_minimum", 5.0)
    c.known(cid, "at_percentage", 0.0)
    c.known(c.add("g15", "break_even_orders", {"profile": half, "terms": half_terms, "fixed_costs": 100.0}), "", None)
    # The minimum fee is still what is paid exactly where flat + percentage reaches it (25 orders of $10 at 20 %).
    edge_terms = _terms(fee_pct=0.2, fee_min=50.0)
    c.known(c.add("g15", "break_even_orders", {"profile": half, "terms": edge_terms, "fixed_costs": 75.0}), "", 25.0)
    c.known(c.add("g15", "break_even_orders", {"profile": half, "terms": edge_terms, "fixed_costs": 78.0}), "", 26.0)   # one order on: 78 / (5 - 2)
    c.known(c.add("g15", "break_even_orders", {"profile": half, "terms": _terms(fee_flat=30.0), "fixed_costs": -100.0}), "", 0.0)   # never below zero, with a flat fee too
    for (orders, fee_paid) in [(0.0, 50.0), (25.0, 50.0), (25.5, 51.0), (0, 50.0)]:
        cid = c.add("g15", "stop_money_at", {"profile": half, "terms": edge_terms, "orders": orders})
        c.known(cid, "spot_fee", fee_paid)
        c.known(cid, "contribution", orders * 10.0 - orders * 5.0 - fee_paid)
    cid = c.add("g15", "stop_money_at", {"profile": _profile(card_share=0.0, tips_include=True), "terms": fx.terms_open, "orders": 10.0})
    c.known(cid, "card_fees", 0.0)                                              # nobody pays by card: no card fees and no tips
    c.known(cid, "tips", 0.0)
    c.add("g15", "stop_money_at", {"profile": _profile(avg_ticket=15, packaging_per_order=1, card_share=1, card_fee_fixed=0, food_cost_pct=0),
                                   "terms": _terms(fee_flat=50, fee_pct=0, fee_min=75), "orders": 12})       # whole numbers throughout
    # Orders that fall as the range rises: low above high before est_levels, in every line, with a fee.
    c.add("g15", "stop_money", {"profile": lossy, "terms": _terms(fee_pct=0.1, fee_min=20.0), "orders": {"value": 30.0, "low": 12.0, "high": 55.0, "confidence": "fair"}})
    c.add("g15", "stop_money", {"profile": P, "terms": fee, "orders": {"value": 10, "low": 10, "high": 10, "confidence": "fixed"}})
    # Day costs: a fuel price of zero and a whole-number price; nothing paid for an empty day but the fuel line still multiplies.
    cid = c.add("g15", "day_costs", {"profile": P, "timeline": two, "fuel_price_per_gal": 0})
    c.known(cid, "fuel", 0.0)
    c.add("g15", "day_costs", {"profile": P, "timeline": two, "fuel_price_per_gal": 5})
    cid = c.add("g15", "day_costs", {"profile": _profile(fixed_cost_per_service_day=85.0, paid_crew=3), "timeline": build_timeline(fx.A, P, fx.thu, [], fx.legs),
                                     "fuel_price_per_gal": 0.0})
    c.known(cid, "total", 0.0)


def _g16_driving(c, fx):
    A = fx.A
    P = fx.profile
    thanksgiving = day_context(A, "2026-11-26", None, None, None, None)
    none = _a(None, REGION_NONE)
    thu_none = day_context(_assume(none), "2026-10-08", None, None, None, None)

    def fallback(lat1, lng1, lat2, lng2):
        return c.add("g16", "fallback_leg", {"A": _a(), "lat1": lat1, "lng1": lng1, "lat2": lat2, "lng2": lng2})

    cid = fallback(39.003, -77.405, 38.96, -77.36)
    c.doc(cid, "distance_m", 8012.82, 2)
    c.doc(cid, "duration_s", 526.315, 3)
    fallback(38.96, -77.36, 39.003, -77.405)                                    # the way back: the same leg
    fallback(39.0, -77.4, 39.0005, -77.4)                                       # a 55.6 m hop
    fallback(39.0, -77.4, 39.0, -77.4)                                          # the same point
    fallback(39.003, -77.405, 38.9072, -77.0369)                                # Sterling to downtown Washington

    def factor(ctx, minute, a=None):
        return c.add("g16", "traffic_factor", {"A": _a() if a is None else a, "ctx": ctx, "minute": minute})

    for (ctx, minute, want) in [(fx.thu, 619, [1.31, 3, 10]), (fx.thu, 860, [1.44, 3, 14]), (fx.thu, -30, [1.12, 2, 23]),
                                (fx.thu, 1470, [1.09, 4, 0]), (thanksgiving, 619, [1.18, 6, 10]),
                                (thanksgiving, 1470, [1.09, 4, 0])]:
        c.doc(factor(ctx, minute), "", want)
    factor(fx.thu, 0)
    factor(fx.thu, 1439)
    factor(fx.sun, 1440)                                                        # Sunday midnight: Monday 00:00
    factor(fx.ctx["2026-10-05"], -1)                                            # Monday minus a minute: Sunday 23:00
    factor(fx.thu, 2900)                                                        # two days on
    factor(thu_none, 1030, none)                                                # the national matrix
    factor(day_context(A, "2026-10-08", "sat", None, None, None), 720)          # treat this day as a Saturday

    leg = fallback_leg(A, 39.003, -77.405, 38.96, -77.36)
    google = {"source": "google", "distance_m": 7805.0, "duration_s": 600.0, "override_minutes": None, "toll": 0.0}

    def minutes(leg_input, ctx, lookup_minute, profile=None, a=None):
        return c.add("g16", "leg_minutes", {"A": _a() if a is None else a, "profile": P if profile is None else profile,
                                            "leg": leg_input, "ctx": ctx, "lookup_minute": lookup_minute})

    for (ctx, lookup, dow, hour, factor_want, raw, want) in [(fx.thu, 619, 3, 10, 1.31, 12.6403, 13), (fx.thu, 860, 3, 14, 1.44, 13.8947, 14),
                                                              (fx.thu, -30, 2, 23, 1.12, 10.807, 11), (fx.thu, 1470, 4, 0, 1.09, 10.5175, 11),
                                                              (thanksgiving, 619, 6, 10, 1.18, 11.3859, 11),
                                                              (thanksgiving, 1470, 4, 0, 1.09, 10.5175, 11)]:
        cid = minutes(leg, ctx, lookup)
        for (path, value, decimals) in [("traffic_dow", dow, None), ("traffic_hour", hour, None), ("traffic_factor", factor_want, None),
                                        ("raw_minutes", raw, 4), ("minutes", want, None), ("source", "fallback", None),
                                        ("base_minutes", 8.7719, 4), ("miles", 4.9789, 4)]:
            c.doc(cid, path, value, decimals)
    for (lookup, factor_want, time_factor, raw, want) in [(619, 1.31, 1.035573, 11.3913, 11), (1030, 1.72, 1.359684, 14.9565, 15),
                                                           (180, 1.04, 0.822134, 9.0435, 9)]:
        cid = minutes(google, fx.thu, lookup)
        for (path, value, decimals) in [("traffic_factor", factor_want, None), ("time_factor", time_factor, 6),
                                        ("raw_minutes", raw, 4), ("minutes", want, None), ("miles", 4.8498, 4)]:
            c.doc(cid, path, value, decimals)
    hop = fallback_leg(A, 39.0, -77.4, 39.0005, -77.4)
    cid = minutes(hop, fx.thu, 619)
    c.doc(cid, "base_minutes", 0.1078, 4)
    c.doc(cid, "minutes", 1)                                                    # rounds to 0, raised to 1
    c.doc(minutes(fallback_leg(A, 39.0, -77.4, 39.0, -77.4), fx.thu, 619), "minutes", 0)
    cid = minutes(dict(google, override_minutes=14, toll=4.5), fx.thu, 1030)
    c.doc(cid, "minutes", 14)
    c.doc(cid, "source", "override")
    minutes(dict(google, override_minutes=0), fx.thu, 619)                      # an override of zero is still an override
    minutes(google, thu_none, 1030, None, none)                                 # the national matrix and its typical value
    minutes(leg, thu_none, 1030, None, none)
    minutes(google, fx.thu, 1030, _profile(truck_time_factor=1.0))
    minutes(dict(google, toll=6.25), fx.sat, 780)
    # Whole days before and after the service date: the real day of the week, also through a week's end
    # and under a holiday's traffic row. Minute 10080 is the same weekday a week later.
    mon = fx.ctx["2026-10-05"]
    for (ctx, minute, want) in [(mon, -1440, [None, 6, 0]), (mon, -1441, [None, 5, 23]), (mon, -2881, [None, 4, 23]),
                                (fx.sun, 2879, [None, 0, 23]), (fx.sun, 2880, [None, 1, 0]), (fx.thu, 10080, [None, 3, 0]),
                                (fx.thu, -10080, [None, 3, 0]), (thanksgiving, 0, [None, 6, 0]), (thanksgiving, 1439, [None, 6, 23]),
                                (thanksgiving, 1440, [None, 4, 0]), (thanksgiving, -1, [None, 2, 23]), (thanksgiving, 10080, [None, 3, 0]),
                                (fx.thu, 59, [None, 3, 0]), (fx.thu, 60, [None, 3, 1])]:
        cid = factor(ctx, minute)
        c.known(cid, "1", want[1])
        c.known(cid, "2", want[2])
    factor(typical_context(A, 6), 1440)                                         # a typical Sunday runs into Monday
    # Halves round away from zero. Monday 10:00 in the dc matrix is exactly 1.25, so with a truck factor
    # of 1 a free-flow leg of 120 s is 2.5 minutes and one of 360 s is 7.5.
    plain = _profile(truck_time_factor=1.0)
    for (seconds, raw, want) in [(120.0, 2.5, 3), (360.0, 7.5, 8), (24.0, 0.5, 1), (72.0, 1.5, 2), (119.0, 2.4791666666666665, 2)]:
        cid = minutes({"source": "fallback", "distance_m": 900.0, "duration_s": seconds, "override_minutes": None, "toll": 0.0}, mon, 600, plain)
        c.known(cid, "traffic_factor", 1.25)
        c.known(cid, "raw_minutes", raw, 9)
        c.known(cid, "minutes", want)
    cid = minutes({"source": "fallback", "distance_m": 0.0, "duration_s": 10.0, "override_minutes": None, "toll": 0.0}, mon, 600, plain)
    c.known(cid, "minutes", 0)                                                  # under half a minute and no distance: 0, not raised to 1
    cid = minutes({"source": "fallback", "distance_m": 0.5, "duration_s": 0.0, "override_minutes": None, "toll": 0.0}, mon, 600, plain)
    c.known(cid, "minutes", 1)                                                  # any real distance takes a minute
    cid = minutes({"source": "fallback", "distance_m": 4000.0, "duration_s": 300.0, "override_minutes": 7, "toll": 1.5}, fx.thu, 1030)
    c.known(cid, "source", "override")                                          # an override on a fallback leg
    c.known(cid, "minutes", 7)
    minutes({"source": "google", "distance_m": 7805, "duration_s": 600, "override_minutes": None, "toll": 2}, fx.thu, 619)     # whole numbers
    minutes(google, typical_context(A, 0), 600)                                 # a typical context
    fallback(39, -77, 39, -77)                                                  # whole-number coordinates
    cid = fallback(0.0, 0.0, 0.0, 0.02)                                         # 2.2 km of equator: under the two local miles after the detour
    c.calc(cid, "all local", lambda r: r["distance_m"] / 1609.344 < 2.0, True)
    cid = fallback(0.0, 0.0, 0.0, 0.03)                                         # 3.3 km: 2 local miles and a trunk part
    c.calc(cid, "local and trunk", lambda r: r["distance_m"] / 1609.344 > 2.0, True)


def _g17_timeline(c, fx):
    P = fx.profile

    def timeline(stops, legs, ctx=None, profile=None):
        return c.add("g17", "build_timeline", {"A": _a(), "profile": P if profile is None else profile,
                                               "ctx": fx.thu if ctx is None else ctx, "stops": stops, "legs": legs})

    office = _stop("office", 660, 840)
    taproom = _stop("taproom", 1020, 1200, point={"lat": 39.0035, "lng": -77.4035})
    cid = timeline([office, taproom], fx.legs)                                  # the blueprint day sheet
    sheet = [("start_prep", 574, None), ("leave_base", 619, None), ("arrive", 630, 0), ("setup_start", 630, 0),
             ("open", 660, 0), ("close", 840, 0), ("leave", 860, 0), ("arrive", 870, 1), ("setup_start", 990, 1),
             ("open", 1020, 1), ("close", 1200, 1), ("leave", 1220, 1), ("back_at_base", 1221, None), ("done", 1251, None)]
    for k in range(len(sheet)):
        c.doc(cid, "events.%d.kind" % k, sheet[k][0])
        c.doc(cid, "events.%d.minute" % k, sheet[k][1])
        c.doc(cid, "events.%d.stop_index" % k, sheet[k][2])
    for (path, want, decimals) in [("day_minutes", 677, None), ("paid_minutes", 677, None), ("unpaid_gap_minutes", 0, None),
                                   ("drive_minutes", 22, None), ("service_minutes", 360, None), ("generator_minutes", 460, None),
                                   ("miles", 9.9, 2), ("stops.1.gap_before_minutes", 120, None)]:
        c.doc(cid, path, want, decimals)
    cid = timeline([office], fx.legs)                                           # lunch only
    for (path, want, decimals) in [("start_prep", 574, None), ("leave_base", 619, None), ("stops.0.arrive", 630, None),
                                   ("stops.0.leave", 860, None), ("back_at_base", 871, None), ("done", 901, None),
                                   ("day_minutes", 327, None), ("generator_minutes", 230, None), ("miles", 9.7, 2)]:
        c.doc(cid, path, want, decimals)
    cid = timeline([office, dict(taproom, open_minute=885, close_minute=1080)], fx.legs)        # late
    for (path, want) in [("stops.1.arrive", 870), ("stops.1.late_minutes", 15), ("stops.1.effective_open", 900),
                         ("stops.1.gap_before_minutes", 0)]:
        c.doc(cid, path, want)
    cid = timeline([office, dict(taproom, open_minute=840, close_minute=848)], fx.legs)         # unreachable
    for (path, want) in [("stops.1.arrive", 870), ("stops.1.late_minutes", 60), ("stops.1.effective_open", 848),
                         ("stops.1.leave", 870), ("events.7.minute", 870), ("events.8.minute", 870), ("events.9.minute", 870),
                         ("events.10.minute", 870), ("events.11.minute", 870), ("back_at_base", 871), ("done", 901),
                         ("day_minutes", 327), ("generator_minutes", 230), ("service_minutes", 180)]:
        c.doc(cid, path, want)
    timeline([office, dict(taproom, gap_before_unpaid=True)], fx.legs)          # the two-hour gap as an unpaid break
    timeline([dict(office, gap_before_unpaid=True), taproom], fx.legs)          # the flag means nothing on the first stop
    timeline([dict(office, open_minute=30, close_minute=210)], fx.legs)         # prep starts the evening before
    timeline([dict(office, setup_minutes=15, teardown_minutes=45), dict(taproom, setup_minutes=60, teardown_minutes=0)], fx.legs)
    timeline([], fx.legs)
    # Three stops and no routed legs: every leg is the straight-line fallback, at the hour it is driven.
    a = _stop("a", 420, 540, point={"lat": 38.96, "lng": -77.36})
    b = _stop("b", 690, 840, point={"lat": 38.99, "lng": -77.2})
    cc = _stop("c", 1020, 1200, point={"lat": 38.9072, "lng": -77.0369})
    timeline([a, b, cc], {})
    timeline([a, b, cc], {}, day_context(fx.A, "2026-11-26", None, None, None, None))    # a holiday's traffic
    # Routed legs with their own durations and tolls.
    routed = {"base>a": {"source": "google", "distance_m": 7805.0, "duration_s": 600.0, "override_minutes": None, "toll": 0.0},
              "a>b": {"source": "google", "distance_m": 15900.0, "duration_s": 1260.0, "override_minutes": None, "toll": 2.5},
              "b>base": {"source": "google", "distance_m": 21400.0, "duration_s": 1500.0, "override_minutes": 31, "toll": 4.75}}
    timeline([a, b], routed)
    timeline([dict(office, open_minute=1320, close_minute=1560)], fx.legs)      # a late-night stop ending after midnight
    timeline([office], fx.legs, None, _profile(prep_minutes=0, setup_minutes=0, teardown_minutes=0, closeout_minutes=0))
    for (ids, want) in [([], []), (["a"], ["base>a", "a>base"]),
                        (["office", "taproom"], ["base>office", "office>taproom", "taproom>base", "base>taproom", "office>base"]),
                        (["a", "b", "c"], ["base>a", "a>b", "b>c", "c>base", "base>b", "a>c", "b>base"])]:
        c.doc(c.add("g17", "required_leg_keys", {"stops": [_stop(i, 660, 840) for i in ids]}), "", want)

    # The ends of the day: a stop opening at minute 0 (the drive and the prep fall on the evening before)
    # and one closing at 2880.
    cid = timeline([dict(office, open_minute=0, close_minute=60)], fx.legs)
    for (path, want) in [("start_prep", -86), ("leave_base", -41), ("stops.0.arrive", -30), ("legs.0.traffic_lookup_minute", -30),
                         ("legs.0.depart_minute", -41), ("done", 121), ("day_minutes", 207)]:
        c.known(cid, path, want)
    cid = timeline([dict(office, open_minute=2700, close_minute=2880)], fx.legs)
    c.known(cid, "done", 2941)
    c.known(cid, "legs.1.traffic_lookup_minute", 2900)
    # Back to back: the second stop opens when the first closes, so the truck is late by its teardown, drive and setup.
    cid = timeline([office, dict(taproom, open_minute=840, close_minute=960)], fx.legs)
    for (path, want) in [("stops.1.arrive", 870), ("stops.1.late_minutes", 60), ("stops.1.effective_open", 900), ("stops.1.gap_before_minutes", 0),
                         ("service_minutes", 240)]:
        c.known(cid, path, want)
    # Three stops, the two later gaps unpaid; the flag on the first stop is ignored.
    third = _stop("third", 1380, 1440, point={"lat": 38.99, "lng": -77.39})
    legs3 = dict(fx.legs)
    legs3["taproom>third"] = _fixed_leg(5, 1.0, 0.75)
    legs3["third>base"] = _fixed_leg(6, 2.0, 1.25)
    cid = timeline([dict(office, gap_before_unpaid=True), dict(taproom, gap_before_unpaid=True), dict(third, gap_before_unpaid=True)], legs3)
    for (path, want) in [("unpaid_gap_minutes", 245), ("stops.0.gap_unpaid", False), ("stops.1.gap_unpaid", True), ("stops.2.gap_before_minutes", 125),
                         ("tolls", 2.0), ("drive_minutes", 32), ("paid_minutes", 677)]:
        c.known(cid, path, want)
    # A stop of no length and one that closes before it opens (day_plan refuses both; the timeline itself only
    # keeps time from running backwards).
    cid = timeline([dict(office, open_minute=700, close_minute=700)], fx.legs)
    c.known(cid, "service_minutes", 0)
    c.known(cid, "stops.0.effective_open", 700)
    cid = timeline([dict(office, open_minute=700, close_minute=650)], fx.legs)
    for (path, want) in [("stops.0.effective_open", 650), ("stops.0.leave", 670), ("service_minutes", 0), ("generator_minutes", 0),
                         ("events.4.minute", 670), ("events.5.minute", 670), ("events.6.minute", 670)]:
        c.known(cid, path, want)
    # The base and the stop at the same point with no routed leg: a fallback leg of no distance takes no time.
    cid = timeline([_stop("home", 660, 840, point={"lat": 39.003, "lng": -77.405})], {})
    for (path, want) in [("drive_minutes", 0), ("miles", 0.0), ("legs.0.source", "fallback"), ("leave_base", 630), ("start_prep", 585)]:
        c.known(cid, path, want)
    # Only one of the legs is routed; the others fall back.
    cid = timeline([office, taproom], {"base>office": _fixed_leg(11, 4.85)})
    c.calc(cid, "leg sources", lambda r: [leg["source"] for leg in r["legs"]], ["override", "fallback", "fallback"])
    # Google legs driven before midnight of the service date and after its end: the lookup minute decides.
    slow = {"source": "google", "distance_m": 30000.0, "duration_s": 3000.0, "override_minutes": None, "toll": 0.0}
    cid = timeline([dict(office, open_minute=20, close_minute=200)], {"base>office": slow, "office>base": slow})
    c.known(cid, "legs.0.traffic_lookup_minute", -65)                           # arrive -10, less floor(50 x 1.1)
    c.known(cid, "legs.0.traffic_dow", 2)
    cid = timeline([dict(office, open_minute=1380, close_minute=1500)], {"base>office": slow, "office>base": slow})
    c.known(cid, "legs.1.traffic_dow", 4)
    c.known(cid, "legs.1.traffic_lookup_minute", 1520)
    timeline([office, taproom], fx.legs, typical_context(fx.A, 1))              # a typical context
    c.known(c.add("g17", "required_leg_keys", {"stops": [_stop(i, 660, 840) for i in ["a", "b", "c", "d", "e"]]}), "",
            ["base>a", "a>b", "b>c", "c>d", "d>e", "e>base", "base>b", "a>c", "b>d", "c>e", "d>base"])


def _g18_day_plan(c, fx):
    A = fx.A
    P = fx.profile
    thu = fx.ctx_fuel["2026-10-08"]
    fri = fx.ctx_fuel["2026-10-09"]
    sat = fx.ctx_fuel["2026-10-10"]
    sun = fx.ctx_fuel["2026-10-11"]

    def plan(date, stops, ctx, ctx_next, legs, profile=None, cal=None, a=None):
        return c.add("g18", "day_plan", {"A": _a() if a is None else a, "profile": P if profile is None else profile,
                                         "plan": {"date": date, "stops": stops}, "ctx": ctx, "ctx_next": ctx_next,
                                         "legs": legs, "cal": cal})

    tap_point = {"lat": 39.0035, "lng": -77.4035}
    office = _stop("office", 660, 840, fx.terms_open, fx.vec)
    taproom = _stop("taproom", 1020, 1200, fx.terms_tap, fx.zero, point=tap_point)

    # 1. The worked day of 4.12: lunch at the office park, then the taproom.
    cid = plan("2026-10-08", [office, taproom], thu, fri, fx.legs)
    for (path, want, decimals) in [
            ("stops.0.orders.value", 60.49, 2), ("stops.0.orders.low", 33.01, 2), ("stops.0.orders.high", 93.19, 2),
            ("stops.1.orders.value", 39.38, 2), ("stops.1.orders.low", 20.76, 2), ("stops.1.orders.high", 62.2, 2),
            ("totals.sales.value", 1498.17, 2), ("totals.day_hours", 11.28, 2), ("totals.miles", 9.9, 1),
            ("totals.drive_gallons", 1.1, 3), ("totals.generator_gallons", 4.6, 3), ("totals.fuel.value", 23.91, 2),
            ("totals.labour.value", 446.82, 2), ("totals.take_home.value", 482.2, 2), ("totals.take_home.low", 42.35, 2),
            ("totals.take_home.high", 1011.86, 2), ("totals.take_home_per_hour.value", 42.74, 2),
            ("stops.1.adds.take_home.value", 135.02, 2), ("stops.1.adds.take_home.low", -42.64, 2),
            ("stops.1.adds.take_home.high", 352.69, 2), ("stops.1.adds.hours", 5.8333, 4),
            ("stops.1.adds.per_hour.value", 23.15, 2), ("stops.1.adds.per_hour.low", -7.31, 2),
            ("stops.1.adds.per_hour.high", 60.46, 2), ("stops.1.adds.added_costs", 240.74, 2),
            ("stops.1.adds.break_even_orders", 25.232, 3), ("stops.0.adds.take_home.value", 318.9, 2),
            ("stops.0.adds.take_home.low", 56.71, 2), ("stops.0.adds.take_home.high", 630.89, 2),
            ("unpaid_gap_alternative.take_home.value", 561.4, 2), ("unpaid_gap_alternative.take_home.low", 121.55, 2),
            ("unpaid_gap_alternative.take_home.high", 1091.06, 2), ("unpaid_gap_alternative.work_hours", 9.2833, 4),
            ("unpaid_gap_alternative.take_home_per_hour.value", 60.47, 2), ("unpaid_gap_alternative.labour_saved", 79.2, 2),
            ("timeline.done", 1251, None), ("timeline.start_prep", 574, None)]:
        c.doc(cid, path, want, decimals)
    # 2. Lunch only: the classic break-even of a one-stop day.
    cid = plan("2026-10-08", [office], thu, fri, fx.legs)
    for (path, want, decimals) in [("totals.sales.value", 907.41, 2), ("totals.day_hours", 5.45, 2), ("totals.miles", 9.7, 1),
                                   ("totals.drive_gallons", 1.078, 3), ("totals.generator_gallons", 2.3, 3),
                                   ("totals.fuel.value", 14.17, 2), ("totals.labour.value", 215.82, 2),
                                   ("totals.take_home.value", 347.18, 2), ("totals.take_home.low", 85.0, 2),
                                   ("totals.take_home.high", 659.18, 2), ("totals.take_home_per_hour.value", 63.7, 2),
                                   ("stops.0.adds.break_even_orders", 24.105, 3), ("stops.0.adds.added_costs", 229.99, 2),
                                   ("unpaid_gap_alternative", None, None)]:
        c.doc(cid, path, want, decimals)
    # 3. The same day on a Friday, and with the gap already marked unpaid.
    office_fri = _stop("office", 660, 840, fx.terms_open, fx.vec)
    c.doc(plan("2026-10-09", [office_fri, taproom], fri, sat, fx.legs), "stops.1.orders.value", 59.08, 2)
    plan("2026-10-08", [office, dict(taproom, gap_before_unpaid=True)], thu, fri, fx.legs)
    # 4. Calibrated: the two spots are spots A and B of the log in 4.13.
    plan("2026-10-08", [_stop("office", 660, 840, _terms(spot_id="A"), fx.vec, spot_id="A"),
                        _stop("taproom", 1020, 1200, _terms(spot_id="B", host=fx.tap_host), fx.zero, spot_id="B", point=tap_point)],
         thu, fri, fx.legs, None, fx.cal)
    # 5. An event with a thin crowd and a high fee (Saturday), then one that fills the truck.
    thin = _stop("fair", 660, 900, _terms(fee_pct=0.12, fee_min=75.0), None, "event",
                 event={"attendance": 1500.0, "vendors": 6, "event_type": "general"})
    plan("2026-10-10", [thin], sat, sun, {"base>fair": _fixed_leg(25, 14.0, 2.0), "fair>base": _fixed_leg(27, 14.0, 2.0)})
    packed = _stop("festival", 690, 870, _terms(fee_flat=250.0), None, "event",
                   event={"attendance": 12000.0, "vendors": 8, "event_type": "food_focused"})
    plan("2026-10-10", [packed, dict(taproom, open_minute=1020, close_minute=1200)], sat, sun,
         {"base>festival": _fixed_leg(25, 14.0), "festival>taproom": _fixed_leg(24, 13.5), "taproom>base": _fixed_leg(1, 0.2),
          "base>taproom": _fixed_leg(1, 0.2), "festival>base": _fixed_leg(25, 14.0)})
    # 6. Catering at lunch (contracted, every line fixed), then the taproom after an unpaid break.
    lunch = _stop("wedding", 690, 810, None, None, "catering",
                  catering={"headcount": 80.0, "price_per_head": 14.0, "guarantee": 1000.0, "food_cost": None})
    plan("2026-10-08", [lunch, dict(taproom, gap_before_unpaid=True)], thu, fri,
         {"base>wedding": _fixed_leg(18, 9.5), "wedding>taproom": _fixed_leg(17, 9.2), "taproom>base": _fixed_leg(1, 0.2),
          "base>taproom": _fixed_leg(1, 0.2), "wedding>base": _fixed_leg(18, 9.5)})
    plan("2026-10-08", [lunch], thu, fri, {"base>wedding": _fixed_leg(18, 9.5), "wedding>base": _fixed_leg(18, 9.5)})
    # 7. Not evaluated: an impossible window and overlapping stops.
    plan("2026-10-08", [office, dict(taproom, open_minute=800, close_minute=900),
                        _stop("oops", 1000, 1000, fx.terms_open, fx.vec), _stop("late", 2700, 2900, fx.terms_open, fx.vec)],
         thu, fri, fx.legs)
    # 8. Stale vectors, outside the region, outside the hours the host allows.
    outside = dict(fx.vec)
    outside["in_region"] = False
    weekdays_noon = {"days": [True, True, True, False, True, False, False], "open_minute": 720, "close_minute": 780}
    plan("2026-10-08", [_stop("office", 660, 840, _terms(visibility="prominent", allowed=weekdays_noon), outside)], thu, fri, fx.legs)
    plan("2026-10-08", [_stop("office", 660, 840, _terms(allowed={"days": [True] * 7, "open_minute": 600, "close_minute": 900}), fx.vec),
                        _stop("taproom", 1020, 1200, _terms(host=_host("v_nightlife", 120.0, point_id="pw1")), fx.zero, point=tap_point)],
         thu, fri, fx.legs)
    # 9. No routed legs at all: a late arrival, a stop the truck cannot reach, straight-line drive times.
    far = _stop("far", 885, 1080, fx.terms_open, fx.vec, point={"lat": 38.99, "lng": -77.2})
    gone = _stop("gone", 1080, 1090, fx.terms_tap, fx.zero, point={"lat": 38.9072, "lng": -77.0369})
    plan("2026-10-08", [office, far, gone], thu, fri, {})
    # 10. A long day: prep before 05:00, a long paid gap, service past midnight (Friday into Saturday).
    early = _stop("office", 330, 510, fx.terms_open, fx.vec)
    night = _stop("taproom", 1260, 1500, fx.terms_tap, fx.zero, point=tap_point)
    plan("2026-10-09", [early, night], fri, sat, fx.legs)
    # 11. A fee that eats the sales, on a day the spot is nearly empty (Saturday at the office park).
    plan("2026-10-10", [_stop("office", 660, 840, _terms(fee_flat=60.0), fx.vec)], sat, sun, fx.legs)
    # 12. A full truck on a default host size.
    big = _terms(host=_host("v_nightlife", 400.0, "default", place_type="taproom"))
    plan("2026-10-10", [_stop("taproom", 1020, 1200, big, fx.zero, point=tap_point)], sat, sun, fx.legs)
    # 13. Demand that rests on a weak seed (a campus).
    plan("2026-10-08", [_stop("campus", 660, 840, fx.terms_open, fx.vec_campus)], thu, fri,
         {"base>campus": _fixed_leg(11, 4.85), "campus>base": _fixed_leg(11, 4.85)})
    # 14. Thanksgiving with a forecast that stops after 13:00; a day the owner treats as a holiday.
    partial = _forecast([_fc(11, 44, 20, "Mostly Cloudy", 12), _fc(12, 46, 20, "Mostly Cloudy", 14)])
    holiday = day_context(A, "2026-11-26", None, partial, FUEL, "seed")
    after = day_context(A, "2026-11-27", None, None, FUEL, "seed")
    plan("2026-11-26", [office, taproom], holiday, after, fx.legs)
    treated = day_context(A, "2026-10-08", "holiday", fx.rain, 4.411, "owner")
    plan("2026-10-08", [office], treated, fri, fx.legs)
    # 15. An empty plan; a typical day (no date-specific weather, so no forecast warning).
    plan("2026-10-08", [], thu, fri, fx.legs)
    typical = typical_context(A, 3)
    typical["fuel_price_per_gal"] = FUEL
    typical["fuel_price_source"] = "seed"
    plan("2026-10-08", [office, taproom], typical, typical_context(A, 4), fx.legs)

    def codes(cid, present, absent):
        """Which warnings the case is there for: codes it must raise and codes it must not."""
        for code in present:
            c.calc(cid, "raises " + code, lambda r, code=code: code in [w["code"] for w in r["warnings"]], True)
        for code in absent:
            c.calc(cid, "does not raise " + code, lambda r, code=code: code in [w["code"] for w in r["warnings"]], False)

    # 16. outside_allowed_hours, one condition at a time: the day, the opening minute, the closing minute; and a window that just fits.
    for (allowed, raised) in [({"days": [True, True, True, False, True, True, True], "open_minute": 0, "close_minute": 2880}, True),
                              ({"days": [True] * 7, "open_minute": 661, "close_minute": 2880}, True),
                              ({"days": [True] * 7, "open_minute": 0, "close_minute": 839}, True),
                              ({"days": [False, False, False, True, False, False, False], "open_minute": 660, "close_minute": 840}, False)]:
        cid = plan("2026-10-08", [_stop("office", 660, 840, _terms(allowed=allowed), fx.vec)], thu, fri, fx.legs)
        codes(cid, ["outside_allowed_hours"] if raised else [], [] if raised else ["outside_allowed_hours"])
    # 17. invalid_window, one condition at a time; stops that touch do not overlap.
    cid = plan("2026-10-08", [dict(office, open_minute=-1, close_minute=60)], thu, fri, fx.legs)
    c.known(cid, "warnings.0.code", "invalid_window")
    c.known(cid, "warnings.0.data.open_minute", -1)
    c.known(cid, "stops", [])
    cid = plan("2026-10-08", [dict(office, open_minute=2700, close_minute=2881)], thu, fri, fx.legs)
    c.known(cid, "warnings.0.data.close_minute", 2881)
    cid = plan("2026-10-08", [dict(office, open_minute=0, close_minute=60), dict(taproom, open_minute=2700, close_minute=2880)], thu, fri, fx.legs)
    codes(cid, ["early_start", "ends_after_midnight", "long_day", "long_gap"], ["invalid_window", "stops_overlap"])     # 0 and 2880 are valid
    cid = plan("2026-10-08", [office, dict(taproom, open_minute=840, close_minute=1000)], thu, fri, fx.legs)
    codes(cid, ["late_arrival"], ["stops_overlap", "stop_unreachable"])
    cid = plan("2026-10-08", [office, dict(taproom, open_minute=839, close_minute=1000)], thu, fri, fx.legs)
    c.known(cid, "warnings.0.code", "stops_overlap")
    c.known(cid, "warnings.0.data.previous_close_minute", 840)
    # 18. Each threshold from both sides: prep starting at 05:00 and at 04:59, a day of 720 and of 721 minutes,
    #     done at 24:00 and at 24:01, a paid gap of 90 and of 89 minutes.
    for (stops, code, raised) in [([dict(office, open_minute=386, close_minute=566)], "early_start", False),
                                  ([dict(office, open_minute=385, close_minute=565)], "early_start", True),
                                  ([dict(office, open_minute=660, close_minute=1233)], "long_day", False),
                                  ([dict(office, open_minute=660, close_minute=1234)], "long_day", True),
                                  ([dict(office, open_minute=1200, close_minute=1379)], "ends_after_midnight", False),
                                  ([dict(office, open_minute=1200, close_minute=1380)], "ends_after_midnight", True),
                                  ([office, dict(taproom, open_minute=990, close_minute=1170)], "long_gap", True),
                                  ([office, dict(taproom, open_minute=989, close_minute=1169)], "long_gap", False)]:
        cid = plan("2026-10-08", stops, thu, fri, fx.legs)
        codes(cid, [code] if raised else [], [] if raised else [code])
    # 19. A fee of exactly a tenth of sales is not "high"; a little more is. 200 attendees per vendor is not thin; fewer is.
    codes(plan("2026-10-08", [_stop("office", 660, 840, _terms(fee_pct=0.1), fx.vec)], thu, fri, fx.legs), [], ["fee_high"])
    codes(plan("2026-10-08", [_stop("office", 660, 840, _terms(fee_pct=0.11), fx.vec)], thu, fri, fx.legs), ["fee_high"], [])
    fair = _stop("fair", 660, 900, _terms(), None, "event", event={"attendance": 2000.0, "vendors": 6, "event_type": "general"})
    fair_legs = {"base>fair": _fixed_leg(25, 14.0), "fair>base": _fixed_leg(25, 14.0)}
    codes(plan("2026-10-10", [fair], sat, sun, fair_legs), [], ["event_thin_crowd"])
    cid = plan("2026-10-10", [dict(fair, event={"attendance": 1999.0, "vendors": 6, "event_type": "general"})], sat, sun, fair_legs)
    codes(cid, ["event_thin_crowd"], [])
    cid = plan("2026-10-10", [dict(fair, event={"attendance": 150.0, "vendors": 0, "event_type": "incidental"})], sat, sun, fair_legs)
    c.calc(cid, "attendees per vendor with no vendor count", lambda r: [w["data"]["attendees_per_vendor"] for w in r["warnings"] if w["code"] == "event_thin_crowd"], [90.0])
    # 20. Hours without a forecast are counted in the context of their own date: a typical next day has none
    #     to miss, and a typical service day raises no forecast warning at all.
    night = _stop("taproom", 1320, 1560, fx.terms_tap, fx.zero, point=tap_point)
    cid = plan("2026-10-09", [night], fri, typical_context(A, 5), fx.legs)
    c.calc(cid, "missing forecast hours", lambda r: [w["data"]["hours"] for w in r["warnings"] if w["code"] == "no_forecast"], [2])
    cid = plan("2026-10-09", [night], fri, sat, fx.legs)
    c.calc(cid, "missing forecast hours", lambda r: [w["data"]["hours"] for w in r["warnings"] if w["code"] == "no_forecast"], [4])
    typical_fri = typical_context(A, 4)
    typical_fri["fuel_price_per_gal"] = FUEL
    typical_fri["fuel_price_source"] = "seed"
    codes(plan("2026-10-09", [night], typical_fri, sat, fx.legs), ["ends_after_midnight"], ["no_forecast", "holiday"])
    full = day_context(A, "2026-10-09", None, _forecast([_fc(h, 60, 10, "Clear", 3) for h in range(24)]), FUEL, "seed")
    some = day_context(A, "2026-10-10", None, _forecast([_fc(0, 55, 20, "Cloudy", 4)]), FUEL, "seed")
    cid = plan("2026-10-09", [night], full, some, fx.legs)                      # only 01:00 on Saturday has no record
    c.calc(cid, "missing forecast hours", lambda r: [w["data"]["hours"] for w in r["warnings"] if w["code"] == "no_forecast"], [1])
    # 21. A default host size that supplies no orders (nobody is in a taproom at 07:00) raises no default_host_size.
    cid = plan("2026-10-08", [_stop("office", 420, 480, _terms(host=_host("v_nightlife", 40.0, "default", place_type="taproom")), fx.vec)], thu, fri, fx.legs)
    codes(cid, [], ["default_host_size", "stale_vectors"])
    c.known(cid, "stops.0.window.host_orders", 0.0)
    # 22. Spot ids made of digits are still keys of the log, not numbers.
    digits_cal = dict(fx.cal, spots={"12": dict(fx.cal["spots"]["A"]), "0": dict(fx.cal["spots"]["B"])})
    cid = plan("2026-10-08", [_stop("s12", 660, 840, _terms(spot_id="12"), fx.vec, spot_id="12"),
                              _stop("s0", 1020, 1200, _terms(spot_id="0", host=fx.tap_host), fx.zero, spot_id="0", point=tap_point),
                              _stop("s012", 1260, 1320, _terms(spot_id="012", host=fx.tap_host), fx.zero, spot_id="012", point=tap_point)],
               thu, fri, {"base>s12": _fixed_leg(11, 4.85), "s12>s0": _fixed_leg(10, 4.85), "s0>s012": _fixed_leg(0, 0.0), "s012>base": _fixed_leg(1, 0.2),
                          "base>s0": _fixed_leg(1, 0.2), "s12>s012": _fixed_leg(10, 4.85), "s0>base": _fixed_leg(1, 0.2), "base>s012": _fixed_leg(1, 0.2)}, None, digits_cal)
    c.known(cid, "stops.0.window.hours.0.result.factors.spot_factor", fx.cal["spots"]["A"]["factor"])
    c.known(cid, "stops.1.window.hours.0.result.factors.spot_factor", fx.cal["spots"]["B"]["factor"])
    c.known(cid, "stops.2.window.hours.0.result.factors.spot_factor", 1.0)
    # 23. All three kinds of stop in one day, the later two after unpaid breaks, tolls on two legs.
    plan("2026-10-10", [_stop("market", 480, 600, _terms(fee_flat=40.0), None, "event", event={"attendance": 900.0, "vendors": 3, "event_type": "food_focused"}),
                        _stop("lunch", 690, 780, None, None, "catering", gap_before_unpaid=True,
                              catering={"headcount": 60.0, "price_per_head": 18.0, "guarantee": 900.0, "food_cost": 250.0}),
                        dict(taproom, gap_before_unpaid=True)], sat, sun,
         {"base>market": _fixed_leg(12, 5.0, 1.5), "market>lunch": _fixed_leg(15, 6.0), "lunch>taproom": _fixed_leg(20, 9.0, 2.25), "taproom>base": _fixed_leg(1, 0.2),
          "base>lunch": _fixed_leg(14, 6.5), "market>taproom": _fixed_leg(13, 5.5), "lunch>base": _fixed_leg(14, 6.5), "base>taproom": _fixed_leg(1, 0.2)})

    # evaluate itself: the internal result behind day_plan (timeline, stops, costs, totals), and the two
    # model errors a plan can run into once it is evaluated.
    def evaluated(date, stops, ctx, ctx_next, legs, profile=None, cal=None):
        return c.add("g18", "evaluate", {"A": _a(), "profile": P if profile is None else profile, "plan": {"date": date, "stops": stops},
                                         "ctx": ctx, "ctx_next": ctx_next, "legs": legs, "cal": cal})

    cid = evaluated("2026-10-08", [office, taproom], thu, fri, fx.legs)
    for (path, want, decimals) in [("take_home.value", 482.2, 2), ("costs.total", 470.73, 2), ("costs.labour", 446.82, 2), ("costs.fuel", 23.91, 2),
                                   ("work_hours", 11.2833, 4), ("totals.take_home_per_hour.value", 42.74, 2), ("stops.1.orders.value", 39.38, 2)]:
        c.known(cid, path, want, decimals)
    cid = evaluated("2026-10-08", [], thu, fri, fx.legs)
    for (path, want) in [("take_home.value", 0.0), ("take_home.confidence", "fixed"), ("costs.total", 0.0), ("work_hours", 0.0), ("stops", []),
                         ("timeline.done", None), ("take_home_per_hour.value", 0.0)]:
        c.known(cid, path, want)
    evaluated("2026-10-08", [_stop("wedding", 690, 810, None, None, "catering",
                                   catering={"headcount": 80.0, "price_per_head": 14.0, "guarantee": 1000.0, "food_cost": None})], thu, fri,
              {"base>wedding": _fixed_leg(18, 9.5), "wedding>base": _fixed_leg(18, 9.5)})
    evaluated("2026-10-10", [fair], sat, sun, fair_legs, None, fx.cal)
    c.known(evaluated("2026-10-09", [night], fri, None, fx.legs), "error", "missing_context")
    c.known(evaluated("2026-10-09", [dict(fair, open_minute=1380, close_minute=1500)], fri, None, fair_legs), "error", "missing_context")
    c.known(evaluated("2026-10-08", [dict(office, open_minute=-100, close_minute=60)], thu, fri, fx.legs), "error", "invalid_window")


def _g19_calibration(c, fx):
    def cal(services, as_of="2026-10-04"):
        return c.add("g19", "calibrate", {"A": _a(), "services": services, "as_of": as_of})

    cid = cal(fx.services)                                                      # the example of 4.13
    c.calc(cid, "factor at spot A", lambda r: r["truck_factor"] * r["spots"]["A"]["factor"], 0.997154, 6)
    c.calc(cid, "factor at spot B", lambda r: r["truck_factor"] * r["spots"]["B"]["factor"], 0.903705, 6)
    c.calc(cid, "factor at spot C", lambda r: r["truck_factor"] * r["spots"]["C"]["factor"], 0.963009, 6)
    for (path, want, decimals) in [("truck_log_factor", -0.045458, 6), ("truck_n", 6, None), ("truck_weight", 4.457955, 6),
                                   ("spots.A.log_factor", 0.034037, 6), ("spots.A.factor", 1.034623, 6), ("spots.A.n", 3, None),
                                   ("spots.A.weight", 1.992693, 6), ("spots.B.log_factor", -0.064365, 6),
                                   ("spots.B.factor", 0.937663, 6), ("spots.B.n", 3, None), ("spots.B.weight", 2.465262, 6),
                                   ("spots.C.log_factor", -0.000804, 6), ("spots.C.factor", 0.999196, 6), ("spots.C.n", 1, None),
                                   ("spots.C.weight", 0.954842, 6), ("resid_sd", 0.180334, 6), ("resid_n", 5, None),
                                   ("resid_weight", 3.67789, 6), ("bias_log", 0.00857, 6), ("truck_factor", 0.963784, 6)]:
        c.doc(cid, path, want, decimals)
    cal(list(reversed(fx.services)))                                            # the order of the list is irrelevant
    cid = cal([])
    for (path, want) in [("truck_factor", 1.0), ("truck_weight", 0.0), ("resid_sd", None), ("bias_log", 0.0)]:
        c.doc(cid, path, want)
    c.doc(cal([fx.services[3]]), "truck_factor", 1.021924, 6)                   # only s4: sold out above the prediction
    c.doc(cal([dict(fx.services[3], actual=30.0)]), "truck_factor", 1.0)        # sold out below it: dropped

    def one(i, spot, actual, predicted_raw, sold_out=False, date="2026-10-04", kind="spot"):
        return _service(i, spot, date, actual, predicted_raw, sold_out, kind)

    clamps = [([one("x", "A", 500.0, 50.0)], 0.277259, 0.0, 1.319508, 1.659193),
              ([one("x", "A", 5.0, 50.0)], -0.277259, 0.0, 0.757858, 0.602703),
              ([one("x", "A", 60.0, 2.9)], 0.0, 0.0, 1.0, 2.132742),
              ([one("m%03d" % i, "A", 80.0, 10.0) for i in range(100)], 1.332975, 0.000227, 3.793172, 2.064162),
              ([one("m%03d" % i, "A", 60.0, 2.9) for i in range(100)], 0.0, 0.0, 1.0, 18.942197),
              ([one("m%03d" % i, "A", 9.0, 60.0) for i in range(100)], -1.332975, 0.00013, 0.263726, 0.578271),
              ([one("t%d" % i, "A", 50.0, 50.0) for i in range(5)] + [one("t9", "A", 500.0, 50.0)], 0.138629, 0.222915, 1.435545, 1.177535)]
    combined = [(2.1893, 109.47), (0.4568, 22.84), (2.1327, 6.18), (7.8297, 78.3), (18.9422, 54.93), (0.1525, 9.15), (1.6904, 84.52)]
    for k in range(len(clamps)):
        (services, log_factor, bias_log, truck_factor, spot_factor) = clamps[k]
        cid = cal(services)
        c.doc(cid, "truck_log_factor", log_factor, 6)
        c.doc(cid, "bias_log", bias_log, 6)
        c.doc(cid, "truck_factor", truck_factor, 6)
        c.doc(cid, "spots.A.factor", spot_factor, 6)
        c.calc(cid, "truck factor x spot factor", lambda r: r["truck_factor"] * r["spots"]["A"]["factor"], combined[k][0], 4)
        c.calc(cid, "predicted_raw x combined factor",
               lambda r, predicted=services[0]["predicted_raw"]: predicted * r["truck_factor"] * r["spots"]["A"]["factor"], combined[k][1], 2)
    c.doc(cid, "resid_sd", 0.862, 3)                                            # one mistyped service among six
    # A sold-out service above the truck's level but below what the logs already say about its own spot.
    others = [one("a%02d" % i, "OTHER", 50.0, 50.0) for i in range(10)] + [one("b1", "A", 82.5, 50.0), one("b2", "A", 82.5, 50.0)]
    for services in (others, others + [one("b3", "A", 55.0, 50.0, True)]):      # dropped: the result is the same
        c.calc(cal(services), "factor at spot A", lambda r: r["truck_factor"] * r["spots"]["A"]["factor"], 1.274117, 6)
    cal(others + [one("b3", "A", 95.0, 50.0, True)])                            # says more than the logs: used
    # Edges.
    cal([one("z1", "A", 0.0, 40.0), one("z2", "A", 3.0, 0.2), one("z3", "B", 12.0, 0.4)])       # zero actual; predictions under 0.5
    cal([one("r1", "A", 5000.0, 50.0), one("r2", "B", 0.0, 60.0), one("r3", "C", 150.0, 2.9)])  # ratios beyond 50 either way
    cal([one("r1", "A", 5000.0, 50.0), one("r2", "A", 60.0, 50.0), one("r3", "A", 48.0, 50.0), one("r4", "A", 55.0, 50.0)])
    cal([one("f1", "A", 50.0, 40.0, False, "2026-10-05"), one("f2", "A", 44.0, 40.0, False, "2026-10-04")])     # one dated after as_of
    cal([one("e1", None, 300.0, 100.0, False, "2026-10-01", "event"), one("e2", None, 80.0, 80.0, False, "2026-10-02", "catering"),
         one("e3", "A", 50.0, 0.0), one("e4", "A", 61.0, 50.0)])                # events, catering and zero predictions are skipped
    cal([one("so1", "A", 70.0, 50.0, True), one("so2", "B", 20.0, 50.0, True), one("so3", "A", 90.0, 50.0, True)])     # all sold out
    cal([one("y1", "A", 55.0, 50.0, False, "2025-10-04"), one("y2", "A", 45.0, 50.0, False, "2026-06-06"),
         one("y3", "A", 52.0, 50.0, False, "2026-10-04")], "2026-10-04")        # a year, a half-life and no age

    c.doc(c.add("g19", "calibration_factor", {"cal": None, "spot_id": "B"}), "", [1.0, 1.0])
    cid = c.add("g19", "calibration_factor", {"cal": fx.cal, "spot_id": "B"})
    c.doc(cid, "0", 0.963784, 6)
    c.doc(cid, "1", 0.937663, 6)
    c.add("g19", "calibration_factor", {"cal": fx.cal, "spot_id": "not-logged"})
    c.add("g19", "calibration_factor", {"cal": fx.cal, "spot_id": None})

    cid = c.add("g19", "accuracy_report", {"entries": fx.services})
    for (path, want, decimals) in [("n_total", 7, None), ("n_scored", 6, None), ("n_sold_out", 1, None), ("bias", 0.068548, 6),
                                   ("mape", 0.196514, 6), ("coverage", 1.0, 4), ("by_spot.0.spot_id", "A", None),
                                   ("by_spot.0.bias", -0.029255, 6), ("by_spot.0.mape", 0.117155, 6),
                                   ("by_spot.1.n_scored", 2, None), ("by_spot.1.bias", 0.386207, 6), ("by_spot.1.mape", 0.38881, 6),
                                   ("by_spot.2.bias", 0.05, 6), ("by_spot.2.mape", 0.05, 6)]:
        c.doc(cid, path, want, decimals)
    c.add("g19", "accuracy_report", {"entries": []})
    c.add("g19", "accuracy_report", {"entries": [one("so1", "A", 70.0, 50.0, True), one("so2", "B", 20.0, 50.0, True)]})
    c.add("g19", "accuracy_report", {"entries": [one("z1", "A", 0.0, 4.0), one("z2", "A", 0.0, 2.5)]})    # nothing sold: bias is null
    wide = dict(one("w1", "A", 130.0, 60.0), predicted=75.0)                    # outside its range; calibrated differs from raw
    c.add("g19", "accuracy_report", {"entries": [wide, one("w2", "B", 0.4, 6.0), one("e1", None, 300.0, 250.0, False, "2026-10-01", "event")]})

    # Dates. as_of is parsed first; the date of a service is parsed only once the service is known to
    # count, so a bad date on an event, on a service without a spot or on one predicted at zero is never read.
    c.known(cal(fx.services, "2026-02-30"), "error", "invalid_date")
    c.known(cal([], "2026-13-01"), "error", "invalid_date")
    c.known(cal([], None), "error", "invalid_date")
    c.known(cal([one("a", "A", 50.0, 40.0, False, "2026-02-30")]), "error", "invalid_date")
    cid = cal([one("a", None, 50.0, 40.0, False, "2026-02-30", "event"), dict(one("b", "A", 50.0, 40.0, False, "2026-99-99"), spot_id=None),
               one("c", "A", 50.0, 0.0, False, "not-a-date"), one("d", "A", 50.0, 40.0)])
    c.known(cid, "truck_n", 1)
    c.known(cid, "spots.A.n", 1)
    c.known(cal(fx.services, "2026-01-01"), "truck_n", 0)                       # every service is dated after as_of
    c.known(cal(fx.services, "1970-01-01"), "truck_factor", 1.0)
    cal(fx.services, "2199-12-31")                                              # 63,000 days on: weights of 1e-158 and less
    c.known(cal([one("a", "A", 70.0, 50.0, False, "2028-02-29")], "2028-03-01"), "truck_weight", 0.994240424, 9)     # one day old, across a leap day
    # Thresholds from both sides: predicted_raw 3 counts for the truck and just under does not; actual at
    # and under min_actual; a ratio of exactly 4 and exactly 50; three residuals give a spread and two do not.
    cid = cal([one("a", "A", 6.0, 3.0), one("b", "B", 6.0, 2.9999999)])
    c.known(cid, "truck_n", 1)
    c.known(cid, "spots.B.n", 1)
    cid = cal([one("a", "A", 0.5, 40.0), one("b", "B", 0.4999, 40.0), one("c", "C", 0.0, 40.0), one("d", "D", -3.0, 40.0)])
    c.calc(cid, "the floor on actual", lambda r: [r["spots"][k]["log_factor"] == r["spots"]["A"]["log_factor"] for k in ("B", "C", "D")], [True, True, True])
    cid = cal([one("a", "A", 200.0, 50.0), one("b", "B", 2500.0, 50.0), one("c", "C", 12.5, 50.0), one("d", "D", 1.0, 50.0)])
    c.known(cid, "truck_log_factor", 0.0, 9)                                    # +ln 4, +ln 4 (clamped), -ln 4, -ln 4 (clamped)
    cid = cal([one("a", "A", 60.0, 50.0), one("b", "A", 40.0, 50.0), one("c", "B", 55.0, 50.0)])
    c.known(cid, "resid_n", 3)
    c.calc(cid, "has a residual spread", lambda r: r["resid_sd"] is not None and r["bias_log"] > 0.0, True)
    cid = cal([one("a", "A", 60.0, 50.0), one("b", "A", 40.0, 50.0)])
    c.known(cid, "resid_n", 2)
    c.known(cid, "resid_sd", None)
    c.known(cid, "bias_log", 0.0)
    cid = cal([one("a%d" % i, "A", 50.0, 50.0) for i in range(5)])              # five services exactly as predicted
    for (path, want) in [("truck_factor", 1.0), ("truck_log_factor", 0.0), ("resid_sd", 0.0), ("bias_log", 0.0), ("spots.A.factor", 1.0), ("truck_weight", 5.0)]:
        c.known(cid, path, want)
    # A spot kind with no spot id is skipped like an event; another kind is skipped whatever it is called.
    cid = cal([dict(one("a", "A", 70.0, 50.0), spot_id=None), dict(one("b", "B", 70.0, 50.0), kind="popup"), one("c", "C", 55.0, 50.0)])
    c.known(cid, "truck_n", 1)
    c.calc(cid, "spots", lambda r: sorted(r["spots"].keys()), ["C"])
    # Spot ids are keys, whatever they look like; services with the same date and id keep the order given.
    odd = ["__proto__", "constructor", "toString", "hasOwnProperty", "", "12", "0", "1", "007", "-1", "1.5", "1e2", "A", "a", "B"]
    odd_log = [one("p%02d" % k, odd[k % len(odd)], 40.0 + 3.0 * ((k * 7) % 11), 50.0, k % 9 == 4, add_days("2026-07-01", (k * 5) % 90)) for k in range(45)]
    cid = cal(odd_log)
    c.calc(cid, "spots", lambda r: sorted(r["spots"].keys()), sorted(odd))
    c.known(cid, "spots.__proto__.n", 3)
    c.known(cid, "spots.12.n", 3)
    cid2 = cal(list(reversed(odd_log)))
    c.calc(cid2, "spots", lambda r: sorted(r["spots"].keys()), sorted(odd))
    cal([one("d", "A", 60.0, 50.0), one("d", "A", 40.0, 50.0), one("d", "B", 55.0, 50.0), one("c", "B", 45.0, 50.0)])
    cal([dict(one("a", "A", 70, 50), actual=70, predicted_raw=50), dict(one("b", "B", 30, 50), actual=30, predicted_raw=50)])     # whole numbers
    full = calibrate(fx.A, odd_log, "2026-10-04")
    for (spot_id, want) in [("__proto__", True), ("toString", True), ("valueOf", False), ("012", False), ("12", True), ("7", False)]:
        cid = c.add("g19", "calibration_factor", {"cal": full, "spot_id": spot_id})
        c.calc(cid, "has a spot factor", lambda r: r[1] != 1.0, want)
    c.known(c.add("g19", "calibration_factor", {"cal": calibrate(fx.A, [], "2026-10-04"), "spot_id": "A"}), "", [1.0, 1.0])
    # Accuracy: the range includes both of its ends; an actual below one order is measured against one.
    edge = [dict(one("a", "A", 10.0, 10.0), low=10.0, high=12.0), dict(one("b", "A", 12.0, 10.0), low=8.0, high=12.0),
            dict(one("c", "A", 12.5, 10.0), low=8.0, high=12.0), dict(one("d", "A", 7.5, 10.0), low=8.0, high=12.0)]
    cid = c.add("g19", "accuracy_report", {"entries": edge})
    c.known(cid, "coverage", 0.5)
    c.known(cid, "n_scored", 4)
    cid = c.add("g19", "accuracy_report", {"entries": [one("a", "A", 0.5, 2.0), one("b", "A", 1.0, 1.0), one("c", "A", 0.999, 3.0)]})
    c.known(cid, "mape", (1.5 + 0.0 + 2.001) / 3.0, 9)
    c.add("g19", "accuracy_report", {"entries": odd_log})
    c.add("g19", "accuracy_report", {"entries": list(reversed(odd_log))})
    c.add("g19", "accuracy_report", {"entries": [dict(one("a", "A", 5, 2), actual=5, predicted=4, predicted_raw=3, low=1, high=9)]})        # whole numbers
    cid = c.add("g19", "accuracy_report", {"entries": [one("e1", None, 300.0, 250.0, False, "2026-10-01", "event"),
                                                       one("e2", None, 100.0, 100.0, True, "2026-10-01", "catering")]})
    c.known(cid, "by_spot", [])                                                 # no spot ids: the overall block only
    c.known(cid, "n_sold_out", 1)


def _g20_events(c, fx):
    P = fx.profile

    def event(ev, ctx, ctx_next, open_minute, close_minute, profile=None, cal=None, a=None):
        return c.add("g20", "event_orders", {"A": _a() if a is None else a, "profile": P if profile is None else profile,
                                             "ev": ev, "cal": cal, "ctx": ctx, "ctx_next": ctx_next, "open": open_minute,
                                             "close": close_minute})

    general = {"attendance": 2000.0, "vendors": 6, "event_type": "general"}
    cid = event(general, fx.sat, None, 660, 900)
    for (path, want, decimals) in [("buyers", 420.0, 1), ("demand", 70.0, 4), ("hours.0.demand", 17.5, 3), ("hours.3.orders", 17.5, 3),
                                   ("orders.value", 70.0, 3), ("orders.low", 25.031, 3), ("orders.high", 129.674, 3),
                                   ("orders.confidence", "very_rough", None)]:
        c.doc(cid, path, want, decimals)
    food = {"attendance": 12000.0, "vendors": 8, "event_type": "food_focused"}
    cid = event(food, fx.sat, None, 690, 870)
    for (path, want, decimals) in [("buyers", 5400.0, 1), ("demand", 675.0, 4), ("hours.0.demand", 112.5, 3), ("hours.0.orders", 22.5, 3),
                                   ("hours.1.demand", 225.0, 3), ("hours.1.orders", 45.0, 3), ("hours.3.orders", 22.5, 3),
                                   ("orders.value", 135.0, 3), ("orders.low", 135.0, 3), ("orders.high", 135.0, 3),
                                   ("orders.confidence", "very_rough", None)]:
        c.doc(cid, path, want, decimals)
    event({"attendance": 600.0, "vendors": 1, "event_type": "evening_show"}, fx.fri, fx.sat, 1080, 1320)       # the only vendor
    event({"attendance": 600.0, "vendors": 0, "event_type": "incidental"}, fx.fri, fx.sat, 1080, 1320)         # vendors 0 counts as 1
    event(general, day_context(fx.A, "2026-10-10", None, _forecast([_fc(11, 52, 60, "Rain", 18), _fc(12, 53, 80, "Heavy Rain", 24)]), None, None),
          None, 660, 900)                                                       # rain for two of the four hours
    event(general, fx.sat, None, 660, 900, None, fx.cal)                        # the truck factor applies; spot factors never do
    event(general, typical_context(fx.A, 5), None, 660, 900)
    event({"attendance": 3000.0, "vendors": 4, "event_type": "evening_show"}, fx.sat, fx.sun, 1350, 1530)      # past midnight
    event(general, fx.sat, None, 720, 720)                                      # zero length
    event(general, fx.sat, None, 660, 900, _profile(capacity_orders_per_hour=15.0))
    event(general, fx.sat, None, 660, 900, None, None, _a({"events.attendance_haircut": 0.8, "events.p_buy.general": 0.5}))
    event(general, fx.sat, None, 900, 660)                                      # invalid_window
    event(general, fx.sat, None, 1380, 1500)                                    # missing_context

    def catering(ct, profile=None):
        return c.add("g20", "catering_money", {"profile": P if profile is None else profile, "ct": ct})

    cid = catering({"headcount": 80.0, "price_per_head": 14.0, "guarantee": 1000.0, "food_cost": None})
    for (path, want) in [("sales.value", 1120.0), ("food_cost.value", 336.0), ("packaging.value", 40.0), ("contribution.value", 744.0),
                         ("contribution.confidence", "fixed")]:
        c.doc(cid, path, want, 2 if isinstance(want, float) else None)
    cid = catering({"headcount": 100.0, "price_per_head": None, "guarantee": 1500.0, "food_cost": 420.0})
    for (path, want) in [("sales.value", 1500.0), ("food_cost.value", 420.0), ("packaging.value", 50.0), ("contribution.value", 1030.0)]:
        c.doc(cid, path, want, 2)
    catering({"headcount": 60.0, "price_per_head": 14.0, "guarantee": 1000.0, "food_cost": None})         # the guarantee is what is paid
    catering({"headcount": 45.0, "price_per_head": 22.5, "guarantee": None, "food_cost": None})
    catering({"headcount": 0.0, "price_per_head": 14.0, "guarantee": None, "food_cost": None})
    catering({"headcount": 120.0, "price_per_head": 18.0, "guarantee": 2000.0, "food_cost": 0.0}, _profile(packaging_per_order=0.0))
    # The window is checked one bound at a time, and before the contexts.
    for (o, cl_) in [(-1, 120), (0, 2881), (2881, 2881), (720, 719)]:
        c.known(event(general, fx.sat, fx.sun, o, cl_), "error", "invalid_window")
    c.known(event(general, fx.sat, None, 1500, 1440), "error", "invalid_window")
    c.known(event(general, fx.sat, None, 1439, 1441), "error", "missing_context")
    cid = event(general, fx.sat, None, 1380, 1440)                              # ends at midnight: no next context needed
    c.calc(cid, "number of hours", lambda r: len(r["hours"]), 1)
    cid = event(general, fx.sat, None, 1440, 1440)                              # empty, at midnight
    c.known(cid, "hours", [])
    c.known(cid, "orders.value", 0.0)
    c.known(cid, "demand", 70.0, 9)
    cid = event(general, fx.sat, fx.sun, 0, 2880)                               # two whole days: 70 orders spread over 48 hours
    c.calc(cid, "number of hours", lambda r: len(r["hours"]), 48)
    c.known(cid, "hours.47.day_index", 1)
    c.known(cid, "orders.value", 70.0, 9)
    cid = event(general, fx.sat, fx.sun, 1439, 1441)
    c.known(cid, "hours.0.demand", 35.0, 9)
    c.known(cid, "hours.0.capacity", 0.75, 9)
    c.known(cid, "orders.value", 1.5, 9)
    # Vendor counts of 0 and 1 are the same; no attendance; no capacity; a buy rate overridden to zero.
    cid = event({"attendance": 600.0, "vendors": 1, "event_type": "incidental"}, fx.fri, fx.sat, 1080, 1320)
    c.known(cid, "demand", 54.0, 9)
    cid = event({"attendance": 0.0, "vendors": 4, "event_type": "general"}, fx.sat, None, 660, 900)
    c.known(cid, "orders.high", 0.0)
    c.known(cid, "orders.confidence", "very_rough")
    cid = event(general, fx.sat, None, 660, 900, _profile(capacity_orders_per_hour=0.0))
    c.known(cid, "orders.value", 0.0)
    c.known(cid, "orders.high", 0.0)
    c.known(event(general, fx.sat, None, 660, 900, None, None, _a({"events.p_buy.general": 0})), "buyers", 0.0)
    event({"attendance": 2000, "vendors": 6, "event_type": "general"}, fx.sat, None, 660, 900, _profile(capacity_orders_per_hour=20))      # whole numbers
    # A dated day into a typical one: the weather rule of each hour's own context.
    wet = day_context(fx.A, "2026-10-10", None, _forecast([_fc(h, 45, 80, "Rain", 22) for h in range(24)]), None, None)
    cid = event(general, wet, typical_context(fx.A, 6), 1320, 1560)
    c.calc(cid, "weather by hour", lambda r: [h["weather"] < 1.0 for h in r["hours"]], [True, True, False, False])
    event(general, typical_context(fx.A, 5), wet, 1320, 1560)
    event(general, fx.sat, None, 660, 900, None, calibrate(fx.A, [], "2026-10-04"))       # an empty log: factor 1
    # Catering: the guarantee exactly equal to the per-head total; a price and a cost of zero are a price and a cost.
    cid = catering({"headcount": 80.0, "price_per_head": 14.0, "guarantee": 1120.0, "food_cost": None})
    c.known(cid, "sales.value", 1120.0)
    cid = catering({"headcount": 50.0, "price_per_head": 0.0, "guarantee": 0.0, "food_cost": 0.0})
    for (path, want) in [("sales.value", 0.0), ("food_cost.value", 0.0), ("contribution.value", -25.0), ("orders.value", 50.0)]:
        c.known(cid, path, want)
    cid = catering({"headcount": 50, "price_per_head": 12, "guarantee": 0, "food_cost": 0})         # whole numbers
    c.known(cid, "contribution.value", 575.0)
    cid = catering({"headcount": 40.0, "price_per_head": None, "guarantee": None, "food_cost": None})  # nothing agreed: no sales, packaging still costs
    c.known(cid, "contribution.value", -20.0)
    catering({"headcount": 80.0, "price_per_head": 14.0, "guarantee": 1000.0, "food_cost": None}, _profile(food_cost_pct=0.95, packaging_per_order=2.0))


def _g21_suggestions(c, fx):
    A = fx.A
    P = fx.profile
    tap_point = {"lat": 39.0035, "lng": -77.4035}
    office = {"spot_id": "office", "point": {"lat": TRUCK_LAT, "lng": TRUCK_LNG}, "terms": _terms(spot_id="office"), "vectors": fx.vec}
    taproom = {"spot_id": "taproom", "point": tap_point, "terms": _terms(spot_id="taproom", host=fx.tap_host), "vectors": fx.zero}
    spots = [taproom, office]
    week = [fx.ctx_fuel[add_days("2026-10-05", d)] for d in range(8)]

    def day(date, spots_in, legs, options=None, profile=None, cal=None):
        return c.add("g21", "suggest_day", {"A": _a(), "profile": P if profile is None else profile, "ctx": fx.ctx_fuel[date],
                                            "ctx_next": fx.ctx_fuel[add_days(date, 1)], "spots": spots_in, "legs": legs,
                                            "cal": cal, "options": options})

    def rows(cid, table):
        for k in range(len(table)):
            (stops, take_home, low, high, day_minutes) = table[k]
            c.doc(cid, "%d.position" % k, k + 1)
            for j in range(len(stops)):
                c.doc(cid, "%d.stops.%d.spot_id" % (k, j), stops[j][0])
                c.doc(cid, "%d.stops.%d.open_minute" % (k, j), stops[j][1])
            c.doc(cid, "%d.take_home.value" % k, take_home, 2)
            c.doc(cid, "%d.take_home.low" % k, low, 2)
            c.doc(cid, "%d.take_home.high" % k, high, 2)
            c.doc(cid, "%d.day_minutes" % k, day_minutes)

    # The example of 4.15, day by day.
    rows(day("2026-10-07", spots, fx.legs), [([("office", 660), ("taproom", 1020)], 446.05, 21.75, 925.98, 677),
                                             ([("office", 660)], 389.94, 109.88, 692.06, 327)])
    cid = day("2026-10-08", spots, fx.legs)
    rows(cid, [([("office", 660), ("taproom", 1020)], 482.2, 42.35, 1011.86, 677), ([("office", 660)], 347.18, 85.0, 659.18, 327)])
    c.doc(cid, "2.take_home.value", 163.31, 2)                                  # the taproom-only day
    rows(day("2026-10-09", spots, fx.legs), [([("office", 660), ("taproom", 1020)], 450.97, 24.25, 971.44, 677),
                                             ([("taproom", 1020)], 351.19, 94.66, 662.9, 307)])
    rows(day("2026-10-10", spots, fx.legs), [([("taproom", 1020)], 403.4, 125.05, 741.13, 307),
                                             ([("taproom", 720)], 39.14, -85.78, 193.9, 307)])
    rows(day("2026-10-11", spots, fx.legs), [([("taproom", 960)], 33.47, -89.02, 185.33, 307),          # 16:00 ties with 17:00
                                             ([("taproom", 720)], -6.03, -111.48, 125.49, 307)])
    # The office park may only be worked from 12:00 to 17:00.
    limited = dict(office, terms=_terms(spot_id="office", allowed={"days": [True] * 7, "open_minute": 720, "close_minute": 1020}))
    day("2026-10-08", [limited, taproom], fx.legs)
    # Three stops of two hours: a campus that only allows the afternoon between the office park and the taproom.
    campus = {"spot_id": "campus", "point": {"lat": 38.975, "lng": -77.38},
              "terms": _terms(spot_id="campus", host=_host("v_campus", 2000.0, only_food=False),
                              allowed={"days": [True] * 7, "open_minute": 840, "close_minute": 1020}),
              "vectors": fx.zero}
    legs3 = dict(fx.legs)
    for (key, minutes, miles) in [("base>campus", 8, 3.1), ("campus>base", 8, 3.1), ("office>campus", 6, 2.0),
                                  ("campus>office", 6, 2.0), ("campus>taproom", 9, 3.2), ("taproom>campus", 9, 3.2)]:
        legs3[key] = _fixed_leg(minutes, miles)
    day("2026-10-08", [office, campus, taproom], legs3, {"service_minutes": 120, "max_stops_per_day": 3, "max_days_per_week": None,
                                                         "max_visits_per_spot_per_week": None, "limit": 4},
        _profile(paid_crew=0))                                                  # owner-operated: no gap costs anything
    day("2026-10-08", [office, campus, taproom], legs3, {"service_minutes": None, "max_stops_per_day": 1, "max_days_per_week": None,
                                                         "max_visits_per_spot_per_week": None, "limit": 2})
    # No feasible plan: nothing saved; a spot nobody is near.
    day("2026-10-08", [], fx.legs)
    day("2026-10-08", [{"spot_id": "nowhere", "point": tap_point, "terms": _terms(spot_id="nowhere"), "vectors": fx.zero}], {})
    # Thirty lunch spots and one taproom, no routed legs: the evening must not be crowded out.
    many = [taproom]
    for i in range(30):
        scale = 1.0 - 0.01 * i
        v = dict(fx.vec)
        v["capture"] = {"day": [x * scale for x in fx.vec["capture"]["day"]], "eve": [x * scale for x in fx.vec["capture"]["eve"]]}
        v["nearby"] = [x * scale for x in fx.vec["nearby"]]
        v["within"] = [x * scale for x in fx.vec["within"]]
        spot_id = "lunch%02d" % i
        many.append({"spot_id": spot_id, "point": {"lat": 38.96 + 0.001 * i, "lng": -77.36}, "terms": _terms(spot_id=spot_id), "vectors": v})
    day("2026-10-08", many, {}, {"service_minutes": None, "max_stops_per_day": None, "max_days_per_week": None,
                                 "max_visits_per_spot_per_week": None, "limit": 2})

    def week_case(spots_in, legs, options=None):
        return c.add("g21", "suggest_week", {"A": _a(), "profile": P, "week_start": "2026-10-05", "contexts": week,
                                             "spots": spots_in, "legs": legs, "cal": None, "options": options})

    cid = week_case(spots, fx.legs)                                             # 5 days, 2 visits per spot
    c.doc(cid, "total_take_home.value", 1550.49, 2)
    c.doc(cid, "leaves_visited", 407)
    for (d, position, first) in [(0, None, None), (1, 1, "office"), (2, 2, "office"), (3, None, None), (4, 2, "taproom"),
                                 (5, 1, "taproom"), (6, None, None)]:
        if position is None:
            c.doc(cid, "days.%d.suggestion" % d, None)
        else:
            c.doc(cid, "days.%d.suggestion.position" % d, position)
            c.doc(cid, "days.%d.suggestion.stops.0.spot_id" % d, first)
    cid = week_case(spots, fx.legs, {"service_minutes": None, "max_stops_per_day": None, "max_days_per_week": None,
                                     "max_visits_per_spot_per_week": 3, "limit": None})
    c.doc(cid, "total_take_home.value", 2032.69, 2)
    c.doc(cid, "leaves_visited", 1419)
    week_case(spots, fx.legs, {"service_minutes": None, "max_stops_per_day": None, "max_days_per_week": 2,
                               "max_visits_per_spot_per_week": 7, "limit": None})
    week_case([], fx.legs)                                                      # nothing saved: seven days off

    # An option of 0 is an option, not "use the default": no results, no stops, no window.
    c.known(day("2026-10-08", spots, fx.legs, {"limit": 0}), "", [])
    c.known(day("2026-10-08", spots, fx.legs, {"max_stops_per_day": 0}), "", [])
    c.known(day("2026-10-08", spots, fx.legs, {"service_minutes": 0}), "", [])
    # Options may be left out one by one; an empty map is all defaults.
    cid = day("2026-10-08", spots, fx.legs, {})
    c.calc(cid, "number of plans", lambda r: len(r), 3)
    c.known(cid, "0.take_home.value", 482.2, 2)
    cid = day("2026-10-08", spots, fx.legs, {"limit": 1})
    c.calc(cid, "number of plans", lambda r: len(r), 1)
    cid = day("2026-10-08", spots, fx.legs, {"limit": 99, "max_stops_per_day": 1})          # every feasible one-stop plan of the day
    c.calc(cid, "plans", lambda r: [[p["position"], p["stops"][0]["spot_id"], p["stops"][0]["open_minute"]] for p in r],
           [[1, "office", 660], [2, "taproom", 1020], [3, "taproom", 1200], [4, "office", 840]])
    cid = day("2026-10-08", spots, fx.legs, {"service_minutes": 60, "limit": 2})            # one-hour windows
    c.calc(cid, "window lengths", lambda r: sorted(set([s["close_minute"] - s["open_minute"] for p in r for s in p["stops"]])), [60])
    cid = day("2026-10-08", spots, fx.legs, {"service_minutes": 480, "limit": 2})           # eight-hour windows: one stop a day at most
    c.calc(cid, "stops per plan", lambda r: sorted(set([len(p["stops"]) for p in r])), [1])
    c.known(day("2026-10-08", spots, fx.legs, {"service_minutes": 720}), "", [])            # twelve hours of service: longer than the 14-hour day allows
    # The spot is closed to trucks on the day (the first test of the allowed rule), and open only off the hour.
    closed = dict(office, terms=_terms(spot_id="office", allowed={"days": [True, True, True, False, True, True, True], "open_minute": 0, "close_minute": 1440}))
    cid = day("2026-10-08", [closed, taproom], fx.legs, {"limit": 1})
    c.calc(cid, "spots used", lambda r: sorted(set([s["spot_id"] for p in r for s in p["stops"]])), ["taproom"])
    off_hour = dict(office, terms=_terms(spot_id="office", allowed={"days": [True] * 7, "open_minute": 630, "close_minute": 870}))
    cid = day("2026-10-08", [off_hour, taproom], fx.legs, {"limit": 1})         # 10:30 to 14:30 holds one whole three-hour window: 11:00
    c.calc(cid, "office opens", lambda r: sorted(set([s["open_minute"] for p in r for s in p["stops"] if s["spot_id"] == "office"])), [660])
    short = dict(office, terms=_terms(spot_id="office", allowed={"days": [True] * 7, "open_minute": 720, "close_minute": 840}))
    cid = day("2026-10-08", [short, taproom], fx.legs, {"limit": 1})            # two hours allowed: no three-hour window fits
    c.calc(cid, "spots used", lambda r: sorted(set([s["spot_id"] for p in r for s in p["stops"]])), ["taproom"])
    # Four copies of one spot: every candidate ties, so spot_id and opening time decide every rank.
    same = [dict(office, spot_id="e%d" % i, terms=_terms(spot_id="e%d" % i)) for i in (3, 1, 0, 2)]
    cid = day("2026-10-08", same, {}, {"limit": 4, "max_stops_per_day": 1})
    c.calc(cid, "spots in rank order", lambda r: [p["stops"][0]["spot_id"] for p in r], ["e0", "e1", "e2", "e3"])
    pairs = [dict(office, spot_id="e1", terms=_terms(spot_id="e1")), dict(taproom, spot_id="t1", terms=_terms(spot_id="t1", host=fx.tap_host)),
             dict(taproom, spot_id="t0", terms=_terms(spot_id="t0", host=fx.tap_host)), dict(office, spot_id="e0", terms=_terms(spot_id="e0"))]
    cid = day("2026-10-08", pairs, {}, {"limit": 3})                            # two-stop plans tie too: the lists of (spot_id, open) decide
    c.calc(cid, "plans", lambda r: [[s["spot_id"] for s in p["stops"]] for p in r], [["e0", "t0"], ["e0", "t1"], ["e1", "t0"]])
    # Spots whose ids differ only in case or are members of every object; a calibrated day; a treated day.
    odd_legs = {}
    for a in ("base", "Office", "office", "constructor", "__proto__"):
        for b in ("base", "Office", "office", "constructor", "__proto__"):
            if a != b:
                odd_legs[a + ">" + b] = _fixed_leg(9 if "base" in (a, b) else 4, 3.0 if "base" in (a, b) else 1.0)
    odd_spots = [dict(office, spot_id="office"), dict(office, spot_id="Office", terms=_terms(spot_id="Office")),
                 dict(taproom, spot_id="constructor", terms=_terms(spot_id="constructor", host=fx.tap_host)),
                 dict(taproom, spot_id="__proto__", terms=_terms(spot_id="__proto__", host=_host("v_nightlife", 150.0)))]
    cid = day("2026-10-09", odd_spots, odd_legs, {"limit": 2})
    c.calc(cid, "first plan", lambda r: [s["spot_id"] for s in r[0]["stops"]], ["Office", "__proto__"])
    logged = [dict(office, spot_id="A", terms=_terms(spot_id="A")), dict(taproom, spot_id="B", terms=_terms(spot_id="B", host=fx.tap_host))]
    day("2026-10-08", logged, {}, {"limit": 2}, None, fx.cal)
    c.add("g21", "suggest_day", {"A": _a(), "profile": P, "ctx": day_context(A, "2026-10-08", "sat", fx.rain, FUEL, "owner"),
                                 "ctx_next": fx.ctx_fuel["2026-10-09"], "spots": spots, "legs": fx.legs, "cal": None, "options": {"limit": 2}})

    # The week: a bad start date fails only when the dates are written out; limits of zero; ids as map keys.
    c.known(c.add("g21", "suggest_week", {"A": _a(), "profile": P, "week_start": "2026-02-30", "contexts": week, "spots": [], "legs": {}, "cal": None,
                                          "options": None}), "error", "invalid_date")
    c.known(c.add("g21", "suggest_week", {"A": _a(), "profile": P, "week_start": None, "contexts": week, "spots": [], "legs": {}, "cal": None,
                                          "options": None}), "error", "invalid_date")
    cid = week_case(spots, fx.legs, {"max_days_per_week": 0})
    c.known(cid, "leaves_visited", 1)
    c.known(cid, "visits", {})
    c.known(cid, "total_take_home.confidence", "fixed")
    cid = week_case(spots, fx.legs, {"max_visits_per_spot_per_week": 0})
    c.known(cid, "leaves_visited", 1)
    cid = week_case(spots, fx.legs, {"max_days_per_week": 1})
    c.calc(cid, "working days", lambda r: len([d for d in r["days"] if d["suggestion"] is not None]), 1)
    cid = week_case(odd_spots[2:], odd_legs, {"max_days_per_week": 2, "max_visits_per_spot_per_week": 1})
    c.known(cid, "visits", {"constructor": 1, "__proto__": 1})
    cid = c.add("g21", "suggest_week", {"A": _a(), "profile": P, "week_start": "2026-10-07", "contexts": week, "spots": [], "legs": {}, "cal": None,
                                        "options": None})                       # week_start is only added to: any valid date is taken
    c.known(cid, "days.6.date", "2026-10-13")


def _g22_scouting(c, fx):
    A = fx.A
    P = fx.profile

    def place(place_id, place_type, size_default, vectors, point_id=None, kitchen=None, point=None):
        return {"place_id": place_id, "place_type": place_type,
                "point": {"lat": TRUCK_LAT, "lng": TRUCK_LNG} if point is None else point,
                "point_id": point_id, "size_default": size_default, "kitchen": kitchen, "vectors": vectors}

    def own(point_id):                                                          # host_vec of a place with its own point
        return _stored(_zero_vectors(exclusion={"point_ids": [point_id], "segment": None, "amount": 0.0}))

    def scout(pl, legs, cal=None, fuel=FUEL, profile=None, a=None):
        return c.add("g22", "scout_estimate", {"A": _a() if a is None else a, "profile": P if profile is None else profile,
                                               "place": pl, "legs": legs, "cal": cal, "fuel_price_per_gal": fuel})

    w100 = place("w100", "taproom", 40.0, own("pw100"), "pw100", None, {"lat": 39.0035, "lng": -77.4035})
    w200 = place("w200", "office_park", 0.0, _stored(fx.vec))
    n300 = place("n300", "bar", 45.0, own("pn300"), "pn300", "unknown", {"lat": 38.9072, "lng": -77.0369})
    legs = {"w100": {"base>w100": _fixed_leg(1, 0.2), "w100>base": _fixed_leg(1, 0.2)},
            "w200": {"base>w200": _fixed_leg(11, 4.85), "w200>base": _fixed_leg(11, 4.85)},
            "n300": {"base>n300": _fixed_leg(20, 9.0), "n300>base": _fixed_leg(20, 9.0)}}
    table = [(w200, 0.8, "no", 1, 660, 66.66, 36.6, 97.93, "rough", 635.96, 22, 9.7, 19.04, 489.725),
             (w100, 1.0, "no", 5, 1020, 21.52, 6.05, 42.76, "very_rough", 205.28, 2, 0.4, 1.51, 203.7777),
             (n300, 0.3, "yes", 5, 1020, 9.68, 2.39, 19.84, "very_rough", 92.38, 40, 18.0, 34.79, -7.0766)]
    for (pl, host_fit, kitchen, dow, open_minute, orders, low, high, label, contribution, minutes, miles, cost, score) in table:
        cid = scout(pl, legs[pl["place_id"]])
        for (path, want, decimals) in [("host_fit", host_fit, None), ("kitchen", kitchen, None), ("best_window.dow", dow, None),
                                       ("best_window.open_minute", open_minute, None), ("orders.value", orders, 2),
                                       ("orders.low", low, 2), ("orders.high", high, 2), ("orders.confidence", label, None),
                                       ("contribution.value", contribution, 2), ("round_trip.minutes", minutes, None),
                                       ("round_trip.miles", miles, 1), ("round_trip.cost", cost, 2), ("score", score, 4),
                                       ("position", 0, None)]:
            c.doc(cid, path, want, decimals)
    c.doc(scout(place("n400", "restaurant", 0.0, _stored(fx.zero)), {}), "", None)        # not a host
    cid = scout(place("n500", "farmers_market", 0.0, _stored(fx.zero)), {})               # nobody near, no people of its own
    for (path, want) in [("best_window", None), ("score", 0.0), ("host_segment", None), ("orders.confidence", "rough"),
                         ("round_trip.minutes", 0)]:
        c.doc(cid, path, want)
    cid = scout(place("w600", "campus", 0.0, _stored(fx.zero)), {})                       # a campus building: size withheld
    c.doc(cid, "host_size", 0.0)
    c.doc(cid, "host_segment", "v_campus")
    scout(place("w101", "taproom", 40.0, own("pw101"), "pw101", "yes", {"lat": 39.0035, "lng": -77.4035}),
          {"base>w101": _fixed_leg(1, 0.2), "w101>base": _fixed_leg(1, 0.2)})   # a taproom that sells its own food
    scout(place("w700", "big_box", 200.0, _stored(fx.vec_mix), None, None, {"lat": 38.9, "lng": -77.03}), {})   # fallback legs
    scout(place("w701", "hospital", 120.0, _stored(fx.vec_mix), None, "no", {"lat": 38.9, "lng": -77.03}), {}, fx.cal, 6.531)
    scout(w200, legs["w200"], None, FUEL, None, _a(None, REGION_NONE))

    results = [scout_estimate(A, P, pl, legs[pl["place_id"]], None, FUEL) for pl in (w100, w200, n300)]
    cid = c.add("g22", "scout_rank", {"results": results})
    for (k, place_id) in [(0, "w200"), (1, "w100"), (2, "n300")]:
        c.doc(cid, "%d.place_id" % k, place_id)
        c.doc(cid, "%d.position" % k, k + 1)
    twin_b = dict(results[0], place_id="w100b")
    twin_a = dict(results[0], place_id="w100a")
    c.add("g22", "scout_rank", {"results": [twin_b, results[2], twin_a, results[1]]})       # a tie broken by id
    crowd = []
    for i in range(53):
        crowd.append(dict(results[2], place_id="n%03d" % (900 - i), score=float((i * 37) % 53) - 20.0 + (0.25 if i % 2 == 0 else 0.0)))
    c.add("g22", "scout_rank", {"results": crowd})                              # more than scout.max_results
    c.add("g22", "scout_rank", {"results": []})

    rows = map_weight_rows(A, P, None)
    for (terms, vectors) in [(_terms(host=_host("v_nightlife", 40.0, "default", True, "pw100", "taproom")), w100["vectors"]),
                             (_terms(), w200["vectors"]),
                             (_terms(host=_host("v_nightlife", 45.0, "default", False, "pn300", "bar")), n300["vectors"])]:
        c.add("g22", "strip_from_rows", {"A": _a(), "profile": P, "terms": terms, "vectors": vectors, "rows": rows})

    # Every place type once on its own default size, to pin which types host and with what kitchen.
    order = seed(A, "place_types.order")
    hosts = []
    for place_type in order:
        row = seed(A, "place_types.rows." + place_type)
        cid = scout(place("t-" + place_type, place_type, row["default_size"], own("p-" + place_type), "p-" + place_type, None, {"lat": 39.0035, "lng": -77.4035}),
                    {"base>t-" + place_type: _fixed_leg(4, 1.5), "t-" + place_type + ">base": _fixed_leg(5, 1.6, 0.75)})
        if row["host_fit"] > 0:
            hosts.append(place_type)
            c.known(cid, "kitchen", row["kitchen_default"])
            c.known(cid, "host_size", row["default_size"] if row["host_segment"] is not None else 0.0)
        else:
            c.known(cid, "", None)
    assert [t for t in order if t not in hosts] == ["restaurant", "fast_food", "cafe", "convenience"]
    # A size on a type that has no host segment is not a host: the place is ranked on its catchment alone.
    cid = scout(place("m1", "farmers_market", 30.0, _stored(fx.vec)), {"base>m1": _fixed_leg(11, 4.85), "m1>base": _fixed_leg(11, 4.85)})
    c.known(cid, "host_size", 0.0)
    c.known(cid, "host_segment", None)
    c.known(cid, "orders.value", 66.66, 2)
    # The place's own kitchen state against the default of its type, both ways; a size below zero is no host.
    c.known(scout(place("b1", "bar", 45.0, own("pb1"), "pb1", "no"), {}), "kitchen", "no")
    c.known(scout(place("g1", "gym", 50.0, own("pg1"), "pg1", "yes"), {}), "kitchen", "yes")
    cid = scout(place("x1", "taproom", -40.0, own("px1"), "px1"), {})
    c.known(cid, "best_window", None)
    c.known(cid, "host_size", 0.0)
    # No capacity: no window at all, whatever the place. A fuel price of zero is a price.
    cid = scout(w100, legs["w100"], None, FUEL, _profile(capacity_orders_per_hour=0.0))
    c.known(cid, "best_window", None)
    c.known(cid, "score", 0.0)
    cid = scout(w200, legs["w200"], None, 0)
    c.known(cid, "round_trip.cost", 22.0 / 60.0 * 2 * 18.0 * 1.1, 9)
    # The best window runs from Sunday night into Monday: the hours after midnight take Monday's typical day.
    late = _a({"segments.v_nightlife.presence.sunday": [0.0] * 23 + [1.0], "segments.v_nightlife.presence.weekday": [1.0] + [0.0] * 23,
               "segments.v_nightlife.presence.saturday": [0.0] * 24, "segments.v_nightlife.dow_factor": [1.0, 0.0, 0.0, 0.0, 0.0],
               "segments.v_nightlife.intent.sunday": [0.3] * 24, "segments.v_nightlife.intent.weekday": [0.3] * 24})
    cid = scout(w100, legs["w100"], None, FUEL, None, late)
    for (path, want) in [("best_window.dow", 6), ("best_window.open_minute", 1320), ("best_window.close_minute", 1500)]:
        c.known(cid, path, want)
    c.known(cid, "orders.value", 40.0 * 0.75 * 0.3 * 0.8 * 2, 9)                # 23:00 on Sunday and 00:00 on Monday, both "late" (fit 0.8)

    def rank(ranked):
        return c.add("g22", "scout_rank", {"results": ranked})

    one = results[0]
    cid = rank([dict(one, place_id="b", score=1.00000004), dict(one, place_id="a", score=1.0), dict(one, place_id="c", score=0.99999996),
                dict(one, place_id="d", score=1.0000006)])                      # a, b and c share a key of 1000000: by id; d is one millionth ahead
    c.calc(cid, "order", lambda r: [x["place_id"] for x in r], ["d", "a", "b", "c"])
    cid = rank([dict(one, place_id="n", score=-5.0), dict(one, place_id="z", score=0.0), dict(one, place_id="m", score=-0.0),
                dict(one, place_id="p", score=0.0000004), dict(one, place_id="q", score=-0.0000004), dict(one, place_id="r", score=-0.0000006)])
    c.calc(cid, "order", lambda r: [x["place_id"] for x in r], ["m", "p", "q", "z", "r", "n"])
    cid = rank([dict(one, place_id="a", score=1.0, host_size=1.0), dict(one, place_id="a", score=1.0, host_size=2.0), dict(one, place_id="A", score=1.0)])
    c.calc(cid, "order", lambda r: [[x["place_id"], x["host_size"]] for x in r], [["A", one["host_size"]], ["a", 1.0], ["a", 2.0]])      # one id twice: the order given
    cid = rank([dict(one, place_id="i%d" % i, score=i, position=9 - i) for i in range(3)])  # whole-number scores; positions are overwritten
    c.calc(cid, "positions", lambda r: [[x["place_id"], x["position"]] for x in r], [["i2", 1], ["i1", 2], ["i0", 3]])

    for (terms, vectors, profile) in [(_terms(host=_host("v_nightlife", 0.0)), _stored(fx.vec_mix), P),             # a host of size 0 is no host
                                      (_terms(host=_host("v_shopping", 150.0, "default", False)), _stored(fx.vec_mix),
                                       _profile(capacity_orders_per_hour=5))]:                                      # an open host; capped hours
        c.add("g22", "strip_from_rows", {"A": _a(), "profile": profile, "terms": terms, "vectors": vectors, "rows": rows})
    c.add("g22", "strip_from_rows", {"A": _a(), "profile": P, "terms": _terms(spot_id="B", host=fx.tap_host), "vectors": fx.vec_mix,
                                     "rows": map_weight_rows(A, P, fx.cal)})    # the rows carry the truck factor; a spot factor never enters


def _g23_fast_path(c, fx):
    A = fx.A
    P = fx.profile
    c.add("g23", "map_weight_rows", {"A": _a(), "profile": P, "cal": None})
    c.add("g23", "map_weight_rows", {"A": _a({"segments.w_office.dow_factor": [1.0, 1.0, 1.0, 1.0, 1.0]}),
                                     "profile": _profile(daypart_fit={"breakfast": 1.0, "lunch": 1.0, "dinner": 0.5, "late": 0.0}),
                                     "cal": fx.cal})
    rows = map_weight_rows(A, P, None)

    def features_of(vectors):
        return list(vectors["capture"]["day"]) + list(vectors["capture"]["eve"]) + list(vectors["nearby"]) + [
            vectors["rivals"]["day"], vectors["rivals"]["eve"]]

    def cells(features, n, how, capacity=45.0):
        regime = seed(A, "hours.regime_of_hour")[how % 24]
        return c.add("g23", "cell_scores", {"features": features, "n": n, "w_opp_row": rows["w_opp"][how],
                                            "w_people_row": rows["w_people"][how], "regime": regime, "capacity": capacity})

    cid = cells(features_of(fx.vec), 1, 84)                                     # the cell of 4.17, Thursday 12:00
    c.doc(cid, "opportunity.0", 29.436015, 6)
    c.doc(cid, "people.0", 437.11, 4)
    c.doc(cid, "competition.0", 0.833985, 6)
    three = features_of(fx.vec) + features_of(fx.vec_mix) + features_of(fx.zero)
    cells(three, 3, 84)
    cells(three, 3, 90)                                                         # Thursday 18:00: the evening regime
    cells(three, 3, 132)                                                        # Saturday 12:00
    cells(three, 3, 84, 10.0)                                                   # the capacity clamp
    cells([], 0, 84)
    block = []
    for cell in range(1000):                                                    # a 1,000-cell block
        for j in range(50):
            if j < 32:
                block.append(float((cell * 37 + j * 11) % 101) * 0.25)
            elif j < 48:
                block.append(float((cell * 13 + j * 7) % 211) * 2.0)
            else:
                block.append(float((cell * 5 + j) % 23) * 0.5)
    cells(block, 1000, 84)
    cells(block[:50 * 40], 40, 138)                                             # Saturday 18:00, the first forty cells
    for (x, hi, want) in [(0.0, 45.0, 0), (1.0, 45.0, 38), (5.0, 45.0, 85), (45.0, 45.0, 255), (60.0, 45.0, 255),
                          (1000.0, 20000.0, 57), (2.0, 100.0, 36)]:
        c.doc(c.add("g23", "score_byte", {"x": x, "hi": hi}), "", want)
    c.doc(c.add("g23", "score_byte", {"x": hourly_orders(A, P, fx.terms_open, fx.vec, None, typical_context(A, 3), 12)["orders"], "hi": 45.0}), "", 206)
    c.doc(c.add("g23", "score_byte", {"x": fx.vec["nearby"][1] * rows["w_people"][84][1], "hi": 20000.0}), "", 38)
    c.doc(c.add("g23", "score_byte", {"x": fx.vec["rivals"]["day"], "hi": 100.0}), "", 23)
    for (x, hi) in [(-3.0, 45.0), (0.0001, 45.0), (44.9, 45.0), (19999.0, 20000.0), (110.0, 100.0)]:
        c.add("g23", "score_byte", {"x": x, "hi": hi})
    # Either side of the steps of the square-root scale: byte k begins where 255 * sqrt(x / hi) passes k - 0.5.
    for k in (1, 2, 128, 255):
        t = ((k - 0.5) / 255.0) * ((k - 0.5) / 255.0)
        c.known(c.add("g23", "score_byte", {"x": 45.0 * t * (1.0 - 1e-6), "hi": 45.0}), "", k - 1)
        c.known(c.add("g23", "score_byte", {"x": 45.0 * t * (1.0 + 1e-6), "hi": 45.0}), "", k)
    for (x, hi, want) in [(0, 45, 0), (45, 45, 255), (1, 1, 255), (0.25, 1.0, 128), (1, 4, 128), (-0.0, 45.0, 0), (1e300, 45.0, 255),
                          (-1e300, 45.0, 0), (5.0, 1e300, 0)]:
        c.known(c.add("g23", "score_byte", {"x": x, "hi": hi}), "", want)
    # No capacity; a capacity below zero is still a cap; whole-number features and weights; the first cells of a longer list.
    cid = cells(three, 3, 84, 0.0)
    c.known(cid, "opportunity", [0.0, 0.0, 0.0])
    cid = cells(three, 2, 84)
    c.calc(cid, "cells scored", lambda r: [len(r["opportunity"]), len(r["people"]), len(r["competition"])], [2, 2, 2])
    cid = c.add("g23", "cell_scores", {"features": list(range(100)), "n": 2, "w_opp_row": [1] * 16, "w_people_row": [2] * 16, "regime": "eve", "capacity": 1000})
    c.known(cid, "opportunity", [376.0, 1000.0])                                # 16 + ... + 31 = 376; 66 + ... + 81 = 1176, capped
    c.known(cid, "people", [1264.0, 2864.0])
    c.known(cid, "competition", [49, 99])
    for how in (0, 4, 5, 15, 16, 23, 167):                                      # the hours either side of the two regime changes
        cells(three, 3, how)


def _g24_seeds(c, fx):
    def read(path, a=None):
        return c.add("g24", "seed", {"A": _a() if a is None else a, "path": path})

    c.doc(read("kernel.outside_option_a0"), "", 1.6)
    c.doc(read("segments.w_office.presence.weekday"), "10", 0.369)
    c.doc(read("segments.w_office.dow_factor"), "", [0.9, 1.19, 1.16, 1.08, 0.67])
    c.doc(read("weather.temperature_bands.rows.50_59.open"), "", 0.9)
    c.doc(read("place_types.rows.taproom"), "default_size", 40.0)
    c.doc(read("host.captive_share", _a({"host.captive_share": 0.6})), "", 0.6)
    read("kernel.rival_weight.cafe")
    read("weather.precip_classes.order")
    read("weather.temperature_bands.rows.95_up.upper_f")                        # null
    read("traffic.dc_typical")
    read("hours.daypart_of_hour")
    read("segments.res.holiday_day_type.major", _a({"segments.res.holiday_day_type.major": "saturday"}))

    def validate(overrides, want):
        c.doc(c.add("g24", "validate_overrides", {"overrides": overrides}), "", want)

    def bad(path, error):
        return [{"path": path, "error": error}]

    validate({"host.captive_share": 0.6}, [])
    validate({"host.captive_share": 1.5}, bad("host.captive_share", "out_of_bounds"))
    validate({"host.captive_share.value": 0.5}, bad("host.captive_share.value", "not_a_seed"))
    validate({"weather.temperature_bands.rows.50_59.upper_f": 61}, bad("weather.temperature_bands.rows.50_59.upper_f", "not_a_seed"))
    validate({"kernel.outside_option_a0": 2.0}, bad("kernel.outside_option_a0", "not_overridable"))
    validate({"profile_defaults.avg_ticket": 12.0}, bad("profile_defaults.avg_ticket", "not_overridable"))
    validate({"segments.w_office.presence.weekday": [0.1] * 23}, bad("segments.w_office.presence.weekday", "wrong_shape"))
    validate({"segments.w_office.presence": {"weekday": [0.1] * 24}}, bad("segments.w_office.presence", "not_a_leaf"))
    validate({"segments.res.holiday_day_type.major": "monday"}, bad("segments.res.holiday_day_type.major", "not_allowed"))
    validate({"no.such.path": 1}, bad("no.such.path", "unknown_path"))
    validate({}, [])
    validate({"segments.w_office.presence.weekday": [0.1] * 24, "segments.w_office.dow_factor": [1.0, 1.0, 1.0, 1.0, 3.0],
              "weather.floor": 0, "events.p_buy.general": 1, "segments.res.holiday_day_type.minor": "sunday",
              "weather.precip_classes.rows.rain.captive": 0.9}, [])
    validate({"weather.floor": "low", "weather.pop_when_missing": True, "segments.res.dow_factor": [1.0, 1.0, "x", 1.0, 1.0],
              "segments.res.intent.weekday": [0.1] * 23 + [1.5], "host.onsite_kitchen_weight": -0.1,
              "segments.w_office.presence.weekday.3": 0.2, "kernel.rival_weight.quick": {"day": 1.0, "eve": 1.0},
              "segments.w_office": {}, "weather.temperature_bands.rows.50_59": 0.9, "events.p_buy.unit": "x",
              "segments.res.holiday_day_type.major": 3},
             [{"path": "events.p_buy.unit", "error": "not_a_seed"},
              {"path": "host.onsite_kitchen_weight", "error": "out_of_bounds"},
              {"path": "kernel.rival_weight.quick", "error": "not_overridable"},
              {"path": "segments.res.dow_factor", "error": "wrong_shape"},
              {"path": "segments.res.holiday_day_type.major", "error": "wrong_shape"},
              {"path": "segments.res.intent.weekday", "error": "out_of_bounds"},
              {"path": "segments.w_office", "error": "not_overridable"},
              {"path": "segments.w_office.presence.weekday.3", "error": "unknown_path"},
              {"path": "weather.floor", "error": "wrong_shape"},
              {"path": "weather.pop_when_missing", "error": "wrong_shape"},
              {"path": "weather.temperature_bands.rows.50_59", "error": "not_a_leaf"}])

    def window(terms, vectors, ctx, open_minute, close_minute, a=None):
        return c.add("g24", "window_orders", {"A": _a() if a is None else a, "profile": fx.profile, "terms": terms,
                                              "vectors": vectors, "cal": None, "ctx": ctx, "ctx_next": None,
                                              "open": open_minute, "close": close_minute})

    # The three cases the anchors point at (8.3).
    a1 = window(fx.terms_open, fx.vec, fx.thu, 660, 840)
    for (path, want, decimals) in [("hours.0.result.orders", 13.119, 3), ("hours.1.result.orders", 29.436, 3),
                                   ("hours.2.result.orders", 17.939, 3), ("orders.value", 60.4938, 4), ("orders.low", 33.01, 2),
                                   ("orders.high", 93.19, 2), ("orders.confidence", "rough", None),
                                   ("demand_adj", 60.493849, 6), ("spread.v_truck", 0.0576, 4), ("spread.v_spot", 0.0361, 4),
                                   ("spread.v_day", 0.04, 4), ("spread.sigma_model", 0.36565, 6), ("spread.v_count", 0.032526, 6),
                                   ("spread.sigma", 0.407709, 6), ("orders.low", 33.0139, 4), ("orders.high", 93.1942, 4)]:
        c.doc(a1, path, want, decimals)
    a2 = window(fx.terms_tap, fx.zero, fx.thu, 1020, 1200)
    for (path, want, decimals) in [("hours.0.result.orders", 10.8, 3), ("hours.1.result.orders", 15.624, 3),
                                   ("hours.2.result.orders", 12.96, 3), ("orders.value", 39.384, 4), ("orders.low", 20.76, 2),
                                   ("orders.high", 62.2, 2), ("orders.confidence", "rough", None)]:
        c.doc(a2, path, want, decimals)
    a2_fri = window(fx.terms_tap, fx.zero, fx.fri, 1020, 1200)
    for (path, want, decimals) in [("hours.0.result.orders", 16.2, 3), ("hours.1.result.orders", 23.436, 3),
                                   ("hours.2.result.orders", 19.44, 3), ("orders.value", 59.076, 4), ("orders.low", 32.19, 2),
                                   ("orders.high", 91.75, 2)]:
        c.doc(a2_fri, path, want, decimals)
    c.anchors.append({"id": "A1", "case": a1, "path": "orders.value", "min": 45.0, "max": 90.0})
    c.anchors.append({"id": "A2", "case": a2, "path": "orders.value", "min": 30.0, "max": 50.0})
    c.anchors.append({"id": "A2-friday", "case": a2_fri, "path": "orders.value", "greater_than_case": a2})
    # A valid override changing an anchor: with a captive share of 0.6 the taproom reads 39.384 x 0.6 / 0.75.
    over = _a({"host.captive_share": 0.6})
    window(fx.terms_tap, fx.zero, day_context(_assume(over), "2026-10-08", None, None, None, None), 1020, 1200, over)

    # seed(): any node of the file can be read; an override wins under exactly its own path, whatever
    # its value (0, null) and whether or not the file has that path.
    c.known(read("host.captive_share.value"), "", 0.75)
    c.known(read("host.captive_share.scope"), "", "owner")
    c.known(read("segments.v_campus.weak"), "", True)
    c.known(read("segments.w_office.weak"), "", False)
    c.known(read("model_version"), "", "tps-0.1.0")
    c.known(read("seeds_revision"), "", 1)
    c.known(read("scout"), "max_results.value", 50)                             # a whole group, as it is in the file
    c.known(read("holidays.rules"), "11.id", "inauguration")
    c.known(read("profile_defaults.daypart_fit"), "late", 0.8)
    c.known(read("kernel.rival_weight.bar"), "eve", 0.6)
    c.known(read("weather.floor", _a({"weather.floor": 0})), "", 0)
    c.known(read("weather.floor", _a({"weather.floor": None})), "", None)
    c.known(read("host.captive_share.value", _a({"host.captive_share": 0.6})), "", 0.75)
    c.known(read("host.captive_share", _a({"host.captive_share.value": 0.6})), "", 0.75)
    c.known(read("host.captive_share.value", _a({"host.captive_share.value": 0.6})), "", 0.6)
    c.known(read("scout.max_results", _a({"scout.max_results": 5})), "", 5)    # seed() does not judge what may be overridden
    c.known(read("no.such.path", _a({"no.such.path": 7})), "", 7)
    c.known(read("toString", _a({"toString": 3})), "", 3)
    c.known(read("__proto__", _a({"__proto__": [1, 2]})), "", [1, 2])
    c.known(read("segments.w_office.presence.weekday", _a({"segments.w_office.presence.weekday": []})), "", [])

    # validate_overrides against the seed file. Shape: a scalar where the seed is an array and the reverse,
    # null, a string and a boolean for a number, the array rules element by element.
    for (overrides, error) in [({"segments.w_office.presence.weekday": 0.5}, "wrong_shape"), ({"host.captive_share": [0.5]}, "wrong_shape"),
                               ({"host.captive_share": None}, "wrong_shape"), ({"host.captive_share": "0.5"}, "wrong_shape"),
                               ({"host.captive_share": True}, "wrong_shape"), ({"host.captive_share": {"value": 0.5}}, "wrong_shape"),
                               ({"segments.res.intent.weekday": []}, "wrong_shape"), ({"segments.res.intent.weekday": [0.1] * 25}, "wrong_shape"),
                               ({"segments.res.intent.weekday": [0.1] * 23 + [None]}, "wrong_shape"),
                               ({"segments.res.intent.weekday": [0.1] * 23 + [True]}, "wrong_shape"),
                               ({"segments.res.intent.weekday": [0.1] * 23 + [[0.1]]}, "wrong_shape"),
                               ({"segments.res.intent.weekday": [0.1] * 23 + ["0.1"]}, "wrong_shape"),
                               ({"segments.res.intent.weekday": [0.1] * 23 + [1.0000001]}, "out_of_bounds"),
                               ({"segments.res.intent.weekday": [-0.0000001] + [0.1] * 23}, "out_of_bounds"),
                               ({"segments.res.holiday_day_type.major": ""}, "not_allowed"), ({"segments.res.holiday_day_type.major": "Weekday"}, "not_allowed"),
                               ({"segments.res.holiday_day_type.major": ["weekday"]}, "wrong_shape"), ({"segments.res.holiday_day_type.major": None}, "wrong_shape"),
                               ({"host.captive_share": 0.0499999}, "out_of_bounds"), ({"host.captive_share": 1.0000001}, "out_of_bounds"),
                               ({"host.onsite_kitchen_weight": 10 ** 22}, "out_of_bounds")]:
        validate(overrides, bad(list(overrides.keys())[0], error))
    # Bounds are inclusive; a whole number is a number; minus zero is not below zero.
    validate({"host.captive_share": 1, "host.shared_kitchen_share": 0.01, "host.onsite_kitchen_weight": -0.0, "weather.floor": 1.0,
              "weather.pop_when_missing": 0, "events.attendance_haircut": 0.05, "segments.res.intent.weekday": [1.0] * 12 + [0] * 12,
              "segments.w_office.presence.weekday": [2] * 24, "segments.v_events.dow_factor": [3, 0, 3.0, 0.0, 1]}, [])
    # A whole number too large for a double (10^400: the other runtimes read the literal as infinity) is not a finite number.
    validate({"host.onsite_kitchen_weight": 10 ** 400}, bad("host.onsite_kitchen_weight", "wrong_shape"))
    validate({"segments.res.dow_factor": [1, 1, -(10 ** 400), 1, 1]}, bad("segments.res.dow_factor", "wrong_shape"))
    # Paths. The result is in byte order of the paths: "" first, digits, capitals, "_", small letters; "-" and "." before "_".
    validate({"b": 1, "a": 1, "B": 1, "a.b": 1, "a-b": 1, "a_b": 1, "10": 1, "9": 1, "": 1, "_": 1},
             [{"path": p, "error": "unknown_path"} for p in ["", "10", "9", "B", "_", "a", "a-b", "a.b", "a_b", "b"]])
    for path in [".", "host.", ".host", "host..captive_share", "toString", "constructor", "__proto__", "host.constructor", "host.__proto__",
                 "host.captive_share.value.x", "segments.w_office.presence.weekday.length", "holidays.rules.0", "vocabulary.segments.0", "Host.captive_share"]:
        validate({path: 0.5}, bad(path, "unknown_path"))
    for path in ["host.captive_share.min", "host.captive_share.max", "host.captive_share.scope", "host.captive_share.unit", "weather.precip_classes.order",
                 "weather.precip_classes.rows", "weather.precip_classes.rows.rain.match", "weather.precip_classes.match_rule", "segments.w_office.weak",
                 "segments.w_office.group", "segments.w_office.host_mode", "segments.w_office.index", "segments.w_office.lodes_cns",
                 "segments.w_office.holiday_day_type.allowed", "holidays.rules", "entry_format.scope", "weather.wind_bands.rows.calm.upper_mph"]:
        validate({path: 0.5}, bad(path, "not_a_seed"))
    for path in ["kernel", "host", "segments", "weather", "events", "host.exclusion_radius_m", "kernel.visibility.normal", "kernel.rival_weight.quick.day",
                 "model_version", "seeds_revision", "vocabulary.segments", "hours.daypart_of_hour", "traffic.dc", "scout.max_results",
                 "money.fuel_price_fallback.gasoline.R1Z", "place_types.rows.taproom.host_fit", "profile_defaults.daypart_fit.lunch"]:
        validate({path: 0.5}, bad(path, "not_overridable"))
    for path in ["events.p_buy", "segments.w_office.holiday_day_type", "segments.w_office.intent", "weather.temperature_bands", "weather.wind_bands",
                 "weather.precip_classes.rows.rain"]:
        validate({path: 0.5}, bad(path, "not_a_leaf"))

    # A seed tree of the case's own, for the rules revision 1 has no entry for: a boolean seed, a text
    # without a list of allowed values, a null, a table of tables, bounds on one side only, a scope and
    # bounds inherited from different levels, a nearer scope replacing a farther one, bounds of 2^53.
    syn = {"vocabulary": {"structural_keys": ["value", "unit", "scope", "min", "max", "allowed"]},
           "plain": {"scope": "owner", "unit": "anything", "flag": True, "name": "abc", "free": 7.5, "nothing": None,
                     "matrix": [[1.0, 2.0], [3.0, 4.0]],
                     "tags": {"allowed": ["a", "b"], "both": ["a", "a"], "one": "a"},
                     "wrapped": {"value": {"a": 1.0}},
                     "low_only": {"value": 5.0, "min": 0.0},
                     "high_only": {"value": 5.0, "max": 10.0}},
           "outer": {"scope": "owner", "min": 0.0, "inner": {"max": 5.0, "leaf": {"value": 3.0}, "shut": {"scope": "fixed", "leaf": 1.0}}},
           "closed": {"scope": "fixed", "leaf": 2.0, "open": {"scope": "owner", "leaf": 2.0}},
           "wide": {"scope": "owner", "whole": {"value": 0, "min": -9007199254740992, "max": 9007199254740992},
                    "real": {"value": 0.0, "min": -9007199254740992.0, "max": 9007199254740992.0}},
           "bare": {"leaf": 1.0}}

    def synthetic(overrides, want):
        c.known(c.add("g24", "validate_overrides", {"seeds": syn, "overrides": overrides}), "", want)

    synthetic({"plain.flag": False, "plain.name": "anything at all", "plain.free": -1.0e300, "plain.tags.both": ["b", "a"], "plain.tags.one": "b",
               "plain.low_only": 1.0e9, "plain.high_only": -1.0e9, "outer.inner.leaf": 5, "closed.open.leaf": 99}, [])
    for (path, value, error) in [("plain.flag", 1, "wrong_shape"), ("plain.flag", "yes", "wrong_shape"), ("plain.flag", None, "wrong_shape"),
                                 ("plain.name", 5, "wrong_shape"), ("plain.name", True, "wrong_shape"),
                                 ("plain.free", True, "wrong_shape"), ("plain.free", "7.5", "wrong_shape"),
                                 ("plain.nothing", 1, "wrong_shape"), ("plain.nothing", None, "wrong_shape"),
                                 ("plain.matrix", [[1.0, 2.0], [3.0, 4.0]], "wrong_shape"), ("plain.matrix", [1.0, 2.0], "wrong_shape"),
                                 ("plain.tags.both", ["a", "c"], "not_allowed"), ("plain.tags.both", ["a"], "wrong_shape"),
                                 ("plain.tags.both", ["a", 1], "wrong_shape"), ("plain.tags.both", "a", "wrong_shape"),
                                 ("plain.tags.one", "c", "not_allowed"), ("plain.tags", "a", "not_a_leaf"), ("plain.wrapped", 1, "not_a_leaf"),
                                 ("plain.wrapped.value", 1, "not_a_seed"), ("plain.unit", "other", "not_a_seed"),
                                 ("plain.low_only", -0.5, "out_of_bounds"), ("plain.high_only", 10.5, "out_of_bounds"),
                                 ("outer.inner.leaf", 5.5, "out_of_bounds"), ("outer.inner.leaf", -0.5, "out_of_bounds"),
                                 ("outer.inner.shut.leaf", 1.0, "not_overridable"), ("closed.leaf", 2.0, "not_overridable"),
                                 ("bare.leaf", 1.0, "not_overridable"), ("bare", 1.0, "not_overridable"), ("vocabulary.structural_keys", [], "not_overridable"),
                                 ("plain.missing", 1, "unknown_path"), ("plain.free.deeper", 1, "unknown_path"), ("plain.matrix.0", 1, "unknown_path")]:
        synthetic({path: value}, bad(path, error))
    # Bounds are compared as binary64 values. 2^53 + 1 is not a double: it is read as 2^53, which is the
    # bound itself, whether the seed file spells the bound as a whole number or as a real. 2^53 + 2 is a
    # double and lies outside.
    for leaf in ("wide.whole", "wide.real"):
        for value in (9007199254740992, 9007199254740993, -9007199254740992, -9007199254740993, 9007199254740992.0):
            synthetic({leaf: value}, [])
        for value in (9007199254740994, -9007199254740994, 9007199254740994.0, 2 ** 63, -(2 ** 64)):
            synthetic({leaf: value}, bad(leaf, "out_of_bounds"))


def build_cases():
    """Every golden case, in its fixed order. Pure: the same list every time."""
    c = _CaseList()
    fx = _Fixtures()
    _g01_rounding(c)
    _g02_dates(c)
    _g03_holidays(c)
    _g04_day_context(c, fx)
    _g05_curves(c, fx)
    _g06_geometry(c)
    _g07_rivals(c, fx)
    _g08_capture(c, fx)
    _g09_host(c, fx)
    _g10_weather(c, fx)
    _g11_hourly(c, fx)
    _g12_window(c, fx)
    _g13_week(c, fx)
    _g14_ranges(c, fx)
    _g15_money(c, fx)
    _g16_driving(c, fx)
    _g17_timeline(c, fx)
    _g18_day_plan(c, fx)
    _g19_calibration(c, fx)
    _g20_events(c, fx)
    _g21_suggestions(c, fx)
    _g22_scouting(c, fx)
    _g23_fast_path(c, fx)
    _g24_seeds(c, fx)
    return c


# -------------------------------------------------------------------------------------------------
# Running a case: dispatch by canonical name, pass the arguments through (8.1)
# -------------------------------------------------------------------------------------------------

CATALOGUE = {
    "round_half_away": round_half_away, "qkey": qkey, "seed": seed, "validate_overrides": validate_overrides,
    "est_fixed": est_fixed, "est_levels": est_levels, "weakest": weakest, "est_sum": est_sum,
    "days_from_civil": days_from_civil, "civil_from_days": civil_from_days, "parse_date": parse_date,
    "format_date": format_date, "day_of_week": day_of_week, "add_days": add_days, "nth_weekday": nth_weekday,
    "last_weekday": last_weekday, "federal_holidays": federal_holidays, "holiday_on": holiday_on,
    "day_context": day_context, "typical_context": typical_context, "make_context": make_context,
    "hour_weights": hour_weights, "expand_curves": expand_curves,
    "haversine_m": haversine_m, "walk_weight": walk_weight,
    "rivals_at_origin": rivals_at_origin, "host_exclusion": host_exclusion, "host_link_point": host_link_point,
    "capture_at_point": capture_at_point, "host_capture": host_capture, "weather_multiplier": weather_multiplier,
    "calibration_factor": calibration_factor, "hourly_orders": hourly_orders, "vectors_match": vectors_match,
    "window_orders": window_orders, "week_strip": week_strip, "best_windows": best_windows,
    "evidence_from": evidence_from, "interval": interval, "interval_capped": interval_capped,
    "stop_money_at": stop_money_at, "stop_money": stop_money, "unit_margins": unit_margins,
    "break_even_orders": break_even_orders, "day_costs": day_costs,
    "fallback_leg": fallback_leg, "traffic_factor": traffic_factor, "leg_minutes": leg_minutes,
    "required_leg_keys": required_leg_keys, "build_timeline": build_timeline, "evaluate": evaluate, "day_plan": day_plan,
    "calibrate": calibrate, "accuracy_report": accuracy_report, "event_orders": event_orders,
    "catering_money": catering_money, "suggest_day": suggest_day, "suggest_week": suggest_week,
    "scout_estimate": scout_estimate, "strip_from_rows": strip_from_rows, "scout_rank": scout_rank,
    "map_weight_rows": map_weight_rows, "cell_scores": cell_scores, "score_byte": score_byte,
}

# Functions of the catalogue that have no golden case of their own: none. (The two internal ones,
# make_context and evaluate, have a few direct cases besides what day_context / typical_context and
# day_plan / suggest_day exercise.)
NO_DIRECT_CASES = []

MODEL_ERRORS = ["invalid_date", "invalid_window", "missing_context"]


def _plain(x):
    """What a result looks like after a trip through JSON: tuples are arrays, nothing else changes."""
    return json.loads(json.dumps(x, allow_nan=False))


def call(function, args):
    """Run one case the way a port does: the function by its name, args as keyword arguments. `A` arrives
    as { overrides, region } and gets the seed file; validate_overrides gets the seed file as `seeds`
    unless the case carries a seed tree of its own; a model error becomes { "error": code }."""
    kwargs = dict(args)
    if "A" in kwargs:
        kwargs["A"] = make_assumptions(kwargs["A"]["overrides"], kwargs["A"]["region"])
    if function == "validate_overrides" and "seeds" not in kwargs:
        kwargs["seeds"] = SEEDS
    try:
        return _plain(CATALOGUE[function](**kwargs))
    except ModelError as error:
        return {"error": error.code}


def numbers_match(a, b):
    """1.4: compared as doubles whatever their JSON spelling. Integral values must be equal; otherwise
    abs(a - b) <= 1e-9 * max(1, abs(a), abs(b))."""
    if a == b:
        return True
    if float(a).is_integer() and float(b).is_integer():
        return False
    return abs(a - b) <= 1e-9 * max(1.0, abs(a), abs(b))


def differences(a, b, path, out, reals=True):
    """Append to `out` every place where b differs from a under the golden-case rule. With reals=False
    only what is discrete is compared: structure, strings, booleans, nulls and integers."""
    if isinstance(a, bool) or isinstance(b, bool) or a is None or b is None or isinstance(a, str) or isinstance(b, str):
        if type(a) is not type(b) or a != b:
            out.append("%s: %r != %r" % (path, a, b))
    elif isinstance(a, (int, float)) and isinstance(b, (int, float)):
        if reals:
            if not numbers_match(a, b):
                out.append("%s: %r != %r" % (path, a, b))
        elif isinstance(a, int) != isinstance(b, int) or (isinstance(a, int) and a != b):
            out.append("%s: %r != %r" % (path, a, b))
    elif isinstance(a, list) and isinstance(b, list):
        if len(a) != len(b):
            out.append("%s: %d items != %d items" % (path, len(a), len(b)))
        else:
            for i in range(len(a)):
                differences(a[i], b[i], "%s.%d" % (path, i), out, reals)
    elif isinstance(a, dict) and isinstance(b, dict):
        if sorted(a.keys()) != sorted(b.keys()):
            out.append("%s: keys %s != %s" % (path, sorted(a.keys()), sorted(b.keys())))
        else:
            for key in sorted(a.keys()):
                differences(a[key], b[key], "%s.%s" % (path, key), out, reals)
    else:
        out.append("%s: %s != %s" % (path, type(a).__name__, type(b).__name__))


def _at(value, path):
    """Follow a dotted path ("orders.value", "hours.1.result.orders", "" for the whole value)."""
    if path == "":
        return value
    for key in path.split("."):
        value = value[int(key)] if isinstance(value, list) else value[key]
    return value


# -------------------------------------------------------------------------------------------------
# The margin rule (8.1): no case may hang on the last bits of exp, ln, sin, cos or asin
# -------------------------------------------------------------------------------------------------

def _other_math(eps, salt):
    """Five stand-ins for the library functions that IEEE-754 does not pin down: each returns the true
    value moved by a relative amount in [-eps, eps] that depends on the argument only (a different, but
    self-consistent, math library). Arguments with an exact answer (exp(0), ln(1), sin(0), cos(0),
    asin(0)) keep it."""
    import hashlib
    import struct

    def stand_in(true_function, tag):
        def function(x):
            y = true_function(x)
            if x == 0.0 or y == 0.0:
                return y
            digest = hashlib.blake2b(struct.pack("<dqq", x, tag, salt), digest_size=8).digest()
            u = int.from_bytes(digest, "little") / 9223372036854775808.0 - 1.0
            return y * (1.0 + eps * u)
        return function

    return (stand_in(math.exp, 1), stand_in(math.log, 2), stand_in(math.sin, 3), stand_in(math.cos, 4),
            stand_in(math.asin, 5))


def _call_with_math(functions, function, args):
    global exp, ln, sin, cos, asin
    saved = (exp, ln, sin, cos, asin)
    (exp, ln, sin, cos, asin) = functions
    try:
        return call(function, args)
    finally:
        (exp, ln, sin, cos, asin) = saved


# -------------------------------------------------------------------------------------------------
# The self-test
# -------------------------------------------------------------------------------------------------

class _Report:
    def __init__(self):
        self.checks = 0
        self.problems = []

    def check(self, ok, message):
        self.checks += 1
        if not ok:
            self.problems.append(message)

    def close(self, got, want, tolerance, message):
        self.check(abs(got - want) <= tolerance, "%s: %r, expected %r" % (message, got, want))

    def problem(self, message):
        self.problems.append(message)


def run_cases(cl, report, thorough):
    """Run every case through call(). Returns { case id: result }. With thorough=True each case is also
    checked for purity (arguments untouched), determinism (a second run is identical) and the margin rule."""
    results = {}
    rounds = [(_other_math(1e-6, salt), False, "1e-6") for salt in (11, 12, 13, 14)]    # nothing discrete may change
    rounds.append((_other_math(1e-13, 15), True, "1e-13"))                               # nothing may leave the tolerance
    for case in cl.cases:
        before = json.dumps(case["args"], sort_keys=True, allow_nan=False)
        args = json.loads(before)                   # exactly what a port reads from the file
        try:
            result = call(case["function"], args)
        except Exception as error:                  # a crash is a problem, not a traceback
            report.problem("%s %s: raised %s: %s" % (case["id"], case["function"], type(error).__name__, error))
            continue
        results[case["id"]] = result
        if not thorough:
            continue
        if json.dumps(args, sort_keys=True, allow_nan=False) != before:
            report.problem("%s %s: the arguments were modified" % (case["id"], case["function"]))
        again = call(case["function"], json.loads(before))
        if json.dumps(again, sort_keys=True) != json.dumps(result, sort_keys=True):
            report.problem("%s %s: a second run gave a different result" % (case["id"], case["function"]))
        if case["id"].startswith("g01"):
            continue                                # exactly rounded operations on literal arguments only
        for (functions, reals, label) in rounds:
            other = _call_with_math(functions, case["function"], json.loads(before))
            found = []
            differences(result, other, "", found, reals)
            if len(found) > 0:
                report.problem("%s %s: margin rule (library functions moved by %s): %s"
                               % (case["id"], case["function"], label, found[0]))
    return results


def _estimates_in(value, path, out):
    """Every Estimate inside a result, with its path."""
    if isinstance(value, dict):
        if "value" in value and "low" in value and "high" in value and "confidence" in value:
            out.append((path, value))
        for key in sorted(value.keys()):
            _estimates_in(value[key], path + "." + key, out)
    elif isinstance(value, list):
        for i in range(len(value)):
            _estimates_in(value[i], "%s.%d" % (path, i), out)


def _records_in(value, key, out):
    """Every dict inside a result that has the given key."""
    if isinstance(value, dict):
        if key in value:
            out.append(value)
        for name in sorted(value.keys()):
            _records_in(value[name], key, out)
    elif isinstance(value, list):
        for item in value:
            _records_in(item, key, out)


def _clock(minute):
    m = mod_floor(minute, 1440)
    return "%d:%02d" % (floor_div(m, 60), mod_floor(m, 60))


def _check_seeds(t):
    S = SEEDS
    v = S["vocabulary"]
    t.check(S["model_version"] == MODEL_VERSION, "seeds: model_version")
    for (name, code) in (("segments", SEGMENTS), ("regimes", REGIMES), ("rival_kinds", RIVAL_KINDS), ("day_types", DAY_TYPES),
                         ("dayparts", DAYPARTS), ("visibility_levels", VISIBILITY_LEVELS), ("confidence_labels", CONFIDENCE_LABELS),
                         ("dow", DOW_KEYS)):
        t.check(v[name] == code, "seeds: vocabulary.%s differs from the constants in code" % name)
    t.check(v["place_types"] == S["place_types"]["order"] and sorted(v["place_types"]) == sorted(S["place_types"]["rows"].keys()),
            "seeds: place type lists disagree")
    for (name, literal) in (("earth_radius_m", EARTH_RADIUS_M), ("pi", PI), ("ln2", LN2), ("z80", Z80),
                            ("meters_per_mile", METERS_PER_MILE), ("round_half", ROUND_HALF), ("qkey_scale", QKEY_SCALE)):
        t.check(S["constants"][name]["value"] == literal, "seeds: constants.%s differs from the literal in code" % name)
    t.check(PI == math.pi and LN2 == math.log(2.0), "constants: pi or ln2 literal is not the double nearest the true value")
    t.check(len(S["hours"]["regime_of_hour"]["value"]) == 24 and len(S["hours"]["daypart_of_hour"]["value"]) == 24, "seeds: hour tables")
    for h in range(24):
        t.check(S["hours"]["regime_of_hour"]["value"][h] == ("day" if 5 <= h <= 15 else "eve"), "seeds: regime of hour %d" % h)
    for s in range(NSEG):
        seg = S["segments"][SEGMENTS[s]]
        t.check(seg["index"] == s, "seeds: index of %s" % SEGMENTS[s])
        t.check(seg["group"] in ("residents", "workers", "visitors") and seg["host_mode"] in ("open", "captive"), "seeds: group/host_mode of %s" % SEGMENTS[s])
        peak = 0.0
        for day_type in DAY_TYPES:
            p = seg["presence"][day_type]
            q = seg["intent"][day_type]
            t.check(len(p) == 24 and len(q) == 24, "seeds: %s %s curves are not 24 long" % (SEGMENTS[s], day_type))
            t.check(min(p) >= 0.0 and max(p) <= 1.0, "seeds: %s presence %s outside [0, 1]" % (SEGMENTS[s], day_type))
            t.check(min(q) >= 0.0 and max(q) <= 0.5, "seeds: %s intent %s outside [0, 0.5]" % (SEGMENTS[s], day_type))
            peak = max(peak, max(p))
        if seg["group"] == "visitors":
            t.check(peak == 1.0, "seeds: %s presence does not peak at exactly 1.0" % SEGMENTS[s])
        if seg["group"] == "workers":
            t.check(0.34 <= max(seg["presence"]["weekday"]) <= 0.52, "seeds: %s weekday presence peak outside 0.34..0.52" % SEGMENTS[s])
        t.check(len(seg["dow_factor"]["value"]) == 5, "seeds: %s dow_factor" % SEGMENTS[s])
        t.check(max(seg["presence"]["weekday"]) * max(seg["dow_factor"]["value"]) <= 1.2 + 1e-12, "seeds: %s presence x factor above 1.2" % SEGMENTS[s])
        for cls in ("major", "minor"):
            t.check(seg["holiday_day_type"][cls] in DAY_TYPES, "seeds: %s holiday day type" % SEGMENTS[s])
    t.check([SEGMENTS[s] for s in range(NSEG) if S["segments"][SEGMENTS[s]]["weak"]] == ["v_campus", "v_hospital", "v_transit"], "seeds: weak segments")
    t.check([SEGMENTS[s] for s in range(NSEG) if S["segments"][SEGMENTS[s]]["host_mode"] == "captive"] == ["v_nightlife", "v_events"], "seeds: captive segments")
    for kind in RIVAL_KINDS:
        t.check("day" in S["kernel"]["rival_weight"][kind] and "eve" in S["kernel"]["rival_weight"][kind], "seeds: rival weight %s" % kind)
    for name in S["place_types"]["order"]:
        row = S["place_types"]["rows"][name]
        t.check(row["visitor_segment"] in SEGMENTS + [None] and row["host_segment"] in SEGMENTS + [None]
                and row["rival_kind"] in RIVAL_KINDS + [None] and row["kitchen_default"] in ("yes", "no")
                and 0.0 <= row["host_fit"] <= 1.0 and row["default_size"] >= 0.0, "seeds: place type %s" % name)
    t.check(len(S["holidays"]["rules"]) == 12, "seeds: twelve holiday rules")
    for name in ("dc", "us_mean"):
        matrix = S["traffic"][name]["value"]
        total = 0.0
        for row in matrix:
            for x in row:
                total += x
        t.check(len(matrix) == 7 and min(len(row) for row in matrix) == 24 and max(len(row) for row in matrix) == 24, "seeds: traffic.%s shape" % name)
        t.check(round_half_away(total / 168.0, 3) == S["traffic"][name + "_typical"]["value"], "seeds: traffic.%s_typical is not the mean of the matrix" % name)
    for (name, dow, values) in (("dc", 0, [1.04, 1.43, 1.25, 1.29, 1.38, 1.59, 1.2]), ("dc", 3, [1.04, 1.54, 1.31, 1.32, 1.44, 1.72, 1.23]),
                                ("dc", 5, [1.07, 1.14, 1.26, 1.36, 1.4, 1.4, 1.24]), ("dc", 6, [1.08, 1.09, 1.18, 1.28, 1.32, 1.28, 1.19]),
                                ("us_mean", 1, [1.04, 1.54, 1.29, 1.31, 1.4, 1.68, 1.19]), ("us_mean", 6, [1.06, 1.08, 1.17, 1.27, 1.29, 1.26, 1.17])):
        t.check([S["traffic"][name]["value"][dow][h] for h in (3, 8, 10, 12, 14, 17, 20)] == values, "seeds: traffic.%s row %d check values (2.3)" % (name, dow))
    for table in ("temperature_bands", "precip_classes", "wind_bands"):
        node = S["weather"][table]
        t.check(sorted(node["order"]) == sorted(node["rows"].keys()), "seeds: weather.%s order and rows disagree" % table)
        for band in node["order"]:
            t.check(node["rows"][band]["captive"] >= node["rows"][band]["open"], "seeds: weather.%s.%s captive below open" % (table, band))
    t.check(S["weather"]["precip_classes"]["order"][-1] == "dry" and S["weather"]["precip_classes"]["rows"]["dry"]["match"] == [], "seeds: dry is the last class and matches nothing")
    U = S["uncertainty"]
    t.check(U["label_good_below"]["value"] < U["label_fair_below"]["value"] < U["label_rough_below"]["value"], "seeds: label thresholds out of order")
    prior = sqrt(U["sd_truck"]["value"] * U["sd_truck"]["value"] + U["sd_spot"]["value"] * U["sd_spot"]["value"] + U["sd_day"]["value"] * U["sd_day"]["value"])
    t.check(U["label_fair_below"]["value"] <= prior < U["label_rough_below"]["value"], "seeds: before any log the label must be rough (DECISIONS 7.7)")
    t.check(validate_overrides(S, {}) == [], "seeds: an empty override map is valid")

    def scopes(node, path, inherited):
        if isinstance(node, dict):
            scope = node.get("scope", inherited) if isinstance(node.get("scope", inherited), str) else inherited
            if "tag" in node and path != "entry_format":
                t.check(node["tag"] in ("measured", "derived", "assumed", "tuned"), "seeds: tag at %s" % path)
            if "scope" in node and path != "entry_format":
                t.check(node["scope"] in ("build", "fixed", "owner", "profile_default"), "seeds: scope at %s" % path)
            for key in node:
                scopes(node[key], path + "." + key if path else key, scope)

    scopes(S, "", None)


def _check_dates(t):
    import datetime                                 # self-test only: an independent calendar to check against

    epoch = datetime.date(1970, 1, 1).toordinal()
    ok_round_trip = True
    ok_calendar = True
    ok_weekday = True
    for z in range(0, 84006):
        (y, m, d) = civil_from_days(z)
        if days_from_civil(y, m, d) != z:
            ok_round_trip = False
        other = datetime.date.fromordinal(epoch + z)
        if (other.year, other.month, other.day) != (y, m, d):
            ok_calendar = False
        if mod_floor(z + 3, 7) != other.weekday():
            ok_weekday = False
    t.check(ok_round_trip, "dates: civil_from_days and days_from_civil are not inverse over 1970..2199")
    t.check(ok_calendar, "dates: civil_from_days disagrees with the proleptic Gregorian calendar")
    t.check(ok_weekday, "dates: day of week disagrees with the calendar (0 = Monday)")
    t.check(civil_from_days(84005) == (2199, 12, 31) and days_from_civil(1970, 1, 1) == 0, "dates: range ends")
    t.check(day_of_week("2026-10-08") == 3 and add_days("2026-12-30", 3) == "2027-01-02", "dates: document examples")
    for bad in ("2026-02-30", "1969-12-31", "2200-01-01", "2026-1-05", "20261008", None, 20261008):
        try:
            parse_date(bad)
            t.check(False, "dates: %r was accepted" % (bad,))
        except ModelError as error:
            t.check(error.code == "invalid_date", "dates: wrong error for %r" % (bad,))

    # Observed dates of the federal holidays as OPM publishes them, 2025-2030 (recon 09 section 7).
    opm = {2025: ["2025-01-01", "2025-01-20", "2025-02-17", "2025-05-26", "2025-06-19", "2025-07-04", "2025-09-01", "2025-10-13", "2025-11-11", "2025-11-27", "2025-12-25"],
           2026: ["2026-01-01", "2026-01-19", "2026-02-16", "2026-05-25", "2026-06-19", "2026-07-03", "2026-09-07", "2026-10-12", "2026-11-11", "2026-11-26", "2026-12-25"],
           2027: ["2027-01-01", "2027-01-18", "2027-02-15", "2027-05-31", "2027-06-18", "2027-07-05", "2027-09-06", "2027-10-11", "2027-11-11", "2027-11-25", "2027-12-24"],
           2028: ["2027-12-31", "2028-01-17", "2028-02-21", "2028-05-29", "2028-06-19", "2028-07-04", "2028-09-04", "2028-10-09", "2028-11-10", "2028-11-23", "2028-12-25"],
           2029: ["2029-01-01", "2029-01-15", "2029-02-19", "2029-05-28", "2029-06-19", "2029-07-04", "2029-09-03", "2029-10-08", "2029-11-12", "2029-11-22", "2029-12-25"],
           2030: ["2030-01-01", "2030-01-21", "2030-02-18", "2030-05-27", "2030-06-19", "2030-07-04", "2030-09-02", "2030-10-14", "2030-11-11", "2030-11-28", "2030-12-25"]}
    off = {"inauguration_day": False}
    on = {"inauguration_day": True}
    for year in sorted(opm.keys()):
        t.check([h["observed"] for h in federal_holidays(year, off)] == opm[year], "holidays: %d differs from the OPM list" % year)
        for date in opm[year]:
            t.check(holiday_on(date, off) is not None, "holidays: %s is not found by holiday_on" % date)
    differing = []
    for year in (2026, 2027, 2028, 2029):
        for h in federal_holidays(year, on):
            if h["observed"] != h["date"]:
                differing.append((h["id"], h["date"], h["observed"]))
    t.check(differing == [("independence", "2026-07-04", "2026-07-03"), ("juneteenth", "2027-06-19", "2027-06-18"),
                          ("independence", "2027-07-04", "2027-07-05"), ("christmas", "2027-12-25", "2027-12-24"),
                          ("new_year", "2028-01-01", "2027-12-31"), ("veterans", "2028-11-11", "2028-11-10"),
                          ("inauguration", "2029-01-20", None), ("veterans", "2029-11-11", "2029-11-12")],
            "holidays: the table of observed dates in 4.1")
    workdays_off = 0
    for z in range(days_from_civil(2026, 1, 1), days_from_civil(2027, 1, 1)):
        date = _date_of_day(z)
        h = holiday_on(date, on)
        if h is not None:
            workdays_off += 1
            t.check(day_of_week(date) <= 4, "holidays: %s is observed on a weekend" % date)
    t.check(workdays_off == 11, "holidays: 2026 has eleven observed federal holidays")


def _check_model(t, fx):
    """Monotonicity, bounds, conservation and symmetry on the fixtures of the golden cases."""
    A = fx.A
    P = fx.profile

    # Rounding.
    t.check(math.copysign(1.0, round_half_away(-0.004, 2)) == 1.0, "rounding: negative zero returned")
    for (x, decimals) in ((2.675, 2), (-17.345, 2), (65.533, 0), (0.1078, 0)):
        r = round_half_away(x, decimals)
        t.check(round_half_away(r, decimals) == r, "rounding: not idempotent at %r" % x)
        t.check(round_half_away(-x, decimals) == -r, "rounding: not symmetric at %r" % x)

    # Curves.
    E = expand_curves(A)
    worst = 0.0
    for s in range(NSEG):
        for how in range(168):
            worst = max(worst, E["presence"][s][how])
            t_ok = 0.0 <= E["intent"][s][how] <= 0.5
            if not t_ok:
                t.problem("curves: intent168[%s][%d] outside [0, 0.5]" % (SEGMENTS[s], how))
    t.check(worst == E["presence"][10][17] == 1.15, "curves: the largest presence of the week is v_leisure Monday 17:00 = 1.15")
    t.check(E["presence"][1][5 * 24 + 10] == SEEDS["segments"]["w_office"]["presence"]["saturday"][10], "curves: Saturday arrays are used as given")

    # Geometry: symmetry, the triangle inequality, monotone decay.
    points = [(38.96, -77.36), (38.9696, -77.3861), (39.003, -77.405), (38.9072, -77.0369), (38.9, -77.03), (-33.8688, 151.2093)]
    for i in range(len(points)):
        for j in range(len(points)):
            d_ij = haversine_m(points[i][0], points[i][1], points[j][0], points[j][1])
            d_ji = haversine_m(points[j][0], points[j][1], points[i][0], points[i][1])
            t.check(abs(d_ij - d_ji) <= 1e-9 * max(1.0, d_ij), "geometry: haversine_m is not symmetric (%d, %d)" % (i, j))
            t.check(d_ij >= 0.0 and (i != j or d_ij == 0.0), "geometry: distance below zero or a point away from itself")
            for k in range(len(points)):
                d_ik = haversine_m(points[i][0], points[i][1], points[k][0], points[k][1])
                d_kj = haversine_m(points[k][0], points[k][1], points[j][0], points[j][1])
                if d_ij > d_ik + d_kj + 1e-6:
                    t.problem("geometry: triangle inequality (%d, %d, %d)" % (i, k, j))
    t.close(haversine_m(0.0, 0.0, 0.0, 180.0), 3.141592653589793 * 6371008.8, 1e-6, "geometry: half the circumference")
    previous = 1.0
    monotone = True
    for step in range(0, 1301):
        f = walk_weight(A, float(step))
        if f > previous or f < 0.0 or f > 1.0 or (step > 1200 and f != 0.0) or (step <= 1200 and f <= 0.0):
            monotone = False
        previous = f
    t.check(monotone, "geometry: walk_weight must fall from 1 to exp(-3) and be 0 beyond the cutoff")

    # Capture: conservation, bounds, monotone in visibility and in rivals, exclusion bookkeeping.
    total_jobs = 0.0
    for source in fx.sources:
        total_jobs += source["base"][1]
    t.check(fx.vec["within"][1] == total_jobs == 2000.0, "capture: within must hold every job inside the cutoff")
    for v in (fx.vec, fx.vec_hidden, fx.vec_prominent, fx.vec_host600, fx.vec_mix):
        for s in range(NSEG):
            ok = 0.0 <= v["capture"]["day"][s] <= v["nearby"][s] + 1e-9 and v["nearby"][s] <= v["within"][s] + 1e-9 and 0.0 <= v["capture"]["eve"][s] <= v["nearby"][s] + 1e-9
            t.check(ok, "capture: 0 <= capture <= nearby <= within fails for %s" % SEGMENTS[s])
    t.check(fx.vec_hidden["capture"]["day"][1] < fx.vec["capture"]["day"][1] < fx.vec_prominent["capture"]["day"][1], "capture: must rise with visibility")
    t.check(fx.vec["capture"]["day"][1] < fx.vec_no_rivals["capture"]["day"][1], "capture: must fall when rival outlets are added")
    t.check(fx.vec_mix["rivals"]["day"] != fx.vec_mix["rivals"]["eve"], "capture: the mixed layout must tell day from evening")
    t.close(fx.vec["within"][1] - fx.vec_host600["within"][1], fx.vec_host600["excluded_amount"], 1e-9, "capture: within must drop by what the exclusion took")
    t.check(fx.vec_host600["excluded_amount"] == 600.0, "capture: the exclusion of 600 jobs")
    too_much = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", fx.sources, fx.outlets, {"point_ids": [], "segment": "w_office", "amount": 5000.0})
    t.check(too_much["excluded_amount"] == 1000.0 and too_much["within"][1] == 1000.0, "capture: an exclusion larger than what is within 250 m takes exactly that")
    shuffled = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", list(reversed(fx.sources)), list(reversed(fx.outlets)), _no_exclusion())
    t.check(json.dumps(shuffled, sort_keys=True) == json.dumps(fx.vec, sort_keys=True), "capture: the order of the input lists must not matter")
    mixed_total = 0.0
    for source in fx.mix_sources:
        if source["id"] != "b500":
            for s in range(NSEG):
                mixed_total += source["base"][s]
    got = 0.0
    for s in range(NSEG):
        got += fx.vec_mix["within"][s]
    t.close(got, mixed_total, 1e-9, "capture: within of the mixed layout must hold every point but the one beyond the cutoff")
    t.check(fx.vec_mix["points_used"] == len(fx.mix_sources) - 1, "capture: points_used of the mixed layout")
    # A venue host left unlinked is linked to the venue's own point; the vectors are then those of the linked host.
    linked_id = host_link_point(A, TRUCK_LAT, TRUCK_LNG, fx.tap_host, fx.pw1)
    relinked = capture_at_point(A, TRUCK_LAT, TRUCK_LNG, "normal", fx.pw1, [], host_exclusion(A, dict(fx.tap_host, point_id=linked_id)))
    t.check(linked_id == "pw1" and json.dumps(relinked, sort_keys=True) == json.dumps(fx.vec_pw1_linked, sort_keys=True), "capture: host_link_point then host_exclusion must give the linked host's vectors")
    t.check(fx.vec_pw1_linked["points_used"] == 0 and fx.vec_pw1_unlinked["points_used"] == 1, "capture: the linked venue point must leave the catchment")

    # Host term: linear in size, a kitchen lowers the share, shares stay inside (0, 1).
    for (host, visibility, rivals_here) in ((fx.tap_host, "normal", fx.zero["rivals"]), (fx.office_host, "prominent", fx.vec["rivals"]),
                                            (_host("v_shopping", 200.0), "hidden", fx.vec_mix["rivals"])):
        with_kitchen = host_capture(A, dict(host, only_food=False), visibility, rivals_here)
        without = host_capture(A, host, visibility, rivals_here)
        double = host_capture(A, dict(host, size=2.0 * host["size"]), visibility, rivals_here)
        for regime in REGIMES:
            t.check(0.0 < with_kitchen["share"][regime] < without["share"][regime] < 1.0, "host: shares must satisfy 0 < with kitchen < only food < 1")
            t.close(double[regime], 2.0 * without[regime], 1e-9, "host: capture must be linear in size")

    # Weather: bounded below by the floor, never above 1 at revision 1, monotone in the probability.
    for setting in ("open", "captive"):
        previous = 2.0
        monotone = True
        for prob in range(0, 101, 5):
            m = weather_multiplier(A, _fc(12, 55, prob, "Rain", 8), setting)["multiplier"]
            if m > previous or m < seed(A, "weather.floor") or m > 1.0:
                monotone = False
            previous = m
        t.check(monotone, "weather: the %s multiplier must fall as rain becomes more likely" % setting)
    t.check(weather_multiplier(A, _fc(12, 62, 0, "Sunny", 3), "open")["multiplier"] == 1.0, "weather: a fine day is 1.0")

    # Orders: more host, more orders; capacity bounds; a split window adds up.
    previous = -1.0
    for size in (0.0, 40.0, 80.0, 120.0, 160.0, 400.0, 1000.0):
        w = window_orders(A, P, _terms(host=_host("v_nightlife", size)), fx.zero, None, fx.sat, None, 1020, 1200)
        t.check(w["orders"]["value"] >= previous and w["orders"]["value"] <= w["capacity_total"], "orders: must rise with host size and stay within capacity")
        previous = w["orders"]["value"]
    for capacity in (0.0, 10.0, 20.0, 45.0, 90.0):
        w = window_orders(A, _profile(capacity_orders_per_hour=capacity), fx.terms_open, fx.vec, None, fx.thu, None, 660, 840)
        t.check(w["orders"]["value"] <= 3.0 * capacity + 1e-9 and w["orders"]["high"] <= 3.0 * capacity + 1e-9, "orders: value and high must respect capacity %r" % capacity)
    for (a, b, cc) in ((660, 720, 840), (690, 745, 825), (1290, 1440, 1500), (600, 601, 900)):
        whole = window_orders(A, P, _terms(host=fx.tap_host), fx.vec_mix, None, fx.fri, fx.sat, a, cc)
        left = window_orders(A, P, _terms(host=fx.tap_host), fx.vec_mix, None, fx.fri, fx.sat, a, b)
        right = window_orders(A, P, _terms(host=fx.tap_host), fx.vec_mix, None, fx.fri, fx.sat, b, cc)
        t.close(whole["orders"]["value"], left["orders"]["value"] + right["orders"]["value"], 1e-9, "orders: a window split at minute %d must add up" % b)
        t.close(whole["demand_adj"], left["demand_adj"] + right["demand_adj"], 1e-9, "orders: demand of a split window must add up")
    strip = week_strip(A, P, fx.terms_tap, fx.zero, None)
    total = 0.0
    nonzero = 0
    for x in strip:
        total += x
        if x > 0.0:
            nonzero += 1
    t.check(nonzero == 98, "orders: the taproom week strip has 98 non-zero hours (4.7), got %d" % nonzero)
    t.close(total, 441.413, 0.00005, "orders: the taproom week strip sums to 441.4130 (4.7)")
    for (terms, vectors) in ((fx.terms_tap, fx.zero), (fx.terms_open, fx.vec), (_terms(host=_host("v_shopping", 200.0, "default", False)), fx.vec_mix)):
        slow = week_strip(A, P, terms, vectors, None)
        fast = strip_from_rows(A, P, terms, vectors, map_weight_rows(A, P, None))
        found = []
        differences(slow, fast, "", found)
        t.check(len(found) == 0, "fast path: strip_from_rows differs from week_strip" + (": " + found[0] if found else ""))
        for dow in (1, 5):
            for hour in (12, 18):
                direct = hourly_orders(A, P, terms, vectors, None, typical_context(A, dow), hour)["orders"]
                t.close(slow[dow * 24 + hour], direct, 0.0, "orders: week_strip must be hourly_orders of the typical week")
    rows = map_weight_rows(A, P, None)
    features = list(fx.vec["capture"]["day"]) + list(fx.vec["capture"]["eve"]) + list(fx.vec["nearby"]) + [fx.vec["rivals"]["day"], fx.vec["rivals"]["eve"]]
    for how in (84, 90, 132):
        regime = seed(A, "hours.regime_of_hour")[mod_floor(how, 24)]
        cell = cell_scores(features, 1, rows["w_opp"][how], rows["w_people"][how], regime, 45.0)
        hr = hourly_orders(A, P, fx.terms_open, fx.vec, None, typical_context(A, floor_div(how, 24)), mod_floor(how, 24))
        t.close(cell["opportunity"][0], hr["orders"], 1e-9 * max(1.0, hr["orders"]), "fast path: opportunity must equal hourly_orders at how %d" % how)
        t.close(cell["people"][0], hr["segments"][1]["nearby_present"], 1e-9 * max(1.0, cell["people"][0]), "fast path: people must equal nearby x presence at how %d" % how)
        t.check(cell["competition"][0] == fx.vec["rivals"][regime], "fast path: competition is the rival weight of the regime")
    previous = -1
    for step in range(0, 500):
        byte = score_byte(step * 0.1, 45.0)
        t_ok = isinstance(byte, int) and 0 <= byte <= 255 and byte >= previous
        if not t_ok:
            t.problem("fast path: score_byte must be a non-decreasing integer in 0..255 (x = %r)" % (step * 0.1))
        previous = byte
    t.check(score_byte(0.0, 45.0) == 0 and score_byte(45.0, 45.0) == 255 and score_byte(1e9, 45.0) == 255, "fast path: byte ends")

    # Ranges: narrower with evidence, capped ones never beyond capacity, labels in order.
    previous = 1.0
    for weight in (0.0, 1.0, 5.0, 20.0, 100.0):
        (e, spread) = interval(A, 60.0, _evidence(truck_weight=weight, spot_weight=weight))
        t.check(spread["sigma_model"] < previous and e["low"] < 60.0 < e["high"], "ranges: the spread must narrow as services are logged")
        previous = spread["sigma_model"]
    labels = [interval(A, 60.0, ev)[0]["confidence"] for ev in (_evidence(weak_share=1.0), _evidence(), _evidence(truck_weight=10.0),
                                                              _evidence(truck_weight=24.0, spot_weight=12.0, resid_sd=0.18, resid_weight=22.0), _evidence(fixed=True))]
    t.check(labels == CONFIDENCE_LABELS, "ranges: one piece of evidence per label, weakest first: %r" % (labels,))
    (plain, spread) = interval(A, 40.0, _evidence())
    (capped, spread) = interval_capped(A, [10.0, 20.0, 10.0], [45.0, 45.0, 45.0], _evidence())
    t.close(capped["low"], plain["low"], 1e-9, "ranges: with no hour near capacity interval_capped is interval (low)")
    t.close(capped["high"], plain["high"], 1e-9, "ranges: with no hour near capacity interval_capped is interval (high)")
    (sixty, spread) = interval(A, 60.0, _evidence())
    t.close(sixty["low"] / 60.0, 1.0 / 1.8, 0.02, "ranges: before logs the low end is roughly value / 1.8 (section 6 item 2)")
    t.close(sixty["high"] / 60.0, 1.55, 0.02, "ranges: before logs the high end is roughly value x 1.55 (section 6 item 2)")

    # Money: lines add up; break-even really breaks even; monotone in costs.
    for terms in (fx.terms_open, _terms(fee_pct=0.1, fee_min=75.0), _terms(fee_flat=100.0), _terms(fee_flat=50.0, fee_pct=0.05, fee_min=100.0)):
        for profile in (P, _profile(tips_include=True)):
            lines = stop_money_at(profile, terms, 57.0)
            t.close(lines["contribution"], lines["sales"] - lines["food_cost"] - lines["packaging"] - lines["card_fees"] - lines["spot_fee"] + lines["tips"], 1e-9, "money: contribution must be sales minus the cost lines")
            previous = -1.0
            for fixed_costs in (0.0, 100.0, 229.99, 240.74, 600.0):
                x = break_even_orders(profile, terms, fixed_costs)
                t.close(stop_money_at(profile, terms, x)["contribution"], fixed_costs, 1e-7, "money: contribution at the break-even orders must equal the costs (%r)" % fixed_costs)
                t.check(x >= previous, "money: break-even must rise with the costs")
                previous = x

    # Driving: the way back is the same fallback leg; longer drives take longer; overrides rule.
    out = fallback_leg(A, 39.003, -77.405, 38.96, -77.36)
    back = fallback_leg(A, 38.96, -77.36, 39.003, -77.405)
    t.close(out["distance_m"], back["distance_m"], 1e-6, "driving: the fallback leg must be the same both ways")
    previous = -1
    for seconds in (0.0, 30.0, 240.0, 600.0, 1800.0, 5400.0):
        leg = leg_minutes(A, P, {"source": "google", "distance_m": seconds * 13.0, "duration_s": seconds, "override_minutes": None, "toll": 0.0}, fx.thu, 1030)
        t.check(leg["minutes"] >= previous and isinstance(leg["minutes"], int), "driving: minutes must be whole and rise with the duration")
        previous = leg["minutes"]
    for minute in (-1440, -1, 0, 619, 1439, 1440, 2879, 4000):
        (factor, dow, hour) = traffic_factor(A, fx.thu, minute)
        t.check(1.0 <= factor <= 1.8 and 0 <= dow <= 6 and 0 <= hour <= 23, "driving: traffic lookup at minute %d" % minute)

    # Calibration: no logs is neutral; symmetric in the ratio; converges; order-free.
    empty = calibrate(A, [], "2026-10-04")
    t.check(empty["truck_factor"] == 1.0 and empty["truck_weight"] == 0.0 and empty["resid_sd"] is None and empty["spots"] == {}, "calibration: an empty log must be neutral")
    up = calibrate(A, [_service("u", "A", "2026-10-04", 100.0, 50.0)], "2026-10-04")
    down = calibrate(A, [_service("u", "A", "2026-10-04", 25.0, 50.0)], "2026-10-04")
    t.close(up["truck_log_factor"], -down["truck_log_factor"], 1e-12, "calibration: twice and half the prediction must mirror each other")
    t.close(up["spots"]["A"]["log_factor"], -down["spots"]["A"]["log_factor"], 1e-12, "calibration: spot factors must mirror each other")
    previous = 1.0
    for n in (1, 3, 10, 30, 100):
        cal = calibrate(A, [_service("n%03d" % i, "A", "2026-10-04", 90.0, 60.0) for i in range(n)], "2026-10-04")
        combined = cal["truck_factor"] * cal["spots"]["A"]["factor"]
        t.check(previous < combined < 1.5, "calibration: the factor must move toward actual / predicted as services accumulate")
        previous = combined
    t.close(previous, 1.5, 0.02, "calibration: a hundred services at 1.5 times the prediction")
    t.check(json.dumps(calibrate(A, list(reversed(fx.services)), "2026-10-04"), sort_keys=True) == json.dumps(fx.cal, sort_keys=True), "calibration: the order of the log must not matter")
    older = calibrate(A, fx.services, "2027-10-04")
    t.check(older["truck_weight"] < fx.cal["truck_weight"] and abs(older["truck_log_factor"]) < abs(fx.cal["truck_log_factor"]), "calibration: old services must count less")

    # Events: demand is spread evenly and conserved; orders never exceed capacity.
    ev = event_orders(A, P, {"attendance": 2000.0, "vendors": 6, "event_type": "general"}, None, typical_context(A, 5), None, 690, 885)
    spread_total = 0.0
    for h in ev["hours"]:
        spread_total += h["demand"]
        t.check(h["orders"] <= h["capacity"] + 1e-12, "events: orders above capacity")
    t.close(spread_total, ev["demand"], 1e-9, "events: demand spread over the hours must add up to the demand")

    # Numbers the document prints that no golden case returns by itself.
    per_point = [(0.829029, 0.691398, 0.265678, 66.4195), (0.731616, 0.610156, 0.248699, 62.1747),
                 (0.645649, 0.538461, 0.231905, 57.9762), (0.569783, 0.47519, 0.215421, 53.8553),
                 (0.502832, 0.419354, 0.199363, 49.8409), (0.443747, 0.370078, 0.183836, 45.9589),
                 (0.391606, 0.326593, 0.168927, 42.2317), (0.345591, 0.288217, 0.154709, 38.6773)]
    for k in range(8):                              # the table of the 4.4 layout, point by point
        source = fx.sources[k]
        d = haversine_m(TRUCK_LAT, TRUCK_LNG, source["lat"], source["lng"])
        f = walk_weight(A, d)
        share = f * 1.0 / (1.6 + f * 1.0 + source["rivals"]["day"])
        t.close(d, 75.0 + 50.0 * k, 1e-6, "document 4.4: distance of %s" % source["id"])
        t.close(f, per_point[k][0], 5.1e-7, "document 4.4: f(d) of %s" % source["id"])
        t.close(source["rivals"]["day"], per_point[k][1], 5.1e-7, "document 4.4: rivals_c of %s" % source["id"])
        t.close(share, per_point[k][2], 5.1e-7, "document 4.4: share of %s" % source["id"])
        t.close(250.0 * share, per_point[k][3], 5.1e-5, "document 4.4: base x share of %s" % source["id"])
    jobs = {"w_office": 1076149.0, "w_health": 356462.0, "w_edu": 292467.0, "w_retail": 261318.0,
            "w_industrial": 224939.0 + seed(A, "etl.cns04_weight") * 165397.0, "w_hospitality": 323428.0, "w_public": 439998.0}
    on_site = 0.0
    for s in range(1, 8):                           # Washington CBSA jobs by segment (03_DATA.md), weekday 12:00
        on_site += jobs[SEGMENTS[s]] * seed(A, "segments." + SEGMENTS[s] + ".presence.weekday")[12]
    t.close(on_site, 1244215.0, 0.5, "document 2.3: people on site at noon in the Washington region")
    t.close(on_site / 3140158.0, 0.396, 0.0005, "document 2.3: share of the region's jobs on site at noon")
    t.close(map_weight_rows(A, P, None)["w_opp"][84][1], 0.070567, 5.1e-7, "document 4.17: w_opp[84][w_office]")
    a1 = window_orders(A, P, fx.terms_open, fx.vec, None, fx.thu, None, 660, 840)
    (e, spread) = interval(A, a1["demand_adj"], a1["evidence"])
    t.close(e["high"], 93.8716, 5.1e-5, "document 4.8: the interval on demand before the cap")
    t.close(e["low"] / a1["demand_adj"], 0.54574, 5.1e-7, "document 4.8: k_low")
    t.close(e["high"] / a1["demand_adj"], 1.551755, 5.1e-7, "document 4.8: k_high")
    t.close(e["high"] / a1["demand_adj"] * a1["hours"][1]["result"]["demand_adj"], 45.677, 5.1e-4, "document 4.8: the 12:00 hour at k_high")
    thu_fuel = fx.ctx_fuel["2026-10-08"]
    for (stop, single) in ((_stop("office", 840, 1020, fx.terms_open, fx.vec), -153.87),
                           (_stop("taproom", 1200, 1380, fx.terms_tap, fx.zero), -108.28)):
        R = evaluate(A, P, {"date": "2026-10-08", "stops": [stop]}, thu_fuel, fx.ctx_fuel["2026-10-09"], fx.legs, None)
        t.close(R["take_home"]["value"], single, 0.0051, "document 4.15: single-stop take-home of the %s candidate" % stop["id"])
    two_spots = [{"spot_id": "office", "point": {"lat": TRUCK_LAT, "lng": TRUCK_LNG}, "terms": _terms(spot_id="office"), "vectors": fx.vec},
                 {"spot_id": "taproom", "point": {"lat": 39.0035, "lng": -77.4035}, "terms": _terms(spot_id="taproom", host=fx.tap_host), "vectors": fx.zero}]
    every_plan = suggest_day(A, P, thu_fuel, fx.ctx_fuel["2026-10-09"], two_spots, fx.legs, None, {"limit": 99})
    t.check(len(every_plan) == 6, "document 4.15: Thursday has 6 feasible plans, got %d" % len(every_plan))
    # 4.13: the service table (age, weight, log ratio) and pass A, which is the calibration of the services not sold out.
    table = [(120, 0.5, -0.143101), (85, 0.612027, 0.121361), (64, 0.690956, -0.272568), (43, 0.780065, 0.132897),
             (22, 0.880666, 0.087011), (8, 0.954842, -0.04879), (1, 0.99424, -0.381368)]
    for k in range(7):
        sv = fx.services[k]
        age = days_from_civil(2026, 10, 4) - _day_number(sv["date"])
        t.check(age == table[k][0], "document 4.13: age of %s" % sv["service_id"])
        t.close(exp(-LN2 * age / 120.0), table[k][1], 5.1e-7, "document 4.13: weight of %s" % sv["service_id"])
        t.close(ln(sv["actual"] / sv["predicted_raw"]), table[k][2], 5.1e-7, "document 4.13: log ratio of %s" % sv["service_id"])
    pass_a = calibrate(A, [sv for sv in fx.services if not sv["sold_out"]], "2026-10-04")
    t.close(pass_a["truck_log_factor"], -0.063579, 5.1e-7, "document 4.13: m_a")
    for (spot_id, want) in (("A", 0.04127), ("B", -0.098259), ("C", 0.00357)):
        t.close(pass_a["spots"][spot_id]["log_factor"], want, 5.1e-7, "document 4.13: s_a[%s]" % spot_id)
    t.close(pass_a["truck_log_factor"] + pass_a["spots"]["B"]["log_factor"], -0.161837, 5.1e-7, "document 4.13: m_a + s_a[B]")
    others = [_service("a%02d" % i, "OTHER", "2026-10-04", 50.0, 50.0) for i in range(10)] + [
        _service("b1", "A", "2026-10-04", 82.5, 50.0), _service("b2", "A", "2026-10-04", 82.5, 50.0)]
    pass_a = calibrate(A, others, "2026-10-04")
    t.close(pass_a["truck_log_factor"], 0.062597, 5.1e-7, "document 4.13: m_a of the sold-out example")
    t.close(pass_a["spots"]["A"]["log_factor"], 0.175271, 5.1e-7, "document 4.13: s_a[A] of the sold-out example")
    t.close(pass_a["truck_log_factor"] + pass_a["spots"]["A"]["log_factor"], 0.237868, 5.1e-7, "document 4.13: m_a + s_a[A]")
    t.close(ln(55.0 / 50.0), 0.09531, 5.1e-7, "document 4.13: Ls of the sold-out service")
    for (ratio, lt, ls) in ((10.0, 1.386294, 2.302585), (0.1, -1.386294, -2.302585), (60.0 / 2.9, 1.386294, 3.029634),
                            (8.0, 1.386294, 2.079442), (9.0 / 60.0, -1.386294, -1.89712)):
        t.close(clamp(ln(ratio), -ln(4.0), ln(4.0)), lt, 5.1e-7, "document 4.13: Lt at ratio %r" % ratio)
        t.close(clamp(ln(ratio), -ln(50.0), ln(50.0)), ls, 5.1e-7, "document 4.13: Ls at ratio %r" % ratio)
    worst = 0.0
    for (terms, vectors) in ((_terms(host=_host("v_nightlife", 40.0, "default")), fx.zero), (_terms(), fx.vec),
                             (_terms(host=_host("v_nightlife", 45.0, "default", False)), fx.zero)):
        slow = week_strip(A, P, terms, vectors, None)
        fast = strip_from_rows(A, P, terms, vectors, map_weight_rows(A, P, None))
        for how in range(168):
            worst = max(worst, abs(slow[how] - fast[how]) / max(1.0, abs(slow[how]), abs(fast[how])))
    t.check(worst <= 2.3e-16, "document 4.16: strip_from_rows and week_strip differ by at most 2.2e-16 over the three places, got %r" % worst)

    # Timeline and day plan on the worked day: the blueprint's sheet, and totals that add up.
    stops = [_stop("office", 660, 840, fx.terms_open, fx.vec), _stop("taproom", 1020, 1200, fx.terms_tap, fx.zero)]
    T = build_timeline(A, P, fx.thu, stops, fx.legs)
    sheet = [_clock(e["minute"]) for e in T["events"] if e["kind"] != "setup_start"]
    t.check(sheet == ["9:34", "10:19", "10:30", "11:00", "14:00", "14:20", "14:30", "17:00", "20:00", "20:20", "20:21", "20:51"],
            "timeline: the blueprint day sheet must come out to the minute, got %s" % ", ".join(sheet))
    previous = None
    for price in (3.0, 4.195, 6.531):
        day = day_plan(A, P, {"date": "2026-10-08", "stops": stops}, day_context(A, "2026-10-08", None, None, price, "owner"), fx.fri, fx.legs, None)
        t.check(previous is None or day["totals"]["take_home"]["value"] < previous, "day plan: take-home must fall as fuel gets dearer")
        previous = day["totals"]["take_home"]["value"]
    t.check(vectors_match(A, stops[0]["terms"], stops[0]["vectors"]) and vectors_match(A, stops[1]["terms"], stops[1]["vectors"]), "day plan: the worked day's vectors must match its terms")

    # Where the model stops without a model error (section 7): these have no golden case, so they are held here.
    def stops_with(expected, label, function, *arguments):
        try:
            function(*arguments)
            t.check(False, "programming errors: %s returned a result" % label)
        except ModelError as error:
            t.check(False, "programming errors: %s raised the model error %s" % (label, error.code))
        except expected:
            t.check(True, "")
        except Exception as error:
            t.check(False, "programming errors: %s raised %s" % (label, type(error).__name__))

    one_stop = build_timeline(A, P, fx.thu, stops[:1], fx.legs)
    empty_day = build_timeline(A, P, fx.thu, [], fx.legs)
    taproom_place = {"place_id": "w100", "place_type": "taproom", "point": {"lat": 39.0035, "lng": -77.4035}, "point_id": "pw100",
                     "size_default": 40.0, "kitchen": None, "vectors": _stored(_zero_vectors(exclusion={"point_ids": ["pw100"], "segment": None, "amount": 0.0}))}
    for bad_price in (None, True, False, "4.195", [4.195]):
        stops_with(TypeError, "day_costs with fuel price %r" % (bad_price,), day_costs, P, one_stop, bad_price)
        stops_with(TypeError, "day_costs of an empty day with fuel price %r" % (bad_price,), day_costs, P, empty_day, bad_price)
        stops_with(TypeError, "scout_estimate with fuel price %r" % (bad_price,), scout_estimate, A, P, taproom_place, {}, None, bad_price)
        stops_with(TypeError, "scout_estimate of a place that does not host, with fuel price %r" % (bad_price,), scout_estimate, A, P,
                   dict(taproom_place, place_type="restaurant"), {}, None, bad_price)
        stops_with(TypeError, "scout_estimate of a place with no window, with fuel price %r" % (bad_price,), scout_estimate, A, P,
                   dict(taproom_place, size_default=0.0), {}, None, bad_price)
    no_fuel = day_context(A, "2026-10-08", None, None, None, None)
    stops_with(TypeError, "day_plan without a fuel price", day_plan, A, P, {"date": "2026-10-08", "stops": stops}, no_fuel, fx.fri, fx.legs, None)
    stops_with(TypeError, "day_plan of an empty plan without a fuel price", day_plan, A, P, {"date": "2026-10-08", "stops": []}, no_fuel, fx.fri, fx.legs, None)
    stops_with(ZeroDivisionError, "day_costs with mpg 0", day_costs, _profile(mpg=0.0), one_stop, FUEL)
    stops_with(ZeroDivisionError, "day_costs of an empty day with mpg 0", day_costs, _profile(mpg=0.0), empty_day, FUEL)
    stops_with(ZeroDivisionError, "scout_estimate with mpg 0", scout_estimate, A, _profile(mpg=0), taproom_place, {}, None, FUEL)
    stops_with(ZeroDivisionError, "score_byte with hi 0", score_byte, 1.0, 0.0)
    for path in ("no.such.path", "host.no_such_seed", "", "segments.w_office.presence.weekday.3", "holidays.rules.0", "host.captive_share.value.x"):
        stops_with((KeyError, TypeError), "seed(%r)" % path, seed, A, path)
    t.check(scout_estimate(A, _profile(mpg=0), dict(taproom_place, size_default=0.0), {}, None, FUEL)["best_window"] is None,
            "programming errors: without a window scout_estimate has no drive to cost, whatever the mpg")
    # A model error raised inside a day is the day's error: day_plan and suggest_day hand it on.
    late_night = _stop("taproom", 1380, 1500, fx.terms_tap, fx.zero)
    for (label, function, arguments) in (
            ("day_plan", day_plan, (A, P, {"date": "2026-10-09", "stops": [late_night]}, fx.ctx_fuel["2026-10-09"], None, fx.legs, None)),
            ("evaluate", evaluate, (A, P, {"date": "2026-10-09", "stops": [late_night]}, fx.ctx_fuel["2026-10-09"], None, fx.legs, None))):
        try:
            function(*arguments)
            t.check(False, "errors: %s past midnight without the next day's context returned a result" % label)
        except ModelError as error:
            t.check(error.code == "missing_context", "errors: %s raised %s, expected missing_context" % (label, error.code))
    t.check(_is_finite_number(1) and _is_finite_number(-2.5) and _is_finite_number(10 ** 300) and not _is_finite_number(10 ** 400)
            and not _is_finite_number(float("inf")) and not _is_finite_number(float("nan")) and not _is_finite_number(True)
            and not _is_finite_number("1") and not _is_finite_number(None), "seeds: what counts as a finite number in an override")


def _check_results(t, cl, results):
    """Invariants that must hold in every golden result, whatever the case."""
    labels_seen = {}
    codes_seen = {}
    errors_seen = {}
    override_errors_seen = {}
    functions_seen = {}
    for case in cl.cases:
        if case["id"] not in results:
            continue
        result = results[case["id"]]
        functions_seen[case["function"]] = True
        where = case["id"] + " " + case["function"]

        estimates = []
        _estimates_in(result, "", estimates)
        for (path, e) in estimates:                 # lows <= means <= highs everywhere
            t.check(e["low"] <= e["value"] <= e["high"] and e["confidence"] in CONFIDENCE_LABELS, "%s%s: low <= value <= high fails: %r" % (where, path, e))
            labels_seen[e["confidence"]] = True
            if e["confidence"] == "fixed":
                t.check(e["low"] == e["value"] == e["high"], "%s%s: a fixed estimate with a range" % (where, path))
        if isinstance(result, dict) and "error" in result and len(result) == 1:
            errors_seen[result["error"]] = True
            # 8.1 rule 10: whoever reads the file may run every day_plan case to a DayResult.
            t.check(case["function"] != "day_plan", "%s: a day_plan case must not end in an error; the errors of a day are evaluate cases" % where)
        if case["function"] == "validate_overrides":
            for problem in result:
                override_errors_seen[problem["error"]] = True

        hours = []
        _records_in(result, "demand_raw", hours)
        for hr in hours:
            if "segments" not in hr:
                continue                            # a segment or host row, not an HourResult
            total = 0.0
            for row in hr["segments"]:
                total += row["orders"]
            if hr["host"] is not None:
                total += hr["host"]["orders"]
            t.check(abs(total - hr["orders"]) <= 1e-9 * max(1.0, hr["orders"]), "%s: segment and host orders must add up to the hour's orders" % where)
            t.check(hr["orders"] <= hr["capacity"] and hr["orders"] <= hr["demand_adj"] and hr["capped"] == (hr["demand_adj"] > hr["capacity"]), "%s: the hourly cap" % where)
            t.check(hr["how"] >= 0 and hr["how"] <= 167 and mod_floor(hr["how"], 24) == hr["hour"], "%s: hour of week" % where)
        windows = []
        _records_in(result, "capped_hours", windows)
        for w in windows:
            if "hours" not in w:
                continue                            # the data of a capacity_bound warning
            total = 0.0
            capped = 0
            for h in w["hours"]:
                total += h["result"]["orders"] * h["fraction"]
                if h["result"]["capped"]:
                    capped += 1
            parts = w["host_orders"]
            for x in w["by_segment"]:
                parts += x
            t.check(abs(total - w["orders"]["value"]) <= 1e-9 * max(1.0, total), "%s: a window's orders must be the sum of its hours" % where)
            t.check(abs(parts - w["orders"]["value"]) <= 1e-9 * max(1.0, parts), "%s: by_segment and host_orders must add up to the window's orders" % where)
            t.check(capped == w["capped_hours"] and w["minutes"] == w["close_minute"] - w["open_minute"] and w["orders"]["value"] <= w["demand_adj"] + 1e-9, "%s: window bookkeeping" % where)
            t.check(0.0 <= w["evidence"]["weak_share"] <= 1.0 + 1e-12 and 0.0 <= w["evidence"]["default_size_share"] <= 1.0 + 1e-12, "%s: evidence shares" % where)
        timelines = []
        _records_in(result, "day_minutes", timelines)
        for T in timelines:
            if "events" not in T:
                continue                            # a Suggestion carries day_minutes too
            last = None
            drive = 0
            for e in T["events"]:
                t.check(last is None or e["minute"] >= last, "%s: timeline events must not run backwards" % where)
                last = e["minute"]
            for leg in T["legs"]:
                drive += leg["minutes"]
            t.check(drive == T["drive_minutes"] and T["paid_minutes"] + T["unpaid_gap_minutes"] == T["day_minutes"], "%s: timeline minutes must add up" % where)
            if T["done"] is not None:
                t.check(T["day_minutes"] == T["done"] - T["start_prep"] and T["generator_minutes"] >= T["service_minutes"], "%s: timeline day length" % where)
        days = []
        _records_in(result, "unpaid_gap_alternative", days)
        for day in days:
            tt = day["totals"]
            costs = tt["labour"]["value"] + tt["fuel"]["value"] + tt["tolls"]["value"] + tt["fixed_cost"]["value"]
            t.check(abs(tt["take_home"]["value"] - (tt["contribution"]["value"] - costs)) <= 1e-9 * max(1.0, abs(tt["contribution"]["value"]), costs), "%s: take-home must be contribution minus the day's costs" % where)
            total = 0.0
            for st in day["stops"]:
                total += st["orders"]["value"]
            t.check(abs(total - tt["orders"]["value"]) <= 1e-9 * max(1.0, total), "%s: the day's orders must be the sum of its stops" % where)
            if len(day["stops"]) == 1:
                add = day["stops"][0]["adds"]
                t.check(abs(add["take_home"]["value"] - tt["take_home"]["value"]) <= 1e-9 * max(1.0, abs(tt["take_home"]["value"])), "%s: the only stop of a day adds the whole day" % where)
            for w in day["warnings"]:
                codes_seen[w["code"]] = True
                t.check(w["code"] in WARNING_CODES and w["level"] in ("info", "warn", "error"), "%s: unknown warning %r" % (where, w["code"]))
            order = [WARNING_CODES.index(w["code"]) for w in day["warnings"] if w["code"] in WARNING_CODES]
            t.check(order == sorted(order), "%s: warnings must come in the order of the table in 4.12" % where)
        if case["function"] == "suggest_day":
            keys = [qkey(s["take_home"]["value"]) for s in result]
            t.check(keys == sorted(keys, reverse=True) and [s["position"] for s in result] == list(range(1, len(result) + 1)), "%s: suggestions must be ranked best first" % where)
        if case["function"] == "suggest_week" and "days" in result:
            total = 0.0
            working = 0
            for d in result["days"]:
                if d["suggestion"] is not None:
                    total += d["suggestion"]["take_home"]["value"]
                    working += 1
            limits = case["args"]["options"] or {}
            t.check(abs(total - result["total_take_home"]["value"]) <= 1e-9 * max(1.0, total), "%s: the week's total must be the sum of its days" % where)
            t.check(working <= (limits.get("max_days_per_week") or 5), "%s: more working days than allowed" % where)
            for spot_id in result["visits"]:
                t.check(1 <= result["visits"][spot_id] <= (limits.get("max_visits_per_spot_per_week") or 2), "%s: visits to %s" % (where, spot_id))
        if case["function"] == "scout_rank":
            keys = [(-qkey(r["score"]), r["place_id"]) for r in result]
            t.check(keys == sorted(keys) and [r["position"] for r in result] == list(range(1, len(result) + 1)) and len(result) <= 50, "%s: scouting rank order" % where)

    # Coverage: the golden file must exercise everything the document names.
    t.check(len(cl.cases) >= 220, "coverage: at least 220 golden cases, got %d" % len(cl.cases))
    for name in sorted(CATALOGUE.keys()):
        if name not in NO_DIRECT_CASES:
            t.check(name in functions_seen, "coverage: no golden case calls %s" % name)
    for family in sorted(FAMILY_NAMES.keys()):
        t.check(cl.counts.get(family, 0) > 0, "coverage: family %s has no case" % family)
    for code in WARNING_CODES:
        t.check(code in codes_seen, "coverage: no golden case raises the warning %s" % code)
    for label in CONFIDENCE_LABELS:
        t.check(label in labels_seen, "coverage: no golden case carries the label %s" % label)
    for code in MODEL_ERRORS:
        t.check(code in errors_seen, "coverage: no golden case raises the error %s" % code)
    for code in OVERRIDE_ERRORS:
        t.check(code in override_errors_seen, "coverage: no golden case reports the override error %s" % code)

    # The numbers the document prints.
    for (case_id, path, want, decimals) in cl.documented:
        if case_id not in results:
            continue
        try:
            if isinstance(path, tuple):
                (path, function) = path
                got = function(results[case_id])
            else:
                got = _at(results[case_id], path)
        except (KeyError, IndexError, TypeError, ValueError):
            t.check(False, "document: %s has nothing at %r" % (case_id, path))
            continue
        if decimals is None:
            same = got == want and type(got) is type(want) if isinstance(want, (bool, str)) or want is None else got == want
            t.check(same, "document: %s %s is %r, the document says %r" % (case_id, path or "(result)", got, want))
        else:
            half_unit = 0.5 / POW10[decimals] * (1.0 + 1e-6)      # half a unit in the last place the document prints
            ok = isinstance(got, (int, float)) and not isinstance(got, bool) and abs(got - want) <= half_unit
            t.check(ok, "document: %s %s is %r, the document says %r (to %d decimals)" % (case_id, path or "(result)", got, want, decimals))

    # The sanity anchors (8.3).
    for anchor in cl.anchors:
        if anchor["case"] not in results:
            continue
        got = _at(results[anchor["case"]], anchor["path"])
        if "greater_than_case" in anchor:
            t.check(got > _at(results[anchor["greater_than_case"]], anchor["path"]), "anchor %s: %r is not above the other case" % (anchor["id"], got))
        else:
            t.check(anchor["min"] <= got <= anchor["max"], "anchor %s: %r is outside %r..%r" % (anchor["id"], got, anchor["min"], anchor["max"]))
    t.check(len(cl.anchors) == 3, "anchors: three are defined in 8.3")

    # Order-independence, seen between golden cases.
    t.check(results.get("g07-003") == results.get("g07-004"), "rivals: the order of the outlet list must not matter")
    crowded = [results[case["id"]] for case in cl.cases if case["function"] == "suggest_day" and len(case["args"]["spots"]) > 30 and case["id"] in results]
    t.check(len(crowded) == 1 and len(crowded[0]) > 0 and [s["spot_id"] for s in crowded[0][0]["stops"]][1:] == ["taproom"],
            "suggestions: with thirty lunch spots the best plan must still be lunch plus the taproom (candidates are kept per daypart)")
    three = [results[case["id"]] for case in cl.cases if case["function"] == "suggest_day" and (case["args"]["options"] or {}).get("max_stops_per_day") == 3 and case["id"] in results]
    t.check(len(three) == 1 and max([len(s["stops"]) for s in three[0]] + [0]) == 3, "suggestions: the three-stop case must return a three-stop plan")
    t.check(results.get("g19-001") == results.get("g19-002"), "calibration: the order of the service list must not matter (golden)")


def self_test(thorough=True):
    """Returns (case list, results, report)."""
    report = _Report()
    cl = build_cases()
    ids = [case["id"] for case in cl.cases]
    report.check(len(ids) == len(set(ids)), "golden: case ids must be unique")
    results = run_cases(cl, report, thorough)
    if thorough:
        _check_seeds(report)
        _check_dates(report)
        _check_model(report, _Fixtures())
        _check_results(report, cl, results)
    return (cl, results, report)


# -------------------------------------------------------------------------------------------------
# The golden file
# -------------------------------------------------------------------------------------------------

def golden_document(cl, results):
    cases = []
    for case in cl.cases:
        cases.append({"id": case["id"], "family": case["family"], "function": case["function"], "args": case["args"],
                      "expected": results[case["id"]]})
    return {"model_version": MODEL_VERSION, "seeds_revision": SEEDS["seeds_revision"],
            "tolerance": {"rel": 1e-9, "abs": 1e-9}, "anchors": cl.anchors, "cases": cases}


def golden_text(document):
    """The file's bytes: keys sorted at every level, shortest round-trip floats, one case per line, LF,
    a trailing newline. The same input always gives the same text."""
    def dumps(value):
        return json.dumps(value, sort_keys=True, ensure_ascii=True, allow_nan=False, separators=(", ", ": "))

    lines = ["{", '"anchors": ' + dumps(document["anchors"]) + ",", '"cases": [']
    for i in range(len(document["cases"])):
        lines.append(dumps(document["cases"][i]) + ("," if i + 1 < len(document["cases"]) else ""))
    lines.append("],")
    lines.append('"model_version": ' + dumps(document["model_version"]) + ",")
    lines.append('"seeds_revision": ' + dumps(document["seeds_revision"]) + ",")
    lines.append('"tolerance": ' + dumps(document["tolerance"]))
    lines.append("}")
    return "\n".join(lines) + "\n"


def _moved_ids(path, cl):
    """8.2: case ids are stable, because other tests name them. Compares the case list with the file
    about to be replaced and reports every id that disappears or now names another call (a case put
    into the middle of a family renumbers the ones after it). The file is written all the same: a
    changed shape legitimately changes the arguments of old cases."""
    if not os.path.isfile(path):
        return []
    try:
        with open(path, "r", encoding="utf-8") as handle:
            before = json.load(handle)
        old = {}
        for case in before["cases"]:
            old[case["id"]] = (case["function"], case["args"])
    except (ValueError, KeyError, TypeError):
        return ["the file being replaced could not be read as a golden file; ids were not compared"]
    new = {}
    for case in cl.cases:
        new[case["id"]] = (case["function"], _plain(case["args"]))
    gone = sorted([case_id for case_id in old if case_id not in new])
    other_function = sorted([case_id for case_id in old if case_id in new and old[case_id][0] != new[case_id][0]])
    other_args = sorted([case_id for case_id in old if case_id in new and old[case_id][0] == new[case_id][0]
                         and old[case_id][1] != new[case_id][1]])
    notes = []
    for (label, ids) in (("no longer exist", gone), ("now call another function", other_function),
                         ("now carry other arguments", other_args)):
        if len(ids) > 0:
            notes.append("%d case ids of the replaced file %s: %s%s"
                         % (len(ids), label, ", ".join(ids[:12]), " ..." if len(ids) > 12 else ""))
    return notes


def write_golden(path):
    (cl, results, report) = self_test(True)
    if len(report.problems) > 0:
        for message in report.problems:
            print("PROBLEM " + message)
        print("%d cases, %d property checks: %d problems; nothing written" % (len(cl.cases), report.checks, len(report.problems)))
        return 1
    text = golden_text(golden_document(cl, results))
    for message in _moved_ids(path, cl):
        print("NOTE " + message)
    directory = os.path.dirname(os.path.abspath(path))
    if not os.path.isdir(directory):
        os.makedirs(directory)
    with open(path, "w", encoding="utf-8", newline="\n") as handle:
        handle.write(text)
    print("wrote %d cases (%d bytes) to %s" % (len(cl.cases), len(text.encode("utf-8")), path))
    return 0


def check_golden(path):
    """Regenerate in memory and compare with the file within the tolerance of 1.4."""
    with open(path, "r", encoding="utf-8") as handle:
        on_disk = json.load(handle)
    (cl, results, report) = self_test(False)
    fresh = _plain(golden_document(cl, results)) if len(report.problems) == 0 else None
    drift = list(report.problems)
    if fresh is not None:
        for key in ("model_version", "seeds_revision", "tolerance", "anchors"):
            differences(fresh.get(key), on_disk.get(key), key, drift)
        mine = [case["id"] for case in fresh["cases"]]
        theirs = [case.get("id") for case in on_disk.get("cases", [])]
        if mine != theirs:
            drift.append("cases: the file holds %d cases, the reference generates %d; first difference at position %d"
                         % (len(theirs), len(mine), next((i for i in range(min(len(mine), len(theirs))) if mine[i] != theirs[i]), min(len(mine), len(theirs)))))
        else:
            for i in range(len(mine)):
                found = []
                differences(fresh["cases"][i], on_disk["cases"][i], mine[i], found)
                drift.extend(found[:3])
    for message in drift[:40]:
        print("DRIFT " + message)
    print("%d cases checked against %s: %d differences" % (len(cl.cases), path, len(drift)))
    return 1 if len(drift) > 0 else 0


# -------------------------------------------------------------------------------------------------
# Branch reach of the golden cases (8.2)
# -------------------------------------------------------------------------------------------------

# What no golden case reaches in the model part of this file, and why: (function, source line, reason).
# --reach fails when a line or a branch outcome that is not listed here goes unreached, and when an
# entry listed here is reached after all.
UNREACHED = [
    ("load_seeds", 'with open(path, "r", encoding="utf-8") as handle:', "runs when the module is loaded, before any case"),
    ("load_seeds", "return json.load(handle)", "runs when the module is loaded, before any case"),
    ("make_assumptions", '"overrides": {} if overrides is None else overrides,', "a case always states its overrides; the default serves the fixtures"),
    ("make_assumptions", '"region": REGION_DC if region is None else region}', "a case always states its region; the default serves the fixtures"),
    ("_is_finite_number", "except OverflowError:", "float() of a number raises nothing else"),
    ("require_number", "if not _is_number(x):", "a fuel price that is not a number is a programming error: no code and no golden case (7)"),
    ("require_number", 'raise TypeError(name + " must be a number")', "the same; the self-test holds it as a property check"),
    ("_rule_order", 'for rule in SEEDS["holidays"]["rules"]:', "every id it is asked for comes from the rule list, so the loop never runs out"),
    ("_rule_order", "return order + 1", "the same"),
    ("hour_weights", 'if A["seeds"] is SEEDS:', "the memo of this reference: a case always runs on the seed file"),
    ("hour_weights", "if key is not None:", "the same"),
    ("_band", 'for band_id in seed(A, "weather." + table + ".order"):', "the last band of every table has no upper bound, so the loop never runs out"),
    ("_band", "return (None, 1.0)", "the same"),
    ("build_timeline.<locals>.point_of", "for s in stops:", "every id it is asked for is a stop of the plan"),
    ("_missing_forecast_hours", 'if cx is None or cx["typical"]:', "a missing next-day context has already stopped evaluate with missing_context"),
]


def reach():
    """Measure what the golden cases reach in the model part of this file (everything above section 8).

    Lines: the standard library's trace module counts the lines executed while every case runs through
    call(). Branches (Python 3.12 or later): sys.monitoring reports every conditional jump taken; each
    conditional jump of the byte code has two outcomes and both must be seen. The fixtures are built
    before the measurement starts, so only the cases themselves count.

    The counts are those of the interpreter that runs this: another version compiles the same source
    to other byte code and may count a few branch outcomes more or fewer. What is not reached is named
    by function and source line, which does not depend on the version."""
    import dis
    import trace
    import types

    if sys.version_info < (3, 11):
        print("--reach needs Python 3.11 or later (3.12 or later for branches)")
        return 2

    here = os.path.abspath(__file__)
    with open(here, "r", encoding="utf-8") as handle:
        source = handle.read().split("\n")
    model_end = 0
    for i in range(len(source)):
        if source[i].startswith("# 8. Golden cases"):
            model_end = i + 1
    module = sys.modules[__name__]

    def text_of(line):
        return source[line - 1].split("  #")[0].strip()

    codes = []

    def collect(code):
        codes.append(code)
        for const in code.co_consts:
            if isinstance(const, types.CodeType):
                collect(const)

    for value in list(vars(module).values()):
        if isinstance(value, types.FunctionType) and os.path.abspath(value.__code__.co_filename) == here and value.__code__.co_firstlineno < model_end:
            collect(value.__code__)

    cl = build_cases()
    texts = [(case["function"], json.dumps(case["args"], sort_keys=True, allow_nan=False)) for case in cl.cases]

    def run_all():
        _HOUR_WEIGHTS_MEMO.clear()
        for (function, text) in texts:
            call(function, json.loads(text))

    # Lines.
    tracer = trace.Trace(count=1, trace=0)
    tracer.runfunc(run_all)
    executed = set()
    for (filename, line) in tracer.results().counts:
        if os.path.abspath(filename) == here:
            executed.add(line)
    unreached = {}                                  # (function, line text) -> what was not reached
    executable = 0
    functions = {}
    for code in codes:
        own_lines = set()
        for (start, end, line) in code.co_lines():
            if line is not None and line != code.co_firstlineno and line < model_end:
                own_lines.add(line)
        for const in code.co_consts:                # a nested function's lines belong to that function
            if isinstance(const, types.CodeType):
                for (start, end, line) in const.co_lines():
                    if line is not None and line != const.co_firstlineno:
                        own_lines.discard(line)
        named = not code.co_qualname.split(".")[-1].startswith("<")
        if named:
            functions[code.co_qualname] = functions.get(code.co_qualname, False) or len(own_lines & executed) > 0
        executable += len(own_lines)
        for line in sorted(own_lines - executed):
            unreached[(code.co_qualname, text_of(line))] = "line never executed"
    lines_missed = len(unreached)

    # Branch outcomes.
    outcomes = 0
    outcomes_missed = 0
    monitoring = getattr(sys, "monitoring", None)
    if monitoring is not None:
        tool = 3
        seen = {}

        def on_branch(code, offset, destination):
            seen.setdefault((code, offset), set()).add(destination)

        events = monitoring.events
        kinds = [events.BRANCH_LEFT, events.BRANCH_RIGHT] if hasattr(events, "BRANCH_LEFT") else [events.BRANCH]
        mask = 0
        monitoring.use_tool_id(tool, "truck-planner-reach")
        for kind in kinds:
            monitoring.register_callback(tool, kind, on_branch)
            mask |= kind
        for code in codes:
            monitoring.set_local_events(tool, code, mask)
        try:
            run_all()
        finally:
            for code in codes:
                monitoring.set_local_events(tool, code, 0)
            monitoring.free_tool_id(tool)
        conditional = ("POP_JUMP_IF_TRUE", "POP_JUMP_IF_FALSE", "POP_JUMP_IF_NONE", "POP_JUMP_IF_NOT_NONE", "FOR_ITER")
        for code in codes:
            for instruction in dis.get_instructions(code):
                line = instruction.positions.lineno if instruction.positions is not None else None
                if instruction.opname not in conditional or line is None or line >= model_end:
                    continue
                outcomes += 2
                taken = len(seen.get((code, instruction.offset), ()))
                if taken < 2:
                    outcomes_missed += 2 - taken
                    key = (code.co_qualname, text_of(line))
                    if key not in unreached:
                        unreached[key] = "a branch never taken" if taken == 1 else "never evaluated"

    justified = {}
    for (function, text, reason) in UNREACHED:
        justified[(function, text)] = reason
    problems = []
    for key in sorted(unreached.keys()):
        if key not in justified:
            problems.append("not reached and not explained: %s: %s (%s)" % (key[0], key[1], unreached[key]))
    if monitoring is not None:
        for key in sorted(justified.keys()):
            if key not in unreached:
                problems.append("listed as unreached but reached: %s: %s" % key)

    entered = len([name for name in functions if functions[name]])
    print("golden cases: %d (Python %d.%d)" % (len(cl.cases), sys.version_info[0], sys.version_info[1]))
    print("model functions: %d, entered by a case: %d, not entered: %s"
          % (len(functions), entered, ", ".join(sorted(name for name in functions if not functions[name])) or "none"))
    print("model lines: %d executable, %d executed, %d not executed" % (executable, executable - lines_missed, lines_missed))
    if monitoring is None:
        print("branch outcomes: not measured (sys.monitoring needs Python 3.12 or later)")
    else:
        print("branch outcomes: %d, %d reached, %d not reached" % (outcomes, outcomes - outcomes_missed, outcomes_missed))
    for key in sorted(unreached.keys()):
        print("  %-34s %-22s %s%s" % (key[0], unreached[key], key[1][:80], "" if key not in justified else "   <- " + justified[key]))
    for message in problems:
        print("PROBLEM " + message)
    print("%d unexplained" % len(problems))
    return 1 if len(problems) > 0 else 0


def main(argv):
    if len(argv) == 2 and argv[1] == "--reach":
        return reach()
    if len(argv) == 2 and argv[1] == "--self-test":
        (cl, results, report) = self_test(True)
        for message in report.problems:
            print("PROBLEM " + message)
        print("%d cases, %d property checks: %d problems" % (len(cl.cases), report.checks, len(report.problems)))
        return 1 if len(report.problems) > 0 else 0
    if len(argv) == 3 and argv[1] == "--write-golden":
        return write_golden(argv[2])
    if len(argv) == 3 and argv[1] == "--check-golden":
        return check_golden(argv[2])
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
