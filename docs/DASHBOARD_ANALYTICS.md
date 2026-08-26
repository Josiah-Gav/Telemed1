# Dashboard Analytics — Documentation & Defence Guide

This document explains how the analytics on the Nurse, Physician, and Admin
dashboards work: what every number means, how it is calculated, and why each
design decision was made. It is written to be read on its own, without the
code beside it.

---

## 1. The one big idea

Every dashboard is split into **two kinds of numbers**, and keeping them
separate is the single most important design decision in this feature.

| | **Operational (Right Now)** | **Historical (Selected Period)** |
|---|---|---|
| Question it answers | *"What needs my attention this second?"* | *"How did we perform over a stretch of time?"* |
| Affected by the date filter? | **No — never** | **Yes** |
| Example | "3 unclaimed requests are waiting" | "We completed 47 consultations last month" |

**Why this matters (likely panel question):** if the date filter also changed
the operational numbers, a nurse could set the filter to "Today", see
"0 unclaimed requests", and walk away — while ten requests submitted last week
still sit unhandled in the queue. That is a patient-safety failure, not just a
UI annoyance. So operational counts ignore the filter completely and always
show live, current state.

On screen this is made explicit: the operational band is labelled
*"Always current, not affected by the date filter below"*, and the filter bar
itself carries a note repeating that its scope is historical analytics only.

---

## 2. Vocabulary you need first

The system stores a patient request across **two database tables**:

- **`consultation_requests`** — the patient's *request*. Its `request_status`
  tracks the workflow.
- **`consultations`** — the clinical *session* created once a request becomes
  real treatment. Its `consultation_status` tracks the clinical side.

Analytics is built on the **request** table, because a request that gets
rejected never receives a session at all — and a rejection still needs to
count in the statistics.

### The seven statuses that matter

```
pending → reviewed → scheduled → active → completed
                              ↘ rejected / cancelled
```

| Status | Meaning |
|---|---|
| `pending` | Submitted by patient, no nurse has claimed it |
| `reviewed` | A nurse has triaged it |
| `scheduled` | A physician has booked a time slot for it |
| `active` | The consultation is happening now |
| `completed` | Finished successfully |
| `rejected` | Turned down by staff |
| `cancelled` | Called off |

**A defence detail worth knowing:** the database enum also contains a status
called `assigned`, but **no code path in the entire application ever writes
it**. It is a leftover from an earlier schema. Analytics deliberately excludes
it, because charting a category that is permanently zero would imply the
system has a stage it does not actually have. This exclusion is documented in
code and covered by a test.

### Two groupings built on top of those statuses

- **Concluded** = `completed` + `rejected` + `cancelled` — a request that can
  never move forward again.
- **In flight** = `pending` + `reviewed` + `scheduled` + `active` — still
  moving through the workflow.

These two groups are exact opposites and together cover all seven statuses.

---

## 3. What each dashboard shows

### 3.1 Nurse dashboard

**Operational — Shared Queue** (the same for every nurse):

| Metric | Definition |
|---|---|
| Unclaimed pending requests | Status is `pending` **and** no nurse assigned |
| High-priority, unclaimed | Same, plus `priority_level = High` |
| Follow-ups awaiting triage | Follow-up requests with status `pending` |

**Operational — My Workload** (this nurse only):

| Metric | Definition |
|---|---|
| My open cases | Requests assigned to me in `reviewed`, `scheduled`, or `active`, broken down by each |
| My active consultations | Requests assigned to me with status `active` |

**Historical (date-filtered):**

| Metric | Definition |
|---|---|
| My reviewed requests | Initial requests assigned to me, submitted in the period |
| My completed | Requests assigned to me that reached completed |

> **Why "my reviewed requests" filters to *initial* requests only:** a nurse is
> recorded on a request when they claim it, and claiming always moves the
> request to `reviewed`. But when a follow-up consultation is created later, it
> **inherits** the nurse's ID from the parent request without that nurse ever
> reviewing anything. Counting follow-ups would inflate a nurse's review count
> with work they never did.

---

### 3.2 Physician dashboard

**Operational:**

| Metric | Definition |
|---|---|
| Active now | My requests with status `active` |
| Scheduled ahead | My requests with status `scheduled` |

**Historical (date-filtered):**

| Metric | Definition |
|---|---|
| Completed | My requests that reached completed |
| Completion rate | See §4 below |

