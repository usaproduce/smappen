# Truck Planner · blueprint

> **Status of this file.** This is the product blueprint the owner handed over on 2026-10-04, saved verbatim below.
> It was written as the README of a larger hand-off bundle (seven documents, a Python reference, golden cases,
> PHP/TS ports, an ETL prototype). **Only this README was supplied** — none of the files it refers to
> (`reference/…`, `ports/…`, `docs/01…07`) exist. The owner's instruction: use this as the blueprint, and build it
> **on top of the existing smappen application**, reusing what is already there. Where this file refers to supplied
> artefacts, read it as "what has to exist when we are done". The mapping onto this repository is in
> [`DECISIONS.md`](DECISIONS.md).

---

# Truck Planner · build spec

Truck Planner is a map-based planning tool for one food truck. The owner drags a time control and the map shows where people are at that hour and how many orders the truck could expect there. They save spots, line them up into a day, and see drive times, gas, costs and take-home before committing.

It works for a single truck with no other users, no location tracking and no AI calls while it runs. Every number is computed from public data and the owner's own settings, and the same inputs always give the same answer.

This folder is the hand-off for Claude Code: seven documents, a tested reference implementation of the math, a tested data pipeline, and the same math already ported to PHP and TypeScript.

---

## What the owner can do

1. **See who is where, by hour.** Residents, workers and venue visitors within walking distance of any point, for any hour of the week.
2. **Simulate a spot.** Click anywhere: expected orders for each hour of the week, the best windows, who the customers would be, what the truck would clear after costs, and how many orders it takes to break even.
3. **Save spots** with their real terms: fee, host, whether the truck is the venue's only food, how visible it is.
4. **Plan a day.** Order the stops and see leave-by times, drive minutes, miles, gas, labour, take-home and take-home per hour, and what each extra stop adds.
5. **Plan against a date.** The forecast and holidays adjust the numbers for the next week.
6. **Log what happened.** Actual orders after each service pull the estimates toward the truck's own results, spot by spot.
7. **Find new spots.** A ranked list of named places within reach that could host a truck, with phone and website.

## Three rules that shape everything

| Rule | What it means in practice |
|---|---|
| **One truck is enough** | Nothing depends on other users. There is no GPS, no background location and no call to the browser's location API. The only places stored are the ones the owner enters. |
| **No AI at runtime** | No model or LLM call in any request or job. Outputs are plain functions of stored data and settings. Learning from the owner's sales is ordinary statistics. A test in CI enforces this. |
| **Honest numbers** | Every estimate is a range with a confidence label and a breakdown the owner can open. The owner's logged results outrank the model. The app never says a spot is legal. |

---

## What has to be built

| Piece | What it does | Session |
|---|---|:---:|
| Decisions file | Maps this spec onto the real repository before any code is written | 0 |
| Estimator, PHP and TypeScript | The math: demand by hour, orders in a window, money, break-even, day plans, suggestions, calibration. Supplied in `ports/` and already passing the tests; the work is adopting it | 1 |
| Geodata pipeline | Census residents, jobs by industry and places, turned into map cells and loaded into PostGIS. Supplied as a tested prototype | 2 |
| Map and time control | Hexagon heat map that recolours instantly; layers for opportunity, people nearby, competition | 3 |
| Spot card | Everything about one point: week strip, best windows, who is here, why this number | 3 |
| Truck profile and costs | Ticket, speed of service, crew, wages, food cost, fuel economy; every assumption editable | 4 |
| Saved spots and money | Fees, hosts, visibility; contribution, break-even | 4 |
| Fuel price | Weekly regional price from the EIA, overridden by the price the owner pays | 4 |
| Drive times | OpenRouteService matrix with a permanent cache, time-of-day adjustment, owner corrections, tolls | 5 |
| Day planner | Timeline, costs, take-home, warnings, what each stop adds; compare spots side by side | 5 |
| Dates | Hourly forecast, holidays, "treat this day as a Saturday", week view | 6 |
| Logging and calibration | Actual orders in, corrected estimates out; an accuracy page | 7 |
| Scout and suggestions | Ranked candidate hosts inside the drive-time and licence area; best day and best week from saved spots | 8 |
| Events, catering, exports | Attendance-based event estimates, guaranteed-fee stops, printable day sheet, calendar file, data page | 9 |

Later layers plug into the same tables without reshaping them: sales import from Square, Clover or a CSV, opt-in check-ins, and anything that needs other trucks. See `docs/07_LATER_LAYERS.md`.

## What is already built and tested here

| File | What it is | Evidence |
|---|---|---|
| `reference/truck_planner_reference.py` | The math, in plain Python with no dependencies. This is the definition; the documents explain it | Runs its own 229 test cases: 0 problems |
| `reference/tp_golden_cases.json` | 229 test cases as data (`function, arguments, expected result`), runnable from any language | Generated by the reference |
| `ports/php/Estimator.php` | The same math for the backend, PHP 8.3, no dependencies | 229 of 229 cases pass, also under three different time zones |
| `ports/ts/estimator.ts` | The same math for the browser, plus a fast path that scores map cells in bulk | 241 of 241 pass (229 plus 12 fast-path checks); strict type-check passes; 10,000 cells scored in about 1 ms |
| `reference/tp_etl_prototype.py` | The data pipeline | Run on real data for the Washington DC region on 2026-10-04: 5.8 million residents, 3.0 million jobs, 51,813 places, 56,653 map cells, built in 12 seconds |
| `reference/tp_anchors_dc_expected.json` | Estimates at 19 named places, used as build checks | Produced by that run |
| `reference/tp_seed_assumptions.json`, `tp_seed_curves.json` | Every starting assumption and hour-by-hour curve | Generated by the reference |

