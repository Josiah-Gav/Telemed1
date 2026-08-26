# Admin Dashboard — Page Specification

> **Overrides:** `dashboards-shared.md` → `../MASTER.md`
> **View:** `resources/views/admin/dashboard.blade.php`
> **Route:** `dashboard` → `DashboardController::index()` admin branch
> **Metric IDs:** Phase 1 spec, `AD-01`…`AD-12`

---

## Purpose & primary question

> **"How is the telemedicine service performing overall?"**

System-wide, analytical, and reviewed periodically rather than continuously. This is the only dashboard where a date filter at page top is appropriate, because nearly everything on it is historical.

It is also the **only** dashboard that shows symptom analytics and the only one that may display custom symptom text — under the constraints in §Privacy.

---

## Page structure

```
BAND 1 · Page header
   h1 "Service Overview"

─── BAND 2 · FILTER ─────────────────────────────────────
   "Showing: [Last 30 days ▾]"   (top-placed here — see rationale)

BAND 3 · SERVICE HEALTH
   [AD-01 Total requests]  [AD-02 Completed]
   [AD-03 Completion rate]  [AD-04 In flight now]

BAND 4 · DEMAND
   [AD-05 Request volume over time]          (full width, line)

BAND 5 · CASE MIX
   [AD-06 Status]  [AD-07 Priority]  [AD-08 Initial vs follow-up]   (3-up)

BAND 6 · "What patients are reporting"   ← own titled section + scope note
   [AD-09 Most reported symptoms]        (full width, horizontal bar)
   [AD-10 Severity distribution]  [AD-11 Custom symptom usage]   (2-up)
   [AD-12 Candidates for standardization]   (table)

BAND 7 · SUPPORTING
   [Existing notifications panel — relocated here]
```

### Filter placement differs here — deliberately

On nurse and physician dashboards the filter sits *below* an operational band, because those pages lead with live state. The admin dashboard has only one unfiltered metric (`AD-04`), so a top-placed filter is honest and conventional.

**`AD-04` "In flight now" is the exception** and must carry its own supporting line: *"Current state — not affected by the date filter."* Without it, a reader will assume every card on the row shares one basis.

### Above the fold (1024px)

Band 1 + Band 2 + Band 3 + the top of `AD-05`. Four numbers answer the primary question; the trend chart provides immediate context.

---

## KPI hierarchy

| Rank | Metric | Treatment |
|---|---|---|
| **1** | `AD-03` Completion rate | The headline. Largest emphasis. Denominator **always visible**. |
| 2 | `AD-01` Total requests | With period-over-period delta vs the preceding window of equal length. |
| 3 | `AD-02` Completed | Cohort basis, same as AD-01, so the two compare directly. |
| 4 | `AD-04` In flight now | Four-way chip breakdown (pending / reviewed / scheduled / active). Placed **immediately beside AD-03**. |

**`AD-04` sits next to `AD-03` by design.** The completion rate deliberately excludes in-flight requests; placing the excluded population directly beside the rate makes that exclusion self-evident rather than hidden. Do not separate them.

### Completion rate presentation — binding

- Label: **"Completion Rate"**. Never "success rate".
- Supporting line: *"34 of 50 concluded requests"*.
- Info tooltip: *"Completed consultations as a percentage of concluded requests. In-progress requests are excluded."*
- Zero concluded → `—`, never `0%`, never `NaN`.
- No gauge or speedometer — it would imply a target nobody has set.
- The definition is OR-based (`request_status = completed` **OR** session `consultation_status = completed`). Do not label or lay out the card in a way implying `request_status` alone determines completion.

---

## Chart hierarchy

### Primary
**`AD-05` Request volume over time** — line, full width, `h-72` at 1440px. Zero-count buckets emitted explicitly so quiet days read as zero rather than compressing the axis. A university infirmary has strong term-time seasonality; a year view will show real gaps and that is correct. Below 4 points → stat line fallback.

### Secondary — case mix (3-up at 1024px+)
- **`AD-06` Status distribution** — horizontal bar, lifecycle order. 7 real categories; **no donut** (invalid above 5 categories and in an accessibility-first context). **No `assigned`.**
- **`AD-07` Priority distribution** — 100% stacked bar. **Footnote required:** *"Unclaimed pending requests carry the default Normal priority and have not yet been triaged."* Annotate rather than exclude — excluding would break the total against `AD-01`.
- **`AD-08` Initial vs follow-up** — 100% stacked bar.

### Symptom section — Band 6

This is a **separately titled section with its own scope statement**, not another row of charts:

> ### What patients are reporting
> Based on initial requests only. Follow-up consultations repeat the original request's symptoms, so including them would count the same report more than once.

That sentence is part of the deliverable. It is what stops a reader reconciling `AD-09`'s denominator against `AD-01` and concluding the numbers are broken.

- **`AD-09` Most reported symptoms** — horizontal bar, top 10, **descending by value**. Counts *distinct requests* reporting the symptom, not raw entries. Chart subtitle states the denominator.
- **`AD-10` Severity distribution** — 4 vertical bars: Very Mild · Mild · **Moderate (default)** · Severe.
  - The severity-3 bar carries the **diagonal hatch** and its axis label reads **"Moderate (default)"**.
  - **Mandatory visible footnote:** *"Severity 3 is pre-selected when a symptom is added, so this bucket includes patients who did not change it."*
  - The **Severe (4)** count is called out as a separate figure beside the chart — it is the only severity value requiring deliberate action, so it is the one carrying real signal.
  - **No average severity anywhere on this page.**
- **`AD-11` Custom symptom usage** — single KPI: *"14% of requests included a custom symptom"* with denominator.
- **`AD-12` Candidates for standardization** — 2-column table (normalized term, request count), descending, **n ≥ 3 only**. Sub-threshold terms collapse to one row: *"Low frequency (fewer than 3 reports) — 12 terms"* with **no text shown**.

---

## Tables

`AD-12` only. Uses `overflow-x-auto` at <768px (rather than a card list) — it is a genuine two-column frequency table where the tabular comparison is the point.

Header: *"Custom symptoms reported 3 or more times"*. Caption explains the threshold exists for privacy, so the omission does not read as a bug.

## Filters

Native `<select>`: Today · This week · This month · **Last 30 days (default)** · This year · Custom range.

Default rationale: a rolling window is always populated. "This month" would render an almost-empty dashboard every 1st of the month — a poor first impression during a capstone defence.

## Actions

Read-only. Links to user management remain in the existing navigation, not surfaced as dashboard KPIs — staff counts are not a service-performance metric.

## States

**Empty:**
- Fresh install / no data → *"No consultation requests in this period."* + widen action. Never a blank canvas.
- `AD-03` zero concluded → `—` + *"No requests have concluded in this period."*
- Symptom section with no valid entries → *"No symptom data recorded for this period."* — **and this is also what renders if every row's JSON is malformed.** A broken chart must never appear.
- `AD-12` with nothing above threshold → *"No custom symptom has been reported 3 or more times in this period."*

**Loading:** Band 3 KPIs first; charts skeleton independently. The symptom section is the slowest (JSON parsed in PHP, 5-minute cache) and must not block the rest of the page.

**Error:** per-container. A failed symptom aggregation shows an error in Band 6 only; service-health KPIs still render.

## Responsive

| | 375px | 768px | 1024px | 1440px |
|---|---|---|---|---|
| Band 3 KPIs | 1-up | 2-up | 4-up | 4-up |
| AD-05 | `h-56` | `h-64` | `h-64` | `h-72` |
| Case mix | Stacked | Stacked | 3-up | 3-up |
| AD-09 | Labels **above** bars, full text | Labels left, truncated ~24ch | Labels left | Labels left |
| AD-10 / AD-11 | Stacked | 2-up | 2-up | 2-up |
| AD-12 | `overflow-x-auto` | Full table | Full table | Full table |

Long symptom names ("Shortness of Breath", "Nausea / Vomiting") are the named hard case — see `dashboards-shared.md` §9.

## Privacy

Binding, and enforced in the service layer rather than the template:

1. **No staff rankings, leaderboards, or per-person comparison.** Not requested and actively recommended against: at this team size a per-staff chart is de-anonymizing, and ranking clinicians by volume rewards the wrong behavior.
2. **No patient-identifiable data.** No patient names, IDs, or row-level records anywhere on this dashboard.
3. **Custom symptom text gated at n ≥ 3** (k-anonymity, k=3). Sub-threshold terms show a count and no text.
4. `AD-12`'s aggregation drops `patient_id` before grouping, so custom text cannot be joined to identity.

## Accessibility

- `AD-10`'s hatch on severity 3 is a non-color encoding of the default caveat, reinforcing the axis label and footnote — three independent signals.
- `AD-09`'s data table carries **full untruncated** symptom names even where the chart truncates.
- The symptom section's scope note is real text in the DOM, read in order before the charts.
- `AD-04`'s "not affected by the date filter" line is text, not a tooltip.
