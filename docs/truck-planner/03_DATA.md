# Truck Planner - 03 Data (geodata specification)

Binding inputs: [BLUEPRINT.md](BLUEPRINT.md), [DECISIONS.md](DECISIONS.md) sections 0 and 8. Where this file and
DECISIONS.md disagree, DECISIONS.md wins. The math is defined in `02_MODEL.md` and the reference implementation, the
assumptions in `reference/tp_seeds.json` (read here at `seeds_revision` 1). This file defines the data that feeds them:
sources, the pipeline (`tools/truck-etl`, Node >= 20, only dependency `h3-js` 4.5.0), the MySQL reference tables, the
loader (`scripts/truck/load-region.php`), the cell pack, the quality gates and the operator procedures.

Evidence tags: **[V]** measured on real files on 2026-10-04 (source recon, the prototype run for this document on the
recon block file, the four LODES 2023 files, 16 saved OpenStreetMap tiles, `h3-js` 4.5.0 and the seed file, or the
statements of section 9 on a scratch MySQL 8.0.45). **[run]** measured by the first run of the pipeline itself
(`tools/truck-etl`, 2026-10-05): the four Geofabrik extracts of 2026-10-03, seeds revision 1 and the first-pass
corrections file `2026-10-05.1`, giving dataset `dc-20261003-d0514a63` with every pipeline gate of section 12 passed.
**[proto]** prototype figure: measured for this document with the seed values of revision 1 (construction jobs at 0.3,
both pruning tests of 7.2), the seven starting entries of 4.3 with the automatic treatment for the other 79 flagged
blocks, and the tile-set places, which hold no stations. A [proto] figure is indicative. Where a [run] figure stands
beside it, the [run] figure is the one to expect. Gate ranges, not [proto] or [run] figures, decide a build.
**[load]** measured by the first load of that build with `scripts/truck/load-region.php` (2026-10-05: PHP 8.2 without
opcache on a laptop, MySQL 8.0.45 on the same machine), every loader gate of section 12 passed. **[S]**
specified, not yet exercised. **[M]** from memory, confirm before relying on it. Not exercised at all: Overpass
`out tags bb` and the tile fetch helper against the live server, the pipeline's downloads against the real hosts (the
download code is tested against a local HTTP server and a stand-in for the hosts, and the first run made no request:
it used files fetched earlier and adopted from the raw cache), the loader on the production server (2 vCPU, PHP 8.3),
the Google requests of 13.3 and 13.4, EIA with a registered key.

## 0. Fixed choices and names

| Item | Value |
|---|---|
| Pipeline version | `tp-etl-1.0.0` (bump on any change to rules, formats or column lists in this file) |
| Model version | `tps-0.1.0` |
| Region membership of a block | GEOID prefix: first five characters of the 15-digit block GEOID are in the region's county list |
| Block coordinate | Census 2020 internal point (`INTPTLAT`, `INTPTLON` of the PL 94-171 geo header), for residents and jobs. The LODES crosswalk point is never used |
| Distance | Haversine on a sphere, R = 6,371,008.8 m. `d = 2R asin(min(1, sqrt(sin^2(dLat/2) + cos(lat1) cos(lat2) sin^2(dLng/2))))` |
| Cell grid | H3 resolution 9. Ids are 15-character lowercase hex strings. `h3-js` argument order is (lat, lng) |
| Identifiers | Block GEOIDs, H3 ids and OSM ids are strings everywhere. Never parse a GEOID as a number |
| Coordinate order | Files, tables and JSON in this document: `lat` then `lng`, decimal degrees WGS84. GeoJSON input is `[lng, lat]` |
| Text files | UTF-8, LF line endings, no BOM, final newline |
| Numbers in text files | JavaScript `Number.prototype.toString()` (shortest decimal that round-trips a double, exponent form allowed, e.g. `1e-7`). PHP reads them with `(float)` |

### 0.1 Seed values the pipeline reads

The pipeline reads `docs/truck-planner/reference/tp_seeds.json` through one adapter module, `tools/truck-etl/src/seeds.mjs`,
which is the only place that knows the JSON paths. A missing value is a hard error that names the path (exit 1), and so
is a value of the wrong type or outside its range: `cns04Weight` and every `host_fit` in 0 to 1, sizes and thresholds
not negative, segment and rival-kind names from the shared vocabulary, `kitchen_default` `yes` or `no`, a row for each
of the 22 place types, and each of CNS01 to CNS20 in exactly one `lodes_cns` list.

| Adapter name | Seed path | Value at revision 1 | Used for |
|---|---|---|---|
| `modelVersion`, `seedsRevision` | `model_version`, `seeds_revision` | `tps-0.1.0`, 1 | manifest, pack header, `dataset_version` (section 8) |
| `earthRadiusM` | `constants.earth_radius_m.value` | 6371008.8 | all distances |
| `walkDecayM` | `kernel.walk_decay_m.value` | 400 | pruning score (7.2) |
| `walkCutoffM` | `kernel.walk_cutoff_m.value` | 1200 | candidate cells, halo, pruning |
| `segmentCns[segment]` | `segments.<segment>.lodes_cns` for the seven `w_` segments | table in 2.2 | grouping jobs into segments |
| `placeTypes[type]` | `place_types.rows.<type>`: `visitor_segment`, `default_size`, `rival_kind`, `host_fit`, `kitchen_default` | summary in 5.3 | sections 5 and 6 |
| `cns04Weight` | `etl.cns04_weight.value` (required) | 0.3 | construction jobs weight (4.5) |
| `cellMinNearby` | `etl.cell_min_nearby.value` (required) | 100 | pruning threshold (7.2) |
| `cellMinVenue` | `etl.cell_min_venue.value` (required) | 15 | pruning threshold for venue visitors (7.2) |
| `hasTrafficMatrix(name)` | `traffic.<name>` and `traffic.<name>_typical` | both present for `dc` and `us_mean` | existence check for the region file's `traffic_matrix` (section 1) |

All of these values have seed scope `build`: changing one means rebuilding the region. (The traffic matrix is only
checked for existence. Its scope is `fixed` and it is not a build parameter.) The loader (PHP) takes the other
build-scope kernel constants from the PHP model, never from the pipeline: `kernel.outside_option_a0` (1.6),
`kernel.rival_weight.<kind>.day|eve`, `hours.regime_of_hour`, `kernel.visibility.normal` (1.0). The manifest records
every value the pipeline used (8.5). The loader compares each one with what the PHP model's seed loader returns and
refuses the build on any difference. The seed file's SHA-256 is recorded for information only, because seeds of other
scopes may change without affecting a region build. `model_version` and `seeds_revision` are part of the
`dataset_version` (section 8): a change to a build-scope seed needs a new `seeds_revision` (02_MODEL.md 2.2) and so
gives a new version, even when the seed is one that only the loader reads and no pipeline output changes.

## 1. Region definition file

Path `tools/truck-etl/regions/<id>.json`. One file per region. `dc.json`, complete:

```json
{
  "schema": 1,
  "id": "dc",
  "name": "Washington, DC region",
  "cbsa": "47900",
  "cbsa_name": "Washington-Arlington-Alexandria, DC-VA-MD-WV",
  "delineation": "OMB list 1, July 2023",
  "timezone": "America/New_York",
  "traffic_matrix": "dc",
  "h3_res": 9,
  "membership": "geoid_prefix",
  "block_point": "census_intpt",
  "map_center": {"lat": 38.9072, "lng": -77.0369},
  "fetch_box": {"south": 37.95, "west": -78.40, "north": 39.75, "east": -76.62},
  "states": [
    {"usps": "dc", "fips": "11", "pl_dir": "District_of_Columbia", "geofabrik": "district-of-columbia"},
    {"usps": "md", "fips": "24", "pl_dir": "Maryland", "geofabrik": "maryland"},
    {"usps": "va", "fips": "51", "pl_dir": "Virginia", "geofabrik": "virginia"},
    {"usps": "wv", "fips": "54", "pl_dir": "West_Virginia", "geofabrik": "west-virginia"}
  ],
  "census": {"product": "PL 94-171", "year": 2020, "reference_date": "2020-04-01"},
  "lodes": {"release": "LODES8", "format": "8.4", "year": 2023, "segment": "S000", "job_type": "JT00", "vintage": "20251202_1657"},
  "fuel_area_by_state": {"DC": "R1Y", "MD": "R1Y", "VA": "R1Z", "WV": "R1Z"},
  "holidays": {"inauguration_day": true, "inauguration_day_counties": ["11001", "24031", "24033", "51013", "51059", "51510", "51610"]},
  "job_review": {"total_min": 5000, "single_sector_min": 2000, "single_sector_share": 0.9},
  "counties": [
    {"fips": "11001", "name": "District of Columbia", "state": "DC", "residents": 689545, "housing_units": 350364, "jobs": 673312, "blocks": 6012},
    {"fips": "24017", "name": "Charles County", "state": "MD", "residents": 166617, "housing_units": 62123, "jobs": 37882, "blocks": 2058},
    {"fips": "24021", "name": "Frederick County", "state": "MD", "residents": 271717, "housing_units": 103493, "jobs": 108637, "blocks": 4667},
    {"fips": "24031", "name": "Montgomery County", "state": "MD", "residents": 1062061, "housing_units": 404423, "jobs": 491378, "blocks": 9513},
    {"fips": "24033", "name": "Prince George's County", "state": "MD", "residents": 967201, "housing_units": 359957, "jobs": 318010, "blocks": 8750},
    {"fips": "51013", "name": "Arlington County", "state": "VA", "residents": 238643, "housing_units": 119085, "jobs": 166762, "blocks": 2146},
    {"fips": "51043", "name": "Clarke County", "state": "VA", "residents": 14783, "housing_units": 6371, "jobs": 3824, "blocks": 529},
    {"fips": "51047", "name": "Culpeper County", "state": "VA", "residents": 52552, "housing_units": 19185, "jobs": 16635, "blocks": 1055},
    {"fips": "51059", "name": "Fairfax County", "state": "VA", "residents": 1150309, "housing_units": 427149, "jobs": 683496, "blocks": 8930},
    {"fips": "51061", "name": "Fauquier County", "state": "VA", "residents": 72972, "housing_units": 28249, "jobs": 23349, "blocks": 1600},
    {"fips": "51107", "name": "Loudoun County", "state": "VA", "residents": 420959, "housing_units": 142074, "jobs": 195681, "blocks": 5882},
    {"fips": "51153", "name": "Prince William County", "state": "VA", "residents": 482204, "housing_units": 158525, "jobs": 139622, "blocks": 4119},
    {"fips": "51157", "name": "Rappahannock County", "state": "VA", "residents": 7348, "housing_units": 3826, "jobs": 1514, "blocks": 444},
    {"fips": "51177", "name": "Spotsylvania County", "state": "VA", "residents": 140032, "housing_units": 52250, "jobs": 40183, "blocks": 1859},
    {"fips": "51179", "name": "Stafford County", "state": "VA", "residents": 156927, "housing_units": 52793, "jobs": 42246, "blocks": 1601},
    {"fips": "51187", "name": "Warren County", "state": "VA", "residents": 40727, "housing_units": 16739, "jobs": 13436, "blocks": 1047},
    {"fips": "51510", "name": "Alexandria city", "state": "VA", "residents": 159467, "housing_units": 80479, "jobs": 79808, "blocks": 1230},
    {"fips": "51600", "name": "Fairfax city", "state": "VA", "residents": 24146, "housing_units": 9330, "jobs": 24910, "blocks": 310},
    {"fips": "51610", "name": "Falls Church city", "state": "VA", "residents": 14658, "housing_units": 6172, "jobs": 10691, "blocks": 164},
    {"fips": "51630", "name": "Fredericksburg city", "state": "VA", "residents": 27982, "housing_units": 12175, "jobs": 24298, "blocks": 497},
    {"fips": "51683", "name": "Manassas city", "state": "VA", "residents": 42772, "housing_units": 14365, "jobs": 22287, "blocks": 409},
    {"fips": "51685", "name": "Manassas Park city", "state": "VA", "residents": 17219, "housing_units": 5525, "jobs": 5010, "blocks": 103},
    {"fips": "54037", "name": "Jefferson County", "state": "WV", "residents": 57701, "housing_units": 23762, "jobs": 17187, "blocks": 1690}
  ],
  "checks": {
    "state_residents": {"11": 689545, "24": 6177224, "51": 8631393, "54": 1793716},
    "state_blocks": {"11": 6012, "24": 83827, "51": 163491, "54": 72558},
    "residents": 6278542, "housing_units": 2458414, "jobs": 3140158, "blocks": 64615,
    "blocks_with_residents": 48034, "blocks_with_jobs": 30247, "blocks_with_either": 51262,
    "jobs_by_segment": {"w_office": 1076149, "w_health": 356462, "w_edu": 292467, "w_retail": 261318, "w_industrial": 390336, "w_hospitality": 323428, "w_public": 439998},
    "cns04_jobs": 165397,
    "res9_cells_with_residents_or_jobs": 29404, "res9_max_residents": 4512, "res9_max_jobs_raw": 39467, "res9_max_jobs_after": 25000,
    "job_review": {"blocks": 86, "jobs": 572153},
    "job_movement": {"discarded_share_max": 0.005, "moved_share_max": 0.12},
    "halo_residents_share_max": 0.05,
    "places": {"min": 22000, "max": 28000},
    "rivals": {"min": 12100, "max": 14900},
    "hosts_with_contact_share": {"min": 0.28, "max": 0.40},
    "cells_kept": {"min": 54000, "max": 64000},
    "anchors": [
      {"geoid": "510594402021008", "label": "Inova Fairfax Hospital", "column": "CNS16", "min": 11000},
      {"geoid": "240317050005004", "label": "NIH, Bethesda", "column": "CNS20", "min": 16000},
      {"geoid": "110010002012001", "label": "Georgetown University", "column": "CNS15", "min": 10000},
      {"geoid": "511079801001010", "label": "Dulles airport", "column": "CNS08", "min": 8000}
    ],
    "h3_probe": {"lat": 38.9696, "lng": -77.3861, "res": 9, "cell": "892aaab3043ffff"}
  }
}
```

Notes. All check values are [V]: the per-county rows equal the official 2020 county counts and the LODES 2023 `JT00` sums.
`checks.jobs*`, `cns04_jobs`, `job_review` and `res9_max_jobs_raw` are tied to `lodes.vintage`. With another vintage they
become warnings (section 12). `checks.jobs_by_segment` are raw sector sums (CNS04 at weight 1, before corrections): they
do not depend on seeds, and G7 compares raw sums. The weighted `w_industrial` total (274,558.1 at seeds revision 1, see
4.5) is checked by G8 and recorded in `manifest.totals`. `traffic_matrix` names the seed matrix `traffic.<name>` (and
`traffic.<name>_typical`) that `Assumptions.region.traffic_matrix` uses (02_MODEL.md section 3): `dc` or `us_mean` at
seeds revision 1. A region file without the key, that is a region without its own matrix, uses `us_mean`. The pipeline
fails if `traffic.<name>` or `traffic.<name>_typical` is missing from the seed file, and the loader checks the same in
G19. `fuel_area_by_state` maps the state of the truck's base (found with Q4 of 9.3) to an EIA `duoarea`. A base with no
state uses `NUS`. `holidays.inauguration_day` is the flag the holiday function reads
(`Assumptions.region.flags.inauguration_day`). Version 1 applies it to the whole region. `inauguration_day_counties` is
kept for a later per-county rule (statutory list [M], confirm against 5 U.S.C. 6103(c)). `map_center` is the default map
view only.

Gate limits that differ from region to region are check values too, so that a second region (and the test fixture of
section 12) needs no code change: `res9_max_jobs_after` (G11), `job_movement` (G8b), `halo_residents_share_max` (G16),
`places`, `rivals` and `hosts_with_contact_share` (G12) and `cells_kept` (G17). Section 12 says how each is used.

The pipeline validates the file when it reads it and stops with exit 1 on the first problem, naming the field:
`schema` 1, `id` matching `[a-z0-9]{1,24}`, `h3_res` 9, `membership` `geoid_prefix`, `block_point` `census_intpt`,
`fetch_box` with south < north and west < east, at least one state (lower-case `usps`, two-digit `fips`), every county
a five-digit `fips` of a listed state and listed once, `census`, `lodes` and `job_review` as shown, and every key of
`checks` shown above present with the type shown. An anchor block must lie in a county of the region. `cbsa`,
`cbsa_name`, `delineation`, `fuel_area_by_state` and `holidays` are not read by the pipeline. They reach the loader
through the manifest, which carries the file verbatim.

## 2. Sources

All four are fetched by the pipeline only. Nothing here is requested at run time. No API key is needed for any of them.
The OpenStreetMap extract is a data download in the same sense as the Census files (DECISIONS 0): it is not a map or
routing provider, and it is isolated behind the pipeline so another source can replace it later. The pipeline's
download hosts (`www2.census.gov`, `lehd.ces.census.gov`, `tigerweb.geo.census.gov`, `download.geofabrik.de`, and
`overpass-api.de` for the development helper) are build-time only. The runtime host allow-list tests must either skip
`tools/truck-etl` or give that directory this list.

### 2.1 Residents and block points - 2020 Census PL 94-171 state files

| | |
|---|---|
| URL | `https://www2.census.gov/programs-surveys/decennial/2020/data/01-Redistricting_File--PL_94-171/{pl_dir}/{usps}2020.pl.zip` |
| Sizes [V] | dc 1,366,469 B. md 18,262,586 B. va 28,074,582 B. wv 9,611,980 B. `Last-Modified` 12 Aug 2021. `Accept-Ranges: bytes` |
| Member read | `{usps}geo2020.pl` only (dc 3.7 MB, md 53.6 MB, va 92.1 MB, wv 40.2 MB unzipped). Other members are ignored |
| Format | Fields separated by the vertical bar (ASCII 124), no header row, exactly 97 fields per line. Decode as latin-1. Use only the fields below |
| Vintage | Counts as of 2020-04-01. Fixed forever. Not scaled |
| Licence | US government work, public domain. Citation in section 14 |

Fields read (position is 1-based, index 0-based):

| Position | Index | Name | Use |
|---:|---:|---|---|
| 3 | 2 | SUMLEV | keep rows with `750` (block) |
| 10 | 9 | GEOCODE | 15-digit block GEOID, string |
| 85 | 84 | AREALAND | land area m2 (review list only) |
| 91 | 90 | POP100 | residents |
| 92 | 91 | HU100 | housing units (gates only) |
| 93 | 92 | INTPTLAT | latitude, e.g. `+38.9100683` |
| 94 | 93 | INTPTLON | longitude, e.g. `-077.0528631` |

ZIP reading without a dependency (Node has zlib but no archive reader): find the end-of-central-directory record
(signature `0x06054b50`, scan back from the end of the file), read its central directory offset (bytes 16..19) and entry
count (bytes 10..11). Walk central headers (signature `0x02014b50`: method at +10, CRC-32 at +16, compressed size at +20,
uncompressed size at +24, name length at +28, extra length at +30, comment length at +32, local header offset at +42, name
at +46). For the wanted member read the local header (signature `0x04034b50`, name length at +26, extra length at +28,
data at `+30 + name + extra`), inflate with `zlib.inflateRawSync` (method 8) and verify the CRC-32 with a table-driven
implementation. Fail on method other than 8, on any size field equal to `0xFFFFFFFF` (zip64), or on a CRC mismatch.

Pitfalls. Field 50 (index 49, CBSA) is the 2020-era delineation and must not be used for membership. Block values carry
disclosure-avoidance noise. 16,581 of the 64,615 region blocks have zero residents. The four files parse in about 2 s [V].

### 2.2 Jobs - LEHD LODES 8.4 workplace area characteristics

| | |
|---|---|
| WAC URL | `https://lehd.ces.census.gov/data/lodes/LODES8/{usps}/wac/{usps}_wac_S000_JT00_2023.csv.gz` |
| Crosswalk URL (labels only) | `https://lehd.ces.census.gov/data/lodes/LODES8/{usps}/{usps}_xwalk.csv.gz` |
| Version, checksums | `https://lehd.ces.census.gov/data/lodes/LODES8/{usps}/version.txt` and `.../{usps}/lodes_{usps}.sha256sum` |
| Sizes [V] | WAC gz: dc 113,247 B (4,020 rows). md 799,052 B (30,936). va 1,340,963 B (55,775). wv 355,443 B (15,480). Crosswalk gz: 106,754 / 1,531,247 / 2,927,130 / 1,296,897 B (83 MB unzipped for va) |
| Format | CSV, UTF-8, LF, header row. WAC has 53 columns and no quoted fields. The crosswalk has 41 columns and quoted fields that contain commas, so it needs a real CSV parser |
| Vintage | Data year 2023, format 8.4, `Data Vintage: 20251202_1657` (line 3 of `version.txt`). `w_geocode` is a 2020 block |
| Licence | US government work, public domain. Citation in section 14 |

WAC columns read: position 1 `w_geocode` (string), 2 `C000` (all jobs), 9 to 28 `CNS01` to `CNS20`. Header must be exactly
53 names, starting `w_geocode,C000,CA01` with `CNS01` at position 9 and `CNS20` at position 28. Crosswalk columns read:
1 `tabblk2020`, 6 `ctyname`, 16 `stplcname`, 36 `milname`. Header must be 41 names starting `tabblk2020`.

Sector to segment grouping. The pipeline reads it from `segments.<segment>.lodes_cns` in the seed file and fails
unless each of CNS01 to CNS20 appears in exactly one list. Content at seeds revision 1, with the region's raw 2023 sums
[V] (every sector at weight 1; `cns04Weight` is applied only when the segment base is built, 4.5):

