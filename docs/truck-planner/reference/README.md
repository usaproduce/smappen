# Truck Planner - reference implementation of the model (`tps-0.1.0`)

| File | What it is |
|---|---|
| `truck_planner_reference.py` | **The definition of the math.** Every function of `../02_MODEL.md` under its canonical name, in the order of the document, in plain Python (3.10+, standard library only). Below the model: the golden-case list, the self-test, the golden-file writer and checker. |
| `tp_seeds.json` | Every seed assumption. The only place a seed is edited. |
| `generate_seed_copies.py` | Writes the two generated copies of the seeds: `src/TruckPlanner/Model/SeedsData.php` and `frontend/src/utils/truck/estimator/seeds.generated.ts`. |
| `../../../tests/fixtures/truck-planner/golden_cases.json` | The golden cases (`function`, `args`, `expected`) that the Python reference, the PHP port and the TypeScript port must all reproduce. Written by the reference, never by hand. |

## Commands

Run from the repository root (any directory works; paths below are relative to the root).

```bash
# every golden case, the margin rule, the documented numbers of 02_MODEL.md and the property checks
python docs/truck-planner/reference/truck_planner_reference.py --self-test
#   -> "683 cases, 7844 property checks: 0 problems"      exit 1 on any problem

# write the golden file (runs the self-test first and writes nothing if it finds a problem)
python docs/truck-planner/reference/truck_planner_reference.py --write-golden tests/fixtures/truck-planner/golden_cases.json

# regenerate in memory and compare with the file, within the tolerance of 02_MODEL.md 1.4
python docs/truck-planner/reference/truck_planner_reference.py --check-golden tests/fixtures/truck-planner/golden_cases.json
#   -> "683 cases checked against ...: 0 differences"     exit 1 on drift

# the generated seed copies: write them, or verify that they match tp_seeds.json
python docs/truck-planner/reference/generate_seed_copies.py
python docs/truck-planner/reference/generate_seed_copies.py --check
```

The output of all of them is independent of the time zone, the locale and `PYTHONHASHSEED`, and the golden
file and the seed copies are byte-stable: writing them twice gives the same bytes.

## The rule

**The document and the reference say the same thing, always.**

1. A change to the math is made in `truck_planner_reference.py` **and** in `02_MODEL.md` in the same change.
   If they disagree, the reference wins and the document is the defect.
2. A change to a seed is made in `tp_seeds.json` (raise `seeds_revision`), then `generate_seed_copies.py`
   is run and the tables of `02_MODEL.md` section 2.3 are updated.
3. After either, `--self-test` must print `0 problems`, the golden file is rewritten with `--write-golden`,
   and the document, the reference, the seeds, the two seed copies and the golden file are committed
   together. A commit that changes one of them without the others is wrong.
4. The PHP and TypeScript ports are then made to pass the new golden file. A port is never "fixed" by
   editing the golden file.
5. A worked number added to the document is added to the self-test as a documented value (`c.doc(...)`
   next to the case that produces it), so the document cannot drift from the code unnoticed.

## What the self-test checks

- Every golden case runs twice through the same by-name dispatch a port uses: the arguments must come
  back untouched and the second result must be identical.
- **Margin rule** (02_MODEL.md 8.1): each case is run again with `exp`, `ln`, `sin`, `cos` and `asin`
  replaced by slightly different functions; no discrete output may change and no real may leave the
  tolerance. A case that hangs on the last bits of a library function is refused.
- More than a thousand numbers printed in `02_MODEL.md` are compared with what the reference computes.
- Property checks: seed integrity, the calendar against an independent one for every day of 1970-2199,
  the OPM holiday lists, conservation, bounds, monotonicity and symmetry, the blueprint day sheet to the
  minute, the two sanity anchors, `low <= value <= high` for every estimate in every result, and
  coverage (every function, warning code, confidence label and error has a golden case).
