# Smappen Clone — Visual & Interaction Style Guide

## How to Use This File

This style guide documents every visual detail, interaction pattern, and UI component observed in the Smappen app (smappen.com) as of May 2026. Feed this file to Claude Code alongside the build prompts to ensure the clone matches the original pixel-for-pixel.

---

## 1. Brand & Color System

### 1.1 Primary Colors

```css
:root {
  /* === PRIMARY PURPLE (brand color) === */
  --purple-900: #3D1D6E;    /* Darkest — nav bar background, footer */
  --purple-800: #4A2282;    /* Dark — header bar background on marketing pages */
  --purple-700: #5C2D91;    /* Deep — dark UI accents */
  --purple-600: #6B37A6;    /* Strong — active states, selected items */
  --purple-500: #7848BB;    /* PRIMARY — theme-color meta tag, main brand purple */
  --purple-400: #8B5FCF;    /* Medium — hover states on purple elements */
  --purple-300: #A78BDA;    /* Light — disabled purple buttons, secondary text */
  --purple-200: #D4C4ED;    /* Lighter — subtle borders, tags */
  --purple-100: #EDE5F7;    /* Lightest — selected row background, hover states */
  --purple-50:  #F6F2FB;    /* Near-white — panel backgrounds, subtle highlights */

  /* === RED (CTA / action buttons) === */
  --red-600: #D42A2A;       /* Dark red — hover state on red buttons */
  --red-500: #E53935;       /* PRIMARY RED — "Log in", "Start exploring" buttons */
  --red-400: #EF5350;       /* Light red — hover state on secondary red */

  /* === AREA MARKER COLORS (user-assignable, shown as dots in area list) === */
  --area-red:    #E53935;   /* Red dot */
  --area-green:  #00897B;   /* Teal/green dot */
  --area-purple: #7848BB;   /* Purple dot (matches brand) */
  --area-orange: #F57C00;   /* Orange dot */
  --area-blue:   #1565C0;   /* Dark blue dot */
  --area-pink:   #D81B60;   /* Pink dot */
  --area-cyan:   #00ACC1;   /* Cyan dot */
  --area-lime:   #7CB342;   /* Lime green dot */
  --area-amber:  #FFB300;   /* Amber/yellow dot */
  --area-indigo: #3949AB;   /* Indigo dot */

  /* === NEUTRALS === */
  --gray-900: #1A1A2E;      /* Headings, primary text */
  --gray-800: #2D2D44;      /* Strong body text */
  --gray-700: #4A4A5A;      /* Default body text */
  --gray-600: #6B6B7B;      /* Secondary text, labels */
  --gray-500: #8E8E9A;      /* Placeholder text, muted icons */
  --gray-400: #B0B0BC;      /* Borders, dividers */
  --gray-300: #D1D1DB;      /* Light borders, disabled states */
  --gray-200: #E8E8EE;      /* Table alternating rows, subtle dividers */
  --gray-100: #F3F3F7;      /* Panel backgrounds, input backgrounds */
  --gray-50:  #F9F9FB;      /* Page background, subtle off-white */
  --white:    #FFFFFF;       /* Cards, panels, modal backgrounds */

  /* === SEMANTIC / FUNCTIONAL === */
  --success: #00897B;        /* Teal green — success states, positive metrics */
  --warning: #F57C00;        /* Orange — warning states, caution */
  --error:   #E53935;        /* Red — error states, destructive actions */
  --info:    #1565C0;        /* Blue — informational, links */
}
```

### 1.2 Heatmap Choropleth Color Scale

The population density heatmap uses a continuous gradient from cool (low density) to hot (high density). These are the key stops observed:

```css
:root {
  /* Heatmap gradient stops (left to right on the legend bar) */
  --heatmap-0:   #4A148C;   /* Very low — deep purple */
  --heatmap-1:   #283593;   /* Low — dark blue */
  --heatmap-2:   #1565C0;   /* Low-medium — blue */
  --heatmap-3:   #00838F;   /* Medium-low — teal */
  --heatmap-4:   #2E7D32;   /* Medium — green */
  --heatmap-5:   #558B2F;   /* Medium — yellow-green */
  --heatmap-6:   #9E9D24;   /* Medium-high — olive/yellow */
  --heatmap-7:   #F9A825;   /* High — yellow/amber */
  --heatmap-8:   #EF6C00;   /* Very high — orange */
  --heatmap-9:   #D32F2F;   /* Extreme — red */
}

/* CSS gradient for the legend bar */
.heatmap-legend-bar {
  background: linear-gradient(
    to right,
    #4A148C, #283593, #1565C0, #00838F,
    #2E7D32, #558B2F, #9E9D24, #F9A825,
    #EF6C00, #D32F2F
  );
  height: 8px;
  border-radius: 4px;
}
```