> **Why the physician's numbers are scoped by the *request's* assigned
> physician, not the session's physician:** a request a physician **rejects**
> never gets a clinical session created. If we scoped on the session, every
> rejection would silently vanish from that physician's statistics — making
> the completion rate look better than reality. Scoping on the request keeps
> rejections in the denominator, where they belong.

---

### 3.3 Admin dashboard

**Operational — Service Health:**

| Metric | Definition |
|---|---|
| Total pending | All requests with status `pending` |
| Total active | All requests with status `active` |
| **In flight now** | All requests in `pending` + `reviewed` + `scheduled` + `active`, with a breakdown of each |

> **Why "In flight now" exists:** an earlier version showed only *pending* and
> *active*. That left requests sitting in `reviewed` (nurse claimed it, no
> physician yet) and `scheduled` (booked but not started) **completely
> invisible to the admin** — precisely the two stages where a request is most
> likely to get stuck. The metric now covers all four in-flight statuses. The
> total and the breakdown come from **one single database query**, so they can
> never contradict each other.

**Historical (date-filtered):** total requests, completed, concluded, and
completion rate — plus the symptom analytics described in §5.

---

## 4. Completion Rate — the formula, and why

```
                    completed
completion rate = ───────────── × 100
                    concluded

where concluded = completed + rejected + cancelled
```

**In-flight requests are excluded from both the top and the bottom.**

### Why not "completed ÷ all requests submitted"?

This was considered and **deliberately rejected**. Here is the problem, with a
worked example:

> Suppose 100 requests were submitted this month. 60 are completed, 10 were
> rejected, and **30 are still in progress** — perfectly normal, they were
> submitted recently.
>
> - Using *all requests*: 60 ÷ 100 = **60%**
> - Using *concluded only*: 60 ÷ 70 = **86%**

The 60% figure is misleading. Those 30 in-progress requests have not failed —
they simply have not finished yet. Including them punishes the clinic for
requests that arrived recently, which means the metric would **drop every time
you widen the filter toward the present day**. It would measure *how recently
you set the date filter*, not the quality of service.

By dividing only by requests that reached a final outcome, the rate answers a
clean question: **"of the cases that finished, what share finished
successfully?"**

### The zero-case

If nothing has concluded yet, dividing is impossible. The system returns
**no value**, and the dashboard displays **"—"**, never "0%". Showing 0% would
falsely imply total failure when the true answer is "we don't know yet".

### One subtlety about "completed"

A request counts as completed if **either** the request says `completed`
**or** its clinical session says `completed`. Both are written together in a
single transaction so in practice they always agree; the "either" rule exists
because the pre-existing consultation-history pages already used it, and
analytics must never disagree with the history page a user can open in the
next tab.

---

## 5. Symptom Analytics (Admin only)

Patients pick symptoms from a fixed list of **12 standardized options** —
Headache, Fever, Cough, Sore Throat, Body Pain, Fatigue, Nausea / Vomiting,
Diarrhea, Runny Nose, Shortness of Breath, Loss of Appetite, Abdominal Pain —
and may also type their own free-text symptom under "Others".

The admin dashboard reports:

- **Most reported symptoms** (top 10 standardized)
- **How often patients needed the "Others" free-text box** (a percentage)
- **Which custom terms recur** — subject to the privacy rule below
- **Severity distribution** across buckets 1–4

### Privacy rule: the k = 3 threshold

**A custom, free-text symptom term is only displayed if at least 3 different
requests reported it.** Terms reported once or twice are hidden, and only a
count of how many were suppressed is shown.

**Why:** free text is unpredictable. A patient may type something rare,
identifying, or deeply personal. If a term appears exactly once and an admin
can see it, that term is effectively attached to one identifiable person. The
threshold of 3 is a standard *k-anonymity* floor: any term shown is guaranteed
to be shared by at least three people, so it cannot single anyone out.

Standardized symptoms need no such protection — they come from a fixed
dropdown, so they reveal nothing a patient did not choose from a public list.

### How a symptom is classified (an important security fix)

The intake form sends a `custom: true/false` flag along with each symptom.
**Analytics ignores that flag entirely.** Classification is decided *only* by
whether the symptom name appears in the standardized list of 12.

**Why this matters:** the request payload is never validated field-by-field on
the server. If analytics trusted the flag, a patient could submit arbitrary
free text marked `custom: false`, and it would land in the standardized
bucket — which has **no k = 3 suppression**. Their private text would be
displayed to the admin even if only they had written it. By classifying on
name membership instead, unrecognized text is *always* treated as custom and
*always* protected, no matter what the client claims. This holds in both
directions and is covered by dedicated tests.

