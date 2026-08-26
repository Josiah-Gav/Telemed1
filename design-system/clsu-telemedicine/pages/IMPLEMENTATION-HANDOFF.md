# Dashboard Analytics — Implementation Handoff

> **For the next session.** Phase 2 (design) is complete. Nothing has been implemented.
> Read in this order: `dashboards-shared.md` → the page file for whichever dashboard you're building.
> Phase 1 (analytics architecture, metric definitions, metric IDs) is the companion spec.

---

## Current state

| | Status |
|---|---|
| Application code | **Untouched.** No controllers, models, routes, migrations, or views modified in Phase 1 or 2. |
| Dependencies | **None added.** Chart.js is *selected* but **not installed**. |
| Design system | 6 markdown files under `design-system/clsu-telemedicine/pages/` |
| Existing dashboards | Still the original shells: banner + `dashboardNotifications()` panel |

---

## Read-order for the rules

```
../MASTER.md                    global brand tokens (authoritative for color + typeface)
  └── dashboards-shared.md      overrides Master for all dashboards (density, components, charts, a11y)
        └── {role}-dashboard.md overrides shared for one page
```

Later files win. `dashboards-shared.md` §1 documents exactly which three Master rules are overridden and why.

---

## Build order

Follow Phase 1's sequence — backend definitions land before any UI. The UI work slots in as:

1. **`<x-dash.chart>` + `<x-dash.empty>` + one chart on the admin dashboard.** Prove the whole pipeline once: Chart.js install, Vite registration, the `data-chart` + `@json` handoff, the `<details>` data table, the empty state. Every later chart is then a data change, not a plumbing change.
2. **`<x-dash.stat>` + `<x-dash.section>`**, then the admin KPI band.
3. **`<x-dash.filter-bar>`**, then the rest of the admin dashboard.
4. Physician dashboard (introduces the two-date-basis labels and `<x-dash.table>`).
5. Nurse dashboard (introduces the shared-queue / personal-workload split).
6. Patient dashboard (two counts + the status sentence; mostly preserves what exists).

---

## Components to create

All **anonymous Blade components** in `resources/views/components/dash/`, auto-registering as `<x-dash.*>`, declaring `@props`. Matches the project's existing convention and the Laravel stack guideline (severity High): use `x-*` components, never `@include`, for reusable UI.

| Component | File | Spec |
|---|---|---|
| `<x-dash.stat>` | `components/dash/stat.blade.php` | shared §7.1 |
| `<x-dash.section>` | `components/dash/section.blade.php` | shared §7.2 |
| `<x-dash.chart>` | `components/dash/chart.blade.php` | shared §7.3 |
| `<x-dash.filter-bar>` | `components/dash/filter-bar.blade.php` | shared §7.4 |
| `<x-dash.badge>` | `components/dash/badge.blade.php` | shared §7.5 |
| `<x-dash.empty>` | `components/dash/empty.blade.php` | shared §7.6 |
| `<x-dash.table>` | `components/dash/table.blade.php` | shared §7.7 |
| `<x-dash.state>` | `components/dash/state.blade.php` | shared §7.8 |

**Nine components, no more.** Each appears on at least two dashboards. Anything used once stays inline markup.

## Views to modify

| View | Change |
|---|---|
| `resources/views/nurse/dashboard.blade.php` | Add Bands 2–4; **relocate** the existing notifications panel to Band 5 |
| `resources/views/physician/dashboard.blade.php` | Same; `P-07` table goes in Band 2 |
| `resources/views/admin/dashboard.blade.php` | Same; adds the symptom section |
| `resources/views/patient/dashboard.blade.php` | Light touch — add the two counts + status sentence |

**Do not rewrite the notifications panel.** It works. Move the existing markup down the page.

## Where data gets inserted

Controllers pass plain arrays from `DashboardAnalyticsService` (Phase 1 §A-03). Blade computes nothing.

```
KPI value      → <x-dash.stat :value="$stats['unclaimed_pending']">
Chart series   → <x-dash.chart :series="$charts['volume']">
                 └─ renders @json($series) into data-chart
                 └─ Alpine reads the attribute and instantiates Chart.js
```

Chart.js is imported and registered once in `resources/js/app.js` — **register only the controllers used** (bar, line) plus needed scales/elements. Do not import `chart.js/auto`.

---

## Visual hierarchy in one line per dashboard

- **Nurse** — shared queue (tinted, unfiltered) → my workload → *filter boundary* → my analytics
- **Physician** — active + next consultations → period performance → *filter boundary* → my analytics
- **Admin** — *filter* → service health → demand trend → case mix → symptoms
- **Patient** — status sentence → current consultation → one action → two small counts

---

## Rules that must not be violated

**Correctness**
1. `assigned` status never rendered — no badge, no chart category, no legend.
2. No concern-category chart. The column is always `general`.
3. No average-severity metric. Severity 3 is a default value.
4. Severity 3 bar carries the diagonal hatch + the visible footnote.
5. Symptom analytics exclude follow-ups, and any symptom section says so in visible text.
6. Zero-denominator rates render `—`. Never `0%`, never `NaN`.
7. Every percentage shows its denominator.
8. Completion rate is labelled **"Completion Rate"** — never "success rate".

**Structure**
9. The nurse's shared queue renders identically regardless of selected date range.
10. The filter bar sits **below** the operational band on nurse and physician dashboards.
11. Shared queue and personal workload are visually distinct surfaces, not just differently-labelled cards.
12. Every chart goes inside `<x-dash.chart>` with its `<details>` data table. No bare `<canvas>`.
13. Chart wrappers have explicit heights.
14. Notifications panel moves to the bottom band on all three staff dashboards.

**Visual**
15. No Bootstrap. Tailwind + Alpine only.
16. Brand tokens unchanged — `brand-green` stays the brand. Status colors are a separate axis.
17. Status/priority colors are identical between badges and charts.
18. `rounded-xl` (12px) on new analytics surfaces; keep `rounded-3xl` on the existing banner.
19. No shadows on resting cards — border only.
20. Hover lift only on cards that are actually links. No transforms.
21. No emoji as icons — Heroicons SVG only.

**Accessibility**
22. Nothing encoded by color alone: status = color + icon + text.
23. Visible focus everywhere; never remove focus rings.
24. Live counts announce meaningful text ("4 unclaimed requests"), not bare numbers.
25. `prefers-reduced-motion` disables all motion, including the active-status pulse.
26. Touch targets ≥44px (≥48px for the patient primary action).
27. No horizontal page scroll at 375px.

**Privacy**
28. No staff rankings or per-person performance comparison on any dashboard.
29. Custom symptom text: nurse = count only, physician = not shown, admin = n ≥ 3 aggregated.
30. The n ≥ 3 threshold is enforced in the service layer, not in Blade.

---

## Known issues in `../MASTER.md` (not fixed — out of scope)

Flagged for a separate, authorized cleanup. They do not block dashboard work because `dashboards-shared.md` overrides all three:

1. **Component Specs still contain the superseded navy palette** (`#1E3A5F`, `#A16207`, `#F8FAFC`) in its `.btn-primary` / `.card` / `.input` CSS — contradicting the "Approved Color Palette" section above it in the same file, which explicitly says not to use those values.
2. **Page Pattern is "Portfolio Grid"** — a landing-page artifact, wrong for every page in this app.
3. **Card spec applies `cursor: pointer` and `translateY(-2px)` to all cards**, which conflicts with that file's own "no layout-shifting hovers" anti-pattern.

Regenerating `MASTER.md` requires `--force`, which needs explicit user authorization — so it was deliberately left untouched.