### 1.3 Isochrone Polygon Styling

```css
/* Default polygon appearance on the map */
.isochrone-polygon {
  /* Fill: translucent version of the area color */
  fill-opacity: 0.20;       /* Very subtle fill — map must remain readable underneath */
  
  /* Stroke: solid white border (visible in heatmap mode) */
  stroke-color: #FFFFFF;
  stroke-weight: 3;          /* 3px white border around isochrone polygons */
  stroke-opacity: 1.0;
  
  /* When heatmap is OFF, stroke uses the area's assigned color instead */
  /* stroke-color: var(--area-color); */
  /* stroke-weight: 2; */
}

/* Selected polygon */
.isochrone-polygon--selected {
  fill-opacity: 0.30;
  stroke-weight: 4;
}

/* Hover state */
.isochrone-polygon--hover {
  fill-opacity: 0.25;
}
```

---

## 2. Typography

### 2.1 Font Family

Smappen uses **Nunito** (Google Font) across the entire application — both the marketing site and the app interface.

```css
@import url('https://fonts.googleapis.com/css2?family=Nunito:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,600&display=swap');

body {
  font-family: 'Nunito', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  font-optical-sizing: auto;
  font-style: normal;
  font-weight: 400;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
```

### 2.2 Type Scale

```css
/* App interface type scale */
.text-xs     { font-size: 11px; line-height: 16px; }  /* Heatmap labels, micro captions */
.text-sm     { font-size: 13px; line-height: 18px; }  /* Secondary info, metadata, "30 min", "50mi" */
.text-base   { font-size: 14px; line-height: 20px; }  /* Default body text, area names in list */
.text-md     { font-size: 15px; line-height: 22px; }  /* Input text, form fields */
.text-lg     { font-size: 16px; line-height: 24px; }  /* Panel headers, "Heatmap", "Demographics" */
.text-xl     { font-size: 18px; line-height: 26px; }  /* Section titles, "My Territory Mapping" */
.text-2xl    { font-size: 20px; line-height: 28px; }  /* Modal titles */
.text-3xl    { font-size: 24px; line-height: 32px; }  /* Big numbers in demographics panel */
.text-hero   { font-size: 28px; line-height: 36px; }  /* Key statistics (population total) */

/* Marketing page type scale */
.text-display-sm  { font-size: 32px; line-height: 40px; }  /* Subheadings on landing page */
.text-display-md  { font-size: 42px; line-height: 52px; }  /* "Territory mapping" heading */
.text-display-lg  { font-size: 56px; line-height: 64px; }  /* Hero numbers "80,000+" */
```

### 2.3 Font Weights Used

```
300 — Light:      Rarely used, only for decorative large numbers
400 — Regular:    Default body text, descriptions, area names in list
500 — Medium:     Not commonly used
600 — SemiBold:   Labels, "Folder" button text, panel section headers, navigation items
700 — Bold:       Headings, selected area name, "Create new area" button text, statistics
800 — ExtraBold:  Hero heading on marketing page ("has never been this easy!")
```

### 2.4 Text Colors

```css
.text-heading      { color: #1A1A2E; }  /* Primary headings */
.text-body         { color: #4A4A5A; }  /* Default body text */
.text-secondary    { color: #6B6B7B; }  /* Secondary labels, timestamps */
.text-muted        { color: #8E8E9A; }  /* Placeholders, disabled text, "e.g. London" */
.text-purple       { color: #7848BB; }  /* Links, accent text, "See our plans" */
.text-white        { color: #FFFFFF; }  /* Text on purple/dark backgrounds */
.text-on-red       { color: #FFFFFF; }  /* Text on red buttons */
.text-label-caps   { color: #6B6B7B; font-size: 11px; font-weight: 700;
                     text-transform: uppercase; letter-spacing: 0.8px; }
                   /* "DATA TO DISPLAY", "BOUNDARY LEVEL" labels in heatmap panel */
```

---

## 3. Spacing & Layout System

### 3.1 Spacing Scale

```css
:root {
  --space-1:  4px;
  --space-2:  8px;
  --space-3:  12px;
  --space-4:  16px;
  --space-5:  20px;
  --space-6:  24px;
  --space-7:  28px;
  --space-8:  32px;
  --space-10: 40px;
  --space-12: 48px;
  --space-16: 64px;
}
```

### 3.2 App Layout Dimensions

```
┌──────────────────────────────────────────────────────────────────────────┐
│  TOP BAR — height: 48px, background: white, border-bottom: 1px #E8E8EE │
├────────┬────────────────────────────────────────────┬────────┬──────────┤
│        │                                            │ RIGHT  │          │
│  LEFT  │              MAP CANVAS                    │ TOOL   │          │
│ PANEL  │           (fills remaining)                │  BAR   │          │
│        │                                            │        │          │
│ width: │                                            │ width: │          │
│ 330px  │                                            │  48px  │          │
│        │                                            │        │          │
│        │                                            │        │          │
├────────┤                                            │        │          │
│ BOTTOM │                                            │        │          │
│ LEFT   │                                            │        │          │
│ PANEL  │                                            │        │          │
│(float) │                                            │        │          │
└────────┴────────────────────────────────────────────┴────────┘──────────┘
```

