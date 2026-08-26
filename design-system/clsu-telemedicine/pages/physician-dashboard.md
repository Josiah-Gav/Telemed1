# Physician Dashboard — Page Specification

> **Overrides:** `dashboards-shared.md` → `../MASTER.md`
> **View:** `resources/views/physician/dashboard.blade.php`
> **Route:** `physician.dashboard` (`physicians/{physician}/dashboard`)
> **Metric IDs:** Phase 1 spec, `P-01`…`P-07`

---

## Purpose & primary question

> **"What consultations do I need to handle, and how is my workload progressing?"**

Personal and clinical. **This is not a system-wide administrative view.** Every number is scoped to the authenticated physician. A physician must never see another clinician's caseload here.

**Scope key:** `consultation_requests.assigned_physician_id = Auth::id()` — not `consultations.physician_id`. The two agree wherever a session exists, but a request the physician *rejected* never gets a session, and scoping on the request keeps those in their own numbers.

---

## Page structure

```
BAND 1 · Page header
   h1 "Physician Dashboard" + name

BAND 2 · NOW  ← never date-filtered
   [P-01 Active now]  [P-02 Scheduled ahead]
   [P-07 Next consultations table — the reason this page is opened]

BAND 2b · PERIOD PERFORMANCE
   [P-03 Completed this period]  [P-04 My completion rate]

─── BAND 3 · FILTER BOUNDARY ────────────────────────────
   "Showing: [This month ▾]"   Applies to performance and analytics below.
                                Active and scheduled always show current state.

BAND 4 · HISTORICAL ANALYTICS
   [P-05 Volume — initial vs follow-up]     (full width, stacked bar)
   [P-06 Status distribution]                (full width or 2-up)

BAND 5 · SUPPORTING
   [Existing notifications panel — relocated here]
```

### Above the fold (1024px)

Band 1 + Band 2. The two live counts and the next-consultations table. A physician opening this between patients needs "what's next", and nothing else.

**`P-07` sits in Band 2, not Band 5.** It is the most actionable element on the page and outranks the analytics entirely. This is the main structural difference from the other dashboards.

---

## KPI hierarchy

| Rank | Metric | Treatment |
|---|---|---|
| **1** | `P-01` Active now | Solid `active` green when > 0; quiet neutral at 0. **Must look correct at 0 — that is the common state.** Links to the active consultation. |
| **2** | `P-02` Scheduled ahead | Neutral. Links to scheduled consultations. |
| 3 | `P-03` Completed this period | Quiet. Label: **"Completed during this period"**. |
| 4 | `P-04` My completion rate | Percentage + denominator on the card. |

### The two-date-basis problem — a required label discipline

`P-03` and `P-04` sit side by side on **different date bases**:

- `P-03` = **event basis** (`completed_at`) → "consultations I completed during this period"
- `P-04` = **cohort basis** (`submitted_at`) → "of requests submitted this period, the share that concluded as completed"

They will not reconcile arithmetically, and a reader will try. Each card therefore carries its basis **in its own supporting line**:

- P-03 supporting: *"Completed during this period"*
- P-04 supporting: *"34 of 50 concluded · of requests submitted this period"*

`P-04` also carries an info tooltip: *"Completed consultations as a percentage of concluded requests. In-progress requests are excluded."* Call it **Completion Rate** — never "success rate".

Zero concluded → render `—`, never `0%`.

---

## Chart hierarchy

1. **`P-05` Volume, initial vs follow-up** — stacked bar over time, full width. Carries the type split, so **no separate initial-vs-follow-up donut exists on this page** — that would be the same fact twice. Legend below, two entries. Below 4 buckets → stat line fallback.
2. **`P-06` Status distribution** — horizontal bar, lifecycle order, `rejected`/`cancelled` in their semantic colors. No `assigned`.

Priority distribution is **not** included: the nurse sets priority, so it carries little decision value for a physician. Available as a future addition if requested.

## Tables

**`P-07` Upcoming scheduled consultations** — next 5.

| Column | 375px behavior |
|---|---|
| Date | Card list: each row becomes a bordered block |
| Time | `label: value` pairs |
| Patient name | Full name — the physician is the assigned clinician and already authorized |
| Type badge | initial / follow-up |

Uses the **stacked card list** at <768px, not horizontal scroll — only four short columns, so restructuring beats scrolling.

Empty: *"No upcoming consultations scheduled."*

> **Data caveat for implementation:** if the Laravel scheduler is not running, `consultations:mark-missed-slots` never fires and stale `scheduled` rows accumulate, inflating `P-02` and this table. Worth verifying before demo.

## Filters

Native `<select>`: Today · This week · **This month (default)** · Last 30 days · This year · Custom range.

Default rationale: matches how clinical workload is conventionally reviewed and reported, and gives a trend enough points to read.

Applies to Band 2b and Band 4 only. `P-01`, `P-02`, and `P-07` are always current.

## Actions

- P-01 → active consultation
- P-02 → scheduled consultations
- P-07 rows → the consultation session
- Read-only overview; no state transitions from this page.

## States

**Empty:**
- P-01 = 0 → not an error. Quiet card reading `0` with supporting text *"No consultation in progress."*
- P-07 empty → *"No upcoming consultations scheduled."*
- Charts empty → *"No consultations in this period."* + widen action.
- P-04 with zero concluded → `—` plus *"No concluded requests yet in this period."*

**Loading:** Band 2 renders first (cheap counts + one small query). Charts skeleton independently.

**Error:** per-container. A failed chart must not hide `P-07` — the schedule is the page's core value.

## Responsive

| | 375px | 768px | 1024px+ |
|---|---|---|---|
| Band 2 KPIs | 1-up | 2-up | 2-up |
| P-07 table | Stacked card list | Card list | Full table |
| Band 2b KPIs | 1-up | 2-up | 2-up |
| P-05 | `h-56` full width | `h-64` | `h-64` |
| P-06 | Full width | Full width | Full width or 2-up beside P-05 |

## Accessibility

- P-04's tooltip is reachable by keyboard (focusable trigger) and its text also exists in the card's supporting line, so the definition never depends on hover.
- P-07 is a real `<table>` with `<th scope="col">` at ≥768px; the mobile card list uses a definition-list structure with the same labels.
- Both date bases are stated in text, so screen-reader users get the distinction the same way sighted users do.