| Segment | WAC columns | Region jobs |
|---|---|---:|
| `w_office` | CNS09 Information, CNS10 Finance and insurance, CNS11 Real estate, CNS12 Professional and technical, CNS13 Management of companies, CNS14 Administrative and support | 1,076,149 |
| `w_health` | CNS16 Health care and social assistance | 356,462 |
| `w_edu` | CNS15 Educational services | 292,467 |
| `w_retail` | CNS07 Retail trade | 261,318 |
| `w_industrial` | CNS01 Agriculture, CNS02 Mining, CNS03 Utilities, CNS04 Construction, CNS05 Manufacturing, CNS06 Wholesale, CNS08 Transportation and warehousing | 390,336 |
| `w_hospitality` | CNS17 Arts and recreation, CNS18 Accommodation and food services | 323,428 |
| `w_public` | CNS19 Other services, CNS20 Public administration | 439,998 |
| | all 20 | 3,140,158 |

Pitfalls. Jobs are jobs of record at the employer's address, not people present. Values are noise-infused. No
self-employed and no uniformed military. `lodes_{usps}.sha256sum` hashes the **uncompressed** CSV (lines are
`<64 hex><two spaces><file name without .gz>`). 2022 and 2023 have no WAC for Alaska and Michigan, so a later region must
pick the newest year per state. `createdate` is not the vintage. `ctyname` and the other crosswalk geographies are
"current" geography and differ from the GEOID prefix for a handful of blocks, which is why membership never uses them.

### 2.3 Places - OpenStreetMap from Geofabrik state extracts

| | |
|---|---|
| URL | `https://download.geofabrik.de/north-america/us/{geofabrik}-latest.osm.pbf` answers 307 with `Location: .../{geofabrik}-{YYMMDD}.osm.pbf`. Resolve the redirect, then download and store the **dated** name |
| Checksum | `{dated url}.md5` (one line, `<32 hex><two spaces><name>`) [V: fetched on 2026-10-05 for the four `261003` extracts, all four match] |
| Sizes [V] | dc 21,012,746 B. md 215,014,445 B. va 427,970,239 B. wv 99,014,658 B. Daily. Headers: `Last-Modified`, `ETag`, `Accept-Ranges: bytes` |
| Snapshot rule | All states of a region must resolve to the same `YYMMDD`. If they differ, stop and re-run. `--osm-date=YYMMDD` pins a date: extracts of that date already in the raw cache are used, a missing one is fetched from its dated address |
| Licence | ODbL 1.0. Duties and strings in section 14 |

#### PBF reader (in repo, zero dependencies: `fs` and `zlib`)

File layout: repeated `[4-byte big-endian length L][BlobHeader, L bytes][Blob, datasize bytes]`. All messages are
protocol buffers. The reader needs four primitives: unsigned varint (accumulate 7 bits per byte in a double, exact to
2^53), zigzag decode (`n` even gives `n/2`, odd gives `-(n+1)/2`), length-delimited field iteration (wire types 0 varint,
1 skip 8 bytes, 2 length-delimited, 5 skip 4 bytes, anything else is an error), and packed arrays (plain varints, or
zigzag varints with a running sum for delta coding).

| Message | Fields used (number: meaning) |
|---|---|
| BlobHeader | 1: type string (`OSMHeader` or `OSMData`). 3: datasize |
| Blob | 1: raw bytes. 2: raw_size (varint, ignored). 3: zlib_data (inflate with `zlib.inflateSync`). Fields 4 to 7 (lzma, bzip2, lz4, zstd) are an error |
| HeaderBlock | 4: required_features (repeated string). 5: optional_features. 32: osmosis_replication_timestamp (seconds) |
| PrimitiveBlock | 1: StringTable (1: repeated bytes). 2: repeated PrimitiveGroup. 17: granularity (default 100). 19: lat_offset. 20: lon_offset (default 0) |
| PrimitiveGroup | 1: repeated Node. 2: DenseNodes. 3: repeated Way. 4: repeated Relation |
| DenseNodes | 1: ids (packed zigzag, delta). 8: lats, 9: lons (packed zigzag, delta). 10: keys_vals (packed, `key,val,...,0` per node; when field 10 is absent or empty no node of the group has tags) |
| Node | 1: id (zigzag). 2: keys, 3: vals (packed). 8: lat, 9: lon (zigzag) |
| Way | 1: id. 2: keys, 3: vals (packed string indexes). 8: refs (packed zigzag, delta) |
| Relation | 1: id. 2: keys, 3: vals. 9: memids (packed zigzag, delta). 10: member types (packed; 0 node, 1 way, 2 relation) |

Coordinates: `nano = offset + granularity * raw`, then `e7 = Math.round(nano / 100)`, `degrees = e7 / 1e7`.

Algorithm:

1. Index pass: walk the file reading only lengths and BlobHeaders. Record `(type, offset, datasize)` per blob.
2. Read the `OSMHeader` blob. Fail on any required_feature other than `OsmSchema-V0.6` and `DenseNodes`. Keep the
   replication timestamp for the manifest.
3. Data pass: visit `OSMData` blobs from **last to first**, one blob in memory at a time. The file must be sorted by
   type (nodes, then ways, then relations; Geofabrik marks this with the optional feature `Sort.Type_then_ID` [V]), so
   the reverse pass meets relations, then ways, then nodes. Enforce it while reading: after the first way no relation
   may appear, after the first node no way or relation. Fail otherwise. Before building a tag object, test the
   element's key strings against the trigger keys of 5.1.
4. Relation: keep if `type=multipolygon` and the taxonomy matches (5.1). Create a bounding-box accumulator. Register its
   way members in `needWay[wayId]` and its node members in `needNode[nodeId]`. Members that are relations are ignored.
   Other relation types are counted as `dropped_relation_type` (5.1).
5. Way: keep as a place if the taxonomy matches. If kept, or if `needWay` has its id, decode `refs` and register each
   node id in `needNode` with every accumulator that wants it.
6. Node: update every accumulator registered for its id (min and max of `e7` lat and lng). Keep as a place if the
   taxonomy matches.
7. Result per element: `osm_type`, `osm_id`, tags, and for ways and relations the bounding box. The point of a way or
   relation is the bounding-box centre, `centre_e7 = Math.floor((min_e7 + max_e7 + 1) / 2)` per axis. An accumulator that
   never received a node is counted as `dropped_no_geometry`.
8. Discard elements whose point lies outside the region's `fetch_box` before any further work (`dropped_outside_box`).
   The test is made on the decimal degrees that will be written (`e7 / 1e7`), edges included. A node is tested as soon
   as it is read, a way or relation when its box is complete.

An element is "read" when it carries a trigger key. Every read element is classified (5.1) before its geometry is
looked at, so the order of the drop reasons is: relation type, closed, no rule, unnamed, no geometry, outside the box.

Measured [V]: the DC extract parses in 0.8 s at 165 MiB RSS and reproduces 6,241 of 6,247 elements of an Overpass pull
taken two days later. The reader's assumptions hold on the four downloaded extracts: required features
`OsmSchema-V0.6` and `DenseNodes`, optional feature `Sort.Type_then_ID`, nodes then ways then relations with no mixed
block, granularity 100, offsets 0, a `raw_size` on every blob, no plain `Node` message. Measured [run]: the four
extracts (763 MB, 103.5 million nodes, 9.5 million ways, 91,441 relations) are read in 26 to 29 s. 5,972,925 elements
carry a trigger key, 5,818,235 of them match no rule (mostly plain buildings), and 33,160 classified elements lie in
the fetch box. One element has no geometry. Implementation note: besides the `needNode` map, wanted node ids are marked
in a bit set of 2^27 bits indexed by `id mod 2^27`, so that a node nobody asked for costs one array read instead of a
map lookup. This took the places stage from 40 s to 26 s and does not change the result.

#### Alternative input for development: a saved Overpass tile set

`--places-source=overpass-tiles --tiles-dir=<dir>` reads `t*.json.gz` files, each one Overpass JSON response. It exists so
that tests and development do not need 763 MB of extracts. It is never used for a production refresh (public Overpass
policy: one-off use only, no commercial backends). Rules: a tile with a top-level `remark` fails the build. Elements are
de-duplicated by `type/id`. The snapshot date is the UTC date of the largest `osm3s.timestamp_osm_base`. Nodes carry
`lat`/`lon`. Ways and relations carry `bounds` (`minlat, minlon, maxlat, maxlon`; preferred) or `center`. New tiles are
fetched with `out tags bb qt;` and the centre is computed from `bounds` with the same formula as step 7. With only
`center` (the 16 saved tiles of the source recon) the point is `Math.round(center.lat * 1e7)`,
`Math.round(center.lon * 1e7)`. An element with neither is counted as `dropped_no_geometry`. Relations are kept only
with `type=multipolygon`. Tiles are read in file-name order and count as separate inputs for the identity step of 5.4.
The fetch helper `tools/truck-etl/bin/fetch-overpass-tiles.mjs --region=<id> --contact=<email> [--out=<dir>]`
(developer tool) cuts the fetch box into tiles of at most 0.45 degrees named `t<row><col>` (row 0 in the south, column
0 in the west), sends the User-Agent of section 3, one `POST` at a time with the query as the form field `data`,
`[timeout:60]`. After 429 or 504 it waits 30 s times the attempt number and tries again, at most six times per tile. A
response with a `remark` splits the tile in four (`a` south-west, `b` south-east, `c` north-west, `d` north-east, at
most three levels deep). Any other status stops the run. Each answer is written as `<name>.json.gz`, by default under
`overpass/<region>/<YYYYMMDD>/` of the raw cache. Query body [S, this exact form was not run against the server; recon
ran an equivalent pair with `out tags center qt`]:

```
[out:json][timeout:60][bbox:{south},{west},{north},{east}];
(
  nwr["amenity"~"^(restaurant|fast_food|food_court|cafe|ice_cream|bar|pub|biergarten|hospital|university|college|bus_station|events_venue|conference_centre|exhibition_centre|theatre|cinema|arts_centre|marketplace)$"];
  nwr["craft"~"^(brewery|winery|distillery|cidery)$"];
  nwr["microbrewery"="yes"];
  nwr["shop"~"^(deli|bakery|pastry|coffee|convenience|supermarket|mall|department_store|wholesale|doityourself|furniture|garden_centre|car)$"];
  nwr["healthcare"="hospital"];
  nwr["railway"="station"];
  nwr["public_transport"="station"];
  nwr["leisure"~"^(stadium|fitness_centre|sports_centre|sports_hall|ice_rink|park|water_park)$"]["name"];
  nwr["tourism"~"^(hotel|museum|attraction|theme_park|zoo)$"]["name"];
  nwr["landuse"~"^(retail|industrial|commercial)$"]["name"];
  nwr["building"~"^(apartments|industrial|warehouse|office)$"]["name"];
  nwr["residential"~"^(apartments|condominium)$"]["name"];
  nwr["industrial"]["name"];
  nwr["man_made"="works"]["name"];
  way["office"]["name"];
  relation["office"]["name"];
);
out tags bb qt;
```

### 2.4 County polygons - TIGERweb

One request per region (23 features, 2.37 MB, 113,474 vertices, up to 3 rings per polygon, 3 MultiPolygons [V]):

```
https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/State_County/MapServer/1/query
  ?where=GEOID IN ('11001','24017',...,'54037')&outFields=GEOID,NAME,AREALAND,INTPTLAT,INTPTLON
  &returnGeometry=true&outSR=4326&geometryPrecision=5&f=geojson
```

URL-encode the `where` value. Response: GeoJSON FeatureCollection of Polygon and MultiPolygon features, `[lng, lat]`.
Only the property `GEOID` and the geometry are used. Require HTTP 200, exactly one feature per county of the region
file, no `exceededTransferLimit` and no `error` member. Public domain. Used only to assign a county to a place (5.5)
and for `manifest.bounds`. Blocks never use polygons.

## 3. Raw download cache

Default directory `storage/truck/raw/` (git-ignored), overridden by `--raw-dir=` or the environment variable `TP_RAW_DIR`.

```
storage/truck/raw/
  index.json                                   one entry per file (below)
  census/pl2020/{usps}2020.pl.zip
  lodes8/{usps}/{usps}_wac_S000_JT00_2023.csv.gz
  lodes8/{usps}/{usps}_xwalk.csv.gz
  lodes8/{usps}/version.txt
  lodes8/{usps}/lodes_{usps}.sha256sum
  tigerweb/counties_{region}.geojson
  geofabrik/{geofabrik}-{YYMMDD}.osm.pbf       plus the same name with .md5
  overpass/{region}/{YYYYMMDD}/t*.json.gz      development alternative only
```

`index.json` is a JSON list sorted by `path`, one entry per file:
`{"path", "url", "final_url", "http_status", "bytes", "sha256", "last_modified", "etag", "fetched_at"}`. `path` is
relative to the raw directory with forward slashes. `url` is the address asked for, `final_url` the one that answered
(for an extract: the dated address). `fetched_at` (UTC, ISO 8601) exists only here, never in the manifest. While a
download is under way the list also holds an entry for `<path>.part` with the validators of the response and
`"accept_ranges": true or false`. It is removed when the file is complete.

Download rules:

1. Header on every request: `User-Agent: TruckPlanner-ETL/1.0.0 (contact: <email>)`. The email comes from `--contact=`
   or the environment variable `TP_CONTACT_EMAIL`. If neither is set the pipeline never makes a request: it says so,
   uses the raw cache exactly as with `--offline`, and stops at the first file it would have to download. With
   `--offline` no request is made and no contact is needed. The pipeline never reads a `.env` file.
2. One request at a time. No parallel downloads. Redirects are not followed.
3. Write to `<name>.part`. If a `.part` exists and the server sent `Accept-Ranges: bytes`, resume with
   `Range: bytes=<size>-` and `If-Range: <stored ETag or Last-Modified>`. 206 appends, 200 restarts the file.
4. A file is complete when its size equals `Content-Length` and its check passes. Then rename atomically and update
   `index.json`. A response that ends short of its `Content-Length`, or a connection that breaks, stops the run and
   leaves the `.part` for the next one. The check runs on the `.part`: a download that fails it is removed and stops
   the run (gate G1), and a complete file of the same name that was already in the cache stays as it was. So the cache
   never holds a file under its name that failed its check.

| File | Check after download |
|---|---|
| LODES `.csv.gz` | gunzip, SHA-256 of the uncompressed bytes equals the entry in `lodes_{usps}.sha256sum` |
| `version.txt` | contains `Release Format Version 8.4`. A different format fails. A different `Data Vintage` is a warning |
| PL zip | central directory parses, member `{usps}geo2020.pl` inflates, CRC-32 matches |
| Geofabrik `.osm.pbf` | MD5 equals the `.md5` file. First blob is `OSMHeader` |
| TIGERweb GeoJSON | parses, feature set equals the county list |

5. An existing complete file is reused without a request when no request may be made (`--offline`, or no contact
   address: "offline" below), or when a conditional request (`If-None-Match`, else `If-Modified-Since`) answers 304. PL
   files and dated Geofabrik files (and their `.md5`) are immutable and are never re-requested once complete. Offline,
   a file found at its cache path without an `index.json` entry is adopted: its check runs, its SHA-256 is computed and
   an entry is written with `url` set to the address the file would be fetched from and `final_url`, `http_status`,
   `last_modified`, `etag` and `fetched_at` null. A missing `.md5` or `.sha256sum` sidecar is then a warning, not a
   failure (gate G1). A sidecar that is present is always used: a mismatch fails. Online, a file that has no entry is
   fetched again. A Geofabrik file must already have its dated name: offline and without `--osm-date`, the newest date
   for which every state of the region has an extract in the cache is used.
6. Every build verifies every file it uses: the size and SHA-256 of a cached file must equal its `index.json` entry
   (a difference stops the run with exit 1 and names the file), and the check of the table above runs again.
7. Any status other than 200, 206 (to a `Range` request), 304 (to a conditional request) or the Geofabrik 307 stops
   the run, and so does a request that cannot be made. There are no silent retries. A 416 to a resume request means
   that the `.part` is not the beginning of the file the server holds (or is already all of it): the `.part` is
   removed before the run stops, so the next run fetches the file from its start. The snapshot date is found with one
   `HEAD` request per state to the `-latest` address. An answer other than 307 with a dated `Location` stops the run.
8. Keep the raw files of the active and the previous dataset version. Geofabrik removes old dated files, so the cache
   is the archive of record. Disk use for `dc` is about 0.85 GB.

Files fetched by other means are put into this layout by hand or with the helper
`node tools/truck-etl/bin/adopt-raw.mjs --region=<id> --from=<dir> --raw-dir=<dir> [--counties=<geojson>]`. It finds
the files by name anywhere under `--from`, hard-links them into place (copies when linking is not possible), never
replaces a cache file and downloads nothing. An extract named `<state>-latest.osm.pbf` gets its dated name from the
UTC date of the replication timestamp in its header. Without one, the newest dated extract of the state is taken. A
LODES `version.txt` is accepted as `<usps>_version.txt` or as `version.txt` in a folder named `<usps>`. A name that
occurs twice under `--from` stops the helper, and so does a missing file (exit 1, with the list of what was not found;
checksum sidecars are optional). The next build that makes no request then adopts the files under rule 5.

## 4. Blocks and job corrections

### 4.1 Block table (in memory)

1. For each state, stream `{usps}geo2020.pl`. Keep rows with SUMLEV `750`. Record GEOID, POP100, HU100, AREALAND and the
   internal point for every block of the state (the state totals are gated). `in_region = county list contains GEOID[0..5)`.
2. Join WAC on `w_geocode`. A WAC block missing from the PL set fails the build. Verify `sum(CNS01..CNS20) = C000` per row.
   A block without a WAC row has no jobs.
3. Join the crosswalk for `ctyname`, `stplcname`, `milname` (review list only: the columns `county_name`, `place_name`
   and `military_name` of 8.4, verbatim). The crosswalk and PL block sets must be equal. Only the labels of the blocks
   that appear in the review list are kept in memory.

The table is sorted by GEOID (byte order). "Ascending GEOID order" below always means this order.

### 4.2 Review rule

A region block is **flagged** when rule A or rule B holds on raw counts (thresholds from `job_review` in the region file):

- A: `C000 >= total_min` (5,000).
- B: `C000 >= single_sector_min` (2,000) and `max(CNSxx) / C000 >= single_sector_share` (0.90).

`dc` today [V]: 86 flagged blocks holding 572,153 jobs (18.2 % of the region). 52 by rule A (456,528 jobs), 59 by rule B,
25 by both. Dominant sectors under rule B: public administration 17, education 14, health care 10, transportation 5,
professional 3, finance 3, information 2, five others 1 each. Payroll addresses (a school system reporting 39,466 jobs
at one block) and real concentrations (NIH, a hospital, a university, an airport) look the same in the data. That is
why a person reviews the list (15.2).

### 4.3 Corrections file

Path `tools/truck-etl/corrections/<region>.jobs.json`, committed to git, one per region.

```json
{
  "schema": 1,
  "region": "dc",
  "version": "2026-10-05.1",
  "lodes": {"year": 2023, "job_type": "JT00", "vintage": "20251202_1657"},
  "entries": {
    "510594525011000": {"action": "spread", "cap": 500, "c000_at_review": 39466, "reviewed": "2026-10-04", "confirmed": false,
                        "reason": "County school system payroll address: 38,385 education jobs at one block. Nominal 500 left for headquarters staff (assumed)."},
    "511539010141066": {"action": "spread", "cap": 500, "c000_at_review": 13373, "reviewed": "2026-10-04", "confirmed": false,
                        "reason": "County school system payroll address: 13,364 of 13,373 jobs are education."},
    "240317009011011": {"action": "spread", "cap": 1000, "c000_at_review": 13068, "reviewed": "2026-10-04", "confirmed": false,
                        "reason": "County government payroll address in Rockville: 12,679 public administration jobs. Nominal 1,000 left on site (assumed)."},
    "240317050005004": {"action": "keep", "c000_at_review": 18644, "reviewed": "2026-10-04", "confirmed": false, "reason": "NIH Bethesda campus. Real concentration."},
    "510594402021008": {"action": "keep", "c000_at_review": 12676, "reviewed": "2026-10-04", "confirmed": false, "reason": "Inova Fairfax Hospital. Real concentration."},
    "110010002012001": {"action": "keep", "c000_at_review": 10768, "reviewed": "2026-10-04", "confirmed": false, "reason": "Georgetown University. Real concentration."},
    "511079801001010": {"action": "keep", "c000_at_review": 10781, "reviewed": "2026-10-04", "confirmed": false, "reason": "Dulles airport. Real concentration."}
  }
}
```

The seven entries above are the start of the file for `dc` (identities from the source recon; the three `cap` values
are assumptions). The committed file holds one entry for each of the 86 flagged blocks: the first-pass review of this
build (15.2), made from the sector mix, the block size, the residents and the named OpenStreetMap features within 350 m
of each block point, not from a visit. `keep` for sites that plainly employ that many people there (hospitals,
campuses, airports, federal complexes, office towers), `spread` for administrative addresses (school systems, county
and city governments, staffing and home-health agencies). Every first-pass entry carries `"confirmed": false`, because
the first pass awaits the owner's confirmation (DECISIONS 8). The automatic treatment remains only for blocks that a
later vintage flags for the first time.

First-pass result for `dc` [run]: 55 blocks kept as reported (347,543 jobs) and 31 spread (224,610 jobs, of which
196,941 move and 27,669 stay on site).