**Exact dimensions:**
- **Top bar:** height 48px, full width, z-index 100
- **Left panel:** width 330px, full height minus top bar, z-index 50
- **Left panel collapse button:** 28px circle with left-chevron, positioned at right edge of panel
- **Right toolbar:** width 48px, right-aligned, vertical icon column
- **Map canvas:** fills all remaining space
- **Heatmap panel (floating):** positioned bottom-left, overlaps map, width ~300px
- **Free version banner:** height 32px, centered text, yellow-tinted background, sits below top bar

### 3.3 Border Radius

```css
:root {
  --radius-sm:  4px;    /* Small elements, tags, badges */
  --radius-md:  6px;    /* Buttons, inputs, cards */
  --radius-lg:  8px;    /* Panels, modals, dropdowns */
  --radius-xl:  12px;   /* Larger cards, marketing elements */
  --radius-2xl: 16px;   /* Hero elements, featured cards */
  --radius-full: 9999px; /* Pill buttons, circular icons, area color dots */
}
```

---

## 4. Component Library

### 4.1 Top Bar / Header

```
┌─────────────────────────────────────────────────────────────────────────┐
│ [Logo] [📊] [>] My map     [...] [↻]            [↩][↪]  [🔒 Share] [👤▾]│
└─────────────────────────────────────────────────────────────────────────┘
```

- **Background:** `#FFFFFF`
- **Height:** 48px
- **Border bottom:** 1px solid `#E8E8EE`
- **Logo:** Smappen logo (your logo), left-aligned, ~120px wide
- **Map icon** (grid/chart icon): 20px, `#6B6B7B`, clickable
- **Breadcrumb separator:** `>` character in `#B0B0BC`
- **Map name:** "My map" in `#1A1A2E`, font-weight 600, font-size 15px, editable on click
- **Three-dot menu** (`...`): 20px icon, `#6B6B7B`
- **Refresh icon** (circular arrows): 20px, `#6B6B7B`
- **Undo/Redo arrows:** 20px icons, `#B0B0BC` when disabled, `#6B6B7B` when active
- **Share button:** icon + text "Share", `#6B6B7B`, font-weight 600
- **User avatar/icon:** 24px circle, `#6B6B7B`, dropdown chevron

### 4.2 Free Version Banner

```
┌─────────────────────────────────────────────────────────────────────────┐
│           You are using the free version. 👉 See our plans >           │
└─────────────────────────────────────────────────────────────────────────┘
```

- **Background:** `#FFFFFF`
- **Height:** 32px
- **Text:** `#4A4A5A`, font-size 13px, font-weight 400, centered
- **"free"** word: font-weight 700
- **"See our plans":** `#7848BB`, font-weight 600, with `>` arrow
- **Emoji:** 👉 before "See our plans"
- **Position:** directly below top bar, full width, z-index 90

### 4.3 Left Panel

**Outer container:**
- Width: 330px
- Background: `#FFFFFF`
- Border right: none (panel floats over map with shadow)
- Box shadow: `2px 0 8px rgba(0, 0, 0, 0.08)`
- Overflow-y: auto
- Padding: 0 (sections have internal padding)

**Address search input (top of panel):**
```
┌──────────────────────────────────────────────────┐
│ Starting address (e.g. London)          [📍]     │
└──────────────────────────────────────────────────┘
```
- Background: `#FFFFFF`
- Border: 2px solid `#7848BB` (purple border!)
- Border-radius: 8px
- Height: 44px
- Padding: 0 16px
- Placeholder text: "Starting address (e.g. London)" in `#8E8E9A`, italic style
- Location pin icon: right-aligned, 20px, `#7848BB`
- Margin: 12px 16px

**"Create new area" button:**
```
┌──────────────────────────────────────────────────┐
│         + Create new area                         │
└──────────────────────────────────────────────────┘
```
- Background: `#7848BB` (solid purple)
- Color: `#FFFFFF`
- Font-weight: 700
- Font-size: 14px
- Height: 44px
- Border-radius: 8px
- Width: 100% (minus panel padding)
- `+` icon before text
- Hover: background darkens to `#6B37A6`
- Margin: 0 16px 12px