The two ports were written by helper agents and checked by machine against every test case. They have not been read line by line by a person. Session 1 includes that review.

---

## How to hand this to Claude Code

1. Put this folder in the repository at `docs/truck-planner/`.
2. Open `docs/06_SESSIONS.md`. Each session has a prompt to paste, a list of what to read, what to build, what is out of scope, and a checklist to finish on.
3. Run one session per sitting, in order. Session 0 writes no feature code: it reads the repository and records how this spec maps onto it.
4. Do not start a session until the previous checklist is fully green.

Paths in the documents use placeholders such as `{{BACKEND_SRC}}`. Session 0 resolves them and writes the answers to `DECISIONS.md`.

## What you need to supply

| Item | Needed for | Cost |
|---|---|---|
| OpenRouteService API key | Drive times and reach | Free plan is enough for one truck |
| EIA API key | Weekly fuel prices | Free. Optional: the owner can type a price instead |
| A map key for the library the app already uses | Base map | Existing |
| A support contact address | The weather service requires one in each request | None |
| The metro area to load first | Everything | The DC region is ready as the example |
| About an hour per region each quarter | Looking over the list of suspicious job counts after a data refresh | Your time |

---

## A worked example

One Thursday, default truck (two paid crew at $18, $15 average ticket, 45 orders an hour at full speed, 9 mpg), base in Sterling, Virginia, regular gasoline at $4.195. Catchment numbers come from the real DC-area run. Drive legs here use the straight-line fallback, because no routing key was involved.

| | Lunch only | Lunch, then a taproom |
|---|---:|---:|
| Herndon office park, 11:00–14:00 | 66 orders (40 to 103) | 66 orders (40 to 103) |
| Sterling taproom, 17:00–20:00, truck is the only food | | 39 orders (23 to 64) |
| Sales | $983 | $1,564 |
| Day, prep to close | 5.5 hours | 11.3 hours |
| Miles driven, fuel | 9.7 mi, $4.54 | 9.9 mi, $4.63 |
| **Take-home** | **$423** ($164 to $801) | **$567** ($154 to $1,198) |
| Take-home per hour | $77 | $50 |

The planner then says what the owner most needs to hear: the second stop adds $144 for 5.8 more hours, about $25 an hour, because the crew is paid through a two-hour gap. If the gap is an unpaid break the day clears $646 at $69 an hour. On a weak day the second stop loses money. On a Friday the same taproom is worth 55 orders instead of 39.

The day sheet for the two-stop version: start prep 9:34, leave base 10:19, arrive 10:30, open 11:00, close 14:00, leave 14:20, arrive at the taproom 14:30, open 17:00, close 20:00, leave 20:20, back at base 20:21, done 20:51.

Every figure above is in `reference/tp_golden_vectors.json` under `gv15_worked_day`.

---

## Confirmed and assumed

**Confirmed on 2026-10-04**

- Every data source answers at the address given in `docs/03_DATA.md`, and the files contain the fields the pipeline reads.
- The pipeline runs end to end on real data and gives the counts and anchor estimates recorded here.
- The reference math passes its own test cases, and both ports reproduce them.
- OpenRouteService free-plan limits, the weather service's response format, and the EIA example prices.

**Assumed, and worth a second look**

- **Every seed number.** The hour-by-hour curves, the pull of competitors, venue sizes, weather effects and traffic factors are engineering placeholders tuned until a real metro area produced plausible magnitudes. None is measured from food truck sales. Before the owner has logged services, the planner is good for ranking places and times against each other and poor at forecasting dollars. After roughly ten logged services the dollar figures start to carry weight.
- **The target stack.** The documents assume PHP 8.3 on a custom framework, MySQL for application tables, PostGIS for geodata, Redis, a job queue, and React with TypeScript, Zustand, TanStack Query and Tailwind. Session 0 confirms or corrects this. The math, the data pipeline and the API contracts do not depend on it.
- **Two response formats not exercised here:** the EIA price API (needs a key) and the Census boundary files (address verified, contents not parsed).
- **Hospitals and campuses** produce some of the highest estimates on the map and have the weakest seeds.

## Documents

| Document | Read it for |
|---|---|
| `docs/01_PRODUCT.md` | Rules, user flows, screens, wording, what version 1 leaves out |
| `docs/02_MODEL.md` | The math in words, every assumption with its default, limits, porting traps |
| `docs/03_DATA.md` | Sources, the pipeline step by step, checks, terms of use |
| `docs/04_BACKEND.md` | Tables, API contracts, services, jobs, tests |
| `docs/05_FRONTEND.md` | Screens in detail, the map layer, performance budgets |
| `docs/06_SESSIONS.md` | The build plan: ten sessions with prompts and checklists |
| `docs/07_LATER_LAYERS.md` | Square, Clover, CSV import, check-ins, network features |

Model version in this bundle: `tp-0.1.0`.