| Decision | Blocks | What they are |
|---|---:|---|
| `keep` | 55 | 12 hospitals, 7 university campuses, 2 airports, 10 federal sites (NIH, Federal Triangle, the Capitol complex on both sides, Constitution Center, Suitland, the Secret Service, NOAA, Judiciary Square, the National Mall museums), 24 office towers, corporate campuses and other single employers (Tysons, Reston, Rosslyn, downtown Washington, Capital One, MITRE, AstraZeneca, two county office buildings, a casino, a parcel hub) |
| `spread`, cap 0 to 500 | 9 | school systems: Fairfax, Prince William, Montgomery, Loudoun, Arlington, Stafford, Spotsylvania, Alexandria, District of Columbia |
| `spread`, cap 300 to 2,500 | 7 | county, city and regional government addresses: Montgomery, Fairfax, Prince George's, Prince William, Frederick, the District, the park and planning commission |
| `spread`, cap 0 | 6 | single-sector lumps on residential blocks with no employer mapped, which look like jobs known only by ZIP code |
| `spread`, cap 200 to 1,500 | 6 | employers whose staff work elsewhere: a consulting headquarters, a guard or staffing firm, a building-services firm, a data-centre operator, a transit authority depot, a construction contractor |
| `spread`, cap 2,000 | 3 | not identified: the automatic cap is kept and the reason says so |

Six entries use `sectors` so that the other sectors of the block stay untouched. The reason of each entry is one
sentence and names what was assumed. Several identifications are inferences (the Smithsonian head count on the Mall,
ZIP-code lumps, the names of single employers): the owner's confirmation is the review that counts.

| Field | Meaning |
|---|---|
| key | 15-digit block GEOID. Any region block may have an entry, flagged or not |
| `action` | `keep`: leave as reported (reviewed, real). `cap`: leave `cap` jobs, discard the excess. `spread`: leave `cap` jobs, move the excess to the rest of the county. `drop`: remove the jobs (same as `cap` with 0) |
| `cap` | Jobs left in place, summed over the affected sectors. Required for `cap`. Default 0 for `spread`. Not allowed for `keep` and `drop` |
| `sectors` | Optional list such as `["CNS15"]`. The action then touches only those columns. Default: all 20. Not allowed for `keep` |
| `reason` | Required free text. Shown in the review list |
| `c000_at_review`, `reviewed` | Required. `C000` when the entry was written (a whole number), and the date (`YYYY-MM-DD`). Used to detect stale entries |
| `confirmed` | Optional boolean, default true. False = first-pass decision awaiting the owner's confirmation. The entry is applied either way |

The header keys `schema` (1), `region` (must equal the region id), `version` (non-empty) and `lodes` (an object, kept
for the reader: the pipeline does not compare it) are required. A file that breaks any rule of this table stops the
pipeline with exit 1 before any work, naming the entry and the field.

### 4.4 Applying corrections

Work on the 20 sector columns as doubles, on region blocks in ascending GEOID order.

1. Reduction. For a block with treatment `cap`, `spread` or `drop`: let `cur` = sum of the affected sectors, `keepJobs =
   min(cap, cur)`, `f = keepJobs / cur` (0 if `cur` is 0). Each affected sector becomes `raw * f`. Its excess is
   `raw * (1 - f)`. The block's own jobs count as reduced (`job_adj = 1`, 6.1) when the excess is above 0, that is when
   `cur > cap`.
2. Automatic default. A flagged block with no entry is treated as `spread` over all sectors with
   `cap = single_sector_min` if rule B holds, otherwise `cap = total_min`. So no unreviewed block keeps more than 5,000
   jobs and no unreviewed single-sector block more than 2,000.
3. Spread. After all reductions, for each spread source in ascending GEOID order: receivers are the region blocks of the
   same county with `POP100 > 0` whose treatment is none or `keep` (a block with a `cap`, `spread`, `drop` or automatic
   treatment never receives, whether or not anything was removed from it, so it can never exceed its cap). Receivers
   are taken in ascending GEOID order and receiver `r` gets, per sector, `excess * POP100_r / sum(POP100 of receivers)`,
   evaluated left to right. Residents are the weight because the employers in question (school systems, county
   governments, home health agencies) work where people live. If a county has no receiver, the excess is discarded and
   counted as `jobs_spread_lost`.
4. Results are fractional. They are not rounded.
5. Halo blocks (6.3) get no spread. A halo block that meets rule A or B is cut to the automatic cap (`f` as in step 1,
   over all sectors), the excess is discarded (`halo_jobs_capped` in the manifest) and its row carries `job_adj = 1`.
   No entry applies to a halo block.
6. An entry is `stale` when `|C000 - c000_at_review| / c000_at_review > 0.25` (when `c000_at_review` is 0: when `C000`
   is not 0), and `orphan` when the GEOID has no WAC row or is outside the region. Both are warnings and appear in the
   review list. They never stop the build. A stale entry is still applied exactly as written. An orphan entry is
   ignored. In `job_review.csv` an orphan row has empty county, coordinate, count and sector columns, `treatment` =
   the entry's action, `entry_state` = `orphan`, and sorts after all other rows by `geoid`.

Effect on `dc`. With the seven entries above and 79 automatic treatments [V, prototype with `cap` 0 for the three
spreads]: 286,284 jobs (9.1 %) move within their county (284,284, or 9.05 %, with the caps of 4.3; the pipeline
reproduces 284,284 exactly with that file), the region total stays 3,140,158 and the largest resolution-9 job cell
falls from 39,467 to 18,644. With the committed first-pass file of 86 entries [run]: 196,941 jobs (6.27 %) move from
31 blocks, nothing is discarded, the region total stays 3,140,158, the largest block after corrections is NIH (18,644
raw, 18,644.07 with the spread it receives), which is also the largest resolution-9 job cell (`892aa841903ffff`). The
four anchor blocks keep 11,208.0 / 16,919.0 / 10,382.3 / 8,121 jobs in their columns (Georgetown University has
residents and receives a share of the school-system jobs of the District).

### 4.5 Construction weight

`cns04Weight` (section 0.1) multiplies CNS04 when sectors are grouped into segments, after corrections:
`w_industrial = CNS01 + CNS02 + CNS03 + cns04Weight * CNS04 + CNS05 + CNS06 + CNS08`. LODES places construction workers
at the contractor's office, not on site, and CNS04 is 165,397 of the region's 390,336 industrial-segment jobs.
DECISIONS 8 sets the weight to 0.3 (seed `etl.cns04_weight`, tagged assumed). Expected region total of `w_industrial`:
`224,939 + cns04Weight * 165,397` = 224,939 + 0.3 x 165,397 = 274,558.1. The corrected worker base of the region is
then 3,024,380.1 (the 3,140,158 raw jobs less 0.7 x 165,397), before anything is discarded [V]. The pipeline applies
whatever the seed file holds and records it in the manifest.

The review list `job_review.csv` (8.4) shows every flagged block and every block with an entry, with its treatment
and outcome.

## 5. Place taxonomy

### 5.1 Tag rules (first match wins)

An element is tested only if it has one of the trigger keys `amenity, craft, microbrewery, shop, healthcare, railway,
public_transport, leisure, tourism, landuse, building, residential, industrial, man_made, office`. Rules are tried in
this order. The first rule whose test holds decides the place type. "Named required" means a `name` tag that is not
empty after trimming. The `brand` fallback of 5.6 does not count. An element that matches a rule but lacks a required
name is dropped (`dropped_unnamed`) and is not tried against later rules. "Any `key=*`" means the tag is present with a
non-empty value.

| # | Place type | Test on OSM tags | Named required |
|---|---|---|:---:|
| R01 | `taproom` | `craft` in {brewery, winery, distillery, cidery} or `microbrewery=yes` | yes |
| R02 | `fast_food` | `amenity` in {fast_food, food_court} or `shop=deli` | no |
| R03 | `restaurant` | `amenity=restaurant` | no |
| R04 | `cafe` | `amenity` in {cafe, ice_cream} or `shop` in {bakery, pastry, coffee} | no |
| R05 | `bar` | `amenity` in {bar, pub, biergarten} | no |
| R06 | `convenience` | `shop` in {convenience, supermarket} | no |
| R07 | `hospital` | `amenity=hospital` or `healthcare=hospital` | yes |
| R08 | `campus` | `amenity` in {university, college} | yes |
| R09 | `transit_station` | `railway=station` or `public_transport=station` or `amenity=bus_station` | yes |
| R10 | `stadium` | `leisure=stadium` | yes |
| R11 | `events_venue` | `amenity` in {events_venue, conference_centre, exhibition_centre, theatre, cinema, arts_centre} | yes |
| R12 | `hotel` | `tourism=hotel` | yes |
| R13 | `attraction` | `tourism` in {museum, attraction, theme_park, zoo} or `leisure=water_park` | yes |
| R14 | `farmers_market` | `amenity=marketplace` | yes |
| R15 | `gym` | `leisure` in {fitness_centre, sports_centre, sports_hall, ice_rink} | yes |
| R16 | `park` | `leisure=park` | yes |
| R17 | `shopping_centre` | `shop=mall` or `landuse=retail` | yes |
| R18 | `big_box` | `shop` in {department_store, wholesale, doityourself, furniture, garden_centre} | yes |
| R19 | `car_dealership` | `shop=car` | yes |
| R20 | `apartment_community` | `building=apartments` or `residential` in {apartments, condominium} | yes |
| R21 | `industrial_site` | `landuse=industrial` or `building` in {industrial, warehouse} or any `industrial=*` or `man_made=works` | yes |
| R22 | `office_park` | `landuse=commercial` or `building=office` or (any `office=*` and the element is a way or relation) | yes |

Before the rules, in this order: a relation whose `type` tag is not `multipolygon` is dropped
(`dropped_relation_type`), then an element with `disused=yes`, `abandoned=yes` or `shop=vacant` is dropped
(`dropped_closed`). Lifecycle prefixes such as `disused:amenity` are not read. No vocabulary additions: all 22 shared
place types are produced and no new type is introduced.

Dropped by design (counted as `dropped_no_rule`): places of worship, clinics, community centres, golf courses,
`office=*` nodes (individual tenants; OSM offices are a contact list, not a measure of workers), shops for sports,
electronics, hardware, outdoor gear and motorcycles, butchers, cheese and confectionery shops, motels and hostels, and
every element whose only trigger key is a plain `building` or `landuse` value.

Counters. Each occurrence of an element with a trigger key in an input (a state extract or a tile) is one element
read. It ends in exactly one place: a row of `places.ndjson`, or one of the counters `dropped_relation_type`,
`dropped_closed`, `dropped_no_rule`, `dropped_unnamed` (this section), `dropped_no_geometry`, `dropped_outside_box`
(2.3), `merged_identity`, `merged_same_venue`, `merged_site_part` (5.4), `dropped_outside_region` (5.5, 6.3). Gate G8
checks the sum.

### 5.2 Derived attributes

| Attribute | Rule |
|---|---|
| `geom_kind` | `point` for a node. `building` for a way or relation with a `building` tag other than `no`. `area` otherwise |
| `kitchen` (three-state) | For `taproom` and `bar`, from the tags: `no` if `food=no`. `yes` if `food=yes`, or `amenity` in {restaurant, fast_food, cafe, food_court}, or a non-empty `cuisine`. Otherwise `unknown`. For every other type: the type's `kitchen_default` (`yes` or `no`). Readers resolve `unknown` with the type's `kitchen_default` |
| `rival_kind` | Let `food` be the place type if it is one of `fast_food`, `restaurant`, `cafe`, `bar`, `convenience`, otherwise the type of the first of R02 to R06 that the element's tags also satisfy (only a `taproom` can have one), otherwise none. With a `food`: the seed `rival_kind` of that type, except none when `food` is `bar` and the resolved kitchen state is `no`. Without one: the seed `rival_kind` of the place type (none for every such type at revision 1). A place with a rival kind is a **rival** |
| `visitor_segment`, `size_default` | The type's `visitor_segment` and `default_size`. Both become none and 0 when the size is 0, and for a `campus` whose `geom_kind` is not `area`. A place with a segment is a **visitor source** |
| `host_fit` | The type's `host_fit`, forced to 0 when the place has no name (its `name` of 5.6 is null, so the `brand` fallback counts here). A place with `host_fit > 0` and `in_region = 1` is a **possible host**. It ranks Scout results and never enters an estimate. The model applies the same test whatever the type's `host_segment` (02_MODEL.md 4.16): a type without one, such as `farmers_market`, is a possible host ranked on its catchment alone |

Consequences at seeds revision 1 [V]. A bar is a rival unless it is tagged as serving no food (its default is `yes`).
A taproom is not a rival (the seed gives it no rival kind and a default of `no`) unless the same element is also tagged
as a restaurant, cafe, fast-food outlet or bar: in the region 2 taprooms become `full` and 5 become `bar`, 167 stay
hosts only [run: the same]. Twenty-nine of the 93 campus places are single buildings or points (satellite offices, one
hall of a larger campus) and would each carry a whole campus's default size, so only the 64 site polygons become
visitor sources. The
campus rule is this document's addition, and `02_MODEL.md` 2.3 accepts it. Scouting takes the host size from the
place's own `size_default` (`PlaceInput.size_default`, 02_MODEL.md 4.16), never from the seed row, so a campus building
stays a possible host that is ranked on its catchment alone.

### 5.3 Roles by place type (copied from `place_types.rows` at seeds revision 1; the seed file is binding)

| Seed field | Content by place type |
|---|---|
| `rival_kind` | `restaurant` full. `fast_food` quick. `cafe` cafe. `convenience` convenience. `bar` bar. Null for every other type, `taproom` included |
| `visitor_segment`, `default_size` | `taproom` v_nightlife 40. `bar` v_nightlife 45. `gym` v_leisure 50. `park` v_leisure 38. `shopping_centre` v_shopping 150. `big_box` v_shopping 200. `car_dealership` v_shopping 15. `campus` v_campus 400. `hospital` v_hospital 120. `transit_station` v_transit 300. `events_venue` v_events 80. `attraction` v_events 150. `hotel` v_lodging 90. `stadium` v_events with size 0, so no source row (use an event stop). Null and 0 for `restaurant`, `fast_food`, `cafe`, `convenience`, `farmers_market`, `office_park`, `apartment_community`, `industrial_site` |
| `host_fit` | `taproom` 1.0. `farmers_market`, `office_park` 0.8. `apartment_community` 0.7. `events_venue`, `industrial_site` 0.6. `big_box`, `car_dealership` 0.5. `gym`, `park`, `shopping_centre`, `campus`, `hospital` 0.4. `bar`, `stadium`, `hotel`, `attraction` 0.3. `transit_station` 0.1. 0 for `restaurant`, `fast_food`, `cafe`, `convenience` |
| `kitchen_default` | `yes`: `bar`, `restaurant`, `fast_food`, `cafe`, `convenience`, `shopping_centre`, `campus`, `hospital`, `stadium`, `hotel`, `attraction`. `no`: every other type |

Size means headcount in the venue's busiest regular hour. OSM `capacity` is unusable (89 of 42,616 elements [V]). In
version 1 every size is the type default from the seed file. `rooms`, `beds`, `capacity` and `building:levels` are kept in
`tags_json` for a later size rule. The seed row also carries `host_segment`, which the API reads from the seed file by
place type. It is not copied into the data.

### 5.4 De-duplication

De-duplication works on place rows, that is after classification (5.1), the fetch-box test (2.3), the derived
attributes (5.2) and normalisation (5.6), and before county assignment (5.5).

1. Identity: same `osm_type` and `osm_id` seen in two state extracts or two tiles. Inputs are read in the state order
   of the region file (tiles in file-name order). The first occurrence that reached this step is kept, every later one
   is counted as `merged_identity` and nothing is copied from it.
2. Same venue (`merged_same_venue`): same place type, equal comparable names (non-empty), distance <= 60 m. Comparable
   name = the `name` of 5.6 in NFKD, combining marks (`\p{M}`) removed, lower case, every `&` replaced by ` and ` with
   the spaces, every run of characters outside `a-z` and `0-9` to one space, trimmed. `AT&T`, `AT & T` and `AT and T`
   compare equal. A name without a Latin letter or digit is empty and never matches.
3. Site and its parts (`merged_site_part`): same place type and distance <= radius, regardless of name. Radii: `campus`
   400 m, `hospital` 250 m, `transit_station` 250 m, `stadium` 250 m, `shopping_centre` 150 m. Other types are not
   touched by this step.

Steps 2 and 3 each sort the rows by the order of preference: `geom_kind` (area, then building, then point), then
`osm_type` (relation, way, node), then lower `osm_id` (compared as a number). They take the rows in that order. A row is
dropped when an already kept row satisfies the step's test (distances are haversine, the limit included). If several
kept rows do, the one that comes first in the order of preference absorbs it: it fills its own empty `phone`,
`website`, `addr_line`, `city`, `state_code`, `postcode`, `cuisine` and `brand` from the dropped row, each field on its
own, and `opening_hours_raw` together with `hours_mask`. Roles and `tags` are never copied.

Measured with the pipeline on the tile set (fetch box): step 2 removes 196, step 3 removes 87 (shopping centre 37,
campus 31, hospital 16, stadium 3). On the extracts [run]: 165 by identity (elements on a state line), 199 by step 2,
124 by step 3 (the same four types plus 37 station parts).

### 5.5 Clipping and county assignment

Point-in-polygon against the TIGERweb features: even-odd ray casting over every ring of every polygon of a county, with a
bounding-box test first, counties tried in ascending FIPS order, first hit wins. A place inside a county gets
`county_fips` and `in_region = 1`. A place inside no county is dropped unless the halo rule keeps it (6.3), in which case
`in_region = 0` and `county_fips` is empty.

### 5.6 Normalisation

| Field | Rule |
|---|---|
| `name` | `name` tag, else `brand` tag (when the `name` tag is empty after the steps that follow). Collapse every run of whitespace to one space, trim, NFC, cut to 160 characters. Empty means no name (null) |
| `brand` | `brand` tag, trimmed, 120 characters |
| `phone` | First non-empty of `phone`, `contact:phone`. Take the part before the first `;`, `,`, `/` or " or ". Remove a trailing extension (`ext`, `ext.` or `x`, optional spaces, digits, at the end, any letter case). Delete every character that is not a digit or `+`. If the result starts with `+1`, remove those two characters. Else if it starts with `001`, remove those three. Else if it starts with `+`, the value is null. If 11 digits remain and the first is `1`, remove it. Accept exactly 10 digits matching `^[2-9]\d{2}[2-9]\d{6}$`. Store `+1` and the 10 digits. Anything else is null |
| `website` | First non-empty of `website`, `contact:website`. Part before the first `;`, trimmed. Null if it contains whitespace. Prefix `https://` when there is no scheme. Scheme must be `http` or `https`. Lower-case scheme and host. Host must match `^[a-z0-9.-]+\.[a-z]{2,}(:\d+)?$`. Null above 255 characters |
| `addr_line` | `addr:housenumber` + space + `addr:street`, then `, ` + `addr:unit` when present. If there is no street, `addr:full`. 200 characters |
| `city`, `state_code`, `postcode` | `addr:city` (80). `addr:state` upper-cased when it is two letters, else null. First five digits of `addr:postcode` when it matches `^\d{5}(-\d{4})?$`, else null |
| `cuisine` | Split `cuisine` on `;` and `,`. Trim, lower-case, spaces to `_`. Drop empties and repeats. First six, joined with `;`; drop values from the end until the joined string is at most 120 characters |
| `tags_json` | Object with those of `brand:wikidata, operator, takeaway, drive_through, outdoor_seating, delivery, rooms, beds, capacity, building:levels, food, email` that are present and not empty after trimming, values trimmed, keys sorted in byte order. Null when empty. The key in `places.ndjson` is `tags` |

Every tag value is trimmed before it is used, and a value that is empty after trimming counts as absent. Every length
limit counts Unicode code points and cuts on a code point boundary (`Array.from(s).slice(0, n).join('')`), which is how
MySQL counts `VARCHAR(n)`. Production MySQL is strict: one value longer than its column fails the whole 500-row insert,
and a cut inside a surrogate pair gives JSON that PHP `json_decode` rejects.

Measured in the region [run]: named 98.8 % (24,589 of 24,886). Every one of the 9,496 phone values and 11,458 website
values of the region normalises (in the whole fetch box 1 phone and 4 websites do not). Brand on 7,172 places, cuisine
on 9,087, street address on 16,981. The longest values written are far below the column sizes (name 84, website 209,
address 66, cuisine 58 characters), except `opening_hours_raw` (254).

### 5.7 Opening hours

Keep the trimmed raw string in `opening_hours_raw` (cut to 255 characters, null if absent or empty). Parse it into
`hours_mask` when the small grammar below accepts the whole string. Otherwise `hours_mask` is null, which means unknown,
never closed. A value longer than 255 characters is stored cut and is never parsed.

Preparation: trim, lower-case, collapse whitespace, remove spaces around `-`, `,` and `;`, remove trailing `;`. A piece
"begins with a day" when it starts with one of the eight tokens of `dayspec` followed by a character other than a
letter, a digit or `_`, or by the end (`sat` and `thu` do not qualify, so English day names are rejected).

```
value   := rule ( ";" rule )*
rule    := sub ( "," sub )*        a comma starts a new sub only when the next piece begins with a day
                                   and the current sub already contains a digit, "off" or "closed"
sub     := "24/7" | [days " "] spans | days " " ("off" | "closed") | days
days    := dayspec ( "," dayspec )*
dayspec := DAY [ "-" DAY ] | "ph"          DAY := mo | tu | we | th | fr | sa | su
spans   := span ( "," span )*
span    := H:MM "-" H:MM [ "+" ] | H:MM "+"     start < 24:00, end <= 48:00, minutes <= 59
```