**Folder/Search row:**
```
┌──────────┬───────────────────────────────────────┐
│ 📁 Folder │ Search areas or folder...    [🔍]     │
└──────────┴───────────────────────────────────────┘
```
- Two elements inline: "Folder" button + search input
- Folder button: text "Folder" with folder icon, border: 1px solid `#D1D1DB`, background: white, border-radius: 6px, padding: 6px 12px, font-size: 13px, font-weight: 600
- Search input: flex-grow, border: 1px solid `#D1D1DB`, border-radius: 6px, height: 36px, placeholder: "Search areas or folder..."
- Search icon: magnifying glass, right side, `#8E8E9A`
- Padding: 8px 16px

**Folder tree section:**
- "New folder" label with collapse chevron (▾ or ▸)
- Font-size: 13px, font-weight: 600, color: `#6B6B7B`
- Padding-left: 16px
- Chevron: 12px, `#8E8E9A`

**Area list items:**
```
┌──────────────────────────────────────────────────┐
│ 🔴  Winchester, VA, USA                          │
│ 🟢 🚗 30 min  Leesburg, VA, USA                 │
│ 🟣  Manassas, VA, USA                            │
│ 🟠 🚗 50mi  Ashland, VA, USA                    │
│ 🔵 🚗 50mi  Sterling, VA, USA                   │  ← selected (highlighted)
└──────────────────────────────────────────────────┘
```

Each area row:
- Height: ~40px
- Padding: 8px 16px
- Display: flex, align-items: center, gap: 8px
- **Color dot:** 12px circle (border-radius: 50%), filled with area color, flex-shrink: 0
- **Transport icon:** 16px car/bike/walk icon, `#6B6B7B`, shown only if area is an isochrone (not shown for manual/radius areas that have the circle icon ◯ instead)
- **Time/distance badge:** "30 min" or "50mi", font-size: 13px, font-weight: 600, color: `#4A4A5A`, shown only for isochrone/isodistance areas
- **Location name:** font-size: 14px, font-weight: 400, color: `#4A4A5A`, truncated with ellipsis if too long
- **Selected state:** background: `#EDE5F7` (purple-100), font-weight: 700 on the location name
- **Hover state:** background: `#F6F2FB` (purple-50)
- **Manual area icon:** ◯ circle outline instead of transport icon, same color as the area dot
- Cursor: pointer

### 4.4 Left Panel Collapse Button

- 28px × 28px circle
- Background: `#FFFFFF`
- Border: 1px solid `#D1D1DB`
- Box-shadow: `0 1px 3px rgba(0, 0, 0, 0.1)`
- Icon: `◀` left chevron when panel is open, `▶` right chevron when collapsed
- Icon color: `#6B6B7B`, 14px
- Position: absolute, right edge of left panel, vertically centered
- z-index: 60 (above panel)
- Hover: background `#F3F3F7`

### 4.5 Right Toolbar (Vertical Icon Bar)

```
┌─────┐
│ ⏱️  │  — Isochrone/drive time tool
│ 📍  │  — Pin/location tool
│ 🏛️  │  — Demographics/data
│ 📋  │  — Data/reports
│ 📊  │  — Analytics
│ 📥  │  — Import
│ ⭐  │  — Favorites/saved
│     │
│     │
│ ➕  │  — Zoom in
│ ➖  │  — Zoom out
│ 💬  │  — Chat/help bubble
└─────┘
```

- Width: 48px
- Background: `#FFFFFF`
- Border-left: 1px solid `#E8E8EE`
- Position: fixed right edge, full height minus top bar
- z-index: 50
- Each icon: 22px, `#4A4A5A`
- Icon button size: 40px × 40px, centered in the 48px column
- Hover: background `#F3F3F7`, border-radius: 6px
- Active/selected: background `#EDE5F7`, icon color `#7848BB`
- Dividers: 1px solid `#E8E8EE` between icon groups
- Bottom icons (zoom, chat) are bottom-aligned with `margin-top: auto`

### 4.6 Heatmap Panel (Floating)

```
┌──────────────────────────────────────────┐
│ Heatmap                              ✕   │
│                                          │
│ DATA TO DISPLAY                          │
│ ┌────────────────────────────────────┐   │
│ │ 📊 Population                  🔍  │   │
│ └────────────────────────────────────┘   │
│                                          │
│ BOUNDARY LEVEL                           │
│ ┌────────────────────────────────────┐   │
│ │ 🏛️ Census unit                  ▾  │   │
│ └────────────────────────────────────┘   │
│                                          │
│ Density by mi²                           │
│ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓  │
│ 0                               38,824  │
└──────────────────────────────────────────┘
```

- **Position:** fixed, bottom-left corner, overlapping the map (not inside left panel)
- **Offset:** left: 16px from left edge (or left panel edge if panel is open), bottom: 16px
- **Width:** ~300px
- **Background:** `#FFFFFF`
- **Border-radius:** 12px
- **Box-shadow:** `0 4px 16px rgba(0, 0, 0, 0.12)`
- **Padding:** 16px

