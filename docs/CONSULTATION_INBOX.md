# Consultation Inbox

The **triage queue** for staff. Two sibling pages — one for nurses, one for
physicians — that turn a patient's submitted request into a scheduled or active
consultation. This document explains what they do, how they are built, and
— most importantly — *why* each decision was made.

---

## 1. What it does

| | Nurse inbox | Physician inbox |
|---|---|---|
| Route name | `nurse.consultation_inbox` | `physician.consultation_inbox` |
| URL | `nurses/{nurse}/consultation-inbox` | `physicians/{physician}/consultation-inbox` |
| Tabs | Pending · Assigned | Normal Priority · High Priority |
| Sees requests with status | `pending`, plus assigned ones | `reviewed`, `assigned`, `scheduled` |
| Actions | Approve (sets priority) · Reject | Schedule · Start · Reject |
| Controller | `NurseController` | `PhysicianController` |

Both pages are a **table of requests + a details modal**. The table is the
scan view (who is waiting, how urgent); the modal is the decision view (full
symptoms, attachments, and the action buttons).

---

## 2. Where a request comes from — the lifecycle

```
Patient submits                    ConsultationController::store
      │                            request_status = 'pending'
      ▼
┌─────────────────┐  notifies all nurses (CONSULTATION_SUBMITTED)
│  NURSE  INBOX   │
└─────────────────┘  nurse approves → status 'reviewed' + priority_level set
      │              nurse rejects  → status 'rejected'  (ends here)
      ▼
┌─────────────────┐  notifies all physicians, via
│ PHYSICIAN INBOX │  ConsultationController::approveConsultation
└─────────────────┘
      │
      ├── Schedule → books a ScheduleSlot, status 'scheduled'
      ├── Start    → status 'active', opens the consultation session
      └── Reject   → status 'rejected'
```

The nurse decides **whether and how urgently**; the physician decides **when and
by whom**. That split is why priority is set by the nurse but the physician inbox
is organised *by* priority — the physician's first question is "what is urgent",
which the nurse has already answered.

---

## 3. The two-table model (the most important thing to understand)

One patient request is split across **two tables with different primary keys**:

| Model | Table | PK | Holds |
|---|---|---|---|
| `Consultation` | `consultation_requests` | `request_id` | The *request*: `request_status`, priority, symptoms, assigned nurse/physician |
| `ConsultationSession` | `consultations` | `id` | The *clinical session*: `consultation_status`, assessment/plan/diagnosis, booked `slot_id` |

They are 1:1, linked by `request_id`. **The table name does not tell you which
model it is** — `consultations` is the *session*, `consultation_requests` is the
*request*. This is schema evolution: the request table came first, and session
data was added later rather than by renaming a live table.

> **Defence note:** the inbox reads mainly from `Consultation`, but eager-loads
> `consultationSession.slot` because the Scheduled Slot column and the Start gate
> both need the booked slot, which lives on the *session* side.

**State transitions never happen in a controller.** They all go through
`App\Services\ConsultationOwnershipService`, which wraps each transition in a
`DB::transaction` with `lockForUpdate()`. This fixed a real race condition: two
physicians opening the same request could both claim it. The pattern is always
**lock → re-check status → write**, and it is covered by
`tests/Feature/ConsultationConcurrencyTest.php`.

---

## 4. The physician inbox is a *shared triage pool*

`PhysicianController::getConsultationInboxData()` applies **no
`assigned_physician_id` filter**:

```php
Consultation::with(['patient', 'nurse', 'consultationSession.slot'])
    ->whereIn('request_status', ['reviewed', 'assigned', 'scheduled'])
    ->orderByDesc('submitted_at')
    ->get();
```

Every physician sees every unclaimed request. This is deliberate: a `reviewed`
request has **no assigned physician yet** (`assigned_physician_id` is `null`), so
filtering by ownership would show an empty inbox and nothing could ever be
claimed. Whoever acts first wins, and the pessimistic lock in
`ConsultationOwnershipService` makes "first" well-defined.

This single fact drives the attachment authorization rule in §8 — get it wrong
and physicians are locked out of exactly the requests they are meant to triage.

---

## 5. Routes and authorization

```
Route::prefix('physicians/{physician}')->group(...)   // inside auth + verified
    GET  /consultation-inbox            physician.consultation_inbox
    GET  /consultation-inbox/refresh    physician.consultation_inbox.refresh
    POST /consultations/{c}/approve-reviewed | start | schedule | reject-reviewed
```

Every action calls a private guard first:

```php
private function authorizePhysician(User $physician)
{
    if (Auth::user()->role !== 'physician' || Auth::id() !== $physician->user_id) {
        abort(403, 'Unauthorized access.');
    }
}
```

It checks **both** the role *and* that the logged-in user matches the `{physician}`
route parameter. **Never trust the route parameter alone** — without the
`Auth::id()` check, any physician could load another's inbox by editing the URL.
`NurseController` has the identical `authorizeNurse()`.

---

## 6. Live updates — why the table refreshes itself