Evaluation: keep a list of spans per weekday (minutes from that day's midnight).

1. A sub without days applies to all seven days. `mo-fr` expands in week order and wraps (`sa-mo` is Saturday, Sunday,
   Monday). The day part of a sub is everything before its first space. `24/7` sets every day to open all day.
2. A span whose end is not after its start ends on the next day (add 1,440). A span longer than 24 hours after that is
   rejected. `H:MM+` runs to 24:00. A `+` after a full span (`10:00-12:00+`) is read as the span itself.
3. `days` alone means open all day. `off` and `closed` give an empty list. Bare `off` or `closed` without days is rejected.
4. The first sub of a rule **replaces** the list of each day it names. Later subs of the same rule **add** to it.
5. `ph` is ignored: a sub naming only `ph` is validated and skipped. Holidays are handled by day types in the model.
6. Render every day's spans onto a 10,080-minute week (Monday 00:00 is minute 0), wrapping Sunday night into Monday.
7. Bit `h` (0..167, `h = dow*24 + hour`, Monday = 0) is set when at least 30 of that hour's 60 minutes are open.
8. `hours_mask` = 21 bytes as 42 lower-case hex characters. Bit `h` is bit `h & 7` (least significant first) of byte `h >> 3`.

Anything else makes the value unparsed: quoted comments, `sunrise`, `sunset`, `dawn`, `dusk`, month names, dates, week
numbers, `[n]` weekday selectors, `||`, `open`, `unknown`.

Test vectors [V]:

| Raw value | `hours_mask` | Open hours |
|---|---|---:|
| `24/7` | `ffffffffffffffffffffffffffffffffffffffffff` | 168 |
| `04:30-21:00` | `f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f` | 119 |
| `Mo-Fr 08:00-12:00,13:00-17:30` | `00ef0300ef0300ef0300ef0300ef03000000000000` | 45 |
| `Mo-Fr 10:00-18:00, Sa 10:00-17:00, Su 12:00-17:00` | `00fc0300fc0300fc0300fc0300fc0300fc0100f001` | 52 |
| `Mo-Th 11:00-22:00; Fr,Sa 11:00-02:00; Su 12:00-20:00` | `00f83f00f83f00f83f00f83f00f8ff03f8ff03f00f` | 82 |
| `Mo off; Tu-Su 16:00-22:45` | `00000000007f00007f00007f00007f00007f00007f` | 42 |
| `Mo-Su 11:00-21:00; PH off` | `00f81f00f81f00f81f00f81f00f81f00f81f00f81f` | 70 |
| `Mo-Su 09:00-18:00, Sa 08:00-15:00` | `00fe0300fe0300fe0300fe0300fe0300ff0300fe03` | 64 |
| `Fr 17:00+` | `0000000000000000000000000000fe000000000000` | 7 |
| `sunrise-sunset`, `"by appointment"`, `Mo-Su 10:00-17:30; Dec 25 off`, `off` | null | |

Measured [V]: 9,824 of 10,185 raw values in the fetch box parse (96.5 %; the pipeline's parser gives the same count on
the tile set). In the region [run] 7,112 of 7,384 (96.3 %). 46 % of rivals and 12 % of possible hosts have the tag at
all. Version 1 stores the mask and does not use it in the share model.

### 5.8 Expected result for `dc`

Two measurements with the rules of this section and seeds revision 1. "Extracts" is the first pipeline run on the four
Geofabrik extracts of 2026-10-03 [run]. "Tile set" is the same pipeline on the 16 saved Overpass tiles of 2026-10-05
(42,616 elements, no stations), which the prototype of this document had measured before: rivals, kitchen flags and
halo places agree with the prototype to the unit, and the region's place count is ten below the prototype's 24,724,
which is consistent with the 13 relations of a type other than multipolygon that the prototype had not excluded.

| Inside the 23 counties | Extracts | Tile set |
|---|---:|---:|
| Places after de-duplication | **24,886** | 24,714 |
| Rivals (full / quick / convenience / cafe / bar) | **13,508** (5,446 / 3,535 / 2,192 / 1,767 / 568) | 13,505 (5,443 / 3,535 / 2,193 / 1,766 / 568) |
| Kitchen flag from tags: `taproom` yes / unknown, `bar` yes / unknown | 9 / 165, 91 / 472 | 9 / 165, 91 / 472 |
| Visitor sources | 7,952 | 7,784 |
| Possible hosts, with phone or website | 11,941, 34.7 % | 11,772, 34.3 % |
| `geom_kind` area / building / point | 4,568 / 7,980 / 12,338 | 4,557 / 7,968 / 12,189 |
| Named | 98.8 % | 98.8 % |

| Whole inputs | Extracts | Tile set |
|---|---:|---:|
| Elements read (with a trigger key) | 5,972,925 | 42,745 |
| Relation of another type / closed / no rule / unnamed | 1,782 / 1,373 / 5,818,235 / 77,161 | 13 / 13 / 9,166 / 551 |
| No geometry / outside the fetch box | 1 / 41,213 | 0 / 29 |
| Merged: identity / same venue / site part | 165 / 199 / 124 | 108 / 196 / 87 |
| Outside every county and not in the halo | 7,396 | 7,475 |
| Rows of `places.ndjson` (region + halo) | 25,276 (24,886 + 390) | 25,107 (24,714 + 393) |

Region counts by type [run]: restaurant 5,444. fast_food 3,535. park 2,537. convenience 2,192. cafe 1,767.
apartment_community 1,697. office_park 1,518. gym 1,078. big_box 733. hotel 683. industrial_site 635. shopping_centre
574. bar 563. attraction 540. car_dealership 431. events_venue 349. taproom 174. transit_station 168. campus 93 (64
areas). farmers_market 84. hospital 58. stadium 33. Herndon and Sterling test box (`38.92,-77.48,39.06,-77.32`): 1,197
places, 685 rivals. The 168 stations (148 points, 10 buildings, 10 areas, after 37 station parts were merged) each add
a `v_transit` source of 300. The tile set has none because the recon query did not ask for stations.

## 6. Source points and rival rows

### 6.1 Block rows

One row per block with any base above 0 after corrections. `point_id = "b" + GEOID`. Coordinates are the internal point.
`b_res = POP100`. The seven worker columns are the sums of 2.2 over the corrected sectors, with `cns04Weight` (4.5).
Visitor columns are 0. `job_adj = 1` when the block's own jobs were reduced by a correction (4.4 step 1, and step 5
for a halo block cut to the automatic cap; receiving spread jobs does not set it). In the first build 32 rows carry
it: the 31 spread blocks and one halo block [run]. Worked example, block `110010001011000` before any spread it may
receive: POP100 607, C000 71 with CNS06 5, CNS09 1, CNS11 30, CNS12 8, CNS13 6, CNS14 11, CNS19 10. So `b_res` 607,
`b_w_office` 56, `b_w_industrial` 5, `b_w_public` 10, the other columns 0. In the first build this block has residents
and so receives its share of the jobs spread over the District: its row reads `b_w_office` 58.647..., `b_w_edu`
7.593..., `b_w_public` 23.244... [run].

### 6.2 Place rows

One row per visitor source (5.2). `point_id = "p" + place_key`, where `place_key` is `n`, `w` or `r` plus the decimal OSM
id (`w264230766`). Coordinates are the place point. The base of its visitor segment is `size_default`. All other columns
are 0. The seed that supplies the size is `place_types.rows.<type>.default_size`. `src_ref` is the `place_key`, which lets the API
exclude a host's own default row when a saved spot is linked to that place.

Host link rule (binding for 04_BACKEND.md). It decides `Host.point_id`, the id that `host_exclusion` (02_MODEL.md 4.4)
removes from the catchment. Q5, one place by key:

```sql
SELECT place_key, place_type, visitor_segment, lat, lng
  FROM tp_places
 WHERE region_id = ? AND dataset_version = ? AND place_key = ?
```

1. `Host.point_id = "p" + place_key` when Q5 returns a row with `visitor_segment` not null, otherwise null (a linked
   office park or campus building has no source row, so there is nothing to exclude).
2. If Q5 returns no row for a stored link (dangling after a refresh: the OSM element changed or lost a
   de-duplication), the server re-links to the nearest place of the same `place_type` (`Host.place_type`) within 100 m
   of the spot (Q3 of 9.3 with r = 100, haversine, ties by `place_key`), stores the new key, and clears the link when
   there is none.
3. When a host of a `v_` segment is saved without a link, the server links it the same way to the nearest place whose
   `visitor_segment` equals the host segment within 100 m, so the venue's default row is excluded and the venue is not
   counted twice.

Rival rows: a rival is not a source point. Every place with a `rival_kind` is written to the places file. The loader
builds its rival list `(place_key, lat, lng, kind)` from those rows, including `in_region = 0` ones.

### 6.3 Halo

People and outlets just outside the county line still count for a truck parked just inside it. Let S0 be the region
source points (6.1 for region blocks, 6.2 for places inside a county) and `c = walkCutoffM`.

| Kept with `in_region = 0` | Test |
|---|---|
| Blocks of the region's state files outside the county list, with residents or jobs | internal point within `2c` (2,400 m) of any S0 point |
| Visitor-source places outside every county, inside the fetch box | within `2c` of any S0 point |
| Rival places outside every county, inside the fetch box | within `3c` (3,600 m) of any S0 point |

`2c` covers every outside point within `c` of any candidate cell centre. `3c` covers every outside outlet within `c` of
such a point. Halo rows take part in the kernel and in nothing else: they are excluded from totals, gates, Scout and the
Data page. The halo covers only the region's own states (Pennsylvania north of Frederick County is not loaded).

Details. "With residents or jobs" is tested on the raw counts (`POP100 > 0` or `C000 > 0`). A halo block's jobs then go
through 4.4 step 5 and the grouping of 4.5, and it gets a row only if a base is above 0. A place outside every county
that passes neither test is dropped (`dropped_outside_region`), whatever its type. A place that is both a visitor
source and a rival and passes only the `3c` test is kept as a rival: it gets no point row, and its `visitor_segment`
and `size_default` are written as null and 0, so that in `places.ndjson` a visitor segment always means that the point
row `"p" + place_key` exists.

`dc` [run]: 1,349 halo blocks with 102,158 residents and 29,763 raw jobs (1.6 % and 0.9 % of the region), one of them
cut to the automatic cap (295 jobs discarded), 115 halo visitor places and 278 halo rivals, 4 of which lost their
visitor role. 7,396 places of the fetch box lie outside every county and outside the halo and are dropped.

## 7. Candidate map cells

### 7.1 Enumeration

For each S0 point `p`: `c0 = latLngToCell(p.lat, p.lng, 9)`. For each cell `x` of `gridDiskDistances(c0, 5)`: accept `x`
when `haversine(p, cellToLatLng(x)) <= walkCutoffM`. The candidate set is the union. Ring 5 exists only as a guard: if any
cell of ring 5 is ever accepted, the disk is too small for this latitude and the build fails. With a centre spacing of
336 to 356 m here, ring 5 starts at about 1,250 m from any point of the centre cell [V: zero ring-5 acceptances]. The
result is a set, so it does not depend on the order of the points. Points of the same `c0` share one disk.

`dc` [run]: 150,849 candidates from 59,214 S0 points (51,262 block rows and 7,952 visitor places), zero ring-5
acceptances. That is nearly every cell of the region's land area, because rural block points are closer together than
2.4 km. Pruning is what keeps the pack useful.

### 7.2 Pruning rule

For each candidate with centre `C`, over all source points (region and halo) with `d = haversine(C, p) <= walkCutoffM`,
taken in ascending `point_id`:

```
nearby_etl(C) = sum over p, over the 16 segments s in order:  base[p][s] * exp(-d / walkDecayM)
venue_etl(C)  = the same sum over the eight v_ segments only (indexes 8 to 15)
keep the cell when nearby_etl(C) >= cellMinNearby (100)  or  venue_etl(C) >= cellMinVenue (15)
```

Each sum is one accumulator that starts at 0: for every point in ascending `point_id`, compute
`f = exp(-d / walkDecayM)` once and add `base[p][s] * f` for `s` = 0 to 15 (zero bases may be skipped, which changes
nothing). `d` is the haversine distance of 02_MODEL.md 4.3, operation for operation, and the cutoff is inclusive.

`nearby_etl` is the sum of the model's `nearby` vector and `venue_etl` the sum of its last eight entries. The loader
recomputes `nearby_etl` with the PHP model and compares (section 10, step 9), which doubles as a cross-language check of
the distance and decay code. A cell under 100 distance-weighted residents and workers cannot give a truck more than
about six orders in a lunch service (100 office jobs at the door: roughly 0.36 present x 0.40 buying x 0.385 share,
figures from recon 10). Venue visitors buy far more often per person, so cells near a venue are kept by the second
test: 15 is what a default-size park (38 visitors) contributes at about 370 m. `cells.tsv` carries `nearby_etl` only,
and which test kept a cell is not recorded. A click on a dropped cell is still simulated exactly by the server, which
never reads the pack.

`dc` [proto]:

| `cellMinNearby` | Cells kept by the first test alone | Share of all nearby mass |
|---:|---:|---:|
| 0 (no pruning) | 150,848 | 100 % |
| 50 | 76,464 | 98.2 % |
| **100** | **58,327** | **96.6 %** |
| 200 | 45,368 | 94.2 % |

With both tests at the seed values the prototype keeps **61,228 cells** (96.8 % of the nearby mass): the second test
adds 2,901. Under the first test alone 174 of the 7,790 visitor-source places would stand in a dropped cell, 43 of the
174 taprooms among them. With both tests one does (a car dealership). 56,886 cells pass on residents and jobs alone, so
the count depends little on venue seeds. For comparison, 31,237 cells contain a source point, 29,404 contain residents
or jobs, and the map layer was measured at 55 to 60 frames per second with 57,000 cells.

`dc` [run]: **61,460 cells** are kept of 150,849 candidates, 58,420 by the first test and 3,040 by the second alone
(the stations add venue cells, the first-pass corrections move the job cells a little). The kept cells have 2,324,212
source points within the cutoff in all (37.8 per cell, 581 at most). Gate G17 warns outside `checks.cells_kept`
(54,000 to 64,000).

## 8. Pipeline outputs

Command: `node tools/truck-etl/bin/build-region.mjs --region=dc [--contact=<email>] [--raw-dir=]
[--out-dir=storage/truck/build] [--out=] [--region-file=] [--seeds=] [--corrections=]
[--places-source=geofabrik|overpass-tiles] [--tiles-dir=] [--osm-date=YYMMDD] [--offline]`. Every option may be written
`--name=value` or `--name value`.

| Option | Meaning | Default |
|---|---|---|
| `--region=<id>` | region to build; reads `regions/<id>.json` of the package | required unless `--region-file` is given |
| `--region-file=<path>` | a region file elsewhere (the id comes from the file) | |
| `--contact=<email>` | contact for the User-Agent of section 3. Needed for any download: without one nothing is requested and the build uses the raw cache as with `--offline` | environment `TP_CONTACT_EMAIL` |
| `--raw-dir=<dir>` | raw download cache | environment `TP_RAW_DIR`, else `storage/truck/raw` |
| `--out-dir=<dir>` | parent of all region builds: output goes to `<out-dir>/<region>/<dataset_version>/` | `storage/truck/build` |
| `--out=<dir>` | the region's build directory itself: output goes to `<out>/<dataset_version>/`. Not together with `--out-dir` | |
| `--seeds=<path>` | seed file | `docs/truck-planner/reference/tp_seeds.json` |
| `--corrections=<path>` | job corrections file | `corrections/<region>.jobs.json` of the package |
| `--places-source=` | `geofabrik` or `overpass-tiles` (development, with `--tiles-dir=<dir>`) | `geofabrik` |
| `--osm-date=YYMMDD` | snapshot date of the extracts (2.3) | resolved, see section 3 |
| `--offline` | never make a request, whether a contact is set or not: every file must be in the raw cache | off |

Paths given on the command line are relative to the current directory. The defaults are relative to the repository
root, whatever the current directory is. Exit 0 when every gate passes (warnings allowed). Exit 2 when a gate fails:
the five files are then written to `_failed/` beside the version directories (`<out-dir>/<region>/_failed/`), replacing
what was there, and a raw file that fails its check (gate G1) stops the run with exit 2 before anything is written.
Exit 1 on usage or I/O errors, on an invalid region, seed or corrections file, on a download that did not finish, and
on a file that is missing from the raw cache when no request may be made (`--offline`, or no contact address). The
command prints the stages with elapsed seconds on standard error and, on standard output, the gate table (one line per
gate entry; value and expectation for every entry that did not pass), the totals, the four output files with rows,
bytes and SHA-256, the raw cache with the number of requests made, the output directory, wall-clock time and peak
memory. An existing directory of the same `dataset_version` is replaced (its content is identical by construction).

Layout of `tools/truck-etl/`: `package.json` (`"type": "module"`, dependency `h3-js` 4.5.0 only, scripts `build:dc` and
`test`), `package-lock.json`, `README.md`, `.gitattributes`, `bin/build-region.mjs`, `bin/adopt-raw.mjs` (section 3),
`bin/fetch-overpass-tiles.mjs` (2.3), `regions/<id>.json`, `corrections/<id>.jobs.json`, `test/` (test files and
`fixtures/`), `test-support/` (helpers that write small PBF and ZIP files, and the tool that cuts the fixture), and one
module per concern under `src/`: `args`, `download`, `zip`, `pl`, `csv`, `lodes`, `blocks` (the block table of 4.1),
`corrections`, `pbf`, `overpass`, `taxonomy`, `normalise`, `hours`, `dedupe`, `counties`, `places` (the places stage of
section 5), `points`, `cells`, `review` (the rows of 8.4), `writers`, `manifest`, `gates`, `geo` (haversine, bucket
grid), `seeds`, `region` (section 1), `vocabulary` (segments, place types, sectors, versions) and `pipeline` (the stage
order). Stages run in this order: download, blocks, jobs and corrections, places, source points and halo, cells, review
list, gates, write. Model code paths never read a clock, use randomness or depend on the locale. The only clock reads
are `fetched_at` of `index.json`, the elapsed time the command prints and the folder date of the tile helper.

Measured for `dc` [run] on a laptop (Node 24, Windows), four runs with every raw file in the cache: 35 to 40 s wall
clock and 481 to 488 MiB peak memory, of which 2 to 3 s verify the 830 MB of raw files, 2 s read the blocks and jobs,
26 to 29 s read the extracts and 3 s enumerate and score the cells.

`dataset_version = <region>-<YYYYMMDD of the OSM snapshot>-<h8>`, where `h8` is the first 8 hex characters of the SHA-256
of six lines: `sha256(points.tsv)`, `sha256(places.ndjson)`, `sha256(cells.tsv)`, `sha256(job_review.csv)` (64 hex
characters each), then `model_version`, then `seeds_revision` in decimal, each line followed by LF. The last two lines
give a new version when a build-scope seed changes that only the loader reads (`kernel.outside_option_a0`, the rival
weights, the regime table) and that therefore changes none of the four files. Example `dc-20261003-3fa9c2d1`. At most
48 characters, `[a-z0-9-]` only.

Determinism: identical raw files, region file, corrections file, seed file and pipeline version give identical bytes
in the four data files, and with the same raw cache index (the `final_url` and `last_modified` of `sources[]` come from
it) in `manifest.json` too. No wall-clock value is written. Every sum runs in the stated order. `nearby_etl` is written
as `String(Number(x.toPrecision(12)))`. It and the cell centres are the only values that depend on `Math.exp` and
trigonometry, so `cells.tsv` (and with it the `dataset_version`) is the one file that another JavaScript engine could
write with a different last digit. Verified for `dc` [run]: two runs on the same machine gave the same SHA-256 for all
five files.

### 8.1 `points.tsv`

Tab-separated, header row, sorted by `point_id` in byte order. 23 columns:

| # | Column | Type | Content |
|---:|---|---|---|
| 1 | `point_id` | string | `b` + GEOID, or `p` + place_key |
| 2 | `src_kind` | string | `block` or `place` |
| 3 | `src_ref` | string | GEOID or place_key |
| 4 | `in_region` | 0 or 1 | 0 for halo rows |
| 5 | `job_adj` | 0 or 1 | 4.4 |
| 6, 7 | `lat`, `lng` | number | 7 decimals at most |
| 8 to 23 | `b_res, b_w_office, b_w_health, b_w_edu, b_w_retail, b_w_industrial, b_w_hospitality, b_w_public, b_v_nightlife, b_v_shopping, b_v_leisure, b_v_campus, b_v_hospital, b_v_transit, b_v_events, b_v_lodging` | number >= 0 | base per segment, in the shared segment order |

The header row holds the 23 column names. Every row has at least one base above 0. A block row has zeros in the eight
`b_v_` columns, a place row has exactly one non-zero base. Real row of the first build [run] (a tab between values):
`b110010001011001 block 110010001011001 1 0 38.9099975 -77.056197 282 6.229805175574284 0.01487631814098686 ...`.

### 8.2 `places.ndjson`

One JSON object per line, keys in exactly this order, `JSON.stringify` without spaces, null for missing values, sorted by
`place_key` in byte order:

`place_key` (string), `osm_type` (`node`, `way`, `relation`), `osm_id` (string of digits), `place_type`, `geom_kind`,
`in_region` (0 or 1), `county_fips` (string or null), `name`, `brand`, `lat`, `lng`, `rival_kind` (string or null),
`visitor_segment` (string or null), `size_default` (number, 0 when none), `host_fit` (number), `kitchen` (`yes`, `no`,
`unknown`), `phone`, `website`, `addr_line`, `city`, `state_code`, `postcode`, `cuisine`, `opening_hours_raw`,
`hours_mask`, `tags` (object or null).

`lat` and `lng` are the place point, `e7 / 1e7` (7 decimals at most). Non-ASCII characters are written as UTF-8, not
as `\u` escapes. Every row with `in_region = 0` has a `rival_kind` or a `visitor_segment` (6.3).

Example, a real element (rule R04; hours as in the 5.7 table). The first build writes exactly this line [V, run]:

```json
{"place_key":"n3413156622","osm_type":"node","osm_id":"3413156622","place_type":"cafe","geom_kind":"point","in_region":1,"county_fips":"51107","name":"Starbucks","brand":"Starbucks","lat":38.9455121,"lng":-77.4516722,"rival_kind":"cafe","visitor_segment":null,"size_default":0,"host_fit":0,"kitchen":"yes","phone":"+13017428261","website":"https://www.starbucks.com/store-locator/store/12403/iad-terminal-d-gate-d-15-44844-aviation-dr-sterling-va-20166-us","addr_line":"44844 Aviation Drive","city":"Sterling","state_code":"VA","postcode":"20166","cuisine":"coffee_shop","opening_hours_raw":"04:30-21:00","hours_mask":"f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f","tags":{"brand:wikidata":"Q37158","takeaway":"yes"}}
```

### 8.3 `cells.tsv`

Tab-separated, header row, sorted by `h3` ascending (string order equals numeric order). Columns: `h3` (15 hex), `lat`,
`lng` (the `cellToLatLng` centre, full double precision), `nearby_etl`. Only kept cells are written. First row of the
first build [run]: `892a8c9062fffff 38.370839826675905 -78.10995892889304 126.985036476`.

### 8.4 `job_review.csv`

CSV (RFC 4180 quoting), header row, sorted by `c000` descending, then `geoid`. Orphan rows (4.4 step 6) have no `c000`
and come after all other rows, by `geoid`. Columns:

`geoid, county_fips, county_name, place_name, military_name, lat, lng, map_url, residents, land_area_m2, c000, top1_sector,
top1_jobs, top1_share, top2_sector, top2_jobs, top3_sector, top3_jobs, rule, treatment, manual, confirmed, cap, jobs_after,
jobs_spread, jobs_discarded, entry_state, hint_place, reason`

One row for every region block that is flagged or has an entry (and a WAC row), plus one row per orphan entry. Lines
end with LF. Numbers are written as in section 0, empty values as nothing.

| Column | Content |
|---|---|
| `county_fips`, `county_name`, `place_name`, `military_name` | the first five characters of the GEOID, then the crosswalk's `ctyname`, `stplcname` and `milname`, verbatim (for example `Fairfax County, VA`, `Springfield CDP, VA`) |
| `lat`, `lng`, `residents`, `land_area_m2` | the block's internal point, POP100 and AREALAND |
| `map_url` | the free Google Maps link `https://www.google.com/maps/search/?api=1&query=<lat>%2C<lng>` with `lat` and `lng` as written in their columns (a URL, not an API call) |
| `c000`, `top1_sector` ... `top3_jobs` | raw counts. The three largest sectors, by jobs descending, then by sector number. `top1_share` = `top1_jobs / c000`, rounded to 4 decimals |
| `rule` | `A`, `B`, `AB` or empty (entry on an unflagged block) |
| `treatment` | `keep`, `cap`, `spread`, `drop` or `auto_spread` |
| `manual`, `confirmed` | `manual` is 1 when an entry exists, else 0. `confirmed` is 0 for an entry with `"confirmed": false`, 1 for any other entry and empty without an entry |
| `cap` | the cap applied: the entry's `cap` (0 for `drop`, the default 0 for `spread`), the automatic cap for `auto_spread`, empty for `keep` |
| `jobs_after` | the block's jobs after all corrections, summed over the 20 sectors at weight 1, including spread jobs a kept block received |
| `jobs_spread`, `jobs_discarded` | what the block gave to the rest of its county, and what `cap` or `drop` removed from it, both summed over the 20 sectors at weight 1. A spread that found no receiver (4.4 step 3) counts in neither column, only in `jobs_spread_lost` of the manifest |
| `entry_state` | `ok`, `stale`, `orphan`, or empty without an entry |
| `hint_place` | the nearest named `hospital` or `campus` place of `places.ndjson` within 800 m of the block point, as `type: name (distance m)` with the distance rounded to whole metres (ties: lower `place_key`), or empty. An aid for the reviewer, never a decision |
| `reason` | the entry's `reason`, empty for `auto_spread` |

### 8.5 `manifest.json`

`JSON.stringify(manifest, null, 2)` plus a final LF, keys in the order of these tables. No timestamps of the run.
Region counts and totals cover `in_region = 1` rows only. What concerns the halo sits under `counts.halo` and in
`totals.halo_jobs_capped`.

