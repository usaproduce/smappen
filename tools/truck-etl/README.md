# truck-etl

Geodata pipeline of Truck Planner. It turns public files into the build of one region:

| Input | Source | What it gives |
|---|---|---|
| 2020 Census PL 94-171 state files | www2.census.gov | residents, housing units and the internal point of every census block |
| LEHD LODES 8 workplace files (WAC) and block crosswalk | lehd.ces.census.gov | jobs by sector per block; labels for the review list |
| County polygons | TIGERweb | the county of each place |
| OpenStreetMap state extracts | download.geofabrik.de | food outlets, venues and possible hosts |

and writes five files per build (`points.tsv`, `places.ndjson`, `cells.tsv`, `job_review.csv`, `manifest.json`).
`scripts/truck/load-region.php` loads them into MySQL. The contract for every rule, column and number format is
[`docs/truck-planner/03_DATA.md`](../../docs/truck-planner/03_DATA.md); this file only says how to run things.

Node 20 or newer. One dependency (`h3-js`). The application never calls this tool or these hosts at run time.

## Build a region

```bash
cd tools/truck-etl && npm ci && cd ../..
node tools/truck-etl/bin/build-region.mjs --region=dc --contact=you@example.com
```

The first run downloads about 0.85 GB into `storage/truck/raw/` (one request at a time, resumable, every file checked
against its published checksum), builds, prints the gate table and writes
`storage/truck/build/dc/<dataset_version>/`. Later runs reuse the cache: they ask the servers only whether the LODES
and county files have changed, and whether there is a newer OpenStreetMap extract (which they then download; pin one
with `--osm-date`). A run without a contact address requests nothing and builds from the cache as it is.

| Option | Meaning | Default |
|---|---|---|
| `--region=<id>` | reads `regions/<id>.json` | required (or `--region-file=<path>`) |
| `--contact=<email>` | sent in the User-Agent of every download. Without one nothing is requested: the build uses the raw cache as with `--offline` | `TP_CONTACT_EMAIL` |
| `--raw-dir=<dir>` | raw download cache | `TP_RAW_DIR`, else `storage/truck/raw` |
| `--out-dir=<dir>` | output goes to `<out-dir>/<region>/<dataset_version>/` | `storage/truck/build` |
| `--out=<dir>` | output goes to `<out>/<dataset_version>/` | |
| `--seeds=<path>` | seed file | `docs/truck-planner/reference/tp_seeds.json` |
| `--corrections=<path>` | job corrections | `corrections/<region>.jobs.json` |
| `--osm-date=YYMMDD` | use the OpenStreetMap extracts of this date | newest |
| `--offline` | never make a request, even with a contact set; everything must be in the raw cache | off |
| `--places-source=overpass-tiles --tiles-dir=<dir>` | development input, never for a real refresh | |

Options may be written `--name=value` or `--name value`. Paths are relative to the current directory; the defaults are
relative to the repository root.

Exit code 0: every gate passed (warnings are printed and allowed). Exit code 2: a gate failed; the files are in
`<region build dir>/_failed/` and `manifest.json` there shows `value` and `expected` of each gate. Exit code 1: wrong
usage, an invalid region, seed or corrections file, a file that cannot be read, or a download that did not finish.

A download that is cut off leaves a `.part` file in the cache and the next run resumes it. A downloaded file that
fails its checksum is removed and the build stops with exit code 2 (gate G1): run the build again. There are no
automatic retries.

### Without network, or with files you already have

```bash
node tools/truck-etl/bin/adopt-raw.mjs --region=dc --from=/data/downloads --raw-dir=storage/truck/raw \
     --counties=/data/downloads/tigerweb_counties.geojson
node tools/truck-etl/bin/build-region.mjs --region=dc --offline
```

`adopt-raw.mjs` finds the files by name under `--from`, hard-links them into the cache layout (it copies when linking is
not possible), and gives an extract named `<state>-latest.osm.pbf` its dated name from the timestamp in its header. It
downloads nothing and replaces nothing, and it lists what it could not find. Every state has a LODES `version.txt`:
name it `<st>_version.txt` or keep it in a folder named `<st>`. With `--offline` the build then adopts every file
after running its check. Keep the checksum lists (`lodes_<st>.sha256sum`, `<name>.osm.pbf.md5`) with the files if you
have them: without them the files are still used and gate G1 warns.

## What a build prints

Stages with elapsed seconds on standard error. On standard output one line per gate check (`ok`, `WARN` or `FAIL`, with
value and expectation when it did not pass), then totals, the four data files with rows, bytes and SHA-256, the raw
cache with the number of requests made, the output directory, wall-clock time and peak memory.

The `dc` build of 2026-10-05 (extracts of 2026-10-03, seeds revision 1, corrections `2026-10-05.1`), with the raw
files already in the cache:

| | |
|---|---|
| `dataset_version` | `dc-20261003-d0514a63` |
| Time, memory | 35 to 40 s, under 500 MiB peak |
| Residents, raw jobs, blocks | 6,278,542 / 3,140,158 / 64,615 (the official 2020 and LODES 2023 totals of the 23 counties) |
| `points.tsv` | 60,678 rows, 9.9 MB (51,262 block rows, 7,952 venues, 1,464 halo rows) |
| `places.ndjson` | 25,276 rows, 14.3 MB (24,886 in the region: 13,508 rivals, 11,941 possible hosts) |
| `cells.tsv` | 61,460 kept of 150,849 candidate cells, 4.1 MB |
| `job_review.csv` | 86 flagged blocks, 196,941 jobs spread from 31 of them |
| Gates | 58 passed, 1 warning (86 job corrections await the owner's confirmation), 0 failed |

Two runs on the same inputs give the same bytes in all five files.

## The job review

LODES reports some employers at one payroll address. `job_review.csv` lists every block with 5,000 jobs or more, or
2,000 or more with one sector at 90 %. `corrections/<region>.jobs.json` says what to do with each (`keep`, `spread`,
`cap`, `drop`); a flagged block without an entry is capped automatically. To review:

1. Open `job_review.csv` of the latest build, sort by `c000`, open `map_url` of each row you are unsure of.
2. Edit the entry in `corrections/dc.jobs.json` (`reason`, `c000_at_review`, `reviewed`; set `"confirmed": true` or
   remove the key once the owner agrees) and raise `version`.
3. Rebuild with `--offline` and read the row again. Gates G8, G8b, G11 and G13 must still pass.

The committed `dc` file is a first pass over all 86 blocks made from the data and the map, not from a visit, and every
entry carries `"confirmed": false`. Section 15.2 of the specification has the decision table.

## A new quarter, a new region

Quarterly refresh: section 15.1 of the specification. A new LODES vintage changes the job check values: update `lodes`
and `checks` in the region file from the `value` column of the failed gates, after you have explained each change.

A new region needs `regions/<id>.json` (states, counties with their official counts, fetch box, check values) and
`corrections/<id>.jobs.json` (may start with no entries). Run once, read `_failed/manifest.json`, fill in the check
values you have verified against an independent source, run again.

## Tests

```bash
cd tools/truck-etl && npm test
```

About 150 tests in 3 seconds, no network. They cover each module, the download rules against a local HTTP server, and
an end-to-end build of the committed fixture `test/fixtures/mini` (Falls Church city, Virginia: 164 real census blocks
and the real OpenStreetMap elements of the area, 195 kB), run twice and compared byte for byte, and once more as an
online build against a stand-in for the download hosts. No test contacts a real host.

The fixture is cut from the real `dc` raw files by a developer tool; rerun it only when the rules of the pipeline
change on purpose, and commit the result:

```bash
node tools/truck-etl/test-support/make-mini-fixture.mjs --raw-dir=storage/truck/raw
```

The same fixture, built with the repository seed file, is a small valid region for backend tests:

```bash
node tools/truck-etl/bin/build-region.mjs --offline \
     --region-file=tools/truck-etl/test/fixtures/mini/mini.json \
     --corrections=tools/truck-etl/test/fixtures/mini/mini.jobs.json \
     --raw-dir=<a copy of tools/truck-etl/test/fixtures/mini/raw> --out=<dir>
```

(Use a copy of the fixture's `raw` directory: a build writes `index.json` into its raw directory.)

## Layout

| Path | What |
|---|---|
| `bin/build-region.mjs` | the build command |
| `bin/adopt-raw.mjs` | puts files you already have into the raw cache layout |
| `bin/fetch-overpass-tiles.mjs` | development only: saves a tile set from the public Overpass server (one-off use, see its policy) |
| `regions/`, `corrections/` | one region file and one job corrections file per region |
| `src/pipeline.mjs` | stage order: download, blocks, jobs and corrections, places, source points and halo, cells, review list, gates, write |
| `src/download.mjs` | raw cache: resume, conditional requests, checksums, `index.json` |
| `src/zip.mjs`, `pl.mjs`, `csv.mjs`, `lodes.mjs`, `blocks.mjs` | census blocks and jobs |
| `src/corrections.mjs`, `review.mjs` | review rule, corrections, the review list |
| `src/pbf.mjs`, `overpass.mjs` | OpenStreetMap readers (the PBF reader has no dependency) |
| `src/taxonomy.mjs`, `normalise.mjs`, `hours.mjs`, `dedupe.mjs`, `counties.mjs`, `places.mjs` | from OSM tags to place rows |
| `src/points.mjs`, `cells.mjs`, `geo.mjs` | source points, halo, candidate cells, distances |
| `src/writers.mjs`, `manifest.mjs`, `gates.mjs` | output files, manifest, quality gates |
| `src/seeds.mjs`, `region.mjs`, `vocabulary.mjs`, `args.mjs` | inputs and fixed names |
| `test/`, `test-support/` | tests and fixture; helpers that write small PBF and ZIP files |

Place data comes from OpenStreetMap (© OpenStreetMap contributors, ODbL 1.0). The manifest carries the attribution
strings the application must show.
