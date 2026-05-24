# Advanced Features Implementation Guide

## Beyond Smappen — 8 Differentiation Features

This guide provides full implementation blueprints for eight features that Smappen does not offer. Each section covers the problem being solved, the technical architecture, database schema additions, API design, frontend components, cost estimates, and which subscription tier should gate the feature.

All features assume the base architecture from the Smappen Clone Blueprint: React + TypeScript frontend, Node.js + Express/Fastify backend, PostgreSQL + PostGIS database, Redis cache, Google Maps Platform, and OpenRouteService for isochrones.

---

## Table of Contents

1. [Automated Territory Balancing & Generation](#1-automated-territory-balancing--generation)
2. [Quantitative Cannibalization Modeling](#2-quantitative-cannibalization-modeling)
3. [Time-of-Day / Day-of-Week Isochrones](#3-time-of-day--day-of-week-isochrones)
4. [Multi-Location Optimization](#4-multi-location-optimization)
5. [Customer Segmentation & Psychographic Data](#5-customer-segmentation--psychographic-data)
6. [Collaboration & Version History](#6-collaboration--version-history)
7. [Mobile Field App](#7-mobile-field-app)
8. [Competitor Monitoring & Alerts](#8-competitor-monitoring--alerts)

---

## 1. Automated Territory Balancing & Generation

### The Problem

Franchisors need to divide a metropolitan area into N balanced territories. Today this is done by hand — a development director eyeballs the map, draws polygons, checks demographics, adjusts, checks again. For 15 territories in a metro area, this can take days.

### The Solution

An algorithm that takes a geographic region, a target number of territories, and one or more balancing criteria (population, household income, POI count, etc.), and automatically generates non-overlapping polygons that distribute the chosen metric as evenly as possible.

### Algorithm: Weighted Voronoi with Iterative Adjustment

The core approach is a **capacitated k-means clustering** applied to Census tracts.

```
Input:
  - market_polygon: GeoJSON polygon defining the total market area
  - n_territories: integer (e.g., 8)
  - balance_metric: 'population' | 'income' | 'households' | 'composite'
  - tolerance: float (e.g., 0.15 = allow 15% deviation from perfect balance)

Algorithm:
  1. Fetch all Census tracts that intersect market_polygon
  2. Weight each tract by balance_metric (e.g., total_population)
  3. Run k-means clustering on tract centroids with k = n_territories
  4. Assign each tract to its nearest cluster center
  5. Compute total weight per cluster
  6. Target weight = total_weight / n_territories
  7. Iteratively swap border tracts between clusters to reduce imbalance:
     a. Find the most over-weight cluster
     b. Find its border tracts (tracts adjacent to another cluster)
     c. Move the border tract that best reduces imbalance
     d. Repeat until all clusters are within tolerance
  8. Merge tract geometries per cluster → territory polygons
  9. Smooth polygon boundaries with ST_SimplifyPreserveTopology

Output:
  - Array of n_territories GeoJSON polygons
  - Per-territory metrics (population, income, area, etc.)
  - Balance score (coefficient of variation across territories)
```

### Backend Implementation

```typescript
// POST /api/territories/auto-generate
interface AutoGenerateRequest {
  projectId: string;
  marketPolygon: GeoJSON.Polygon;
  numTerritories: number;
  balanceMetric: 'population' | 'median_income' | 'households' | 'composite';
  tolerance: number; // 0.0 to 0.5
  compositeWeights?: {
    population: number;
    income: number;
    households: number;
  };
}

interface AutoGenerateResponse {
  territories: Array<{
    polygon: GeoJSON.Polygon;
    metrics: {
      totalPopulation: number;
      medianIncome: number;
      totalHouseholds: number;
      areaSqKm: number;
      tractCount: number;
    };
    suggestedName: string; // "Territory 1 — Northwest"
  }>;
  balanceScore: number; // Coefficient of variation (lower = better)
  iterations: number;
}
```

**Step 1: Fetch tracts within market area**

```sql
-- Get all Census tracts within the market polygon, weighted by metric
SELECT
  ct.geoid,
  ST_Centroid(ct.geometry) AS centroid,
  ST_X(ST_Centroid(ct.geometry)) AS lng,
  ST_Y(ST_Centroid(ct.geometry)) AS lat,
  cd.total_population,
  cd.median_household_income,
  cd.housing_units_total,
  ct.geometry
FROM census_tracts ct
JOIN census_demographics cd ON ct.geoid = cd.geoid
WHERE ST_Intersects(ct.geometry, ST_GeomFromGeoJSON($1))
  AND cd.total_population > 0
ORDER BY ct.geoid;
```

**Step 2: K-means clustering (server-side)**

```typescript
import * as tf from '@tensorflow/tfjs-node';

function kMeansCluster(
  tracts: TractData[],
  k: number,
  balanceMetric: string,
  tolerance: number,
  maxIterations: number = 500
): ClusterResult[] {
  // Extract coordinates and weights
  const coords = tracts.map(t => [t.lng, t.lat]);
  const weights = tracts.map(t => getMetricValue(t, balanceMetric));
  const totalWeight = weights.reduce((a, b) => a + b, 0);
  const targetWeight = totalWeight / k;

  // Initial k-means on coordinates only
  let assignments = initialKMeans(coords, k);

  // Iterative swap phase for balance
  let iteration = 0;
  let balanced = false;

  while (!balanced && iteration < maxIterations) {
    const clusterWeights = computeClusterWeights(assignments, weights, k);
    const cv = coefficientOfVariation(clusterWeights);

    if (cv <= tolerance) {
      balanced = true;
      break;
    }

    // Find most over-weight cluster
    const maxCluster = clusterWeights.indexOf(Math.max(...clusterWeights));
    const minCluster = clusterWeights.indexOf(Math.min(...clusterWeights));

    // Find border tracts in maxCluster adjacent to minCluster
    const borderTracts = findBorderTracts(tracts, assignments, maxCluster, minCluster);

    if (borderTracts.length === 0) break;

    // Swap the tract that best reduces imbalance
    const bestSwap = borderTracts.reduce((best, tractIdx) => {
      const newWeightMax = clusterWeights[maxCluster] - weights[tractIdx];
      const newWeightMin = clusterWeights[minCluster] + weights[tractIdx];
      const improvement = Math.abs(newWeightMax - targetWeight)
                        + Math.abs(newWeightMin - targetWeight);
      return improvement < best.improvement
        ? { tractIdx, improvement }
        : best;
    }, { tractIdx: -1, improvement: Infinity });

    if (bestSwap.tractIdx >= 0) {
      assignments[bestSwap.tractIdx] = minCluster;
    }

    iteration++;
  }

  return buildClusterResults(tracts, assignments, k);
}
```

**Step 3: Merge tract geometries into territory polygons**

```sql
-- Merge all tracts assigned to each cluster into a single polygon
SELECT
  cluster_id,
  ST_SimplifyPreserveTopology(
    ST_Union(ct.geometry),
    0.001  -- Smoothing tolerance in degrees (~100m)
  ) AS territory_polygon,
  SUM(cd.total_population) AS total_population,
  ROUND(AVG(cd.median_household_income)) AS avg_median_income,
  SUM(cd.housing_units_total) AS total_households,
  ST_Area(ST_Union(ct.geometry)::geography) / 1e6 AS area_sq_km
FROM tract_assignments ta
JOIN census_tracts ct ON ta.geoid = ct.geoid
JOIN census_demographics cd ON ct.geoid = cd.geoid
GROUP BY cluster_id;
```

### Database Additions

```sql
-- Track auto-generation history
CREATE TABLE territory_generation_jobs (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  market_polygon GEOMETRY(Polygon, 4326) NOT NULL,
  num_territories INT NOT NULL,
  balance_metric VARCHAR(50) NOT NULL,
  tolerance FLOAT NOT NULL,
  result_balance_score FLOAT,
  result_iterations INT,
  status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
  created_by UUID REFERENCES users(id),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  completed_at TIMESTAMPTZ
);

-- Auto-generated territories link back to the job
ALTER TABLE areas ADD COLUMN generation_job_id UUID REFERENCES territory_generation_jobs(id);
```

### Frontend Components

```
src/components/territories/
├── AutoGenerateWizard.tsx       — Step-by-step wizard UI
│   ├── Step 1: Draw or select market boundary
│   ├── Step 2: Choose number of territories
│   ├── Step 3: Select balancing metric + tolerance slider
│   └── Step 4: Preview results, adjust, accept
├── BalanceIndicator.tsx         — Visual bar showing per-territory metric distribution
├── TerritoryAdjuster.tsx        — Drag border tracts between territories post-generation
└── BalanceScoreCard.tsx         — Shows coefficient of variation, min/max territory stats
```

### Tier Gating

- **Pro ($199/mo):** Auto-generate up to 10 territories, single metric
- **Advanced (custom):** Unlimited territories, composite metric weighting, save generation presets

### Dependencies

- `@tensorflow/tfjs-node` or `ml-kmeans` for clustering (can also use a simple custom implementation)
- PostGIS `ST_Union`, `ST_SimplifyPreserveTopology` for polygon merging
- Turf.js `booleanAdjacent` for identifying border tracts

### Cost

Zero additional API cost — this is entirely computation against data you already have (Census tracts in PostGIS). Server compute cost is minimal; a 15-territory generation across 500 tracts completes in under 2 seconds on a modest server.

---

## 2. Quantitative Cannibalization Modeling

### The Problem

Smappen shows you visually that two territories overlap. It does not tell you how much revenue or population the new location would steal from an existing one. Franchise development teams need a number they can put in a board deck: "Opening Location C would cannibalize 23% of Location B's trade area population."

### The Approach

For any new proposed area, compute its geometric intersection with every existing area in the project, then calculate what percentage of each existing area's key metrics (population, households, POI count) fall within the overlap zone.

### Backend Implementation

```typescript
// POST /api/areas/:areaId/cannibalization
interface CannibalizationRequest {
  newAreaId: string;        // The proposed new area
  existingAreaIds: string[]; // Areas to check against (or "all" in project)
  metrics: ('population' | 'households' | 'income' | 'poi_count')[];
}

interface CannibalizationResult {
  newArea: { id: string; name: string };
  impacts: Array<{
    existingArea: { id: string; name: string };
    overlapPolygon: GeoJSON.Polygon | null;
    overlapAreaSqKm: number;
    overlapPercentOfExisting: number; // Geographic overlap %
    metrics: {
      population?: {
        existingTotal: number;
        overlapTotal: number;
        cannibalizedPercent: number; // This is the key number
      };
      households?: {
        existingTotal: number;
        overlapTotal: number;
        cannibalizedPercent: number;
      };
      // ... other metrics
    };
    riskLevel: 'low' | 'moderate' | 'high' | 'critical';
    // low: <10%, moderate: 10-25%, high: 25-40%, critical: >40%
  }>;
  totalNetworkImpact: {
    totalCannibalizedPopulation: number;
    avgCannibalizedPercent: number;
    mostImpactedArea: string;
  };
}
```

**Core SQL: Compute overlap demographics**

```sql
-- For a given new area and existing area, find the overlap
-- and compute weighted demographics within it
WITH overlap AS (
  SELECT
    ST_Intersection(new_area.geometry, existing_area.geometry) AS overlap_geom,
    ST_Area(ST_Intersection(new_area.geometry, existing_area.geometry)::geography) AS overlap_area_m2,
    ST_Area(existing_area.geometry::geography) AS existing_area_m2
  FROM areas new_area, areas existing_area
  WHERE new_area.id = $1 AND existing_area.id = $2
    AND ST_Intersects(new_area.geometry, existing_area.geometry)
),
overlap_tracts AS (
  SELECT
    ct.geoid,
    cd.total_population,
    cd.housing_units_total,
    cd.median_household_income,
    -- What fraction of this tract falls within the overlap zone?
    CASE
      WHEN ST_Area(ct.geometry) > 0
      THEN ST_Area(ST_Intersection(ct.geometry, o.overlap_geom)) / ST_Area(ct.geometry)
      ELSE 0
    END AS tract_overlap_fraction
  FROM census_tracts ct
  JOIN census_demographics cd ON ct.geoid = cd.geoid
  CROSS JOIN overlap o
  WHERE ST_Intersects(ct.geometry, o.overlap_geom)
),
existing_tracts AS (
  SELECT
    ct.geoid,
    cd.total_population,
    cd.housing_units_total,
    CASE
      WHEN ST_Area(ct.geometry) > 0
      THEN ST_Area(ST_Intersection(ct.geometry, existing_area.geometry)) / ST_Area(ct.geometry)
      ELSE 0
    END AS tract_overlap_fraction
  FROM census_tracts ct
  JOIN census_demographics cd ON ct.geoid = cd.geoid
  CROSS JOIN areas existing_area
  WHERE existing_area.id = $2
    AND ST_Intersects(ct.geometry, existing_area.geometry)
)
SELECT
  -- Overlap zone demographics
  (SELECT SUM(total_population * tract_overlap_fraction) FROM overlap_tracts) AS overlap_population,
  (SELECT SUM(housing_units_total * tract_overlap_fraction) FROM overlap_tracts) AS overlap_households,
  -- Existing area total demographics
  (SELECT SUM(total_population * tract_overlap_fraction) FROM existing_tracts) AS existing_total_population,
  (SELECT SUM(housing_units_total * tract_overlap_fraction) FROM existing_tracts) AS existing_total_households,
  -- Geographic overlap
  (SELECT overlap_area_m2 FROM overlap) AS overlap_area_m2,
  (SELECT existing_area_m2 FROM overlap) AS existing_area_m2;
```

**Risk level classification:**

```typescript
function classifyRisk(cannibalizedPercent: number): RiskLevel {
  if (cannibalizedPercent < 10) return 'low';
  if (cannibalizedPercent < 25) return 'moderate';
  if (cannibalizedPercent < 40) return 'high';
  return 'critical';
}
```

### Frontend Components

```
src/components/cannibalization/
├── CannibalizationPanel.tsx     — Right panel showing results for selected area
├── OverlapHeatmap.tsx           — Color-coded overlap zones on map (green→yellow→red)
├── ImpactTable.tsx              — Table: existing areas, overlap %, risk level
├── ImpactGauge.tsx              — Circular gauge showing cannibalization % per area
└── NetworkImpactSummary.tsx     — Aggregate impact across all existing areas
```

**Map visualization:**

```typescript
// Render overlap zones with risk-based colors
function renderOverlapZone(overlapPolygon: GeoJSON.Polygon, riskLevel: string) {
  const colors = {
    low: '#22c55e',      // Green
    moderate: '#eab308', // Yellow
    high: '#f97316',     // Orange
    critical: '#ef4444'  // Red
  };

  return new google.maps.Polygon({
    paths: geoJsonToGooglePaths(overlapPolygon),
    fillColor: colors[riskLevel],
    fillOpacity: 0.4,
    strokeColor: colors[riskLevel],
    strokeWeight: 2,
    strokeOpacity: 0.8,
    map: map
  });
}
```

### Tier Gating

- **Essential ($99/mo):** Visual overlap detection only (existing Smappen-level feature)
- **Pro ($199/mo):** Full quantitative cannibalization with population/household metrics
- **Advanced:** Cannibalization modeling with custom imported data, batch analysis across scenarios

### Cost

Zero additional API cost — purely PostGIS spatial operations against existing Census tract data.

---

## 3. Time-of-Day / Day-of-Week Isochrones

### The Problem

A 15-minute drive-time isochrone at 2 PM on a Tuesday looks very different from the same isochrone at 5:30 PM on a Friday. Smappen's isochrones are static — they don't account for traffic patterns. For restaurants, retail stores, and delivery businesses, the realistic reachable area during peak hours is what matters.

### The Approach

Use the **Google Routes API** with `departureTime` parameter to generate traffic-aware travel times, then feed those into isochrone generation. Since OpenRouteService (self-hosted) doesn't support real-time traffic, we use a hybrid approach:

1. **Google Routes API** to sample travel times to grid points at a specific departure time
2. **Contour interpolation** to generate the isochrone polygon from sampled points
3. **Comparison view** showing multiple time-of-day isochrones overlaid

### Backend Implementation

```typescript
// POST /api/isochrone/traffic-aware
interface TrafficAwareIsochroneRequest {
  origin: { lat: number; lng: number };
  travelTimeMinutes: number;
  travelMode: 'DRIVE' | 'BICYCLE' | 'WALK' | 'TRANSIT';
  departureTime: string; // ISO 8601, e.g. "2026-06-02T17:30:00-05:00"
  // OR use preset slots:
  departurePreset?: 'weekday_morning_rush' | 'weekday_midday'
                  | 'weekday_evening_rush' | 'weekend_midday';
}

// The algorithm:
// 1. Generate a grid of sample points radiating from origin
// 2. Query Google Routes API for travel time to each point at departure time
// 3. Interpolate to find the boundary where travel time = target
// 4. Build a polygon from the boundary points

async function generateTrafficAwareIsochrone(
  request: TrafficAwareIsochroneRequest
): Promise<GeoJSON.Polygon> {

  const { origin, travelTimeMinutes, travelMode, departureTime } = request;
  const targetSeconds = travelTimeMinutes * 60;

  // Step 1: Generate radial sample points
  // Use 36 directions (every 10°), multiple distance rings
  const bearings = Array.from({ length: 36 }, (_, i) => i * 10);
  const distances = [0.25, 0.5, 1, 2, 3, 5, 8, 12, 18, 25]; // km

  const samplePoints: SamplePoint[] = [];
  for (const bearing of bearings) {
    for (const dist of distances) {
      const point = turf.destination(
        turf.point([origin.lng, origin.lat]),
        dist,
        bearing,
        { units: 'kilometers' }
      );
      samplePoints.push({
        lat: point.geometry.coordinates[1],
        lng: point.geometry.coordinates[0],
        bearing,
        distance: dist,
        travelTime: null
      });
    }
  }

  // Step 2: Batch query Google Routes API
  // Use Routes API computeRoutes with traffic awareness
  const travelTimes = await batchRoutesQuery(
    origin,
    samplePoints,
    travelMode,
    departureTime
  );

  // Step 3: For each bearing, find the interpolated distance
  //         where travelTime crosses the target
  const boundaryPoints: [number, number][] = [];

  for (const bearing of bearings) {
    const radialSamples = travelTimes
      .filter(s => s.bearing === bearing)
      .sort((a, b) => a.distance - b.distance);

    // Find where travel time crosses the target
    let boundaryDist = 0;
    for (let i = 1; i < radialSamples.length; i++) {
      const prev = radialSamples[i - 1];
      const curr = radialSamples[i];

      if (prev.travelTime <= targetSeconds && curr.travelTime > targetSeconds) {
        // Linear interpolation
        const fraction = (targetSeconds - prev.travelTime)
                        / (curr.travelTime - prev.travelTime);
        boundaryDist = prev.distance + fraction * (curr.distance - prev.distance);
        break;
      }
    }

    if (boundaryDist > 0) {
      const pt = turf.destination(
        turf.point([origin.lng, origin.lat]),
        boundaryDist,
        bearing,
        { units: 'kilometers' }
      );
      boundaryPoints.push(pt.geometry.coordinates as [number, number]);
    }
  }

  // Step 4: Close the polygon and smooth
  boundaryPoints.push(boundaryPoints[0]); // Close ring
  const polygon = turf.polygon([boundaryPoints]);
  return turf.simplify(polygon, { tolerance: 0.001 }).geometry;
}
```

**Google Routes API batch query:**

```typescript
async function batchRoutesQuery(
  origin: LatLng,
  destinations: SamplePoint[],
  mode: string,
  departureTime: string
): Promise<SamplePoint[]> {
  // Google Routes API supports computing routes one at a time
  // Batch in groups of 10 with rate limiting
  const results: SamplePoint[] = [];
  const batchSize = 10;

  for (let i = 0; i < destinations.length; i += batchSize) {
    const batch = destinations.slice(i, i + batchSize);

    const batchResults = await Promise.all(
      batch.map(async (dest) => {
        const response = await fetch(
          'https://routes.googleapis.com/directions/v2:computeRoutes',
          {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Goog-Api-Key': process.env.GOOGLE_API_KEY,
              'X-Goog-FieldMask': 'routes.duration'
            },
            body: JSON.stringify({
              origin: {
                location: {
                  latLng: { latitude: origin.lat, longitude: origin.lng }
                }
              },
              destination: {
                location: {
                  latLng: { latitude: dest.lat, longitude: dest.lng }
                }
              },
              travelMode: mode,
              departureTime: departureTime,
              routingPreference: 'TRAFFIC_AWARE'
            })
          }
        );

        const data = await response.json();
        const durationSeconds = parseInt(
          data.routes?.[0]?.duration?.replace('s', '') || '999999'
        );

        return { ...dest, travelTime: durationSeconds };
      })
    );

    results.push(...batchResults);
    await sleep(100); // Rate limiting
  }

  return results;
}
```

### Preset Time Slots

For usability, offer preset departure times instead of requiring users to pick exact times:

```typescript
const DEPARTURE_PRESETS = {
  weekday_morning_rush: {
    label: 'Weekday Morning Rush',
    description: 'Mon–Fri, 8:00 AM',
    // Next upcoming weekday at 8 AM in user's timezone
    getDepartureTime: (tz: string) => getNextWeekday(8, 0, tz)
  },
  weekday_evening_rush: {
    label: 'Weekday Evening Rush',
    description: 'Mon–Fri, 5:30 PM',
    getDepartureTime: (tz: string) => getNextWeekday(17, 30, tz)
  },
  weekday_midday: {
    label: 'Weekday Midday',
    description: 'Mon–Fri, 12:00 PM (low traffic baseline)',
    getDepartureTime: (tz: string) => getNextWeekday(12, 0, tz)
  },
  weekend_midday: {
    label: 'Weekend Midday',
    description: 'Sat, 1:00 PM',
    getDepartureTime: (tz: string) => getNextSaturday(13, 0, tz)
  }
};
```

### Comparison View

The real power is showing multiple isochrones overlaid:

```typescript
// Show how the same 15-min drive-time area shrinks during rush hour
const comparisonLayers = [
  { preset: 'weekday_midday', color: '#22c55e', label: 'Midday (best case)' },
  { preset: 'weekday_morning_rush', color: '#f59e0b', label: 'Morning rush' },
  { preset: 'weekday_evening_rush', color: '#ef4444', label: 'Evening rush' },
];
// Render all three overlaid — the visual delta is immediately powerful
```

### Caching Strategy

Traffic patterns are cyclical. Cache results by:
- `origin_lat, origin_lng` (rounded to 3 decimal places)
- `travel_time_minutes`
- `travel_mode`
- `departure_preset` (not exact time)
- Cache TTL: 7 days (traffic patterns don't change drastically week to week)

### Cost

This is the most expensive feature in API terms. Each isochrone requires ~360 Google Routes API calls (36 bearings × 10 distance rings). At $5/1,000 routes (basic) or $10/1,000 (traffic-aware):

| Scenario | Routes calls | Cost per isochrone |
|----------|-------------|-------------------|
| Basic (no traffic) | 360 | $1.80 |
| Traffic-aware | 360 | $3.60 |
| Comparison (3 time slots) | 1,080 | $10.80 |

**Optimization:** Reduce to 24 bearings and 7 distance rings = 168 calls per isochrone ($1.68 traffic-aware). Use adaptive sampling — start coarse, then add more sample points near the boundary.

**Aggressive caching** is essential. A given origin + time preset combination should only be computed once per week.

### Tier Gating

- **Free/Essential:** Static isochrones only (OpenRouteService, no traffic)
- **Pro ($199/mo):** Traffic-aware isochrones, 10 per day
- **Advanced:** Unlimited traffic-aware isochrones, comparison view, custom departure times

---

## 4. Multi-Location Optimization

### The Problem

"I have 12 existing franchise locations and budget for 3 more. Where should I put them to maximize population coverage with minimal overlap with my existing network?"

This is a classic **facility location problem** — specifically, the **maximal covering location problem (MCLP)**.

### The Algorithm

```
Input:
  - existing_locations: Array of { lat, lng } for current locations
  - existing_areas: Array of GeoJSON polygons (current trade areas)
  - n_new_locations: integer (how many to add)
  - candidate_points: Array of potential locations (either user-provided
    or auto-generated from a grid)
  - objective: 'max_coverage' | 'min_overlap' | 'balanced'
  - coverage_radius_minutes: integer (trade area drive time)

Algorithm (Greedy with Local Search):
  1. Compute the "uncovered" area:
     market_polygon MINUS union(existing_areas)
  2. Generate candidate grid points within uncovered area
     (or use centroids of uncovered Census tracts)
  3. For each candidate point, compute potential isochrone
  4. Score each candidate:
     - max_coverage: population in isochrone NOT already covered
     - min_overlap: population in isochrone NOT in any existing area
     - balanced: weighted combination
  5. Greedy selection:
     a. Pick the highest-scoring candidate → add to selected set
     b. Update "covered" area to include new selection
     c. Re-score remaining candidates (coverage of a candidate changes
        when another candidate nearby is selected)
     d. Repeat until n_new_locations are selected
  6. Local search refinement:
     a. For each selected location, try shifting it to nearby
        candidate points
     b. Accept if total objective improves
     c. Repeat until no improvement found
```

### Backend Implementation

```typescript
// POST /api/optimize/locations
interface OptimizationRequest {
  projectId: string;
  existingAreaIds: string[];
  numNewLocations: number;
  marketPolygon: GeoJSON.Polygon;
  coverageTimeMinutes: number;
  travelMode: string;
  objective: 'max_coverage' | 'min_overlap' | 'balanced';
  candidatePoints?: LatLng[]; // Optional: user-suggested locations
  gridResolutionKm?: number;  // Default 2km spacing
}

interface OptimizationResult {
  recommendedLocations: Array<{
    rank: number;
    location: LatLng;
    nearestAddress: string; // Reverse geocoded
    isochrone: GeoJSON.Polygon;
    incrementalPopulation: number; // New pop. covered by this location
    overlapWithExisting: number;   // % overlap with current network
    score: number;
  }>;
  networkMetrics: {
    currentCoveredPopulation: number;
    projectedCoveredPopulation: number;
    coverageGainPercent: number;
    remainingWhitespace: GeoJSON.Polygon;
  };
}
```

**Candidate point generation:**

```typescript
function generateCandidateGrid(
  uncoveredArea: GeoJSON.Polygon,
  resolutionKm: number = 2
): LatLng[] {
  const bbox = turf.bbox(uncoveredArea);
  const candidates: LatLng[] = [];

  // Generate grid within bounding box
  const grid = turf.pointGrid(bbox, resolutionKm, {
    units: 'kilometers',
    mask: uncoveredArea // Only points inside the uncovered area
  });

  for (const feature of grid.features) {
    candidates.push({
      lat: feature.geometry.coordinates[1],
      lng: feature.geometry.coordinates[0]
    });
  }

  return candidates;
}
```

**Smart candidate selection (use Census tract centroids instead of blind grid):**

```sql
-- Find Census tract centroids in uncovered whitespace,
-- ranked by population (prioritize high-pop uncovered tracts)
SELECT
  ct.geoid,
  ST_Y(ST_Centroid(ct.geometry)) AS lat,
  ST_X(ST_Centroid(ct.geometry)) AS lng,
  cd.total_population
FROM census_tracts ct
JOIN census_demographics cd ON ct.geoid = cd.geoid
WHERE ST_Intersects(ct.geometry, ST_GeomFromGeoJSON($1))  -- market polygon
  AND NOT ST_Intersects(
    ST_Centroid(ct.geometry),
    ST_Union(ARRAY(
      SELECT geometry FROM areas WHERE id = ANY($2)  -- existing area IDs
    ))
  )
  AND cd.total_population > 500  -- Filter low-pop tracts
ORDER BY cd.total_population DESC
LIMIT 200;
```

### Precomputation for Speed

Computing full isochrones for 200 candidate points is slow. Optimization:

1. **First pass:** Use straight-line distance as a fast proxy to narrow candidates to top 30
2. **Second pass:** Compute actual isochrones only for the top 30 candidates
3. **Cache:** Store isochrone results keyed by `[lat, lng, time, mode]`

### Frontend Components

```
src/components/optimization/
├── OptimizationWizard.tsx       — Configure and run optimization
├── CandidateMap.tsx             — Show candidate locations with score heatmap
├── RankedResultsList.tsx        — Ranked list of recommended locations
├── CoverageComparison.tsx       — Before/after coverage visualization
├── WhitespaceOverlay.tsx        — Highlight remaining uncovered areas
└── OptimizationReport.tsx       — Exportable summary of recommendations
```

### Tier Gating

- **Pro:** Single-location recommendations ("where should I open next?")
- **Advanced:** Multi-location optimization (up to 10 new locations), custom objectives

### Cost

Primary cost is isochrone computation for candidate points. Using OpenRouteService (self-hosted), this is free but compute-intensive. Budget ~30 seconds for a 3-location optimization with 50 candidates.

---

## 5. Customer Segmentation & Psychographic Data

### The Problem

Census data tells you demographics — age, income, household size. It doesn't tell you lifestyle, spending habits, or consumer behavior. Knowing that a trade area has a median income of $72K is far less useful than knowing it's 45% "Young Urban Professionals" who spend heavily on fitness and dining out.

### Data Sources

**Option A: Esri Tapestry Segmentation (commercial, recommended)**

Esri's Tapestry classifies every US neighborhood into 67 segments grouped into 14 LifeMode categories. Available via the ArcGIS GeoEnrichment API.

```
Pricing: ~$100/month for ArcGIS Developer subscription
         $0.01 per enrichment request (per geographic area)
```

**Option B: Free approximation using Census + derived clusters**

Build your own simplified segmentation using Census variables. You won't match Esri's quality, but you can create 8–12 meaningful segments from publicly available data.

### Option B Implementation: DIY Segmentation

**Step 1: Define segment profiles from Census variables**

```typescript
// Each segment is defined by Census variable thresholds
const SEGMENTS = [
  {
    id: 'young_urban_professional',
    label: 'Young Urban Professionals',
    description: 'High-income 25–34 year olds in urban areas',
    icon: '🏙️',
    criteria: {
      age_25_to_34_pct: { min: 0.20 },     // >20% of population
      median_income: { min: 75000 },
      housing_renters_pct: { min: 0.50 },   // Majority renters
      bachelors_degree_pct: { min: 0.40 },
    },
    spending_profile: {
      dining_out: 'very_high',
      fitness: 'high',
      luxury_retail: 'high',
      home_improvement: 'low',
      family_entertainment: 'low'
    }
  },
  {
    id: 'suburban_families',
    label: 'Suburban Families',
    description: 'Middle-income families with children in suburban areas',
    criteria: {
      age_35_to_54_pct: { min: 0.25 },
      median_income: { min: 50000, max: 120000 },
      housing_owner_pct: { min: 0.60 },
      avg_household_size: { min: 2.8 },
    },
    spending_profile: {
      dining_out: 'moderate',
      fitness: 'moderate',
      family_entertainment: 'very_high',
      home_improvement: 'high',
      grocery: 'high'
    }
  },
  {
    id: 'affluent_retirees',
    label: 'Affluent Retirees',
    description: 'High-income 65+ homeowners',
    criteria: {
      age_65_plus_pct: { min: 0.25 },
      median_income: { min: 60000 },
      housing_owner_pct: { min: 0.75 },
      median_home_value: { min: 300000 },
    },
    spending_profile: {
      healthcare: 'very_high',
      dining_out: 'high',
      travel: 'high',
      home_improvement: 'moderate',
      family_entertainment: 'low'
    }
  },
  // ... define 8-12 total segments
];
```

**Step 2: Score each Census tract against segments**

```typescript
function classifyTract(tractDemographics: TractDemographics): SegmentScores {
  const scores: SegmentScores = {};

  for (const segment of SEGMENTS) {
    let matchScore = 0;
    let criteriaCount = 0;

    for (const [variable, threshold] of Object.entries(segment.criteria)) {
      criteriaCount++;
      const value = tractDemographics[variable];

      if (threshold.min !== undefined && value >= threshold.min) matchScore++;
      if (threshold.max !== undefined && value <= threshold.max) matchScore++;
      if (threshold.min !== undefined && threshold.max !== undefined) criteriaCount++;
    }

    scores[segment.id] = matchScore / criteriaCount;
  }

  return scores;
}

// Assign each tract its dominant segment (highest score)
// Also store the score distribution for showing composition
```

**Step 3: Aggregate segments within a territory**

```sql
-- Pre-compute segment assignments per tract
CREATE TABLE tract_segments (
  geoid VARCHAR(11) PRIMARY KEY REFERENCES census_tracts(geoid),
  primary_segment VARCHAR(50) NOT NULL,
  segment_scores JSONB NOT NULL, -- All segment scores
  classified_at TIMESTAMPTZ DEFAULT NOW()
);

-- Query: Get segment composition within an isochrone area
SELECT
  ts.primary_segment,
  seg_def.label AS segment_label,
  COUNT(*) AS tract_count,
  SUM(cd.total_population * overlap_fraction) AS segment_population,
  ROUND(
    SUM(cd.total_population * overlap_fraction) * 100.0 /
    NULLIF(SUM(SUM(cd.total_population * overlap_fraction)) OVER (), 0)
  , 1) AS segment_pct
FROM census_tracts ct
JOIN tract_segments ts ON ct.geoid = ts.geoid
JOIN census_demographics cd ON ct.geoid = cd.geoid
CROSS JOIN LATERAL (
  SELECT
    CASE
      WHEN ST_Area(ct.geometry) > 0
      THEN ST_Area(ST_Intersection(ct.geometry, ST_GeomFromGeoJSON($1)))
           / ST_Area(ct.geometry)
      ELSE 0
    END AS overlap_fraction
) fractions
WHERE ST_Intersects(ct.geometry, ST_GeomFromGeoJSON($1))
GROUP BY ts.primary_segment, seg_def.label
ORDER BY segment_population DESC;
```

### Additional Census Variables Needed

To power segmentation, pull these additional ACS variables beyond the base blueprint:

```typescript
const ADDITIONAL_CENSUS_VARS = [
  'B15003_022E', // Bachelor's degree
  'B15003_023E', // Master's degree
  'B15003_025E', // Doctorate
  'B11001_001E', // Total households
  'B11003_003E', // Married couple families with children
  'B25003_002E', // Owner-occupied housing units
  'B25003_003E', // Renter-occupied housing units
  'B25010_001E', // Average household size
  'B08301_010E', // Public transit commuters
  'B08301_019E', // Work from home
  'B07001_017E', // Moved from different state (mobility)
];
```

### Frontend Components

```
src/components/segmentation/
├── SegmentDonutChart.tsx        — Donut chart showing segment composition
├── SegmentBreakdownPanel.tsx    — Detailed view per segment with spending profile
├── SegmentComparisonView.tsx    — Compare two territories' segment mixes
├── SegmentHeatmap.tsx           — Map layer colored by dominant segment per tract
└── SpendingProfileCards.tsx     — Cards showing spending tendencies per segment
```

### Tier Gating

- **Essential:** No segmentation (demographics only)
- **Pro:** DIY segmentation (8–12 segments from Census data)
- **Advanced:** Esri Tapestry integration (67 segments) — requires the user's own Esri license, or bundle it

### Cost

- DIY approach: Zero additional cost (all Census data)
- Esri Tapestry: ~$100/month + $0.01/enrichment call

---

## 6. Collaboration & Version History

### The Problem

Territory planning is a team activity. The real estate director proposes territories, the VP of franchise development reviews and adjusts, legal checks for FDD compliance, and the CEO signs off. Today this happens via screenshots pasted into emails. Smappen has no commenting, no version history, no approval workflow.

### Database Schema

```sql
-- Project-level collaboration
CREATE TABLE project_collaborators (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  user_id UUID REFERENCES users(id),
  role VARCHAR(20) NOT NULL, -- 'owner', 'editor', 'commenter', 'viewer'
  invited_by UUID REFERENCES users(id),
  invited_at TIMESTAMPTZ DEFAULT NOW(),
  accepted_at TIMESTAMPTZ,
  UNIQUE(project_id, user_id)
);

-- Version history (snapshots)
CREATE TABLE project_versions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  version_number INT NOT NULL,
  title VARCHAR(255), -- e.g., "Q3 Territory Proposal v2"
  description TEXT,
  snapshot JSONB NOT NULL, -- Full serialized state of all areas/folders
  -- Snapshot includes: areas[], folders[], settings, viewport
  created_by UUID REFERENCES users(id),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  is_auto_save BOOLEAN DEFAULT FALSE, -- vs. manual "save version"
  UNIQUE(project_id, version_number)
);

-- Comments on specific areas or the project
CREATE TABLE comments (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  area_id UUID REFERENCES areas(id) ON DELETE CASCADE, -- NULL = project-level
  parent_comment_id UUID REFERENCES comments(id), -- Thread replies
  user_id UUID REFERENCES users(id),
  body TEXT NOT NULL,
  -- Pin comment to a map location (optional)
  pin_lat DOUBLE PRECISION,
  pin_lng DOUBLE PRECISION,
  -- Status
  resolved BOOLEAN DEFAULT FALSE,
  resolved_by UUID REFERENCES users(id),
  resolved_at TIMESTAMPTZ,
  -- Metadata
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Change log (granular, auto-captured)
CREATE TABLE change_log (
  id BIGSERIAL PRIMARY KEY,
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  user_id UUID REFERENCES users(id),
  action VARCHAR(50) NOT NULL,
  -- 'area_created', 'area_modified', 'area_deleted',
  -- 'area_moved_folder', 'demographics_refreshed',
  -- 'area_color_changed', 'area_renamed', etc.
  entity_type VARCHAR(30), -- 'area', 'folder', 'project'
  entity_id UUID,
  entity_name VARCHAR(255),
  previous_value JSONB, -- For undo support
  new_value JSONB,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_change_log_project ON change_log (project_id, created_at DESC);

-- Approval workflow (optional, for Advanced tier)
CREATE TABLE approval_requests (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  version_id UUID REFERENCES project_versions(id),
  requested_by UUID REFERENCES users(id),
  status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected, changes_requested
  reviewers JSONB, -- Array of { userId, status, note, respondedAt }
  created_at TIMESTAMPTZ DEFAULT NOW(),
  resolved_at TIMESTAMPTZ
);
```

### Version Snapshot Structure

```typescript
interface ProjectSnapshot {
  version: number;
  capturedAt: string;
  viewport: {
    center: LatLng;
    zoom: number;
  };
  areas: Array<{
    id: string;
    name: string;
    areaType: string;
    geometry: GeoJSON.Polygon;
    center: LatLng;
    travelMode?: string;
    travelTimeMinutes?: number;
    folderId?: string;
    fillColor: string;
    fillOpacity: number;
    strokeColor: string;
    demographicsCache?: object;
    notes?: string;
  }>;
  folders: Array<{
    id: string;
    name: string;
    color: string;
    parentFolderId?: string;
    sortOrder: number;
  }>;
}
```

### API Endpoints

```typescript
// Version management
POST   /api/projects/:id/versions          // Save a named version
GET    /api/projects/:id/versions          // List all versions
GET    /api/projects/:id/versions/:vid     // Get specific version snapshot
POST   /api/projects/:id/versions/:vid/restore  // Restore project to version

// Comments
POST   /api/projects/:id/comments          // Add comment
GET    /api/projects/:id/comments          // List comments (filterable by area)
PATCH  /api/comments/:id                   // Edit comment
PATCH  /api/comments/:id/resolve           // Resolve/unresolve
DELETE /api/comments/:id                   // Delete comment

// Collaboration
POST   /api/projects/:id/collaborators     // Invite user
PATCH  /api/projects/:id/collaborators/:uid // Change role
DELETE /api/projects/:id/collaborators/:uid // Remove collaborator

// Change log
GET    /api/projects/:id/changelog         // Paginated change history
GET    /api/projects/:id/changelog/diff/:v1/:v2  // Diff two versions

// Approvals (Advanced tier)
POST   /api/projects/:id/approval-requests // Request approval
PATCH  /api/approval-requests/:id/review   // Submit review decision
```

### Auto-Save Logic

```typescript
// Auto-save a version every time a significant change is made
// Debounce to avoid saving on every polygon vertex drag
const AUTO_SAVE_DEBOUNCE_MS = 30_000; // 30 seconds after last change

function useAutoSave(projectId: string) {
  const debouncedSave = useDebouncedCallback(
    async () => {
      const snapshot = serializeProjectState();
      await api.post(`/projects/${projectId}/versions`, {
        snapshot,
        isAutoSave: true,
        title: `Auto-save ${new Date().toLocaleString()}`
      });
    },
    AUTO_SAVE_DEBOUNCE_MS
  );

  // Trigger on any area/folder mutation
  useEffect(() => {
    const unsubscribe = projectStore.subscribe(
      (state) => [state.areas, state.folders],
      () => debouncedSave()
    );
    return unsubscribe;
  }, [projectId]);
}

// Retention policy: keep last 50 auto-saves, all manual saves
```

### Version Diff View

```typescript
// Compare two snapshots and highlight changes
function diffVersions(
  v1: ProjectSnapshot,
  v2: ProjectSnapshot
): VersionDiff {
  return {
    areasAdded: v2.areas.filter(a => !v1.areas.find(x => x.id === a.id)),
    areasRemoved: v1.areas.filter(a => !v2.areas.find(x => x.id === a.id)),
    areasModified: v2.areas.filter(a => {
      const prev = v1.areas.find(x => x.id === a.id);
      return prev && JSON.stringify(prev.geometry) !== JSON.stringify(a.geometry);
    }),
    // On the map: green outlines = added, red outlines = removed,
    //             yellow outlines = modified (show old boundary as dashed)
  };
}
```

### Frontend Components

```
src/components/collaboration/
├── CollaboratorsList.tsx        — Show/manage project collaborators with roles
├── InviteModal.tsx              — Invite by email, set role
├── VersionTimeline.tsx          — Vertical timeline of versions with diff links
├── VersionDiffView.tsx          — Side-by-side or overlay showing changes
├── CommentsPanel.tsx            — Thread-style comments, filterable by area
├── CommentPin.tsx               — Map marker for pinned comments
├── ChangeLogFeed.tsx            — Activity feed: "Sarah added Territory North"
├── ApprovalBanner.tsx           — "This version is pending approval from 2 reviewers"
└── RestoreVersionDialog.tsx     — Confirm restore with preview
```

### Real-time Sync (Optional, Advanced)

For simultaneous editing, add WebSocket layer:

```typescript
// Server: Socket.io room per project
io.on('connection', (socket) => {
  socket.on('join-project', (projectId) => {
    socket.join(`project:${projectId}`);
    // Broadcast current editors
    io.to(`project:${projectId}`).emit('editors-updated', getActiveEditors(projectId));
  });

  socket.on('area-updated', (data) => {
    // Broadcast to all other editors
    socket.to(`project:${data.projectId}`).emit('area-updated', data);
    // Log to change_log
    logChange(data);
  });
});
```

### Tier Gating

- **Free/Essential:** Single user only
- **Pro:** Up to 5 collaborators, comments, manual version saves (up to 20), changelog
- **Advanced:** Unlimited collaborators, approval workflows, real-time co-editing, unlimited version history

### Cost

Zero external API cost. Infrastructure cost: WebSocket server adds ~$20–50/month for a dedicated process. Storage for version snapshots is minimal (each snapshot is ~50–200KB of JSON).

---

## 7. Mobile Field App

### The Problem

The people evaluating franchise sites are often physically standing at a potential location. They're on a street corner, looking around, trying to assess the trade area. Right now they can't pull up territory data on their phone, compare the location to demographics, or capture field notes that tie back to their planning project.

### Architecture

Build a lightweight mobile companion (not a full replica of the desktop app) using React Native or a Progressive Web App (PWA). The PWA route is recommended — zero app store friction, shares codebase with the main React app.

### PWA Setup

```typescript
// In the main React app, add PWA support

// vite.config.ts (if using Vite)
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'TerritoryMap Field',
        short_name: 'FieldMap',
        description: 'Territory intelligence in the field',
        theme_color: '#6B4EFF',
        background_color: '#0e0e14',
        display: 'standalone',
        icons: [
          { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icon-512.png', sizes: '512x512', type: 'image/png' }
        ]
      },
      workbox: {
        runtimeCaching: [
          {
            // Cache Google Maps tiles for offline-ish usage
            urlPattern: /^https:\/\/maps\.googleapis\.com/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'google-maps-cache',
              expiration: { maxEntries: 500, maxAgeSeconds: 7 * 24 * 60 * 60 }
            }
          }
        ]
      }
    })
  ]
});
```

### Mobile-Specific Features

**1. "Where Am I?" Territory Lookup**

```typescript
// Use browser Geolocation API
function useCurrentLocation() {
  const [location, setLocation] = useState<LatLng | null>(null);

  useEffect(() => {
    navigator.geolocation.watchPosition(
      (pos) => setLocation({
        lat: pos.coords.latitude,
        lng: pos.coords.longitude
      }),
      (err) => console.error('Geolocation error:', err),
      { enableHighAccuracy: true, maximumAge: 30000 }
    );
  }, []);

  return location;
}

// Check which territories contain the user's current location
async function findContainingTerritories(lat: number, lng: number): Promise<Area[]> {
  // PostGIS query
  const result = await db.query(`
    SELECT a.*, p.name AS project_name
    FROM areas a
    JOIN projects p ON a.project_id = p.id
    WHERE ST_Contains(a.geometry, ST_SetSRID(ST_MakePoint($1, $2), 4326))
      AND p.organization_id = $3
  `, [lng, lat, orgId]);

  return result.rows;
}
```

**2. Quick Demographics at Current Location**

```typescript
// GET /api/mobile/demographics-here?lat=X&lng=Y
// Returns demographics for the Census tract the user is standing in
async function getDemographicsHere(lat: number, lng: number) {
  const result = await db.query(`
    SELECT
      cd.total_population,
      cd.median_household_income,
      cd.median_home_value,
      cd.housing_units_total,
      cd.age_18_to_34,
      cd.age_35_to_54,
      cd.age_55_to_64,
      cd.age_65_plus,
      ct.name AS tract_name
    FROM census_tracts ct
    JOIN census_demographics cd ON ct.geoid = cd.geoid
    WHERE ST_Contains(ct.geometry, ST_SetSRID(ST_MakePoint($1, $2), 4326))
    LIMIT 1
  `, [lng, lat]);

  return result.rows[0];
}
```

**3. Field Notes with Geotagged Photos**

```typescript
// Database schema
CREATE TABLE field_notes (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  area_id UUID REFERENCES areas(id),          -- Optional: link to territory
  user_id UUID REFERENCES users(id),
  lat DOUBLE PRECISION NOT NULL,
  lng DOUBLE PRECISION NOT NULL,
  point GEOMETRY(Point, 4326) NOT NULL,
  note_text TEXT,
  note_type VARCHAR(30) DEFAULT 'observation',
  -- 'observation', 'competitor_sighting', 'site_evaluation',
  -- 'traffic_count', 'photo_note'
  photos JSONB, -- Array of S3/GCS URLs
  rating INT, -- 1-5 field assessment score
  tags TEXT[], -- e.g., ['high-traffic', 'parking-available', 'corner-lot']
  weather_conditions VARCHAR(50), -- Captured from weather API at note time
  created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_field_notes_geom ON field_notes USING GIST (point);
```

```typescript
// Mobile: capture photo and upload
async function captureFieldNote(
  projectId: string,
  location: LatLng,
  noteText: string,
  photos: File[]
) {
  const photoUrls = await Promise.all(
    photos.map(photo => uploadToS3(photo, `field-notes/${projectId}/`))
  );

  return api.post('/api/field-notes', {
    projectId,
    lat: location.lat,
    lng: location.lng,
    noteText,
    photos: photoUrls,
    noteType: 'site_evaluation',
    capturedAt: new Date().toISOString()
  });
}
```

**4. Nearby POI Quick-Check**

```typescript
// Show POIs within 500m of current location
// Reuses existing Google Places integration
async function nearbyPOICheck(lat: number, lng: number) {
  return api.get('/api/places/nearby', {
    params: {
      lat, lng,
      radius: 500,
      types: ['restaurant', 'gym', 'school', 'grocery_or_supermarket']
    }
  });
}
```

### Mobile UI Layout

```
┌──────────────────────────────┐
│  ≡  TerritoryMap Field  📍  │
├──────────────────────────────┤
│                              │
│         MAP (full screen)    │
│    [Your location marker]    │
│    [Territory boundaries]    │
│    [Field note pins]         │
│                              │
├──────────────────────────────┤
│  ┌──────────────────────┐    │
│  │ You are in:          │    │
│  │ Territory: NW Austin │    │
│  │ Pop: 45,200          │    │
│  │ Med Income: $78K     │    │
│  └──────────────────────┘    │
├──────────────────────────────┤
│  [📝 Add Note] [📷 Photo]   │
│  [🏢 Nearby POI] [📊 Data]  │
└──────────────────────────────┘
```

### Offline Support

```typescript
// Pre-cache territory polygons and demographics for offline use
async function downloadProjectForOffline(projectId: string) {
  const project = await api.get(`/api/projects/${projectId}/offline-bundle`);

  // Store in IndexedDB
  const db = await openDB('field-app', 1, {
    upgrade(db) {
      db.createObjectStore('projects');
      db.createObjectStore('areas');
      db.createObjectStore('demographics');
    }
  });

  await db.put('projects', project.data, projectId);
  for (const area of project.data.areas) {
    await db.put('areas', area, area.id);
  }
  for (const demo of project.data.demographics) {
    await db.put('demographics', demo, demo.geoid);
  }
}
```

### Tier Gating

- **Essential:** Mobile map view only (read-only territories)
- **Pro:** Full mobile app with field notes, photos, "where am I" lookup
- **Advanced:** Offline mode, team field note feed, auto-sync with desktop

### Cost

- PWA: Zero additional infrastructure cost (served from same frontend)
- Photo storage: S3 costs (~$0.023/GB/month), negligible
- Geolocation: Browser API, free

---

## 8. Competitor Monitoring & Alerts

### The Problem

Smappen lets you search for competitors at a point in time. But markets change — competitors open new locations, existing ones close, new businesses enter your category. Franchisors need ongoing intelligence, not one-time snapshots.

### Architecture

A background job system that periodically re-scans monitored territories for changes in the competitive landscape and sends alerts when significant changes are detected.

### Database Schema

```sql
-- Monitor configuration
CREATE TABLE competitor_monitors (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
  area_id UUID REFERENCES areas(id) ON DELETE CASCADE,
  organization_id UUID REFERENCES organizations(id),

  -- What to monitor
  search_query TEXT NOT NULL,          -- e.g., "pizza restaurant"
  place_types TEXT[],                  -- e.g., ['restaurant', 'meal_takeaway']
  keywords TEXT[],                     -- e.g., ['dominos', 'pizza hut']

  -- How often
  scan_frequency VARCHAR(20) DEFAULT 'weekly',
  -- 'daily', 'weekly', 'biweekly', 'monthly'
  last_scanned_at TIMESTAMPTZ,
  next_scan_at TIMESTAMPTZ,

  -- Alert settings
  alert_on_new_competitor BOOLEAN DEFAULT TRUE,
  alert_on_closed_competitor BOOLEAN DEFAULT TRUE,
  alert_on_rating_change BOOLEAN DEFAULT FALSE,
  alert_threshold_new INT DEFAULT 1,   -- Alert if >= N new competitors found
  notify_emails TEXT[],                -- Additional emails beyond account owner
  notify_slack_webhook TEXT,           -- Optional Slack integration

  -- Status
  is_active BOOLEAN DEFAULT TRUE,
  created_by UUID REFERENCES users(id),
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Historical scan results
CREATE TABLE competitor_scans (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  monitor_id UUID REFERENCES competitor_monitors(id) ON DELETE CASCADE,
  scanned_at TIMESTAMPTZ DEFAULT NOW(),
  total_results INT,
  new_places INT DEFAULT 0,
  closed_places INT DEFAULT 0,
  changed_places INT DEFAULT 0,
  scan_results JSONB, -- Full results for this scan
  status VARCHAR(20) DEFAULT 'completed'
);

-- Individual tracked places (persisted across scans)
CREATE TABLE tracked_places (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  monitor_id UUID REFERENCES competitor_monitors(id) ON DELETE CASCADE,
  google_place_id VARCHAR(255) NOT NULL,
  name VARCHAR(255),
  address TEXT,
  lat DOUBLE PRECISION,
  lng DOUBLE PRECISION,
  point GEOMETRY(Point, 4326),
  place_types TEXT[],
  rating FLOAT,
  user_rating_count INT,
  business_status VARCHAR(50), -- OPERATIONAL, CLOSED_TEMPORARILY, CLOSED_PERMANENTLY
  phone VARCHAR(50),
  website TEXT,

  -- Tracking
  first_seen_at TIMESTAMPTZ DEFAULT NOW(),
  last_seen_at TIMESTAMPTZ DEFAULT NOW(),
  status VARCHAR(20) DEFAULT 'active', -- active, closed, new
  UNIQUE(monitor_id, google_place_id)
);

CREATE INDEX idx_tracked_places_geom ON tracked_places USING GIST (point);

-- Alerts generated by scans
CREATE TABLE competitor_alerts (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  monitor_id UUID REFERENCES competitor_monitors(id) ON DELETE CASCADE,
  scan_id UUID REFERENCES competitor_scans(id),
  alert_type VARCHAR(30) NOT NULL,
  -- 'new_competitor', 'competitor_closed', 'rating_changed',
  -- 'new_cluster' (multiple new competitors nearby)
  place_id UUID REFERENCES tracked_places(id),
  title VARCHAR(255),
  description TEXT,
  severity VARCHAR(10) DEFAULT 'info', -- info, warning, critical
  is_read BOOLEAN DEFAULT FALSE,
  read_by UUID REFERENCES users(id),
  read_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ DEFAULT NOW()
);
```

### Background Scanner Job

```typescript
// Run via cron job or task queue (Bull, Agenda, etc.)
// Recommended: Bull + Redis for job scheduling

import Queue from 'bull';

const scanQueue = new Queue('competitor-scans', {
  redis: process.env.REDIS_URL
});

// Schedule scans based on frequency
async function scheduleScans() {
  const monitors = await db.query(`
    SELECT * FROM competitor_monitors
    WHERE is_active = TRUE
      AND (next_scan_at IS NULL OR next_scan_at <= NOW())
  `);

  for (const monitor of monitors.rows) {
    await scanQueue.add('scan', { monitorId: monitor.id }, {
      attempts: 3,
      backoff: { type: 'exponential', delay: 60000 }
    });
  }
}

// Run scheduler every hour
cron.schedule('0 * * * *', scheduleScans);

// Process scans
scanQueue.process('scan', async (job) => {
  const { monitorId } = job.data;
  const monitor = await getMonitor(monitorId);
  const area = await getArea(monitor.area_id);

  // Step 1: Search Google Places within the territory
  const currentResults = await searchPlacesInPolygon(
    monitor.search_query,
    area.geometry,
    monitor.place_types
  );

  // Step 2: Compare with previously tracked places
  const previousPlaces = await db.query(
    'SELECT * FROM tracked_places WHERE monitor_id = $1 AND status = $2',
    [monitorId, 'active']
  );

  const previousIds = new Set(previousPlaces.rows.map(p => p.google_place_id));
  const currentIds = new Set(currentResults.map(p => p.placeId));

  // Step 3: Identify changes
  const newPlaces = currentResults.filter(p => !previousIds.has(p.placeId));
  const closedPlaces = previousPlaces.rows.filter(p => !currentIds.has(p.google_place_id));
  const existingPlaces = currentResults.filter(p => previousIds.has(p.placeId));

  // Step 4: Check for rating changes on existing places
  const ratingChanges = [];
  for (const current of existingPlaces) {
    const previous = previousPlaces.rows.find(
      p => p.google_place_id === current.placeId
    );
    if (previous && Math.abs((current.rating || 0) - (previous.rating || 0)) >= 0.3) {
      ratingChanges.push({
        place: current,
        previousRating: previous.rating,
        newRating: current.rating
      });
    }
  }

  // Step 5: Update tracked_places table
  for (const place of newPlaces) {
    await upsertTrackedPlace(monitorId, place, 'new');
  }
  for (const place of closedPlaces) {
    await updateTrackedPlaceStatus(place.id, 'closed');
  }
  for (const place of existingPlaces) {
    await updateTrackedPlaceLastSeen(monitorId, place);
  }

  // Step 6: Create scan record
  const scan = await createScanRecord(monitorId, {
    totalResults: currentResults.length,
    newPlaces: newPlaces.length,
    closedPlaces: closedPlaces.length,
    changedPlaces: ratingChanges.length
  });

  // Step 7: Generate alerts
  if (newPlaces.length >= monitor.alert_threshold_new && monitor.alert_on_new_competitor) {
    await createAlert(monitorId, scan.id, {
      type: 'new_competitor',
      title: `${newPlaces.length} new ${monitor.search_query} found in ${area.name}`,
      description: newPlaces.map(p => `• ${p.name} — ${p.address}`).join('\n'),
      severity: newPlaces.length >= 3 ? 'warning' : 'info'
    });
  }

  if (closedPlaces.length > 0 && monitor.alert_on_closed_competitor) {
    await createAlert(monitorId, scan.id, {
      type: 'competitor_closed',
      title: `${closedPlaces.length} ${monitor.search_query} closed in ${area.name}`,
      description: closedPlaces.map(p => `• ${p.name} — ${p.address}`).join('\n'),
      severity: 'info'
    });
  }

  // Step 8: Send notifications
  await sendAlertNotifications(monitorId, scan.id);

  // Step 9: Schedule next scan
  await updateNextScanTime(monitorId, monitor.scan_frequency);
});
```

### Notification System

```typescript
// Email notification
async function sendAlertEmail(monitor: Monitor, alerts: Alert[]) {
  const emailBody = renderAlertEmail({
    areaName: monitor.areaName,
    scanDate: new Date(),
    alerts,
    mapSnapshotUrl: await generateMapSnapshot(monitor.area_id, alerts),
    dashboardUrl: `${APP_URL}/projects/${monitor.project_id}/monitors/${monitor.id}`
  });

  await sendgrid.send({
    to: monitor.notify_emails,
    from: 'alerts@yourapp.com',
    subject: `[Territory Alert] ${alerts.length} changes in ${monitor.areaName}`,
    html: emailBody
  });
}

// Slack notification (optional)
async function sendSlackAlert(webhookUrl: string, alerts: Alert[]) {
  await fetch(webhookUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      text: `🚨 Competitor Alert: ${alerts[0].title}`,
      blocks: alerts.map(alert => ({
        type: 'section',
        text: {
          type: 'mrkdwn',
          text: `*${alert.title}*\n${alert.description}`
        }
      }))
    })
  });
}

// In-app notification (always)
async function createInAppNotification(userId: string, alert: Alert) {
  await db.query(`
    INSERT INTO notifications (user_id, type, title, body, link, created_at)
    VALUES ($1, 'competitor_alert', $2, $3, $4, NOW())
  `, [userId, alert.title, alert.description,
      `/projects/${alert.projectId}/monitors/${alert.monitorId}`]);

  // Push via WebSocket if user is online
  io.to(`user:${userId}`).emit('notification', alert);
}
```

### API Endpoints

```typescript
// Monitor CRUD
POST   /api/monitors                    // Create monitor
GET    /api/projects/:id/monitors       // List monitors for project
PATCH  /api/monitors/:id                // Update settings
DELETE /api/monitors/:id                // Delete monitor
POST   /api/monitors/:id/scan-now       // Trigger immediate scan

// Scan history
GET    /api/monitors/:id/scans          // List past scans
GET    /api/monitors/:id/scans/:sid     // Scan details with diff

// Alerts
GET    /api/alerts                      // All alerts (filterable)
PATCH  /api/alerts/:id/read             // Mark as read
GET    /api/alerts/unread-count         // Badge count for nav bar

// Tracked places timeline
GET    /api/monitors/:id/places         // All tracked places with history
GET    /api/monitors/:id/timeline       // Timeline of changes
```

### Frontend Components

```
src/components/monitoring/
├── MonitorSetupWizard.tsx       — Create a new competitor monitor
│   ├── Step 1: Select territory/area to monitor
│   ├── Step 2: Define search (query, types, keywords)
│   ├── Step 3: Set frequency and alert preferences
│   └── Step 4: Run initial scan and preview results
├── MonitorDashboard.tsx         — Overview of all active monitors
├── ScanResultsView.tsx          — Map + list showing new/closed/changed places
├── CompetitorTimeline.tsx       — Chronological feed of changes
├── AlertBell.tsx                — Nav bar notification bell with unread count
├── AlertFeed.tsx                — Full-page alert feed with filters
└── CompetitorTrendChart.tsx     — Chart: competitor count over time per territory
```

### Cost

The primary cost is Google Places API calls for periodic scans.

| Scan frequency | Areas monitored | Places calls/scan | Monthly API cost |
|---------------|----------------|-------------------|-----------------|
| Weekly | 10 | ~30 per area | $6–$24 |
| Weekly | 50 | ~30 per area | $30–$120 |
| Daily | 10 | ~30 per area | $42–$168 |
| Daily | 50 | ~30 per area | $210–$840 |

**Optimization:** Only scan for changes (compare place IDs), don't re-fetch full details for known places. Use Essentials-tier fields ($5/1K) for scans, only fetch Pro-tier fields when displaying details to the user.

### Tier Gating

- **Essential:** No monitoring (point-in-time POI search only)
- **Pro:** 5 monitors, weekly scans, email alerts
- **Advanced:** Unlimited monitors, daily scans, Slack integration, competitor trend analytics

---

## Implementation Priority & Timeline

| Feature | Effort | Impact | Priority | Recommended Phase |
|---------|--------|--------|----------|------------------|
| Quantitative Cannibalization (#2) | Low (2–3 days) | High | P1 | Phase 2 (Core) |
| Time-of-Day Isochrones (#3) | Medium (1 week) | High | P1 | Phase 2 (Core) |
| Collaboration & Version History (#6) | Medium (2 weeks) | High | P1 | Phase 3 (BI) |
| Competitor Monitoring (#8) | Medium (1–2 weeks) | High | P2 | Phase 3 (BI) |
| Auto Territory Generation (#1) | High (2–3 weeks) | Very High | P2 | Phase 3 (BI) |
| Mobile Field App (#7) | Medium (2 weeks) | Medium | P2 | Phase 4 (Polish) |
| Multi-Location Optimization (#4) | High (2–3 weeks) | Very High | P3 | Phase 5 (Growth) |
| Customer Segmentation (#5) | Medium (1–2 weeks) | Medium | P3 | Phase 5 (Growth) |

**Start with cannibalization and time-of-day isochrones** — they're relatively low effort, use data/APIs you already have, and create immediate "wow" moments that differentiate from Smappen on day one.

**Collaboration is a retention play** — it makes the product sticky by embedding it into team workflows. Build it in Phase 3 alongside the analytics features.

**Territory generation and multi-location optimization are the flagship differentiators** — they're the features that justify premium pricing and win enterprise deals. They require the most engineering effort but have the highest ceiling for value.

---

## Appendix: Additional Database Indexes

```sql
-- Indexes for the new tables (in addition to those defined inline above)
CREATE INDEX idx_competitor_monitors_next_scan
  ON competitor_monitors (next_scan_at)
  WHERE is_active = TRUE;

CREATE INDEX idx_competitor_alerts_unread
  ON competitor_alerts (monitor_id, is_read, created_at DESC)
  WHERE is_read = FALSE;

CREATE INDEX idx_field_notes_project
  ON field_notes (project_id, created_at DESC);

CREATE INDEX idx_project_versions_project
  ON project_versions (project_id, version_number DESC);

CREATE INDEX idx_comments_project_area
  ON comments (project_id, area_id, created_at DESC)
  WHERE area_id IS NOT NULL;

CREATE INDEX idx_project_collaborators_user
  ON project_collaborators (user_id, project_id);

CREATE INDEX idx_tract_segments_segment
  ON tract_segments (primary_segment);
```