**The problem.** The table was rendered once by Blade at page load. A physician
who left the tab open never saw newly approved requests appear. They *did* get a
bell notification, but the notification and the table were unrelated — the table
stayed stale until a manual reload.

**The solution.** `physicianConsultationInbox()` (Alpine) polls every 30 seconds:

```js
init() { setInterval(() => this.refreshInbox(), 30000); }

refreshInbox() {
    if (this.showModal) return;          // never mutate rows mid-decision
    fetch(window.physicianInboxRefreshUrl, { headers: {...} })
        .then(r => r.json())
        .then(data => { this.consultations = [...normal, ...high]; })
        .catch(() => {});                // next tick retries
}
```

Design decisions worth defending:

| Decision | Why |
|---|---|
| **Polling, not WebSockets** | The whole app already polls (messaging, presence, the notification bell). Adding a broadcast stack for one table would be a new piece of infrastructure for a 30-second freshness requirement. |
| **30 seconds** | Matches the notification bell's existing interval. A consistent cadence, not a new number to justify. |
| **Table only, not `location.reload()`** | A reload would lose scroll position, the active tab, and any open dialog. The nurse page reloads; the physician page updates just the rows. |
| **Skip while the modal is open** | Rows must not shift under a physician who is reading a case and about to act. |
| **Swallow fetch errors** | A dropped poll is not worth an error dialog; the next tick recovers. |

---

## 7. Why the physician table is Alpine-rendered (and the badge problem)

Because the table repopulates from JSON, rows are rendered by Alpine `x-for`,
**not** by Blade `@foreach`. That created a real design problem:

> `<x-dash.badge>` is a **server-side Blade component**. It cannot re-render per
> Alpine row. So how do physician rows get the same status colours as nurse rows?

Three options were considered:

1. **Duplicate the colour map in JavaScript.** Rejected — it would silently drift
   from the Blade component, and `CLAUDE.md` explicitly forbids duplicating a
   business rule that already exists elsewhere.
2. **Return rendered HTML from the refresh endpoint.** Rejected — swapped-in HTML
   containing Alpine directives needs manual `Alpine.initTree()`, and the endpoint
   would have to return both HTML and JSON for the modal.
3. **Serialize the presentation tokens.** ✅ Chosen.

The maps moved into `App\Support\StatusBadge`, which is now the **single source of
truth** consumed by two renderers:

```
                  App\Support\StatusBadge
                  (label + classes + icon)
                    ╱                  ╲
     dash/badge.blade.php        serializeConsultations()
     (server-rendered rows)      (JSON tokens per row)
              │                           │
        nurse inbox,               physician inbox
        dashboards, history        Alpine x-for rows
```

One map, two renderers, zero drift. A colour change reaches both pages.

**A bug this exposed:** `assigned` was missing from the status map, so any request
in that state rendered an **empty badge**. Physician rows are exactly
`reviewed`/`assigned`/`scheduled`, so this was about to be visible on a third of
the queue. Fixed and covered by a test.

---

## 8. Attachments and the authorization trap

The modal shows attachments as image thumbnails with a click-to-zoom lightbox.
Two things had to change to make that work.

**(a) URLs, not raw paths.** Attachments are stored on Cloudinary *or*, if that
call fails, the local disk (see `CLAUDE.md`). A stored value may therefore be a
path that is not web-reachable. `serializeAttachmentUrls()` converts every stored
value into a routed `consultation.attachment` URL, normalising the basename the
same way the controller does (`parse_url(..., PHP_URL_PATH)` then `basename`) so a
Cloudinary URL carrying a query string still matches on the way back in.

**(b) Authorization.** `AttachmentController::show` previously allowed nurses and
**nobody else**:

```php
if (Auth::user()->role !== 'nurse') abort(403);
```

The obvious fix — "allow the assigned physician" — is **wrong**, and this is the
subtlest point in the whole feature. Per §4, the inbox is a shared pool and a
`reviewed` request has `assigned_physician_id = null`. That rule would have
returned 403 on precisely the unclaimed requests physicians work from.

The rule grants exactly what the inbox already displays, and nothing more:

```php
$user->role === 'nurse'
|| ($user->role === 'physician' && (
       $consultation->assigned_physician_id === $user->user_id   // theirs, any status
    || in_array($consultation->request_status,
           ['reviewed', 'assigned', 'scheduled'], true)          // the shared pool
   ))
```

A request that has left the pool and belongs to another physician stays private,
and patients are still refused here (they reach their own files through
`ConsultationController`).

---

## 9. The Start gate

`resolveCanStart()` computes, per row, whether Start is legal and *why not* if it
isn't. The modal binds `:disabled="!can_start"` and shows `can_start_message`
above the buttons.

| Status | Result |
|---|---|
| `reviewed` / `assigned` | Can start immediately |
| `scheduled`, slot missing or not `booked` | Blocked — "Assigned slot is missing or not booked" (or *missed* / *completed*) |
| `scheduled`, more than 15 min before start | Blocked — "Start will be available at …" |
| `scheduled`, within 15 min of start, before slot end | **Can start** |
| `scheduled`, past slot end | Blocked — "This schedule slot window has already ended" |