- **"Heatmap" title:** font-size: 18px, font-weight: 700, color: `#1A1A2E`
- **Close "✕" button:** top-right, 20px, `#8E8E9A`, hover: `#4A4A5A`
- **Section labels:** ("DATA TO DISPLAY", "BOUNDARY LEVEL")
  - font-size: 11px
  - font-weight: 700
  - text-transform: uppercase
  - letter-spacing: 0.8px
  - color: `#6B6B7B`
  - margin-top: 16px, margin-bottom: 8px

- **Selector inputs:**
  - Height: 40px
  - Border: 1px solid `#D1D1DB`
  - Border-radius: 8px
  - Background: `#FFFFFF`
  - Padding: 0 12px
  - Font-size: 14px
  - Icon on left (16px), search/chevron icon on right (16px)
  - Focus: border-color `#7848BB`

- **"Density by mi²" label:** font-size: 12px, color: `#E53935` (red/pink text), font-weight: 600
- **Gradient bar:** height 8px, border-radius: 4px, full width, the choropleth gradient
- **Scale labels:** "0" left-aligned, "38,824" right-aligned, font-size: 12px, color: `#6B6B7B`

### 4.7 Map Pin/Marker

- **Default marker:** 28px tall pin shape
- **Fill:** area's assigned color (solid)
- **Border:** 2px white stroke around the pin
- **Drop shadow:** `0 2px 4px rgba(0, 0, 0, 0.2)`
- **Center dot:** 6px white circle at the pin's center
- **Label tooltip:** white background, 1px solid `#D1D1DB`, border-radius: 6px, padding: 4px 8px, font-size: 13px, box-shadow: `0 2px 8px rgba(0, 0, 0, 0.12)`
- **Label format:** `● Location Name` where ● is the area's color dot (6px)

### 4.8 Right Panel (Demographics / Analytics)

When an area is selected and demographics/business data is shown:

```
┌──────────────────────────────────────────┐
│ ✕  Demographics                          │
│                                          │
│ 📍 Washington, DC, USA                   │
│                                          │
│ [Population] [Employment] [Households]   │
│ [Housing] [Export]                        │
│                                          │
│ ┌────────────────────────────────────┐   │
│ │ Population                         │   │
│ │ 7,667,625                          │   │
│ │                                    │   │
│ │ Median Income                      │   │
│ │ $55,900                            │   │
│ └────────────────────────────────────┘   │
│                                          │
│ Population age and gender                │
│ ┌────────────────────────────────────┐   │
│ │ [bar chart visualization]          │   │
│ └────────────────────────────────────┘   │
└──────────────────────────────────────────┘
```

- **Width:** ~350px (slides in from right side)
- **Background:** `#FFFFFF`
- **Border-left:** 1px solid `#E8E8EE`
- **Box-shadow:** `-2px 0 8px rgba(0, 0, 0, 0.08)`
- **Header:** "Demographics" with ✕ close button, font-size: 16px, font-weight: 700
- **Location label:** with 📍 pin icon, font-size: 14px, color: `#4A4A5A`
- **Tab/pill buttons:** inline row of category toggles:
  - Background: `#F3F3F7`, border-radius: 6px, padding: 6px 12px
  - Selected: background `#7848BB`, color white
  - Unselected: color `#4A4A5A`
  - Font-size: 12px, font-weight: 600

- **Big stat numbers:** font-size: 28px, font-weight: 700, color: `#1A1A2E`
- **Stat labels:** font-size: 13px, font-weight: 400, color: `#6B6B7B`
- **Currency format:** `$55,900` with comma thousands separator
- **Charts:** blue bar charts (`#3B82F6` or `#5B8FF9`), background bars in `#E8E8EE`

---

## 5. Buttons

### 5.1 Primary Button (Purple)

```css
.btn-primary {
  background: #7848BB;
  color: #FFFFFF;
  font-family: 'Nunito', sans-serif;
  font-weight: 700;
  font-size: 14px;
  padding: 10px 20px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.btn-primary:hover { background: #6B37A6; }
.btn-primary:active { background: #5C2D91; }
.btn-primary:disabled { background: #A78BDA; cursor: not-allowed; }
```

### 5.2 CTA Button (Red)

```css
.btn-cta {
  background: #E53935;
  color: #FFFFFF;
  font-weight: 700;
  font-size: 14px;
  padding: 10px 24px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.btn-cta:hover { background: #D42A2A; }
.btn-cta:active { background: #C62828; }
```

### 5.3 Outline Button

```css
.btn-outline {
  background: #FFFFFF;
  color: #4A4A5A;
  font-weight: 600;
  font-size: 13px;
  padding: 6px 12px;
  border: 1px solid #D1D1DB;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.btn-outline:hover { background: #F3F3F7; border-color: #B0B0BC; }
.btn-outline:active { background: #E8E8EE; }
```

