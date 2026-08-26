# Nurse Dashboard — Page Specification

> **Overrides:** `dashboards-shared.md` → `../MASTER.md`
> **View:** `resources/views/nurse/dashboard.blade.php`
> **Route:** `nurse.dashboard` (`nurses/{nurse}/dashboard`)
> **Metric IDs:** Phase 1 spec, `N-01`…`N-07`

---

## Purpose & primary question

> **"What needs my attention right now?"**

Operational and queue-oriented. This dashboard is opened many times a shift, often for under ten seconds, to answer one question: *is there work waiting?* Analytics are secondary and must never displace that answer.

## The central design problem

Two different concepts must not be confused:

| Concept | Data meaning | Behavior |
|---|---|---|
| **Shared queue** | `assigned_nurse_id IS NULL` | Belongs to no one. Every nurse competes for it. **Always current — never date-filtered.** |
| **My workload** | `assigned_nurse_id = Auth::id()` | This nurse's responsibility, carried through the whole lifecycle and inherited by follow-ups. |

**These are separated structurally, not just by label.** The shared queue sits in its own visually distinct band above the filter boundary; personal workload sits in a second band. A nurse must never have to work out which numbers are theirs.

---

## Page structure

```
BAND 1 · Page header
   h1 "Nurse Dashboard" + nurse name

BAND 2 · SHARED QUEUE  ← distinct surface: brand-green-soft tint, green left rule
   [N-01 Unclaimed pending]  [High priority waiting]  [N-03 Follow-ups to triage]
   Scope line: "Shared across all nurses · always current"

BAND 2b · MY WORKLOAD  ← white surface, no tint
   [N-02 My open cases]  [My active]  [My completed (period)]
   Scope line: "Assigned to you"

─── BAND 3 · FILTER BOUNDARY ────────────────────────────
   "Showing: [Last 30 days ▾]"   Historical analytics only —
                                  the queue above always shows current state.

BAND 4 · HISTORICAL ANALYTICS
   [N-04 Intake volume of my caseload]           (full width, line)
   [N-06 Status distribution]  [N-05 Priority mix]   (2-up)

BAND 5 · SUPPORTING
   [N-07 Symptoms in current queue] (optional)
   [Existing notifications panel — relocated here]
```

### Above the fold (1024px)

Band 1 + Band 2 + Band 2b. Six numbers and the filter boundary. Nothing else needs to be visible for the dashboard to do its job.

At 375px, above the fold is Band 1 + the three shared-queue cards. That is the correct priority: on a phone mid-shift, the shared queue *is* the dashboard.

---

## Visual distinction between the two bands

| | Shared queue (Band 2) | My workload (Band 2b) |
|---|---|---|
| Surface | `brand-green-soft` `#edf8f0` | white |
| Left rule | 3px `brand-green` | none |
| Section label | "Shared queue" + `users` icon | "My workload" + `user` icon |
| Scope line | "Shared across all nurses · always current" | "Assigned to you" |

The tint is doing real work: it marks the band that is exempt from the date filter. Do not tint both bands, and do not remove the tint to "clean up" the page.

---

## KPI hierarchy

| Rank | Metric | Treatment |
|---|---|---|
| **1** | `N-01` Unclaimed pending | Largest. `tone="critical"` **when the High-priority companion count > 0**, otherwise neutral. Links to consultation inbox. |
| **2** | High-priority unclaimed | Sits beside N-01 as its own card. Red tint whenever > 0. |
| **3** | `N-03` Follow-ups awaiting triage | Neutral card, links to follow-up queue. |
| 4 | `N-02` My open cases | With `reviewed / scheduled / active` chip row beneath. |
| 5 | My active consultations | Solid green `active` treatment. |
| 6 | My completed (period) | Quiet. **Only card in Band 2b affected by the date filter — label must say "this period".** |

> **Care point:** card 6 is date-filtered while cards 4 and 5 are not. Its label carries the period explicitly, or the row is internally inconsistent. If this proves confusing in review, move card 6 into Band 4.

---

## Chart hierarchy

1. **`N-04` Intake volume of my caseload** — line, full width. Title: **"Requests in my caseload, by submission date"**. Never "My activity" — there is no `claimed_at` timestamp, so the data cannot support that claim. Footnote: *"Plotted by when requests were submitted, not when you claimed them."* Below 4 points → stat line fallback.
2. **`N-06` Status distribution** — horizontal bar, fixed lifecycle order. Never shows `pending` (a claimed request is not pending) and never shows `assigned`.
3. **`N-05` Priority mix** — 100% stacked bar, single row.

## Tables

None required. `N-07` is a compact ranked list (symptom + count), not a table — five rows, two fields.

## Filters

Single native `<select>`: Today · This week · This month · **Last 30 days (default)** · This year · Custom range.

Default rationale: a rolling window is always populated, and the nurse's *current* state is already fully covered by the unfiltered bands above.

**The filter must not alter Bands 2 or 2b (except card 6).** This is the single most important implementation rule on this page.

## Actions

- N-01 card → consultation inbox
- N-03 card → follow-up requests
- N-02 card → consultation inbox filtered to this nurse
- No destructive actions. This is a read-only overview; claiming happens in the inbox.

## States

**Empty:**
- N-01 = 0 → **positive** empty state: *"The queue is clear — no unclaimed requests."* Green tint, check icon. This is a success, not an absence.
- N-02 = 0 → neutral: *"You have no open cases."*
- Charts with no rows → *"No requests in your caseload for this period."* + "Try last 90 days" action.
- N-07 with an empty queue → hide the panel entirely (it is a live-queue view; with no queue there is nothing to say).

**Loading:** skeletons matching final card dimensions. Bands 2/2b render first — they are cheap, unfiltered counts and must not wait on chart aggregation.

**Error:** per-container. A failed chart never blanks the queue. Band 2 failing is the serious case: show `role="alert"` with a Retry, because a nurse must not silently believe the queue is empty. **Never render `0` as a fallback for a failed query.**

## Responsive

| | 375px | 768px | 1024px+ |
|---|---|---|---|
| Shared queue | 1-up stacked | 3-up | 3-up |
| My workload | 1-up stacked | 3-up | 3-up |
| Filter | Full-width select | Inline | Inline |
| Charts | Stacked, `h-56` | Stacked | N-04 full width; N-06 + N-05 2-up |
| N-07 list | Full width | Full width | Half width |

At 375px the shared-queue band keeps its tint and left rule — the distinction survives the stack. Do not drop it to save space.

## Accessibility

- Queue counts: `<span role="status" aria-atomic="true">4 unclaimed requests</span>` — never a bare number.
- Each band is `<section aria-labelledby>` with a real heading, so screen-reader users get the shared/personal split from structure, not from the tint.
- The tint is reinforced by the icon + scope line, so the distinction never depends on color.
