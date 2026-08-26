# Patient Dashboard — Page Specification

> **Overrides:** `dashboards-shared.md` → `../MASTER.md`
> **View:** `resources/views/patient/dashboard.blade.php`
> **Route:** `dashboard` → `DashboardController::index()` patient branch
> **Metric IDs:** Phase 1 spec, `PT-01`, `PT-02`

---

## Purpose & primary question

> **"What is happening with my consultation, and what do I need to do next?"**

**This is not an analytics dashboard.** It is a status page with two small counts attached. The patient is a member of the public — possibly anxious, possibly on a phone, possibly unfamiliar with the system. Clarity and reassurance outrank density everywhere.

**Patients see no system-wide data of any kind.**

---

## Overrides of the shared dashboard layer

| Shared layer | Patient dashboard | Reason |
|---|---|---|
| 14px base type | **16px base type** | General public, including older and less confident users |
| Density 8/10 | **Density 5/10** — `p-4`/`p-5` cards, `space-y-6` | Six elements on the page; density buys nothing and costs calm |
| Charts everywhere | **No charts at all** | Nothing here benefits from a chart. A chart of one's own illness is neither useful nor kind |
| Date filter | **No filter** | Lifetime counts only |
| `rounded-xl` (12px) | **`rounded-2xl` (16px)** | Softer than the staff tools; matches the existing patient dashboard's current feel |

The existing patient dashboard already implements the active-consultation card, status badge, scheduled slot, and follow-up state via `DashboardController::index()`. **This spec largely preserves it** and adds a modest history summary.

---

## Page structure

```
BAND 1 · Greeting header
   "Hello, {first name}" + one-line status sentence

BAND 2 · CURRENT CONSULTATION  ← the whole point of the page
   Status badge · type · submitted date · symptom summary
   Scheduled slot (date + time) if scheduled
   Primary action: [Open messages] or [View details]
   Clinical-ready / prescription chips when present

BAND 3 · WHAT YOU CAN DO NOW  ← contextual, single clear action
   Either [Request a consultation]  or  [Request a follow-up]
   or an explanatory line when neither is available

BAND 4 · MY HISTORY (light)
   [PT-01 Total requests]  [PT-02 Completed consultations]
   [View consultation history →]
```

### Above the fold (375px — the primary target)

Band 1 + Band 2. A patient must see their current status **without scrolling on a phone**. This constraint drives the whole layout; if Band 2 grows past it, trim Band 2, not the type size.

---

## The status sentence

Band 1 carries a plain-language sentence derived from status — the single highest-value element for this user:

| Status | Sentence |
|---|---|
| `pending` | "Your request has been submitted and is waiting for a nurse to review it." |
| `reviewed` | "A nurse has reviewed your request. A doctor will be assigned shortly." |
| `scheduled` | "Your consultation is scheduled for {date} at {time}." |
| `active` | "Your consultation is in progress." |
| `completed` | "Your consultation is complete. You can view the summary in your history." |
| `rejected` | "Your request was not approved. See the reason below." |
| `cancelled` | "This request was cancelled." |
| none | "You don't have an active consultation request." |

Written in second person, no system vocabulary. The patient never sees the words "request_status", "session", or "triage".

---

## KPI hierarchy

Only two, and they are **small inline stats, not KPI cards**:

| Metric | Label | Note |
|---|---|---|
| `PT-01` | **"Requests submitted"** | Not "consultations" — it counts cancellations and rejections too, so the honest noun is "requests" |
| `PT-02` | **"Consultations completed"** | |

**No completion rate for patients.** Telling someone that 40% of their health requests were rejected is a hostile way to present a care record. Counts only — this is a firm rule, not a default.

Rendered as two small figures side by side above the history link, using `text-2xl` — visibly subordinate to Band 2.

## Charts

**None.** If a future request asks for one here, the answer should start from whether the patient benefits, not from what data exists.

## Tables

None. Consultation history lives on its existing dedicated page (`consultations.history`), which this dashboard links to.

## Filters

None.

## Actions

Exactly **one primary action visible at a time**, driven by state:

| Condition | Action |
|---|---|
| No active request | **Request a consultation** (primary button) |
| Active request, session messageable | **Open messages** (primary) |
| Completed session, follow-up allowed | **Request a follow-up** (primary) |
| Active request, not yet messageable | No button; explanatory line only |

Never show "Request a consultation" and "Request a follow-up" simultaneously — the existing one-active-request rule makes one of them guaranteed to fail, and offering an action that errors is worse than offering none.

## States

**Empty (no consultation ever):** the most important empty state on the site — a first-time patient's first impression.
> **"You don't have any consultations yet."**
> "When you're not feeling well, you can request a consultation with the university infirmary and a nurse will review it."
> [Request a consultation]

Positive tone, not a grey "No data" box. Band 4 is hidden entirely when both counts are zero.

**Loading:** the existing page renders server-side; keep it that way. Only the active-consultation poll (`dashboard.active_consultation`) updates asynchronously, and it must **never** blank the card while refreshing — update in place.

**Error:** if the async refresh fails, keep the last-known state and show a quiet inline note ("Couldn't refresh just now"). **Never** replace a patient's consultation status with an error block; a patient seeing their consultation vanish is alarming and the underlying record is fine.

## Responsive

| | 375px | 768px | 1024px+ |
|---|---|---|---|
| Band 2 card | Full width, `p-4` | Full width, `p-5` | Max ~720px, left aligned |
| Slot info | Stacked under status | Inline row | Inline row |
| Primary action | **Full-width button, 48px** | Auto width | Auto width |
| Band 4 stats | 2-up (they're small) | 2-up | 2-up |
| Navigation | Existing bottom nav | Existing sidebar | Sidebar |

The page deliberately does **not** expand to `max-w-7xl` on large screens. A single status card stretched across 1280px reads as broken; capping the content column keeps it composed.

## Accessibility

- 16px base, line-height ≥1.5.
- The status sentence is real text — the status badge is supplementary, so the meaning never depends on reading a colored pill.
- Primary action is a real `<button>`/`<a>` with a descriptive label ("Open messages", not "Click here").
- Async status updates announce via `role="status"` with the full sentence, not a bare status word.
- Touch targets ≥48px on the primary action — larger than the 44px baseline, since this is the page's single most important control.