### 5.4 Ghost/Icon Button

```css
.btn-icon {
  background: transparent;
  color: #6B6B7B;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}
.btn-icon:hover { background: #F3F3F7; color: #4A4A5A; }
.btn-icon--active { background: #EDE5F7; color: #7848BB; }
```

---

## 6. Form Inputs

### 6.1 Text Input

```css
.input {
  font-family: 'Nunito', sans-serif;
  font-size: 14px;
  font-weight: 400;
  color: #1A1A2E;
  background: #FFFFFF;
  border: 1px solid #D1D1DB;
  border-radius: 8px;
  padding: 10px 12px;
  height: 40px;
  width: 100%;
  outline: none;
  transition: border-color 0.15s ease;
}
.input::placeholder { color: #8E8E9A; font-style: italic; }
.input:focus { border-color: #7848BB; box-shadow: 0 0 0 3px rgba(120, 72, 187, 0.1); }
.input:hover:not(:focus) { border-color: #B0B0BC; }
.input--error { border-color: #E53935; }
.input--error:focus { box-shadow: 0 0 0 3px rgba(229, 57, 53, 0.1); }
```

### 6.2 Address Search Input (Special — Purple Border)

```css
.input-address {
  border: 2px solid #7848BB;   /* Thicker purple border — distinctive */
  border-radius: 8px;
  height: 44px;
  padding: 0 44px 0 16px;     /* Right padding for icon */
  font-size: 14px;
}
.input-address::placeholder { color: #8E8E9A; font-style: italic; }
/* Pin icon positioned absolute right */
```

### 6.3 Select/Dropdown

```css
.select {
  appearance: none;
  font-family: 'Nunito', sans-serif;
  font-size: 14px;
  color: #1A1A2E;
  background: #FFFFFF;
  border: 1px solid #D1D1DB;
  border-radius: 8px;
  padding: 10px 36px 10px 12px;
  height: 40px;
  background-image: url("data:image/svg+xml,..."); /* Chevron down */
  background-position: right 12px center;
  background-repeat: no-repeat;
}
.select:focus { border-color: #7848BB; }
```

---

## 7. Shadows & Elevation

```css
:root {
  --shadow-xs:  0 1px 2px rgba(0, 0, 0, 0.05);                        /* Subtle lift for cards */
  --shadow-sm:  0 1px 3px rgba(0, 0, 0, 0.08);                        /* Buttons, small cards */
  --shadow-md:  0 4px 12px rgba(0, 0, 0, 0.10);                       /* Panels, dropdowns */
  --shadow-lg:  0 4px 16px rgba(0, 0, 0, 0.12);                       /* Floating panels (heatmap) */
  --shadow-xl:  0 8px 24px rgba(0, 0, 0, 0.14);                       /* Modals */
  --shadow-panel-left:  2px 0 8px rgba(0, 0, 0, 0.08);                /* Left panel */
  --shadow-panel-right: -2px 0 8px rgba(0, 0, 0, 0.08);               /* Right panel */
}
```

---

## 8. Icons

Smappen uses a mix of custom icons and what appears to be a **Phosphor Icons** or **Lucide Icons** style — clean, 1.5px stroke weight, rounded line caps.

**Recommended icon set:** Lucide Icons (MIT license, React component library available)

```
npm install lucide-react
```

**Key icons used and their mappings:**

| UI Element | Icon | Lucide Name |
|---|---|---|
| Address pin | 📍 | `MapPin` |
| Car/driving | 🚗 | `Car` |
| Bicycle | 🚲 | `Bike` |
| Walking | 🚶 | `Footprints` |
| Folder | 📁 | `Folder` |
| Search | 🔍 | `Search` |
| Close | ✕ | `X` |
| Settings | ⚙️ | `Settings` |
| Share | 🔗 | `Share2` |
| Expand/Collapse | ◀▶ | `ChevronLeft` / `ChevronRight` |
| Undo/Redo | ↩↪ | `Undo2` / `Redo2` |
| Plus/Add | ➕ | `Plus` |
| Three dots menu | ⋯ | `MoreHorizontal` |
| Zoom in/out | ＋－ | `ZoomIn` / `ZoomOut` |
| Download/Export | ⬇ | `Download` |
| Import/Upload | ⬆ | `Upload` |
| Chart/Analytics | 📊 | `BarChart3` |
| Refresh | 🔄 | `RefreshCw` |
| User/Account | 👤 | `User` |
| Isochrone/Clock | ⏱ | `Clock` |
| Demographics/Building | 🏛 | `Building` |
| Star/Favorite | ⭐ | `Star` |
| Help/Chat | 💬 | `MessageCircle` |
| Location target | ◎ | `Crosshair` |
| Heatmap | 🗺 | `Map` |
| Grid/Territory | ⊞ | `LayoutGrid` |
| Report/Clipboard | 📋 | `ClipboardList` |