### Why follow-up requests are excluded from symptom analytics

When a follow-up consultation is created, it **copies the symptom list
verbatim** from the original request. Those are not a new patient report. If
they were counted, a single episode of "Fever" would be counted twice, three
times, or more — once for every follow-up that patient received — making
common conditions look artificially more common. Only initial requests are
counted.

### Why average severity is never reported

Every symptom starts at **severity 3 the moment it is selected**. A patient
who never touches the slider still submits a 3. So a "3" in the data may mean
"moderate" or may mean "didn't adjust it" — the two are indistinguishable.
Averaging that number would produce a confident-looking figure built on an
unknown mix of real answers and untouched defaults.

Instead, the dashboard shows the **distribution** of buckets 1–4 and visually
marks bucket 3 as the default value, so the reader can judge the data
themselves. This is an honest presentation of a limitation rather than a
statistic that hides it.

### Robustness

`symptoms_desc` is an unvalidated JSON column, so it may contain malformed
rows. The symptom analyser is written so that **it can never throw an
exception**: a bad row is skipped and tallied under `malformed_requests`. One
corrupt record can never take down the admin dashboard.

---

## 6. The date filter

Available presets: **Today, This Week, This Month, Last 30 Days (default),
This Year, Custom**.

| Rule | Behaviour |
|---|---|
| Invalid or unknown input | Falls back to Last 30 Days — never errors |
| Custom range longer than 730 days (2 years) | Clamped to 730 days |
| Start date after end date | Rejected, falls back to default |
| "Last 30 days" | Today **and** the previous 29 days = 30 calendar days |

**Why clamping instead of rejecting:** the range comes from the URL query
string, which anyone can edit. A request for a 50-year range would force the
database into an enormous scan. Clamping keeps the page responsive without
showing the user an error for something they may have done by accident.

**Why filtering is by *submission* date:** it is the only timestamp that
exists reliably on every request. There is no "claimed at" or "completed at"
column in the schema. The nurse's volume chart states this on the chart itself
rather than leaving the reader to assume otherwise.

---

## 7. How the code is organised (and why)

```
Consultation model  ──►  DashboardAnalyticsService  ──►  Controller  ──►  Blade view
  (definitions)            (composition)               (thin)         (display only)
```

**Layer 1 — Definitions live on the model as query scopes.**
`completed()`, `concluded()`, `inFlight()`, `initial()`, `highPriority()` and
others are each defined exactly once. Every metric composes these scopes.

*Why:* if "completed" were re-written by hand in the nurse dashboard, the
physician dashboard, and the admin dashboard, the three would eventually drift
apart and report different numbers for the same thing. One definition means
every dashboard mathematically agrees with every other by construction.

**Layer 2 — One service composes them.** `DashboardAnalyticsService` has one
method per role (`forNurse`, `forPhysician`, `forAdmin`), each returning the
same shape: `operational`, `period`, `charts`, `filters` (plus `symptoms` for
admin).

**Layer 3 — Controllers stay thin.** They authorise the user, build a date
range from the request, call one service method, and pass the result to the
view. No calculations.

**Layer 4 — Views display only.** No arithmetic in Blade templates. For
example, the admin's "In flight now" total is computed in the service and
passed down; the template does not add up the four statuses itself. A test
asserts there is no arithmetic in that template.

*Why this discipline:* a number computed in a template cannot be unit-tested,
cannot be reused, and cannot be verified by anyone reading the service. Keeping
all logic behind the service means every displayed figure is testable.

---

## 8. Charts

| Chart | Type | Note |
|---|---|---|
| Volume over time | Line | Daily buckets, **zero-filled** |
| Status distribution | Horizontal bar | All 7 meaningful statuses |
| Priority mix | Split bar | High vs Normal |
| Initial vs Follow-up | Split bar | Request type share |
| Most reported symptoms | Horizontal bar | Admin only, top 10 |
| Symptom severity | Bar | Admin only, bucket 3 marked as default |

**Zero-filling explained:** if no requests arrived on a Wednesday, the chart
shows Wednesday with a value of 0 rather than skipping that day. Omitting the
day would compress the time axis and make a quiet period look like a
continuous busy one.

**Why bars instead of a pie/donut for status:** with seven categories, human
readers cannot reliably compare pie slice angles. Bars share a common baseline,
so comparison is exact.