The 15-minute grace window lets a physician open a little early without letting
them start hours ahead. Slots left unstarted are flipped to `missed` by the
`consultations:mark-missed-slots` command, scheduled `everyMinute`.

> **Why gate in the UI when the server already enforces it?** The server is still
> the authority — the gate is *additive*. Without it the physician clicks Start,
> waits for a round-trip, and gets an error dialog that explains nothing about
> *when* they can start. The data was already being computed and thrown away.

---

## 10. What the table shows

| Column | Source | Note |
|---|---|---|
| Patient Name | `patient_name` | Avatar initial + presence dot, with an `sr-only` "Online"/"Offline" label so it is not colour-only |
| Severity | `severity_badge` | The **highest** severity across all symptoms (1–4), not the first |
| Scheduled Slot | `scheduled_slot` | `2:00 PM - 2:30 PM` over `Aug. 29, 2026`; em dash when unscheduled |
| Submitted At | `submitted_at` | `M. j, Y g:i A` |
| Status / Priority | `status_badge` / `priority_badge` | From `StatusBadge` (§7) |

"Online" means `online_status === 'online'` **and** `last_seen_at` within the last
2 minutes — the stored flag alone would show a physician as online after a browser
crash. Presence is maintained by the global `TrackUserPresence` middleware plus a
heartbeat ping for idle pages.

`scheduled_slot.slot_date` is formatted for **display**; `starts_at_iso` is the
machine-readable form. They are built from the same date, so the ISO string is
derived *before* the display format is applied.

---

## 11. Tests

| File | Covers |
|---|---|
| `PhysicianConsultationInboxTableTest` | Refresh payload shape, badge tokens, highest-severity selection, `N/A` fallback, attachment URLs, `can_start`, slot formatting + `starts_at_iso` |
| `PhysicianAttachmentAccessTest` | Pool access, own-request access, cross-physician denial, nurse unchanged, patient denied |
| `NurseConsultationInboxTableTest` | The nurse table + its refresh endpoint |
| `ConsultationConcurrencyTest` | The claim race condition and its locks |

Feature tests run against in-memory SQLite. **Caveat:** two enum migrations
`return` early on SQLite, so values like `scheduled` and `missed` are enforced by
MySQL in dev/production but only by application code in tests. A status the tests
accept can still be rejected by the MySQL enum — do not treat a green suite as
proof that a *new* status value is valid.

---

## 12. Deliberate scope decisions

- **No WebSockets.** 30-second polling matches the rest of the app (§6).
- **The nurse page still full-reloads** on change instead of swapping rows. It was
  left as-is rather than refactored in the same commit; the physician page is the
  newer pattern.
- **`concern_category` is still serialized** but no longer shown in the modal. It
  was removed from the UI as a display decision; the field is a cheap passthrough
  and pruning it is a separate change.
- **The nurse inbox still computes severity inline in Blade** rather than through
  `StatusBadge::severity()`. Migrating it is a safe follow-up, deliberately kept
  out of this change to keep the diff reviewable.
- **`PhysicianController::createPhysicianFollowUp`** duplicates slot-booking logic
  that also lives in `ConsultationOwnershipService::decideFollowUpByPhysician`.
  A known maintenance risk documented in `CLAUDE.md` — check both when changing
  follow-up creation.

---

## 13. Likely defence questions

**Why two tables for one consultation?**
Schema evolution. `consultation_requests` modelled the request; clinical session
data was added later in `consultations` rather than renaming a live table. They
are 1:1 via `request_id`. See §3.

**Why can every physician see every request?**
It is a claim-based triage pool. A `reviewed` request has no physician yet, so an
ownership filter would show an empty inbox and nothing could be claimed. Safe
because every transition takes a row lock. See §4.

**How do you prevent two physicians starting the same consultation?**
`ConsultationOwnershipService` wraps each transition in a transaction with
`lockForUpdate()` and re-checks the status *inside* the lock. The second
physician's re-check fails and they get an error. Covered by
`ConsultationConcurrencyTest`.

**Why polling instead of real-time?**
The requirement is "within about half a minute", and the app already polls for
messaging, presence, and notifications. WebSockets would add a broadcast server
and a new failure mode for no requirement that polling misses.

**Isn't rendering badges from JSON a security risk?**
The values are server-side constants from `App\Support\StatusBadge` — labels,
Tailwind class names, and SVG path data. No user input reaches them. Patient-
supplied text (names, symptoms, reasons) is bound with `x-text`, which escapes.

**Why not just reuse the Blade badge component?**
A Blade component renders once on the server; the rows are re-rendered on the
client after every poll. Serializing the tokens keeps one map for both. See §7.

**What happens if the refresh request fails?**
It is swallowed and the next tick retries. The table keeps showing the last good
data rather than blanking or interrupting the physician.