**Icon sizing:**
- Toolbar icons: 22px
- Inline icons (in text/buttons): 16px
- Header/nav icons: 20px
- Small decorative: 14px

**Icon color:** Inherits text color by default. Uses `currentColor`.

---

## 9. Map Styling

### 9.1 Google Maps Custom Style (Subtle, Light)

Smappen uses a clean, light map style that de-emphasizes visual clutter so the isochrones and heatmaps pop. Apply this custom style to the Google Maps instance:

```javascript
const mapStyles = [
  // Slightly desaturate everything
  { elementType: 'geometry', stylers: [{ saturation: -20 }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#4A4A5A' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#FFFFFF' }, { weight: 3 }] },
  // Lighten water
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#C5D8E8' }] },
  // Subtle land
  { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#F0F0F0' }] },
  // De-emphasize POI
  { featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#D5E8D0' }] },
  // Roads
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#D1D1DB' }] },
  { featureType: 'road.arterial', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'road.local', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  // Transit
  { featureType: 'transit', stylers: [{ visibility: 'simplified' }] },
  // Admin boundaries
  { featureType: 'administrative', elementType: 'geometry.stroke', stylers: [{ color: '#B0B0BC' }] },
];
```

### 9.2 Minimap Toggle (Bottom Left)

- A small map thumbnail (64px × 64px) in the bottom-left corner of the map
- Shows a satellite preview if currently in roadmap mode, and vice versa
- Border: 2px solid `#FFFFFF`
- Border-radius: 4px
- Box-shadow: `0 2px 4px rgba(0, 0, 0, 0.2)`
- Label below: "Heatmap" or "Map" in font-size 11px, `#4A4A5A`
- Click toggles between views

---

## 10. Animations & Transitions

```css
/* Global transition defaults */
:root {
  --transition-fast:   0.1s ease;
  --transition-normal: 0.15s ease;
  --transition-smooth: 0.25s ease;
  --transition-panel:  0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Panel slide-in/out */
.left-panel-enter  { transform: translateX(-330px); }
.left-panel-active { transform: translateX(0); transition: transform var(--transition-panel); }

.right-panel-enter  { transform: translateX(350px); }
.right-panel-active { transform: translateX(0); transition: transform var(--transition-panel); }

/* Polygon render animation */
.polygon-appear {
  opacity: 0;
  animation: polygonFadeIn 0.4s ease forwards;
}
@keyframes polygonFadeIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}

/* Button press */
.btn:active { transform: scale(0.97); }

/* Hover transitions on all interactive elements */
button, a, .clickable { transition: all var(--transition-normal); }

/* Loading spinner */
@keyframes spin { to { transform: rotate(360deg); } }
.spinner {
  width: 24px; height: 24px;
  border: 3px solid #E8E8EE;
  border-top-color: #7848BB;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
```

---

## 11. Responsive Breakpoints

```css
/* Smappen is desktop-first but works on tablets */
@media (max-width: 1200px) {
  /* Left panel becomes overlay with backdrop */
  .left-panel { position: fixed; z-index: 200; }
  .left-panel-backdrop { background: rgba(0, 0, 0, 0.3); }
}

@media (max-width: 768px) {
  /* Left panel becomes bottom sheet */
  .left-panel {
    width: 100%;
    height: 50vh;
    bottom: 0;
    left: 0;
    border-radius: 16px 16px 0 0;
    transform: translateY(calc(100% - 60px)); /* Peek mode: just top visible */
  }
  /* Right toolbar moves to bottom */
  .right-toolbar {
    width: 100%;
    height: 48px;
    flex-direction: row;
    bottom: 0;
  }
  /* Right panel becomes full-screen overlay */
  .right-panel { width: 100%; }
}
```

---

## 12. Marketing / Landing Page Style

### 12.1 Navigation Bar

- Background: `#3D1D6E` (dark purple, nearly black-purple)
- Height: 64px
- Logo: white version, left-aligned
- Nav links: `#FFFFFF`, font-size: 14px, font-weight: 600
- Dropdown chevrons: `#FFFFFF`, 12px
- "Try for free" button: outline style, border: 1px solid `#FFFFFF`, color: `#FFFFFF`, border-radius: 8px
- "Log in" button: background `#E53935` (red), color `#FFFFFF`, border-radius: 8px

### 12.2 Hero Section

- Background: gradient from `#3D1D6E` (top) to `#FFFFFF` (bottom), with curved/wave divider
- Subtitle: "BUILT FOR THE FUTURE OF FRANCHISE." — all caps, `#FFFFFF`, underlined, font-size: 13px, font-weight: 700, letter-spacing: 1px
- Main heading line 1: "Territory mapping" in `#7848BB` (purple), font-size: 42px, font-weight: 700
- Main heading line 2: "has never been this easy!" in `#1A1A2E`, font-size: 42px, font-weight: 800
- Body text: `#6B6B7B`, font-size: 16px, line-height: 26px
- Address input + CTA: combined input+button element
  - Input: white background, border-radius: 50px (pill shape), height: 56px
  - Button "Start exploring": `#E53935` red, border-radius: 50px, inside the input container, with search icon