**Fallback for short ranges:** a trend line needs at least 4 data points to be
meaningful. If the selected range has fewer days, the dashboard shows a single
total figure and explains why, instead of drawing a two-point "trend" that
implies a pattern from almost no data.

### A security note on charts

Chart data is passed from PHP into JavaScript through an HTML attribute. Blade's
built-in `@json` helper was found to encode this **unsafely** in this context —
its output could break out of the surrounding attribute. The code uses
Laravel's `Js::encode()` instead, which always escapes the characters that
matter (`<`, `>`, `&`, quotes, apostrophes).

This is verified by **parsing the rendered HTML with a real DOM parser** and
confirming the data survives intact and no injected markup becomes a live
element — not merely by checking that a dangerous string is absent from the
output, which would be a much weaker test.

---

## 9. Testing

The analytics layer has **102 automated tests, all passing**:

| Test file | Tests | Covers |
|---|---|---|
| `ConsultationAnalyticsScopesTest` | 22 | Each scope's definition |
| `SymptomAnalyticsTest` | 20 | Aggregation, malformed data, k=3 |
| `DashboardAnalyticsServiceTest` | 15 | Per-role composition |
| `SymptomVocabularyTest` | 12 | Classification & privacy bypass |
| `DateRangeTest` | 10 | Presets, clamping, invalid input |
| `ChartPayloadEscapingTest` | 7 | Chart injection safety (DOM-parsed) |
| `AdminInFlightMetricTest` | 6 | In-flight total & breakdown |
| `DashboardControllerWiringTest` | 6 | Controller → service wiring |
| `DashboardViewRenderingTest` | 4 | Views render for each role |

The whole feature was built **test-first**: each test was written and watched
to fail before the code that satisfies it was written. This matters because a
test written *after* the code will pass immediately — which proves the test
runs, but never proves it can actually catch the bug it claims to guard.

Run them with:

```bash
vendor/bin/pest tests/Feature/Analytics/
```

---

## 10. Known limitations (state these before the panel finds them)

Naming a limitation yourself is stronger than being caught by it. Each of
these is a deliberate trade-off, not an oversight.

1. **No "time to completion" metric.** The schema has no `claimed_at` or
   `completed_at` timestamp, only `submitted_at`. Any duration figure would be
   an estimate presented as fact.
2. **Volume charts always use daily buckets**, even across a two-year custom
   range. Weekly or monthly rollups for wide ranges are a presentation
   improvement, not a correctness problem.
3. **No caching.** Every dashboard load queries live. This is correct for a
   clinic of this size and guarantees freshness; caching would be the first
   optimisation if the request table grew very large.
4. **The 12 standardized symptoms are hard-coded** to match the intake form.
   There is no database table for them, so the two lists must be kept in sync
   by hand. This is documented directly above the list in the code.
5. **Severity is reported as a distribution, never an average** — see §5.

---

## 11. Quick answers to likely defence questions

**"Why is your completion rate not just completed over total?"**
Because in-flight requests have not failed — they simply have not finished. See
the worked example in §4: including them makes the rate measure filter recency
rather than service quality.

**"What stops one patient's private free-text symptom from being displayed?"**
A k = 3 threshold: a custom term is only ever shown if at least three separate
requests contain it. And classification ignores the client's `custom` flag
entirely, so unrecognized text cannot be smuggled into the unprotected bucket.

**"How do you know the nurse, physician, and admin dashboards agree?"**
They do not each define their own metrics. All three compose the same query
scopes defined once on the model, so agreement is structural rather than
coincidental.

**"Why doesn't the date filter change the numbers at the top?"**
Because those are live operational counts. If filtering to "Today" hid a
request submitted last week that is still waiting, staff could believe the
queue was clear when it was not.

**"Is this tested?"**
102 automated tests covering the analytics layer, written test-first, all
passing — including tests specifically for privacy suppression and for
injection safety in chart data.

---

## Source files

| File | Role |
|---|---|
| `app/Models/Consultation.php` | Canonical metric definitions (query scopes) |
| `app/Services/DashboardAnalyticsService.php` | Per-role composition |
| `app/Services/SymptomAnalytics.php` | Symptom aggregation & privacy |
| `app/Support/DateRange.php` | Date filter resolution |
| `resources/views/components/dash/` | Reusable dashboard UI components |
| `resources/js/dashboards.js` | Chart.js rendering |
| `tests/Feature/Analytics/` | 102 tests |
