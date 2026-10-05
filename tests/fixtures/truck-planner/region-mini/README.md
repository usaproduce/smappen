# region-mini

A small, real region for backend tests: **Falls Church city, Virginia** (county 51610), as one build of the
geodata pipeline. It is what `scripts/truck/load-region.php` loads in CI before the HTTP smoke test, and what
`tests/TruckPlanner/Services/RegionLoaderTest.php` loads without a database.

| | |
|---|---|
| `build/` | one pipeline build, the five files of 03_DATA.md section 8: `manifest.json`, `points.tsv`, `places.ndjson`, `cells.tsv`, `job_review.csv` |
| `dataset_version` | `mini-20261003-8d5536a4` (region id `mini`) |
| Source points | 363: 152 census blocks and 49 venues of the city, 162 halo rows outside it |
| Places | 420: 226 in the city (146 rival outlets, 83 possible hosts), 194 in the halo |
| Map cells | 197 kept of 211 candidates, real H3 resolution-9 ids |
| Size | about 350 kB |

The region names no traffic matrix (it uses `us_mean`), has a halo on every side, a job-corrections file with one
entry per treatment, and four pipeline warnings (opening hours, absent place types, a stale and an orphan
correction, one unconfirmed correction). None of that stops a load: only `fail` gates do.

## Rebuilding it

The build is made by the pipeline itself, offline, from the pipeline's own committed test input
(`tools/truck-etl/test/fixtures/mini`) and the repository seed file:

```bash
cd tools/truck-etl && npm ci && cd ../..
node tests/fixtures/truck-planner/region-mini/make-fixture.mjs
```

Rebuild and commit the five files whenever the pipeline's rules change, or a build-scope seed changes (the
loader's gate G19 refuses a build whose recorded seed values are not the model's, and the `dataset_version`
changes with `seeds_revision`). The version above is then another one: update it here and in the tests that
name it.

To load it by hand:

```bash
php scripts/truck/load-region.php --build=tests/fixtures/truck-planner/region-mini/build --dry-run
php scripts/truck/load-region.php --build=tests/fixtures/truck-planner/region-mini/build --activate
```

The files are compared by checksum: `.gitattributes` marks this folder `-text`, so git never translates their
line endings.

## Sources

Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Jobs: U.S. Census Bureau, LEHD
LODES 8.4 (2023). Both are in the public domain. Places: © OpenStreetMap contributors, snapshot of 2026-10-03
(Geofabrik extract), available under the Open Database License (ODbL) 1.0; `build/places.ndjson` and the place
rows of `build/points.tsv` are a database derived from it.