### 12.3 Stats Section

- Large numbers: `#7848BB` (purple), font-size: 56px, font-weight: 700
- Labels below: `#6B6B7B`, font-size: 14px
- Layout: three columns, centered

---

## 13. Tailwind CSS Configuration

If using Tailwind CSS, extend the config to match Smappen's design system:

```javascript
// tailwind.config.js
const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
  content: ['./src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Nunito', ...defaultTheme.fontFamily.sans],
      },
      colors: {
        brand: {
          50:  '#F6F2FB',
          100: '#EDE5F7',
          200: '#D4C4ED',
          300: '#A78BDA',
          400: '#8B5FCF',
          500: '#7848BB',
          600: '#6B37A6',
          700: '#5C2D91',
          800: '#4A2282',
          900: '#3D1D6E',
        },
        cta: {
          500: '#E53935',
          600: '#D42A2A',
          700: '#C62828',
        },
        area: {
          red:    '#E53935',
          green:  '#00897B',
          purple: '#7848BB',
          orange: '#F57C00',
          blue:   '#1565C0',
          pink:   '#D81B60',
          cyan:   '#00ACC1',
          lime:   '#7CB342',
          amber:  '#FFB300',
          indigo: '#3949AB',
        },
      },
      borderRadius: {
        DEFAULT: '6px',
        sm: '4px',
        md: '6px',
        lg: '8px',
        xl: '12px',
        '2xl': '16px',
      },
      boxShadow: {
        'panel-left':  '2px 0 8px rgba(0, 0, 0, 0.08)',
        'panel-right': '-2px 0 8px rgba(0, 0, 0, 0.08)',
        'float':       '0 4px 16px rgba(0, 0, 0, 0.12)',
        'modal':       '0 8px 24px rgba(0, 0, 0, 0.14)',
      },
      spacing: {
        '4.5': '18px',
        '13': '52px',
        '15': '60px',
      },
      width: {
        'panel-left': '330px',
        'panel-right': '350px',
        'toolbar': '48px',
      },
      height: {
        'topbar': '48px',
        'banner': '32px',
      },
      fontSize: {
        'hero': ['28px', '36px'],
      },
    },
  },
  plugins: [],
};
```

---

## 14. Key Interaction Patterns

### 14.1 Area Creation Flow
1. User clicks "Create new area" button (purple)
2. Button expands into an inline form at the top of the left panel (doesn't open a modal)
3. User types address → autocomplete dropdown appears
4. User selects transport mode (car/bike/walk toggle buttons)
5. User adjusts time slider (5-120 min)
6. "Add an area" button generates isochrone
7. Polygon appears on map with fade-in animation
8. Area appears in the list below

### 14.2 Area Selection
1. Click area in list OR click polygon on map
2. List row highlights with purple-100 background
3. Map zooms/pans to fit the selected area
4. Right panel slides in with demographics data
5. Polygon border thickens

### 14.3 Heatmap Toggle
1. Click heatmap thumbnail in bottom-left of map
2. Choropleth layer fades in over the map (~0.5s)
3. Heatmap control panel slides up in bottom-left
4. All area polygons switch to white-stroke-only mode
5. Click "✕" or heatmap thumbnail again to dismiss

### 14.4 Folder Management
1. Folders shown as collapsible groups in area list
2. Click folder name to expand/collapse
3. Drag areas between folders
4. Right-click folder for context menu (rename, delete, change color)

---

## 15. Do's and Don'ts

### DO:
- Keep the left panel clean and scannable — no visual clutter
- Use purple sparingly — it's the accent, not the dominant color
- Keep the map as the hero — panels are support, not competition
- Use Nunito everywhere — consistency is key
- Match the exact spacing: generous padding in panels, tight in the area list
- Use the purple-100 (#EDE5F7) highlight for selected states — it's subtle but distinctive
- Make the address input border purple (2px) — it's a signature design choice

### DON'T:
- Don't use rounded/bubbly buttons everywhere — Smappen's border-radius is restrained (6-8px)
- Don't use a dark theme or dark panels — Smappen is white/light panels on map
- Don't hide the area list behind tabs — it's always visible in the left panel
- Don't use Google Maps default blue markers — use custom colored pin markers
- Don't make the right toolbar wider than 48px — it's intentionally minimal
- Don't add gradients to buttons — Smappen buttons are flat solid colors
- Don't use borders on the left panel — it uses box-shadow only, no right border line