| Key | Content |
|---|---|
| `schema` | 1 |
| `dataset_version`, `region_id`, `pipeline_version`, `model_version` | identity (`model_version` from the seed file) |
| `region` | the region definition file, verbatim (the loader fills `tp_regions` from it) |
| `bounds` | `lat_min, lng_min, lat_max, lng_max` of the county polygons |
| `inputs` | `region_file_sha256`, `seeds_revision`, `seeds_sha256`, `corrections_version`, `corrections_sha256`, `places_source` (`geofabrik` or `overpass-tiles`). The three hashes are SHA-256 of the file bytes as read |
| `parameters` | every seed value used: `walk_decay_m`, `walk_cutoff_m`, `earth_radius_m`, `cns04_weight`, `cell_min_nearby`, `cell_min_venue` (numbers), `segment_cns` (object: the seven `w_` segments in shared order, each a list of sector names as in the seed file), `place_types` (object: the 22 types in vocabulary order, each `{visitor_segment, default_size, rival_kind, host_fit, kitchen_default}`), then `h3_res` and `job_review` (`{total_min, single_sector_min, single_sector_share}` of the region file) |
| `sources[]` | one entry per raw file used, sidecars excluded: `kind` (`census_pl`, `lodes_wac`, `lodes_xwalk`, `lodes_version`, `tigerweb`, `osm_pbf`, `overpass_tile`), `state` (lower-case `usps`, null for `tigerweb` and tiles), `path` (relative to the raw directory, which names the dated extract; a tile's file name), `url`, `final_url`, `bytes`, `sha256`, `last_modified`. The last five come from `index.json` (null where it has null; a tile has null `url`, `final_url` and `last_modified`). Order: by `kind` as listed, then by state order of the region file, tiles by name |
| `vintages` | `census_reference_date` (`2020-04-01`), `lodes_year`, `lodes_format`, `lodes_vintage` (from `version.txt`; the values of the states joined with `,` if they differ), `osm_snapshot_date` (`YYYY-MM-DD`: the dated extract name, or the UTC date of the newest tile), `osm_replication_timestamp` (ISO 8601 UTC to the second: the largest header timestamp of the extracts, or the newest `timestamp_osm_base`; null if absent) |
| `counts` | table below |
| `totals` | table below |
| `gates[]` | one entry per check of the gates G1 to G17 of section 12, in gate order: `{id, part, level, check, value, expected, pass}`. `id` is the gate number (`G1` ... `G17`, `G8b`). `part` names the check when a gate has several (`files`, `sidecars`, ...) and is null otherwise: section 12 lists them as `id.part`. `level` is `fail` or `warn`, `check` one line of plain text, `value` what was measured, `expected` the check value or limit, `pass` a boolean. A reader that only wants "which gates warned" takes the distinct `id` of the entries with `level = "warn"` and `pass = false` |
| `outputs[]` | `{file, bytes, sha256, rows}` for `points.tsv`, `places.ndjson`, `cells.tsv`, `job_review.csv` in that order. `rows` excludes the header row |
| `attribution` | object with the keys `osm` (string 1 of section 14), `osm_long` (string 2), `residents` (string 3), `jobs` (string 4, placeholders left as written), `places` (string 5 with `{osm_snapshot_date}` filled and `{contact}` left as written), `boundaries` (string 8) |

`counts`, keys in this order:

| Key | Content |
|---|---|
| `blocks` | `state` (blocks of all state files), `by_state` (object by state FIPS), `region`, `with_residents`, `with_jobs`, `with_either` (region blocks, raw counts) |
| `points` | `total` (rows of `points.tsv`), `block` and `place` (region rows), `halo_block`, `halo_place` |
| `places` | `total` (rows of `places.ndjson`), `in_region`, `halo`, `named` (region places with a name), `by_type` (the 22 types in vocabulary order), `by_geom_kind` (`area`, `building`, `point`). The last three count region places |
| `rivals` | `total`, `by_kind` (`quick`, `full`, `cafe`, `bar`, `convenience`): region places with a rival kind |
| `visitor_sources` | `total`, `by_type` (22 types): region places with a visitor segment |
| `hosts` | `total` (region places with `host_fit > 0`), `with_contact` (of those, with a phone or a website) |
| `halo` | `residents`, `jobs_raw` (of the halo block rows), `blocks_capped`, `visitor_places`, `rival_places` |
| `cells` | `candidates`, `kept`, `kept_by_venue_test_only`, `point_cell_pairs` and `max_points_in_range` (source points within the cutoff of the kept cells: the sum and the largest), `occupied`, `max_residents`, `max_jobs_raw`, `max_jobs_after` (gates G10 and G11), `max_jobs_after_cell` (its H3 id) |
| `elements_read` and the ten counters of 5.1 | `dropped_relation_type`, `dropped_closed`, `dropped_no_rule`, `dropped_unnamed`, `dropped_no_geometry`, `dropped_outside_box`, `merged_identity`, `merged_same_venue`, `merged_site_part`, `dropped_outside_region` |
| `coverage` | region places: `phone_raw` and `website_raw` (places whose own element carries a value), `phone_ok` and `website_ok` (of those, normalised), `hours_raw` (places with `opening_hours_raw`), `hours_parsed` (with `hours_mask`) |

`totals`, keys in this order:

| Key | Content |
|---|---|
| `residents`, `housing_units`, `jobs` | region sums of POP100, HU100 and raw `C000` |
| `jobs_by_sector` | raw region jobs per sector, `CNS01` to `CNS20` |
| `base_by_segment` | sum of each of the 16 base columns over the region rows of `points.tsv`, in shared segment order (the corrected worker bases with `cns04Weight`, and the default sizes of the visitor sources) |
| `jobs_spread`, `jobs_discarded`, `halo_jobs_capped`, `jobs_spread_lost` | jobs moved to receivers, removed by `cap` and `drop`, removed from halo blocks, and lost for want of a receiver, at weight 1 |
| `blocks_adjusted` | region blocks with `jobs_spread` above 0 |
| `review_blocks`, `review_jobs` | flagged region blocks and their raw jobs |
| `by_county` | one `{fips, residents, housing_units, jobs, blocks}` per county, in the order of the region file |

The values the backend reads by name (04_BACKEND.md): `inputs.corrections_version`, `inputs.places_source`,
`vintages.*`, `parameters.*`, `totals.residents`, `totals.jobs`, `totals.blocks_adjusted`, `totals.jobs_spread`,
`counts.points.total`, `counts.places.total`, `counts.cells.kept`, `gates[]`, `outputs[]`, `region`, `bounds`.
First build [run]: `counts.points.total` 60,678, `counts.places.total` 25,276, `counts.cells.kept` 61,460,
`totals.residents` 6,278,542, `totals.jobs` 3,140,158, `totals.jobs_spread` 196,941, `totals.blocks_adjusted` 31.

## 9. MySQL reference tables

Shared reference data: no organization column, read-only for requests (except `tp_fuel_prices`), written by
`scripts/truck/load-region.php`. Model inputs are `DOUBLE` or integers, never `DECIMAL` or `FLOAT`. The one binary
column, `tp_places.host_vec`, holds exact doubles as well. Identifier columns are `ascii_bin` so that MySQL `ORDER BY`
equals byte order in PHP and JavaScript. There is no cell table: cells exist only inside the pack, so PHP never needs H3.

### 9.1 Migration `src/Migrations/042_tp_reference_data.sql`

(File name fixed by DECISIONS 8. These tables do not depend on the owner tables.)

```sql
-- 042_tp_reference_data.sql - Truck Planner shared reference tables.
--
-- tp_regions, tp_points, tp_places, tp_region_packs, tp_fuel_prices.
-- Not organization-scoped. Written by scripts/truck/load-region.php and the fuel price client.
-- Idempotent: CREATE TABLE IF NOT EXISTS only. No spatial columns, no foreign keys.
-- Splitter rule: statement-ending semicolons at the end of a line, none inside comments.

CREATE TABLE IF NOT EXISTS tp_regions (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name              VARCHAR(120)  NOT NULL,
  cbsa              VARCHAR(5)    CHARACTER SET ascii COLLATE ascii_bin NULL,
  timezone          VARCHAR(60)   NOT NULL,
  h3_res            TINYINT UNSIGNED NOT NULL,
  bbox_lat_min      DOUBLE        NOT NULL,
  bbox_lng_min      DOUBLE        NOT NULL,
  bbox_lat_max      DOUBLE        NOT NULL,
  bbox_lng_max      DOUBLE        NOT NULL,
  center_lat        DOUBLE        NOT NULL,
  center_lng        DOUBLE        NOT NULL,
  active_version    VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  previous_version  VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  config_json       JSON          NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tp_points (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dataset_version   VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  point_id          VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src_kind          VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src_ref           VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  in_region         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  job_adj           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lat               DOUBLE        NOT NULL,
  lng               DOUBLE        NOT NULL,
  b_res DOUBLE NOT NULL DEFAULT 0,           b_w_office DOUBLE NOT NULL DEFAULT 0,
  b_w_health DOUBLE NOT NULL DEFAULT 0,      b_w_edu DOUBLE NOT NULL DEFAULT 0,
  b_w_retail DOUBLE NOT NULL DEFAULT 0,      b_w_industrial DOUBLE NOT NULL DEFAULT 0,
  b_w_hospitality DOUBLE NOT NULL DEFAULT 0, b_w_public DOUBLE NOT NULL DEFAULT 0,
  b_v_nightlife DOUBLE NOT NULL DEFAULT 0,   b_v_shopping DOUBLE NOT NULL DEFAULT 0,
  b_v_leisure DOUBLE NOT NULL DEFAULT 0,     b_v_campus DOUBLE NOT NULL DEFAULT 0,
  b_v_hospital DOUBLE NOT NULL DEFAULT 0,    b_v_transit DOUBLE NOT NULL DEFAULT 0,
  b_v_events DOUBLE NOT NULL DEFAULT 0,      b_v_lodging DOUBLE NOT NULL DEFAULT 0,
  rivals_day DOUBLE NOT NULL DEFAULT 0,      rivals_eve DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (region_id, dataset_version, point_id),
  KEY idx_tpp_geo (region_id, dataset_version, lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every row of tp_places is derived from OpenStreetMap (ODbL). No other source may be merged into this table.
-- host_vec is the location vector of a possible host: 50 little-endian IEEE-754 doubles, written by the loader.
CREATE TABLE IF NOT EXISTS tp_places (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dataset_version   VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  place_key         VARCHAR(20)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  osm_type          VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  osm_id            BIGINT UNSIGNED NOT NULL,
  snapshot_date     DATE          NOT NULL,
  place_type        VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  geom_kind         VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  in_region         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  county_fips       VARCHAR(5)    CHARACTER SET ascii COLLATE ascii_bin NULL,
  name              VARCHAR(160)  NULL,
  brand             VARCHAR(120)  NULL,
  lat               DOUBLE        NOT NULL,
  lng               DOUBLE        NOT NULL,
  rival_kind        VARCHAR(12)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  visitor_segment   VARCHAR(16)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  size_default      DOUBLE        NOT NULL DEFAULT 0,
  host_fit          DOUBLE        NOT NULL DEFAULT 0,
  kitchen           VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unknown',
  phone             VARCHAR(16)   NULL,
  website           VARCHAR(255)  NULL,
  addr_line         VARCHAR(200)  NULL,
  city              VARCHAR(80)   NULL,
  state_code        VARCHAR(2)    NULL,
  postcode          VARCHAR(5)    NULL,
  cuisine           VARCHAR(120)  NULL,
  opening_hours_raw VARCHAR(255)  NULL,
  hours_mask        VARCHAR(42)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  tags_json         JSON          NULL,
  host_vec          VARBINARY(400) NULL,
  PRIMARY KEY (region_id, dataset_version, place_key),
  KEY idx_tpl_geo (region_id, dataset_version, lat, lng),
  KEY idx_tpl_type (region_id, dataset_version, place_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per loaded dataset version of a region. It is the dataset ledger and it holds the cell pack.
CREATE TABLE IF NOT EXISTS tp_region_packs (
  region_id         VARCHAR(24)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dataset_version   VARCHAR(48)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  load_state        VARCHAR(12)   NOT NULL DEFAULT 'loading',
  model_version     VARCHAR(24)   NOT NULL,
  pipeline_version  VARCHAR(24)   NOT NULL,
  osm_snapshot      DATE          NULL,
  point_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  place_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  cell_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  pack_format       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pack_len          INT UNSIGNED  NOT NULL DEFAULT 0,
  pack_gz_len       INT UNSIGNED  NOT NULL DEFAULT 0,
  pack_sha256       VARCHAR(64)   CHARACTER SET ascii COLLATE ascii_bin NULL,
  pack_gz           LONGBLOB      NULL,
  kernel_json       JSON          NULL,
  manifest_json     JSON          NULL,
  loaded_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at      DATETIME      NULL,
  PRIMARY KEY (region_id, dataset_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly EIA retail price per area and product. price_milli is dollars per gallon times 1000 (4.195 is stored as 4195).
CREATE TABLE IF NOT EXISTS tp_fuel_prices (
  duoarea           VARCHAR(8)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product           VARCHAR(12)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  period            DATE          NOT NULL,
  price_milli       INT UNSIGNED  NOT NULL,
  series_id         VARCHAR(40)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  src               VARCHAR(12)   NOT NULL DEFAULT 'eia',
  fetched_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (duoarea, product, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Column notes. `tp_regions.bbox_*` is the bounding box of the county polygons (`dc`: 37.9907, -78.3947, 39.7201,
-76.6625). It is for map framing only and is never a membership test (9.3, Q4). `center_*` is `map_center`,
`config_json` is the region file verbatim. `load_state` is `loading`, `ready` or `failed`. Which version is live is
decided only by `tp_regions.active_version`. `kitchen`, `geom_kind`, `rival_kind` and `place_type` hold the vocabulary
strings. A fuel price has three decimals, so it is stored in thousandths of a dollar instead of cents. The repository
returns `price_milli / 1000`. `JSON` columns arrive in PHP as strings and are decoded by hand. Never `SELECT *` from
`tp_region_packs` outside the pack endpoint: the row carries a 3 MB blob.

`tp_places.host_vec` is filled only for possible hosts (`in_region = 1 AND host_fit > 0`) and is NULL on every other
row. It holds 50 IEEE-754 binary64 values, little-endian, in cell-pack column order (`capture.day[0..15]`,
`capture.eve[0..15]`, `nearby[0..15]`, `rivals.day`, `rivals.eve`): exact doubles, never quantised. PHP writes
`pack('e50', ...$v)` and reads `array_values(unpack('e50', $bin))` [V on MySQL 8.0.45: the 400 bytes round-trip bit for
bit with native prepares and a `PDO::PARAM_LOB` bind]. The value is the place's location vector at visibility normal
with the place's own source point excluded, computed by the loader (section 10, step 8a). Scout decodes it and never
computes vectors per candidate (02_MODEL.md 4.4).

Size per dataset version for `dc`: 60,678 point rows and 25,276 place rows [run]. In MySQL [load]: `tp_points` 16.6 MB
of rows and 6.5 MB of indexes, `tp_places` 19.6 MB and 5.0 MB (4.6 MB of it are the 11,941 host vectors, and writing
them after the insert splits pages), and one pack row of 3.5 MB. Two versions are kept.

### 9.2 Versioned loads

- Every row of `tp_points`, `tp_places` and `tp_region_packs` carries `dataset_version`. A load only inserts rows of
  its own version and touches no other.
- Activation is one transaction, run only when the version's `load_state` is `ready`. The switch itself is a single
  row update: `UPDATE tp_regions SET previous_version = active_version, active_version = ?, updated_at = NOW() WHERE region_id = ?`,
  followed by `UPDATE tp_region_packs SET activated_at = NOW() WHERE region_id = ? AND dataset_version = ?`.
  Activating the version that is already active changes nothing (the switch would otherwise overwrite
  `previous_version` with the active one).
- Readers resolve the version once per request (9.3, query Q0) and pass it to every later query, so a request never
  mixes two versions even if the switch happens while it runs.
- Rollback is the same statement with the previous version. It works for as long as that version's rows exist.
- Retention: the active and the previous version. `--prune` deletes every other version in batches
  (`DELETE FROM tp_points WHERE region_id = ? AND dataset_version = ? LIMIT 5000`, repeated until 0 rows, then the same
  for `tp_places`, then the pack row).
- Saved spots store capture vectors with the `dataset_version` they were computed against (04_BACKEND.md). After a
  switch they are recomputed lazily.

### 9.3 Queries the API uses

Bounding-box half-widths for a radius `r` metres around `(lat, lng)`, with a 1 % guard band:

```
dLat = rad2deg(r / 6371008.8) * 1.01
dLng = dLat / max(0.01, cos(deg2rad(lat)))
```

For `r = 1200` at latitude 38.9: `dLat = 0.0108998`, `dLng = 0.0140056` degrees. SQL only narrows the candidates. PHP then
keeps rows with haversine distance `<= r`. Placeholders are positional. Fetch with `PDO::FETCH_NUM` in the column
order shown, which is part of the contract between the repository, the model code and the loader.

Q0, active version (cache it for the request):

```sql
SELECT active_version, timezone, h3_res FROM tp_regions WHERE region_id = ?
```

Kernel check. Once per PHP process (one web request or one CLI run) the reader also loads `kernel_json` of the active
version (written by section 10, step 12):

```sql
SELECT kernel_json FROM tp_region_packs WHERE region_id = ? AND dataset_version = ?
```

It compares `kernel_json.kernel` with the kernel object built from the PHP model's seeds (the `kernel` object of
section 11). Compare the decoded structures, never the strings: numbers as doubles, lists and strings exactly, objects
key by key. MySQL re-orders the keys of a `JSON` column and re-prints its numbers (`400.0` comes back as `400`) [V]. It
also hands about one in nine of the numbers that need 16 or 17 significant digits back one unit in the last place off
(numbers of at most 15 digits come back exact) [V on MySQL 8.0.45], so two numbers within two units in the last place
of each other count as equal here: that is not another build. On
a difference the pack endpoint and every capture request answer
`Response::error('Region data was built with different model constants', 409)`. This is the refusal that 02_MODEL.md 2.2
requires for a pack whose recorded build values differ from the seed file.

Q1, source points near a point. Parameters: `region_id, dataset_version, lat - dLat, lat + dLat, lng - dLng, lng + dLng`.

```sql
SELECT point_id, src_kind, src_ref, lat, lng, rivals_day, rivals_eve,
       b_res, b_w_office, b_w_health, b_w_edu, b_w_retail, b_w_industrial, b_w_hospitality, b_w_public,
       b_v_nightlife, b_v_shopping, b_v_leisure, b_v_campus, b_v_hospital, b_v_transit, b_v_events, b_v_lodging
  FROM tp_points FORCE INDEX (idx_tpp_geo)
 WHERE region_id = ? AND dataset_version = ?
   AND lat BETWEEN ? AND ?
   AND lng BETWEEN ? AND ?
 ORDER BY point_id
```

Q2, rival outlets near a point. Same parameters.

```sql
SELECT place_key, place_type, rival_kind, lat, lng, name, kitchen, hours_mask
  FROM tp_places FORCE INDEX (idx_tpl_geo)
 WHERE region_id = ? AND dataset_version = ?
   AND lat BETWEEN ? AND ?
   AND lng BETWEEN ? AND ?
   AND rival_kind IS NOT NULL
 ORDER BY place_key
```

Q3, possible hosts in a box (Scout). Parameters of Q1 plus the last `place_key` of the previous page (the empty string
for the first page). Any box size. The caller pages by `place_key`: it repeats the query until a page holds fewer than
2,000 rows. `host_vec` comes back as its 400 bytes and is decoded as described in 9.1. This statement names no
index: over a large box, paging along the primary key is the right plan.

```sql
SELECT place_key, place_type, name, brand, lat, lng, county_fips, host_fit, kitchen, size_default, visitor_segment,
       phone, website, addr_line, city, state_code, postcode, opening_hours_raw, hours_mask, host_vec
  FROM tp_places
 WHERE region_id = ? AND dataset_version = ?
   AND lat BETWEEN ? AND ?
   AND lng BETWEEN ? AND ?
   AND in_region = 1 AND host_fit > 0
   AND place_key > ?
 ORDER BY place_key
 LIMIT 2000
```

Q4, nearest block (region membership, county and state of a clicked point, a spot or the base). Parameters as Q1 with
r = 2,400 m (2 x `walk_cutoff_m`, the halo depth).

```sql
SELECT point_id, src_ref, in_region, lat, lng
  FROM tp_points FORCE INDEX (idx_tpp_geo)
 WHERE region_id = ? AND dataset_version = ?
   AND lat BETWEEN ? AND ?
   AND lng BETWEEN ? AND ?
   AND src_kind = 'block'
 ORDER BY point_id
```

PHP keeps the rows within r and, of those, the row with the smallest haversine distance (ties: first in `point_id`
order).

| Outcome | Result |
|---|---|
| No row | `in_region = false`, county and state null |
| A row | `in_region = (row.in_region == 1)`, `county_fips = substr(src_ref, 0, 5)`, `state_fips = substr(src_ref, 0, 2)` |

Vectors are always computed from what Q1 and Q2 return (a point just outside a county line still has halo data).
`in_region` only sets the label and the `outside_region` warning. With no Q4 row the vectors are therefore all zeros,
unless a venue stands alone within the cutoff. Neither the region box nor Q4 ever replaces Q1 and Q2, because the
loader's self-check (section 10, step 10) requires the request path to reproduce the pack at every kept cell.

The EIA area is `fuel_area_by_state[USPS]`, where USPS is the upper-case `usps` of the `config_json.states` entry whose
`fips` equals `state_fips`, or `NUS` when there is no state. `tp_regions.bbox_*` is for map framing only and must never
be used as a membership test: the `dc` box also covers Howard County, Hagerstown and Winchester, which are not loaded.
The rule is exact except within one block spacing of a county line, and a point of the region farther than 2,400 m
from every block row (open water, a large park) is reported as outside. On the prototype data every kept cell centre
has a block row within 2,400 m, 720 of the 61,228 centres are nearer to a halo block than to a region block and are
labelled outside, and so are 3 of the 11,782 possible hosts [proto].

Q5, one place by key, is defined with the host link rule in 6.2.

Rules. `ORDER BY` fixes the summation order, so the request path and the loader add the same doubles in the same
order. Q1 returns at most about 900 rows for 1,200 m in `dc` (581 points lie within 1,200 m of the densest cell [run]).
Plan of Q1, Q2 and Q4: range scan on `idx_tpp_geo` or `idx_tpl_geo` with index condition pushdown and a filesort. The
three statements name their index (`FORCE INDEX`) because the optimizer does not always choose it on the loaded
region: a latitude band through Washington holds 4,052 of the 60,678 points, and MySQL 8.0.45 then prefers to read the
whole dataset version through the primary key, which already has the wanted order. Medians of seven runs [load]: at a
downtown Washington point Q1 took 77 ms, Q2 28 ms and Q4 32 ms that way, and Q4 took 35 ms in Herndon and Sterling
too; with the index named each of the three takes 6 ms downtown and 1 to 3 ms in Herndon and Sterling, and a whole
capture for three visibility levels (Q1 to Q4 and the model) takes 27 ms downtown and 6 to 7 ms in Herndon and
Sterling. Q3 returned every host of a
synthetic table exactly once across its pages [V]. With native prepares `DOUBLE` columns arrive as PHP floats, and a
float bound directly loses digits (38.91006831234568 is stored as 38.910068312346), so every double parameter is bound
as its `json_encode` string [V]. A web request never reads a region's rows without a box: 57,000 rows of 23 columns as
associative arrays cost about 132 MB of PHP memory.

## 10. Loader `scripts/truck/load-region.php`

```
php scripts/truck/load-region.php --build=storage/truck/build/dc/<dataset_version> [--activate] [--dry-run]
php scripts/truck/load-region.php --region=dc --activate=<dataset_version>
php scripts/truck/load-region.php --region=dc --list
php scripts/truck/load-region.php --region=dc --prune
```

The script starts with `scripts/truck/_bootstrap.php` (04_BACKEND.md 7.2: the autoloader, the environment,
`set_time_limit(0)`, `ini_set('serialize_precision', '-1')`, `ini_set('precision', '17')` and the option reader) and
sets `ini_set('memory_limit', '1024M')`. The work is done by `App\TruckPlanner\Services\RegionLoader`.
Exit 0 on success, 2 on a failed check (the version stays `failed` and is never activated), 1 on usage or I/O errors.

The four forms. `--build` names the directory that holds the five files of one build. The name of the directory is
not read: region and version come from the manifest. `--region=<id> --activate=<version>` is the switch of 9.2 and
nothing else: it needs a version whose `load_state` is `ready` (exit 2 otherwise, exit 1 for a region or a version that
does not exist), it prunes nothing, and it prints a warning when the kernel recorded with that version differs from
this server's seeds (a way back may come before the code goes back, and requests answer 409 until the two agree).
`--list` prints the region's ledger: version, active or previous, state, counts, pack size, load and activation time.
`--prune` deletes every version but the active and the previous one (9.2). The loader reads no wall clock: the
timings it prints are differences of the monotonic `hrtime()`.

Model calls. Steps 6, 8 and 8a call the PHP model functions of 02_MODEL.md 4.4 with one `Assumptions` object:
`A` = `{model_version, seeds_revision, seeds, overrides: {}, region: {id, traffic_matrix, flags}}`, built from the PHP
seed loader and `manifest.region` (`id`; `traffic_matrix`, or `us_mean` when the key is absent;
`flags.inauguration_day` from `holidays.inauguration_day`). `overrides` must be empty. Every ordering of ids in these
steps uses `strcmp` (byte order). PHP's default `sort()` compares numeric-looking strings as numbers and must not be
used on ids. The loader checks while it reads them that `points.tsv`, `places.ndjson` and `cells.tsv` are in strictly
ascending byte order of their ids (8.1 to 8.3) and refuses a build that is not (exit 2), so a list of row numbers in
ascending order is in `strcmp` order of its ids. It refuses in the same way a row that does not have the columns,
types and vocabulary of section 8.

Steps:

1. Read `manifest.json`. Verify the size, the SHA-256 and the row count of the four data files. Require every `fail`
   gate to have passed, `model_version` to equal the PHP model's version, every seed value in `manifest.parameters`
   to equal what the PHP model's seed loader returns (numbers compared as doubles, lists and strings exactly), and
   the region's traffic matrix and its typical value to exist in the PHP model's seeds (G19). Any mismatch stops
   here: rebuild the region.
2. Upsert `tp_regions` from `manifest.region` and `manifest.bounds` (name, CBSA, zone, resolution, box, `map_center`,
   `config_json`). `active_version` is not touched.
3. If a `tp_region_packs` row for this version exists: when its `kernel_json` is not null and differs from the one
   this run would write (step 12, compared as in the kernel check of 9.3), exit 2 with the message
   `build-scope seeds changed: raise seeds_revision and rebuild the region` and leave that row as it is. Otherwise
   stop if it is the active version (exit 0: there is nothing to load). Otherwise delete the version's rows in
   batches (points, places, then the pack row) and start again. Insert the pack row with `load_state = 'loading'`.
4. Read `places.ndjson` line by line. Insert into `tp_places` in batches of 500 rows per statement (29 columns, 14,500
   placeholders; `host_vec` stays NULL until step 8a), ten statements per transaction. Collect rivals into flat arrays
   `place_key[]`, `lat[]`, `lng[]`, `kind[]`, and possible hosts (`in_region = 1` and `host_fit > 0`) into flat arrays
   `place_key[]`, `lat[]`, `lng[]`, `has_point[]` (true when `visitor_segment` is not null).
5. Read `points.tsv` into one flat packed array per column, and `cells.tsv` the same way. Never build an array of row
   arrays.
6. Rivals per point. Bucket rivals on a fixed grid whose cells are `dLat` high and `dLngMax` wide, where `dLat` is the
   value of 9.3 for r = `walk_cutoff_m` and `dLngMax = dLat / max(0.01, cos(deg2rad(L)))` with L = the largest
   absolute latitude among all points, rivals, hosts and cell centres of the build (`dc`: L about 39.74, `dLngMax`
   about 0.014175 [proto]). A point's bucket is `(floor(lat / dLat), floor(lng / dLngMax))`. Evaluating the width at L
   makes every bucket at least r wide everywhere in the region. A width taken at the region centre would be 1,198 m
   at the northern edge of `dc`, and the 3 by 3 neighbourhood would miss pairs just inside the cutoff. For each point,
   in file order, take the rivals of the 3 by 3 buckets around it, build `outlets` = those rivals as
   `Outlet {id: place_key, lat, lng, kind: rival_kind}`, sorted with `strcmp` on `id`, and call
   `rivals_at_origin(A, point.lat, point.lng, outlets)` (02_MODEL.md 4.4). Store `.day` and `.eve`. This is the same
   function that computes a location's `rivals` vector.
7. Insert `tp_points` in batches of 500 (27 columns, 13,500 placeholders). Bind every double as a string that
   round-trips: file values verbatim, computed values through `json_encode`. PDO's default float binding keeps only 14
   significant digits.
8. Vectors per cell. Bucket points on the same grid. For each cell of `cells.tsv`, in file order, take the points and
   rivals of the 3 by 3 buckets around the centre, build `sources` = those points as
   `SourcePoint {id: point_id, lat, lng, base[16], rivals: {day, eve}}` (rivals from step 6) and `outlets` as in
   step 6, both sorted with `strcmp` on `id`, and call
   `capture_at_point(A, cell.lat, cell.lng, "normal", sources, outlets, {point_ids: [], segment: null, amount: 0.0})`.
   The model applies the cutoff. The 50 numbers are `capture.day[0..15]`, `capture.eve[0..15]`, `nearby[0..15]`,
   `rivals.day`, `rivals.eve`, kept in 50 flat column arrays. The two lists of a bucket's neighbourhood may be kept
   for the next cells that fall into the same bucket, within a fixed bound (some thousands of points): never the
   whole region as row arrays.
   **Step 8a, host vectors.** For every place with `in_region = 1` and `host_fit > 0`, take
   `sources` and `outlets` as in step 8 around the place's `lat, lng` and call
   `capture_at_point(A, lat, lng, "normal", sources, outlets, {point_ids: P, segment: null, amount: 0.0})` with
   `P = ["p" + place_key]` when the place has a point row (`has_point`) and `P = []` otherwise. The vectors may be
   computed in any order (bucket by bucket, so that neighbouring hosts share their lists). Write the 50 numbers, in
   the order of step 8 and in `place_key` order, with
   `UPDATE tp_places SET host_vec = ? WHERE region_id = ? AND dataset_version = ? AND place_key = ?`, the value
   `pack('e50', ...$v)` bound as `PDO::PARAM_LOB`, 500 updates per transaction.
9. Pruning cross-check: for every cell `|sum(nearby) - nearby_etl| <= 1e-9 * max(1, nearby_etl)`. A failure means the
   JavaScript and PHP distance or decay code disagree.
10. Self-check through the request-time path. Sample: the cells with index `i % step == 0`,
    `step = max(1, floor(N / 200))`, the 20 cells with the largest `sum(nearby)`, and for each anchor block of the
    region file the cell whose centre is nearest to that block. For each, call the same service the `simulate`
    endpoint uses, with this region and `dataset_version` passed explicitly (the version is not active yet), at the
    cell centre with visibility normal. All 50 numbers must match the bulk result within `1e-12` relative (absolute
    floor `1e-12`). Then the possible hosts with index `i % hstep == 0` in `place_key` order,
    `hstep = max(1, floor(H / 50))` (H = number of possible hosts): the same service, at the place point with the
    exclusion of step 8a, must reproduce the `host_vec` read back from MySQL (through Q3 around the place) within the
    same tolerance. This proves that the rows in MySQL, queries Q1 and Q2 and the API code path reproduce the pack
    and the host vectors. For `dc` the sample is 225 cells and 51 hosts, and both differences are exactly 0 [load].
11. Build the pack (section 11), gzip it with `gzencode($bytes, 9)`, decode it again and verify every value against the
    quantisation bound (with a relative 1e-9 added to the bound, for the rounding of the comparison itself). Fail
    above 16 MB compressed.
12. `UPDATE tp_region_packs` with counts, `pack_len`, `pack_gz_len`, `pack_sha256` (of the uncompressed bytes),
    `kernel_json`, `manifest_json` (the text of `manifest.json` as the pipeline wrote it), the blob bound as
    `PDO::PARAM_LOB`, and `load_state = 'ready'`. It is one statement of about 3.5 MB for `dc`: the server's
    `max_allowed_packet` (64 MB by default in MySQL 8.0) must admit it.
    `kernel_json` = `{"seeds_revision": n, "kernel": <the pack header's kernel object>}`.
13. With `--activate`: the switch of 9.2, then prune. Print region, version, counts, pack size, one line per check
    (the files, the pipeline's gates, G19 to G22), the time of each step, wall-clock time and peak memory.

`--dry-run` does steps 1, 4 (reading only, no inserts), 5, 6, 8, 8a (no updates), 9 and 11 from the files and writes
nothing: it does not open a database connection, and `--activate` given with it is not applied. Cost for `dc`:
2,324,212 point-to-cell pairs for the 61,460 cells (38 points per cell on average, 581 at most) [run] and about 1.5
million point-to-host pairs for the 11,941 hosts (130 points per host, because hosts stand where blocks are small)
[proto]. Measured [load], two loads of the whole build: 47 and 51 s wall clock and 222 MiB peak memory, of which the
rival pull of the 60,678 points takes 4 to 5 s, the cell vectors 14 to 16 s, the host vectors 12 to 16 s, the
inserts 6 s, the self-check 3 s and the pack 2 to 3 s. A dry run takes 28 to 29 s and 206 MiB. `--prune` removed a
whole version (60,678 points, 25,276 places, the pack row) in 2.5 s. The production server (2 vCPU, PHP 8.3) was not
measured: allow two to three minutes there [S]. `memory_limit` 1024M leaves a wide margin. Run it with the same
PHP minor version as the web tier (`php8.3` on the server). The kernel constants written to the pack come from the PHP
model. Changing any of them means a new `seeds_revision`, a rebuild and a reload of the region (section 8).

## 11. Cell pack format (version 1)

All integers are little-endian. The file is a 12-byte prefix, a JSON header, padding, then two sections.

| Offset | Size | Content |
|---:|---:|---|
| 0 | 4 | magic, ASCII `TPCP` (`54 50 43 50`) |
| 4 | 2 | format version, u16 = 1 |
| 6 | 2 | flags, u16 = 0 (reserved) |
| 8 | 4 | `H`, u32, byte length of the JSON header |
| 12 | `H` | JSON header, UTF-8, no BOM |
| 12 + H | `P` | zero bytes, `P = (8 - (12 + H) mod 8) mod 8` |
| `D` = 12 + H + P | 8N | section `h3`: N cell ids as u64, ascending. Id = the 15 hex characters read as a number |
| D + 8N | 2NK | section `features`: K = 50 columns of N u16 codes, **column-major** (all cells of column 0, then column 1, ...) |

Total length `D + 8N + 100N`. Cell centres are not stored: the browser derives geometry from the id with `h3-js`, and
the vectors were computed at `cellToLatLng(id)`.

Columns, in order (names in the header): `c_day_<seg>` for the 16 segments in shared order (indexes 0 to 15), `c_eve_<seg>`
(16 to 31), `n_<seg>` (32 to 47), `r_day` (48), `r_eve` (49). `c_` is capture, `n_` nearby, `r_` rivals.

Quantisation, per column `j` with `scale[j]` = the largest value of the column over all cells (0 for an empty column):

```
code   = 0                                             if scale[j] = 0
code   = min(65535, floor(65535 * sqrt(v / scale[j]) + 0.5))
decode = scale[j] * (code / 65535)^2
```

Error bound: `|decode - v| <= sqrt(v * scale) / 65535 + scale / (4 * 65535^2)`. Relative error is 1.5e-5 at `v = scale`,
1.5e-4 at `0.01 * scale`, 1.5e-3 at `1e-4 * scale`, and below 1 % for every `v >= 2.35e-6 * scale`. Zero is exact, and
so is the largest value of a column. The largest absolute error is `1.53e-5 * scale`. Measured on the `dc` pack: of
its 3,073,000 values none is outside the bound, and the worst one uses 0.999998 of it [load]. The
pack feeds the map layer only. Every number shown to the owner comes from exact vectors computed by the server.

JSON header (keys in this order, numbers as shortest round-trip decimals, a whole number without a fraction):

```json
{
  "format": "tp-cell-pack", "format_version": 1,
  "region_id": "dc", "dataset_version": "dc-20261003-d0514a63",
  "model_version": "tps-0.1.0", "pipeline_version": "tp-etl-1.0.0",
  "h3_res": 9, "cell_count": 61460,
  "bounds": {"lat_min": 38.00483902797089, "lng_min": -78.34937127065517, "lat_max": 39.72057772678223, "lng_max": -76.66133495242785},
  "kernel": {"earth_radius_m": 6371008.8, "walk_decay_m": 400, "walk_cutoff_m": 1200, "a0": 1.6, "visibility": 1,
             "regime_of_hour": ["eve","eve","eve","eve","eve","day","day","day","day","day","day","day",
                                "day","day","day","day","eve","eve","eve","eve","eve","eve","eve","eve"],
             "rival_weights": {"day": {"quick": 1, "full": 0.6, "cafe": 0.5, "bar": 0.1, "convenience": 0.5},
                               "eve": {"quick": 1, "full": 1, "cafe": 0.2, "bar": 0.6, "convenience": 0.4}}},
  "segments": ["res", "w_office", "w_health", "w_edu", "w_retail", "w_industrial", "w_hospitality", "w_public",
               "v_nightlife", "v_shopping", "v_leisure", "v_campus", "v_hospital", "v_transit", "v_events", "v_lodging"],
  "columns": ["c_day_res", "c_day_w_office", "r_eve"],
  "quant": {"type": "u16-sqrt", "levels": 65535},
  "scale": [2114.9956820673206, 1824.2004122670173, 112.69707005810689],
  "sections": [{"name": "h3", "type": "u64le", "offset": 0, "count": 61460},
               {"name": "features", "type": "u16le", "layout": "column-major", "offset": 491680, "count": 3073000}],
  "vintages": {"census_reference_date": "2020-04-01", "lodes_year": 2023, "osm_snapshot_date": "2026-10-03"},
  "attribution": ["© OpenStreetMap contributors", "U.S. Census Bureau, 2020 Census", "U.S. Census Bureau, LEHD LODES 8.4 (2023)"]
}
```

`columns` and `scale` are shortened here: both have exactly 50 entries in column order. The `kernel` block carries the
PHP model's build-scope seed values (shown: seeds revision 1). Section offsets are relative to `D`. `bounds` covers the
cell centres. `vintages` holds exactly the three keys shown, taken from the manifest. The header `attribution` array is
exactly the three strings shown, with the LODES format and year taken from the manifest (`vintages.lodes_format`,
`vintages.lodes_year`). The example is the header of the first load [load], with `columns` and `scale` cut to three
entries each.

Writing in PHP: `pack('VV', hexdec(substr($h, -8)), hexdec(substr($h, 0, -8)))` per id (low word first) and
`pack('v*', ...$codes)` per column chunk. Formatting hex is not H3 arithmetic. The uncompressed bytes can differ in a
few codes between platforms because `exp()` comes from the C library. The header JSON is deterministic.

Size for `dc`: the first build has N = 61,460 cells [run], so the two sections take 108 x N = 6,637,680 bytes (491,680
for the ids, 6,146,000 for the codes), plus the prefix, the header and its padding. The first load wrote a header of
3,116 bytes with no padding: 6,640,808 bytes in all, 3,413,224 gzipped (level 9) [load]. 45 % of the codes are zero.
Splitting high and low bytes into planes saves only 6 % [proto], so it is not done. Largest values [load]: 32,952
distance-weighted office jobs near one cell, rival pull 87.8 by day and 112.7 in the evening.

### 11.1 Serving

Route (final name in `04_BACKEND.md`): `GET /api/truck/regions/{region_id}/pack/{dataset_version}`, behind the existing
auth middleware, no rate-limit middleware. The version is part of the URL, so a response never changes.

1. Read `pack_gz_len, pack_sha256, load_state, kernel_json` for the key, not the blob yet. Missing, not `ready`, or
   ids that do not have the form of a region id (section 1) and of a dataset version (section 8):
   `Response::error('Not found', 404)`. Then apply the
   kernel check of 9.3 to this row's `kernel_json`: on a difference answer
   `Response::error('Region data was built with different model constants', 409)`.
2. `etag = '"' . substr(pack_sha256, 0, 32) . '-gz"'` when `Accept-Encoding` contains `gzip`, without `-gz` for the
   identity encoding. If `If-None-Match` names it (the header may hold a list, and a weak mark `W/` is ignored): the
   headers of step 5, status 304, no body, `exit`. The blob is not read for a 304.
3. Read `pack_gz`. For the identity encoding `gzdecode` it. This happens before any header is sent, so that an error
   answer never carries the cache headers.
4. `ini_set('zlib.output_compression', 'Off')` and close every output buffer, so nothing compresses twice. Apache
   `mod_deflate` leaves a response alone when it already has a `Content-Encoding`.
5. Headers: `Response::corsHeaders()`, then `Content-Type: application/octet-stream`,
   `Cache-Control: private, max-age=31536000, immutable`, `ETag`, `Vary: Accept-Encoding` (appended),
   `X-Content-Type-Options: nosniff`. Do **not** call `Response::cacheable()`: it adds `Vary: Authorization`, and a
   new login token would then invalidate the cached pack.
6. With gzip: `Content-Encoding: gzip`, `Content-Length: pack_gz_len`, echo the blob as stored. Otherwise send the
   decoded bytes with their own length. Then `exit`.

The response is not the JSON envelope. Errors are. Never return 401 here for anything but a missing login.
Exercised through `php -S` [load]: for the `dc` pack 200 with `Content-Encoding: gzip` and 3,413,224 bytes, 304 with
the validator, and 200 with 6,640,808 plain bytes under the validator without `-gz`; 404 in the envelope for a version
that is not loaded, and 409 for a pack row whose recorded kernel was changed by hand.

### 11.2 Decoding in the browser

```ts
const buf = await res.arrayBuffer();               // the browser has already removed the gzip encoding
const dv = new DataView(buf);
if (dv.getUint32(0, false) !== 0x54504350) throw new Error('not a cell pack');      // 'TPCP'
if (dv.getUint16(4, true) !== 1) throw new Error('unsupported pack version');
const H = dv.getUint32(8, true);
const header = JSON.parse(new TextDecoder('utf-8').decode(new Uint8Array(buf, 12, H)));
const D = 12 + H + ((8 - ((12 + H) % 8)) % 8);
const N = header.cell_count, K = header.columns.length;
if (buf.byteLength !== D + 8 * N + 2 * N * K) throw new Error('pack length mismatch');
const ids = new Array<string>(N);
for (let i = 0; i < N; i++) {
  const lo = dv.getUint32(D + 8 * i, true), hi = dv.getUint32(D + 8 * i + 4, true);
  ids[i] = hi.toString(16) + lo.toString(16).padStart(8, '0');                      // 15 characters
}
const F = D + 8 * N;
const features = new Float32Array(N * K);                                           // row-major for the scorer
for (let j = 0; j < K; j++) {
  const s = header.scale[j] / (65535 * 65535);
  for (let i = 0; i < N; i++) { const q = dv.getUint16(F + 2 * (j * N + i), true); features[i * K + j] = s * q * q; }
}
```

`DataView` is used so the code is correct on any byte order. A `Uint16Array` view over `buf` at `F` is a permitted
fast path on little-endian machines (`F` is a multiple of 8). The client refuses a pack whose `model_version` differs
from its estimator's or whose `dataset_version` differs from the one the server announced, and shows the map without
the layer. Hexagon outlines come from `cellToBoundary(id)`.

## 12. Quality gates

Evaluated by the pipeline (P) or the loader (L). A `fail` stops the build or the load. A `warn` is recorded and printed.
Every check of the pipeline gates G1 to G17 is one entry of `manifest.gates` (8.5), named `id.part` in the table. G18
is evaluated by the test suite and by the operator's second run, G19 to G22 by the loader. Values are for `dc`: what
differs by region comes from `checks` of the region file (section 1), what holds for every region is a constant of the
pipeline. Region counts and totals use `in_region = 1` rows only. "On this vintage" means that every state's
`version.txt` names the `lodes.vintage` of the region file: the check is then exact and `fail`. On another vintage it
is `warn` and passes within 2 %.

| # | Check | Pass condition for `dc` | Level |
|---|---|---|---|
| G1.files | Downloads (P) | every raw file used is complete, non-empty, opens as gzip, zip or PBF and passes its check of section 3. A failure stops the run at once with exit 2 | fail |
| G1.sidecars | Checksum sidecars (P) | no file was verified without its `.sha256sum` or `.md5` sidecar (possible only when no request is made, section 3 rule 5) | warn |
| G2 | Headers (P) | every PL geo row has 97 fields (counted, gated at the end). WAC 53 columns starting `w_geocode,C000,CA01` with CNS01 to CNS20 at positions 9 to 28, crosswalk 41 columns starting `tabblk2020`, and the same field count on every row of both: a violation stops the run at once | fail |
| G3.format | LODES release (P) | every `version.txt` says `lodes.format` (8.4) | fail |
| G3.vintage | LODES vintage (P) | every `version.txt` says `lodes.vintage` | warn |
| G4 | State residents (P) | equal to `checks.state_residents`: DC 689,545. MD 6,177,224. VA 8,631,393. WV 1,793,716, exactly | fail |
| G5.wac_in_pl | Block sets (P) | every WAC block is in the PL set | fail |
| G5.state_blocks | Block sets (P) | blocks per state equal to `checks.state_blocks` (6,012 / 83,827 / 163,491 / 72,558) | fail |
| G5.xwalk | Block sets (P) | the crosswalk rows number the sum of `checks.state_blocks` (325,888), every one is a PL block, none twice, no PL block missing | fail |
| G6 | Sector sums (P) | `sum(CNS01..20) = C000` on every WAC row | fail |
| G7.residents, G7.housing_units, G7.blocks, G7.blocks_with_residents | Region totals (P) | 6,278,542 / 2,458,414 / 64,615 / 48,034, exactly | fail |
| G7.counties | County rows (P) | residents, housing units and blocks of all 23 county rows of the region file, exactly | fail |
| G7.county_jobs, G7.jobs, G7.jobs.`<segment>` (seven), G7.cns04_jobs, G7.blocks_with_jobs, G7.blocks_with_either | Jobs (P) | raw jobs per county, 3,140,158 in all, the seven raw segment sums (CNS04 at weight 1, before corrections) of `checks.jobs_by_segment`, CNS04 165,397, 30,247 and 51,262 blocks: on this vintage | fail (warn on another vintage) |
| G8.segments | Conservation (P) | per worker segment, `sum over region block rows = raw segment total - discarded`, both sides with `cns04Weight` applied to CNS04, relative 1e-9 (`w_industrial`: 274,558.1 minus discarded). `discarded` is what `cap`, `drop` and a spread without receivers removed. The value recorded is the largest relative error (4e-14 in the first build) | fail |
| G8.residents | Conservation (P) | `b_res` over the region block rows equals `checks.residents` | fail |
| G8.places | Conservation (P) | elements read = rows of `places.ndjson` + the ten counters of 5.1 | fail |
| G8b.discarded | Job movement (P) | `(jobs_discarded + jobs_spread_lost) / raw region jobs` at most `checks.job_movement.discarded_share_max` (0.5 %; 0 in the first build) | fail |
| G8b.moved | Job movement (P) | `jobs_spread / raw region jobs` at most `checks.job_movement.moved_share_max` (12 %; 6.27 % in the first build, 9.05 % with 79 automatic treatments) | fail |
| G8b.review_sums | Job movement (P) | the sums of the `jobs_spread` and `jobs_discarded` columns of `job_review.csv` equal the manifest totals (relative 1e-9), and every block with a non-zero value has a row | fail |
| G9.fetch_box | Geometry (P) | every point and place inside the fetch box, edges included | fail |
| G9.county | Geometry (P) | every `in_region = 1` place lies in the county polygon of its `county_fips` | fail |
| G9.cells | Geometry (P) | every kept cell passes `isValidCell` and has resolution 9 | fail |
| G9.h3_probe | Geometry (P) | `latLngToCell` of `checks.h3_probe` gives its `cell` (`892aaab3043ffff`) | fail |
| G10 | Occupied cells (P) | resolution-9 cells holding a region block with raw residents or jobs: `checks.res9_cells_with_residents_or_jobs` (29,404) within 1 % | warn |
| G11.residents | Largest cells (P) | most residents in one cell: `checks.res9_max_residents` (4,512) within 1 % | warn |
| G11.jobs_raw | Largest cells (P) | most raw jobs in one cell: `checks.res9_max_jobs_raw` (39,467), exactly | warn |
| G11.caps | Corrections applied (P) | no block treated `spread`, `cap`, `drop` or `auto_spread` keeps more than its cap in the affected sectors, relative 1e-9 | fail |
| G11.jobs_after | Largest cells (P) | most jobs in one cell after corrections at most `checks.res9_max_jobs_after` (25,000; 18,644 in the first build, the NIH cell) | warn |
| G12.places, G12.rivals | Places (P) | region places within `checks.places` (22,000 to 28,000; 24,886 in the first build), rivals within `checks.rivals` (12,100 to 14,900; 13,508) | warn |
| G12.named | Places (P) | at least 97 % of the region places have a name (98.8 %) | warn |
| G12.host_contact | Places (P) | share of possible hosts with a phone or a website within `checks.hosts_with_contact_share` (28 to 40 %; 34.7 %) | warn |
| G12.hours | Places (P) | at least 93 % of the raw opening hours of region places parse (96.3 %) | warn |
| G12.coordinates | Places (P) | zero places without finite coordinates | fail |
| G12.types | Places (P) | every place type of the vocabulary occurs in the region. `transit_station` may be missing on the tile input | warn |
| G13 | Anchors (P) | every `checks.anchors` block keeps at least `min` jobs in its column after corrections (11,208 / 16,919 / 10,344 / 8,121 raw) | fail |
| G14 | State coverage (P) | every state of the region file has region blocks, and its residents and (on this vintage) raw jobs equal the sums of its county rows. For `dc` this is the West Virginia check: Jefferson County, 57,701 residents and 17,187 raw jobs | fail |
| G15.flagged | Job review (P) | flagged blocks and their jobs equal `checks.job_review` (86 and 572,153) | warn |
| G15.entries | Job review (P) | no stale and no orphan entry | warn |
| G15.review_pending | Job review (P) | at most half of the flagged jobs are under automatic treatment | warn |
| G15.unconfirmed | Job review (P) | no entry with `confirmed` false. The value is the number of job corrections that await the owner's confirmation (86 in the first build, its one standing warning) | warn |
| G16.share | Halo (P) | halo residents at most `checks.halo_residents_share_max` of the region's residents (5 %; 1.6 % in the first build) | fail |
| G16.totals | Halo (P) | no halo row is flagged `in_region` or counted in a total, and no region row lies outside the county list | fail |
| G17.ring5 | Cells (P) | zero ring-5 acceptances (7.1) | fail |
| G17.kept | Cells (P) | kept cells within `checks.cells_kept` (54,000 to 64,000; 61,460 in the first build) | warn |
| G18 | Determinism (P, test suite) | two runs on the same inputs give byte-identical files. Not an entry of the manifest | fail |
| G19 | Model match (L) | manifest model version and every seed value in `manifest.parameters` equal the PHP model's. The load fails if `traffic.<name>` or `traffic.<name>_typical` is missing from the PHP model's seeds for the region's `traffic_matrix` (`us_mean` when the key is absent). Printed as `G19.model_version`, `G19.parameters` and `G19.traffic_matrix` | fail |
| G20 | Pruning cross-check (L) | step 9 of section 10 (largest relative difference in the first load: 4.97e-12, of a limit of 1e-9 [load]) | fail |
| G21 | Request-path self-check (L) | step 10 of section 10: sampled cells (`G21.cells`: 225 in the first load) and sampled host vectors (`G21.hosts`: 51), both reproduced with a difference of exactly 0 [load] | fail |
| G22 | Pack (L) | decode within the quantisation bound. `cell_count` equals rows of `cells.tsv` and the ids are those of the file. Compressed size at most 16 MB (3.4 MB [load]) | fail |

Pipeline tests (`tools/truck-etl/test/*.test.mjs`, run in the Truck Planner CI job with `npm test`, which is
`node --test` in the package directory; about 150 tests, 3 s): varint, zigzag, packed arrays and the PBF reader on
files written by a test helper (bounding-box centres, granularity and offsets, compression fields, required features,
sort order, missing geometry). One element per taxonomy rule and tag alternative, and one per drop reason. The 5.7
vectors and the edges of the grammar. Phone and website cases (`+1 301-742-8261` gives `+13017428261`,
`+-804-867-5421` and `https://www/x.com` give null) and code-point cuts. De-duplication and the counters of the places
stage. The zip reader on small archives. Three real lines of the District's PL geo header, the real WAC header and
first row, a real crosswalk row. The corrections arithmetic on a synthetic county, and the committed `dc` corrections
file (86 entries whose `c000_at_review` add up to 572,153). County point-in-polygon. Source points and the three halo
distances. Cell enumeration (against a brute-force search, independent of the order of the points, the ring-5 guard)
and pruning. The writers, including the example line of 8.2 character for character, the `dataset_version` formula and
the manifest key order. Every gate on good and on bad facts. The download rules against a local HTTP server (fresh
file, 304, replaced file, a connection cut in mid-file and its resume with `Range` and `If-Range`, restart, 416,
refused status, a request that cannot be made, a download that fails its check, adoption, MD5 and SHA-256 failures,
the snapshot date). The adoption helper on the fixture's files under other names. The tile reader, and the tile fetch
helper against a fake server. Source rules: one dependency, no randomness, no clock outside `index.json` and the
console, requests only from the download and tile modules and only to the hosts of section 2.

End to end, the suite builds the committed fixture `test/fixtures/mini` twice (195 kB, cut from the real `dc` raw files
by `test-support/make-mini-fixture.mjs`): Falls Church city, Virginia, with all 164 of its census blocks, their WAC and
crosswalk rows, the county polygon and the OpenStreetMap elements of the area (851 ways, 25 relations and their nodes),
plus a strip of 254 neighbouring blocks that stands for the rest of the state, so that the halo has blocks to keep and
blocks to leave out. It checks byte-identical output (G18), the official county totals (14,658 residents, 6,172 housing
units, 10,691 jobs) against an independent reading of the files, the structure of all five files, the recorded hashes,
and the command line with its three exit codes. One more build runs online from an empty cache against a stand-in for
the hosts that answers the real source addresses with the fixture's files: it checks the order of the requests, one
request at a time, the cache and its index, the conditional requests of a second run, and that the four data files
are those of the offline build. The fixture has its own region file (`mini`), a corrections file with
one entry per treatment, one stale and one orphan, and a frozen copy of the seed values, so that a change of the seed
file does not move the recorded result. Fixture files are marked `-text` in `tools/truck-etl/.gitattributes` so
checksums survive Windows checkouts, and the region and corrections files are marked `eol=lf` so that their hashes in
the manifest are the same on every platform. The fixture build is a valid pipeline build with real H3 ids. Built with
the repository seed file (`--region-file`, `--corrections`, `--raw-dir` pointing into a copy of the fixture's raw
directory, `--offline`), it is the mini region that the backend tests load: 363 source points, 420 places and 197
cells, `mini-20261003-8d5536a4` at seeds revision 1. Its five files are committed as
`tests/fixtures/truck-planner/region-mini/build/`, and `node tests/fixtures/truck-planner/region-mini/make-fixture.mjs`
runs that build again and replaces them. It must be run again, and the files committed, whenever a build-scope seed
or a rule of the pipeline changes: gate G19 refuses a build whose recorded seed values are not the model's.

## 13. Runtime data notes for the live services

Backend clients are specified in `04_BACKEND.md`. These are the facts they build on. DECISIONS section 0 ("Google Maps
only") fixes the runtime hosts: `routes.googleapis.com`, `maps.googleapis.com`, `places.googleapis.com`,
`api.weather.gov`, `api.eia.gov`. Drive times come from Google only (DECISIONS 0). Never put exception text that may
contain a URL with a key into a response or a log line.

### 13.1 api.weather.gov - hourly forecast

| | |
|---|---|
| Step 1 | `GET https://api.weather.gov/points/{lat},{lng}` with both rounded to 4 decimals. Use `properties.gridId`, `gridX`, `gridY`, `forecastHourly`, `timeZone` |
| Step 2 | `GET https://api.weather.gov/gridpoints/{gridId}/{gridX},{gridY}/forecast/hourly` (161 kB) |
| Headers to send | `User-Agent: (TruckPlanner, <contact>)` with contact = `TP_CONTACT_EMAIL`, else `MAIL_FROM`; with neither, no request is made and the forecast is missing. `Accept: application/geo+json`. No key. Never send `Feature-Flags` (it changes field shapes) |
| Fields used | `properties.generatedAt`, `properties.periods[]`: `startTime` and `endTime` (ISO 8601 with local offset), `temperature` (integer, Fahrenheit), `temperatureUnit`, `probabilityOfPrecipitation.value` (percent, may be null: keep null and pass `HourForecast.precip_prob = null` to the model, never 0; 02_MODEL.md 4.6 then applies `weather.pop_when_missing` when the text names precipitation), `windSpeed` (text such as `2 mph` or `5 to 10 mph`: take the largest integer), `shortForecast` (text), `relativeHumidity.value`, `dewpoint.value` (Celsius), `isDaytime` |
| Coverage | 156 hourly periods, 6.5 days from the current hour. Nothing beyond that, so later days get no weather adjustment |
| Caching | Step 1 answers `Cache-Control: public, max-age=25457` with `Expires` (on 2026-10-05: `max-age=86400, s-maxage=120`): cache the grid mapping for 14 days. Step 2 answers `max-age=1708, s-maxage=3600`, `Expires` about one hour after `generatedAt`, `Last-Modified` and a weak `ETag`: cache per `gridId/gridX,gridY` until `Expires` (not less than 10 minutes) |
| Quota | Not published ("generous"). When limited, retry after 5 s |

Failure modes [V]: no User-Agent gives 403 (HTML). More than four decimals gives 301 with a relative `Location`. A point
outside coverage gives 404 with problem JSON `.../problems/InvalidPoint`, an unknown grid point 404 `InvalidGridpoint`.
Intermittent 500 and 503 on grid endpoints are known [M]: retry once, then serve the last good copy for up to 6 hours,
labelled with its `generatedAt`, then no adjustment. Store periods by instant (`startTime`): 2026-11-01 has 25 hours.
When a `DayContext` is built, each period maps to the civil date and wall-clock hour written in its `startTime` (the
local offset of the grid point, which is the region's wall clock). If two periods map to the same hour the first wins,
and an hour with no period stays null (02_MODEL.md 1.3 item 7). The grid is 2.5 km, so stops in different grid cells
need separate calls. Apparent temperature is not in the hourly product. It is in the raw grid (`forecastGridData`,
`apparentTemperature`, Celsius, ISO 8601 intervals).

Seen again on 2026-10-05 through the backend client [V]: `/points/38.9072,-77.0369` answers 200 with grid cell
`LWX 96,72`, its hourly forecast 200 with 156 periods, every `startTime` written with the offset of the place
(`-04:00`) and `Expires` one hour after `generatedAt`. A fifth decimal that is not a zero (`38.90721`) answers 301 with
a relative `Location`; trailing zeros are accepted. No `User-Agent` still answers 403, a point outside coverage 404
`InvalidPoint`. That day no period of seven forecast offices carried a null `probabilityOfPrecipitation.value`, so the null case
is not a daily one; the client keeps it null whenever it comes (04_BACKEND.md 5.5).

### 13.2 EIA API v2 - weekly retail fuel price

| | |
|---|---|
| Request | `GET https://api.eia.gov/v2/petroleum/pri/gnd/data/?api_key=<EIA_API_KEY>&frequency=weekly&data[0]=value&facets[duoarea][]=R1Y&facets[duoarea][]=R1Z&facets[duoarea][]=NUS&facets[product][]=EPMR&facets[product][]=EPD2D&start=<YYYY-MM-DD>&sort[0][column]=period&sort[0][direction]=desc&offset=0&length=100`. The key must be in the URL. `http_build_query` output is accepted [V] |
| Fields used | `response.data[]`: `period` (the Monday, `YYYY-MM-DD`), `duoarea`, `product`, `series`, `value` (a **string**, dollars per gallon, three decimals), `units` (`$/GAL`). `response.total` is also a string |
| Areas | `R1Y` Central Atlantic, PADD 1B (DC, MD). `R1Z` Lower Atlantic, PADD 1C (VA, WV). There is no state or city series for this region. `NUS`, the U.S. average, serves a base with no state (9.3, Q4) |
| Products | `EPMR` regular gasoline (series `EMM_EPMR_PTE_{area}_DPG`), `EPD2D` No 2 diesel (`EMD_EPD2D_PTE_{area}_DPG`) |
| Release | Tuesday about 10:00 a.m. Eastern, dated the Monday. Wednesday in federal-holiday weeks. One request a week is enough |
| Store | Upsert into `tp_fuel_prices` (`price_milli = round(value * 1000)`). The newest row per area and product is the current price. Keep the last attempt time in `CacheService` (6 hours) so a failing upstream is not hit on every request |
| Check values [V] | Period 2026-09-28: `R1Z` regular 4.195, `R1Y` regular 4.411, `R1Z` diesel 5.953, `R1Y` diesel 6.531, `NUS` regular 4.465, `NUS` diesel 6.382 |

Failure modes [V]: no key gives 403 `{"error":{"code":"API_KEY_MISSING"}}`, a bad key 403 `API_KEY_INVALID`. More rows
than `length` adds a top-level `warnings` array. Limits: 5,000 rows per response. Exceeding unpublished tolerances
suspends the key temporarily. Without a key, or on failure, show the last stored value with its date, else the seed
default with its date. The owner's own price always wins. `DEMO_KEY` is not for production.

### 13.3 Google Routes API - route matrix

This subsection was written from memory of Google's documentation and was checked against it on 2026-10-05, before
the clients were coded: the reference of `computeRouteMatrix`, the guides "Get a route matrix" and "Calculate toll fees
for a route matrix", the Routes usage-and-billing page, the Maps Platform SKU and price lists, and the reference and
usage page of the Distance Matrix API (Legacy). Field names, masks, limits and prices below are as documented there
[V]. Nothing was requested from Google (no key on the build machine), so what a refused or over-quota key really
answers is still [M]. DECISIONS sections 0 and 9 are the binding description; the clients are specified in
04_BACKEND.md 5.3.

| | |
|---|---|
| Request | `POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix` with `Content-Type: application/json`, `X-Goog-Api-Key: <GOOGLE_API_KEY>` and `X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration`. A field mask is mandatory |
| Body | `{"origins": [{"waypoint": {"location": {"latLng": {"latitude": 38.97, "longitude": -77.39}}}, "routeModifiers": {"avoidTolls": <profile.avoid_tolls>, "avoidHighways": <profile.avoid_highways>}}], "destinations": [{"waypoint": {"location": {"latLng": {...}}}}], "travelMode": "DRIVE", "routingPreference": "TRAFFIC_UNAWARE"}`. `routeModifiers` is added to every origin only when at least one of the two routing options is true, and then holds both as JSON booleans (04_BACKEND.md 5.3) |
| Response fields used | A JSON array with one element per pair, in any order: `originIndex`, `destinationIndex` (treat a missing `originIndex` or `destinationIndex` as 0: JSON omits zero values), `condition` (`ROUTE_EXISTS` or `ROUTE_NOT_FOUND`), `distanceMeters` (integer, may be absent when 0), `duration` (seconds as a string with an `s` suffix, such as `"160s"`), `status` (empty object when fine, else `code` and `message`) |
| Tolls | Only when wanted: add `"extraComputations": ["TOLLS"]` to the body and `travelAdvisory.tollInfo` to the mask. `travelAdvisory.tollInfo.estimatedPrice[]` carries `currencyCode`, `units` (a string) and `nanos`. `tollInfo` is absent when no toll is expected on the route, and present without `estimatedPrice` when there are tolls of unknown amount. Without toll passes in the request the amount is the cash price. Toll calculation moves the request to the dearest SKU (Enterprise) |
| Limits | 625 elements (origins x destinations) per request at this routing preference (100 for `TRAFFIC_AWARE_OPTIMAL` and for transit); no limit on one side for coordinates. Rate limit 3,000 elements per minute |
| Billing | Per element. Essentials 5.00 USD per 1,000 elements: every request of this client without tolls, also with `routeModifiers` (avoiding tolls or highways is not a "Pro" feature; Pro, 10.00 USD, is for traffic-aware routing and location modifiers). Enterprise 15.00 USD per 1,000: requests with `TOLLS`. The legacy Distance Matrix API is 5.00 USD per 1,000 elements without traffic information |
| Determinism | `TRAFFIC_UNAWARE` durations do not depend on the time of the request. Time-of-day factors are ours (seed table) |
| Caching | No cache headers. Legs go to `tp_drive_legs` per directed pair of rounded coordinates plus the two routing options (`avoid_tolls`, `avoid_highways`), with `fetched_at`, and are refreshed after 30 days at most. Google content is never kept longer. Owner corrections are the owner's data and do not expire |

Failure modes: errors are JSON `{"error": {"code", "message", "status", "details"}}` [V: Google's "Handle request
errors" page shows 403 `PERMISSION_DENIED` for a missing key and 400 `INVALID_ARGUMENT` for a malformed request]. 403
`PERMISSION_DENIED` when the Routes API is not enabled for the key's project (`details[].reason` `SERVICE_DISABLED`)
or the key's restrictions exclude it (`API_KEY_SERVICE_BLOCKED`), and 429 `RESOURCE_EXHAUSTED` over quota [M: the reason
words are Google's, the pairing with these HTTP statuses was not observed]. On "not enabled", DECISIONS allows one
attempt at the legacy Distance Matrix API [V for what follows]:
`GET https://maps.googleapis.com/maps/api/distancematrix/json` with `origins` and `destinations` as `lat,lng` pairs
joined by the vertical bar, `mode=driving`, `units=metric`, `key`, and `avoid` holding only the options that are true
(`avoid=tolls`, `avoid=highways` or `avoid=tolls|highways`; the parameter is omitted when neither is set). It answers
HTTP 200 even when refused, with a top-level `status` (`OK`, `REQUEST_DENIED`, `OVER_QUERY_LIMIT`, ...) and per pair
`rows[i].elements[j]` holding `status`, `duration.value` (seconds) and `distance.value` (metres), at most 25 origins,
25 destinations and 100 elements per request. The key sits in that URL, so the URL must never be logged. If both are
refused: the labelled straight-line estimate, and no new attempt for an hour. Every Google call is metered in
`api_cost_events`.

### 13.4 Google Places (New) Text Search - contact lookup on demand

Used for one Scout candidate at a time, when the owner asks (DECISIONS 0 and 9). Checked on 2026-10-05 against Google's
reference of `places.searchText`, its guides "Text Search (New)" and "Place Data Fields (New)", the Places API
usage-and-billing page, the Google Maps Platform price list and the Places API policies page. No request was sent:
this machine has no key. The client is specified in `04_BACKEND.md` 5.4.

| | |
|---|---|
| Request | `POST https://places.googleapis.com/v1/places:searchText` with `Content-Type: application/json`, `X-Goog-Api-Key: <GOOGLE_API_KEY>` and `X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.location,places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri`. A field mask is mandatory, and these are the fields' exact mask names |
| Body | `{"textQuery": "<place name>", "languageCode": "en", "pageSize": 1, "locationBias": {"circle": {"center": {"latitude": .., "longitude": ..}, "radius": 500.0}}}`. `textQuery` is the only required field. `pageSize` is 1 to 20 (`maxResultCount` is its deprecated name). The radius is in metres, 0.0 to 50,000.0. A bias prefers results near the point, it does not exclude others |
| Response fields used | Of `places[0]`: `id` (the place id), `displayName.text`, `formattedAddress`, `location.latitude` and `location.longitude`, `nationalPhoneNumber`, `websiteUri`, `googleMapsUri`. A field Google does not have is absent, and with no match `places` itself is absent (`{}`) |
| Match check | The bias cannot exclude a namesake elsewhere, so the location is asked for: a first result more than 300 m from the candidate's point, or without a location, is no confident match and is stored as not found. The location is used for that comparison only and is never stored (DECISIONS 0) |
| Billing | Per request, at the dearest SKU the mask touches: `id` alone is Text Search Essentials (IDs Only); `displayName`, `formattedAddress`, `location` and `googleMapsUri` are Pro fields; `nationalPhoneNumber` and `websiteUri` are Enterprise fields. Every lookup is one Text Search Enterprise request: 35.00 USD per 1,000 in the first price tier, the first 1,000 of a month free |
| Caching | No cache headers. Google's policy: Places API content must not be pre-fetched, cached or stored beyond the listed exceptions. The place id is exempt and may be kept indefinitely (refresh one that is older than 12 months; a Place Details request for the id alone is free). Latitude and longitude may be cached for up to 30 consecutive days [M: read in a summary of the service specific terms, not on the page itself]. Name, address, phone, website and Maps link are not among the exceptions |

Failure modes [M: Google's documented mapping, not observed without a key]: errors are JSON `{"error": {"code",
"message", "status", "details"}}`. 403 `PERMISSION_DENIED` with the reason `SERVICE_DISABLED` while Places API (New) is
not enabled for the key's project, 400 `INVALID_ARGUMENT` for a request Google cannot read (a missing field mask among
them), 429 `RESOURCE_EXHAUSTED` for quota.

Data rule: the result belongs to the owner's Scout lead (place id kept, other fields for 30 days at most) and is
**never** written to `tp_places`. `tp_places.phone` and `website` hold OpenStreetMap values only. Looked-up Google
fields are shown only on the Scout lead they belong to (with string 12 of section 14), never in the day sheet, the
calendar file or any export, and are deleted when their 30 days end. Whether keeping name, address, phone, website and
Maps link for those 30 days is covered by the account's agreement with Google is open: the policy page names the place
id as the exception, the service specific terms the coordinates (`04_BACKEND.md` section 10, C21). The free
"Open in Google Maps" link needs no call: `https://www.google.com/maps/search/?api=1&query=<lat>%2C<lng>`, and with a
stored place id
`https://www.google.com/maps/search/?api=1&query=<name>&query_place_id=<place id>`.

## 14. Terms and the strings the UI must show

This is a reading of the licences, not legal advice. The owner's confirmation of the ODbL terms is pending (DECISIONS 14).

Exact strings (each is shown in a code span; `{...}` are placeholders, filled as listed after the strings):

1. Wherever OpenStreetMap-derived places are listed: the Scout list, the spot card's lists of nearby outlets and
   places, the day sheet, every export that contains place names or contacts, and the map legend, where it is part of
   string 10 (DECISIONS 8). Linked to `https://www.openstreetmap.org/copyright`:
   `© OpenStreetMap contributors`
2. Where there is room for a sentence (Scout footer, day sheet footer, export header). Same link:
   `Place data © OpenStreetMap contributors, available under the Open Database License (ODbL).`
3. Data page, residents:
   `Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Counts as of April 1, 2020, not adjusted for growth.`
4. Data page, jobs. Linked to `https://lehd.ces.census.gov/data/`:
   `Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), version 8.4, 2023, all jobs. Job counts are jobs of record with statistical noise added by the Census Bureau, not people present. {blocks_adjusted} payroll-address blocks holding {jobs_spread} jobs were spread over their county (corrections {corrections_version}); construction jobs count at {cns04_weight_percent} %.`
5. Data page, places:
   `Places: OpenStreetMap snapshot of {osm_snapshot_date} (Geofabrik extracts). © OpenStreetMap contributors, ODbL 1.0. The places table is a database derived from OpenStreetMap and is available under the ODbL on request: {contact}.`
6. Data page, weather: `Forecast: National Weather Service (weather.gov).`
7. Data page, fuel: `Fuel price: U.S. Energy Information Administration, weekly retail prices, week of {period}.`
8. Data page, boundaries: `County boundaries: U.S. Census Bureau, TIGERweb.`
9. Data page, drive times: `Drive times and distances: Google Maps Platform. Kept for at most 30 days.` Google's own
   attribution rules for results shown away from a Google map (day sheet, exports) were not read today [M].
10. Map legend, source line (our own legend text, not Google's map attribution), always visible with the legend and
    filled from the pack header `vintages`. The part `© OpenStreetMap contributors` is linked to
    `https://www.openstreetmap.org/copyright`:
    `People: US Census {census_year}, LEHD {lodes_year} · Venues: © OpenStreetMap contributors`
    For `dc` this reads `People: US Census 2020, LEHD 2023 · Venues: © OpenStreetMap contributors`.
11. Data page, traffic: `Time-of-day traffic factors: derived from the TomTom Traffic Index 2025.` TomTom's terms were
    not read. Until someone confirms the table may be used, ship `traffic.dc` and `traffic.us_mean` as all 1.0 with
    `traffic.dc_typical` and `traffic.us_mean_typical` 1.0 (02_MODEL.md 9.3 item 1). The line is then not shown.
12. Next to any looked-up contact detail (13.4): `Phone and website from Google Maps` [M: confirm Google's wording and
    logo rules for Places content shown without a map before release].

| Placeholder | Filled from |
|---|---|
| `{osm_snapshot_date}` | `manifest_json.vintages.osm_snapshot_date` |
| `{contact}` | `TP_CONTACT_EMAIL`, else `MAIL_FROM` |
| `{period}` | `period` of the `tp_fuel_prices` row whose price is shown |
| `{blocks_adjusted}`, `{jobs_spread}` | `manifest_json.totals.blocks_adjusted` and `totals.jobs_spread`, the latter shown as a whole number |
| `{corrections_version}` | `manifest_json.inputs.corrections_version` |
| `{cns04_weight_percent}` | `manifest_json.parameters.cns04_weight` x 100, shown without decimals (30) |
| `{census_year}`, `{lodes_year}` | pack header: the first four characters of `vintages.census_reference_date`, and `vintages.lodes_year` |

The Data page also shows the `dataset_version`, the pipeline and model versions and the corrections file version from
`manifest_json`. The Google base map's own attribution does not cover the OpenStreetMap-derived data, and the heat
layer is computed partly from OpenStreetMap venues and outlets, so the map legend carries string 10 (DECISIONS 8). It
is legend text, not a change to Google's map attribution.

ODbL duties and how they are met:

1. Attribution wherever OSM-derived data is shown: the strings above.
2. Share-alike applies to the derived database once the product is used by customers. The derived database is
   `tp_places`, the place rows of `tp_points` and the cell pack. The offer on request is either an export
   (`scripts/truck/export-places.php`, NDJSON of `tp_places` for the active version with a licence line) or the method
   that produces it: the dated extract names and checksums in the manifest, this document and `tools/truck-etl`.
3. Keep OSM-derived rows separate. `tp_places` holds OSM rows only, each with `osm_type`, `osm_id` and `snapshot_date`.
   Owner tables refer to a place by `place_key` and never copy other sources into `tp_places`. Never merge Google
   Places results (13.4), vendor tables or owner-entered hosts into it: that would extend share-alike to them and
   would break Google's storage terms.
4. Census, LODES and owner data sit in their own tables and columns (a collective database) and are not affected.
5. The source stays replaceable (DECISIONS 0): only section 2.3 and the `osm_*` columns know that places come from
   OpenStreetMap. Everything after `places.ndjson` works on place types.

Census, LODES, TIGERweb, NWS and EIA data are US government works in the public domain. The Census API sentence about
endorsement is not required because the bulk files are used. `h3-js` is Apache-2.0 (library, no data). Google content
(route durations and distances, looked-up place details) is cached for at most 30 days. Google place ids may be kept.

## 15. Operator procedures

### 15.1 Quarterly refresh (per region, about an hour including the review)

Nothing in this procedure touches production before step 9, and step 9 needs the owner's go-ahead.

1. On a workstation with Node 20 or newer and 2 GB of free disk: `git pull`, then `npm ci` in `tools/truck-etl`.
2. Check for new inputs: read `https://lehd.ces.census.gov/data/lodes/LODES8/dc/version.txt` (a new `Data Vintage` or
   data year means the jobs side changes: update `lodes` in the region file, expect G3, G7 and G15 warnings and plan
   the review of 15.2). The Census 2020 files never change.
3. Run `node tools/truck-etl/bin/build-region.mjs --region=dc --contact=<email>`. It downloads what is missing (the four
   extracts are 763 MB), builds, and prints the gate table.
4. If the run fails on a gate, read the gate's `value` and `expected` in `_failed/manifest.json`. Do not edit check
   values to make a gate pass unless the source really changed (new LODES vintage) and the new value has been explained.
5. Review jobs (15.2). Rebuild with `--offline` after editing the corrections file. Repeat until G15 has no "review
   pending" warning or the remaining automatic treatments are accepted on purpose.
6. Compare with the previous manifest: residents identical, jobs within 2 % unless the vintage changed, places and
   rivals within 10 %, cells within 5 %. Read every `warn` line. Larger moves need an explanation before going on.
7. Commit the corrections file and any region-file change. Note the `dataset_version`.
8. Copy the build directory `storage/truck/build/dc/<dataset_version>/` (28 MB) to the same path on the server.
9. On the server, with the owner's go-ahead: take a database dump (`scripts/backup-db.sh`), run pending migrations
   (`php8.3 scripts/migrate.php`), then `php8.3 scripts/truck/load-region.php --build=storage/truck/build/dc/<dataset_version>`.
   It must end with all loader gates passed and `load_state ready`.
10. Activate: `php8.3 scripts/truck/load-region.php --region=dc --activate=<dataset_version>`.
11. Smoke test: open the map (the pack request returns 200 with the new version in its URL), click one office area and
    one residential area, open one saved spot, open Scout, open the Data page and check the vintages.
12. If anything is wrong: `php8.3 scripts/truck/load-region.php --region=dc --activate=<previous version>`. The
    previous rows and pack are still there.
13. After a week without problems: `--prune` on the server, and delete raw files older than the previous version.

### 15.2 Suspicious-jobs review

1. Open `job_review.csv` of the build in a spreadsheet. Sort by `c000` descending. Work through rows whose `treatment`
   is `auto_spread`, whose `confirmed` is 0 or whose `entry_state` is `stale` or `orphan`.
2. For each row open `map_url` and look at what stands at the point. Use `top1_sector`, `top1_share`, `place_name`,
   `military_name`, `residents` and `hint_place`.
3. Decide:

   | What you see | Action |
   |---|---|
   | A site that plainly employs that many people there: hospital, university, airport, federal campus, office tower, plant | `keep` |
   | An administrative address of an employer whose staff work across the county: school system, county or city government, home health or staffing agency | `spread`, with `cap` = a rough count of people actually in that building (0 if unknown) |
   | Jobs that cannot be at that point and have no sensible local distribution: a payroll processor, a headquarters reporting staff from other regions | `cap` with a small number, or `drop` |
   | Cannot tell | leave it without an entry. The automatic cap applies and the row returns next quarter |

4. Write the entry in `tools/truck-etl/corrections/<region>.jobs.json` with `reason` (one sentence a stranger would
   accept), `c000_at_review` (copy `c000`) and `reviewed` (today). Use `sectors` when only one sector is wrong. A
   first-pass decision that still awaits the owner carries `"confirmed": false`. Once the owner agrees, set it to true
   or remove the key.
5. For `stale` entries: confirm the decision still holds for the new count and update `c000_at_review` and `reviewed`.
   Delete `orphan` entries after checking the GEOID is not a typing error.
6. Increase `version` in the file (date plus a counter).
7. Rebuild with `--offline`. Check in the new `job_review.csv` that each edited row shows the intended `treatment` and
   `jobs_after`, that G13 (anchors), G8 (conservation) and G8b (job movement) pass, and that the largest job cell
   (G11) is a place you believe.
8. Commit the file with a message naming the region and the number of entries changed.

On first use the owner confirms the 86 first-pass entries for `dc` (set `confirmed` true or change the action). After
that expect only new, stale or changed rows. Never mark a block `keep` just to make a favourite spot look better: the
review decides where jobs are, the owner's logged services decide how good a spot is.

