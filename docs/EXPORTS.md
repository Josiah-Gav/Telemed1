# Export Feature

CSV and PDF export for the **dashboard analytics** and the **consultation history**
pages. This document explains what it does, how it is built, and — most
importantly — *why* each decision was made.

---

## 1. What it does

Five endpoints. Each one exports **exactly what the user is currently looking at**,
in either CSV or PDF.

| # | Route name | URL | Who |
|---|---|---|---|
| 1 | `nurse.dashboard.export` | `nurses/{nurse}/dashboard/export` | That nurse |
| 2 | `physician.dashboard.export` | `physicians/{physician}/dashboard/export` | That physician |
| 3 | `admin.dashboard.export` | `admin/dashboard/export` | Any admin |
| 4 | `consultations.history.export` | `consultations/history/export` | That patient |
| 5 | `physician.consultation_history.export` | `physicians/{physician}/consultation-history/export` | That physician |

All five accept `?format=csv` (default) or `?format=pdf`. Anything else returns
**422**. All five sit behind `auth` + `verified` middleware.

Users reach them through an **Export ▾** dropdown on the dashboard and history
pages, which offers *Export as CSV* and *Export as PDF*.

> **Not built:** nurse and admin *consultation history* export. Those pages don't
> exist in the app (the nurse history page is still a "coming soon" stub), so there
> is nothing to export. This was a deliberate scope decision, not an oversight.

---

## 2. Architecture

```
Authenticated User
       ↓
   Controller ──── authorizes, resolves filters, gets the user's name
       ↓
  Query / Analytics Service ──── fetches the data (unchanged, pre-existing)
       ↓
   Rows Mapper ──── turns data into rows (pure: no DB, no Auth, no HTTP)
       ↓
    ┌──┴──┐
   CSV   PDF
```

Four pieces:

| File | Job |
|---|---|
| `app/Support/CsvDownload.php` | The **only** way this app writes a CSV. Owns streaming, UTF‑8 BOM, and the formula‑injection guard. |
| `app/Services/Export/DashboardExportRows.php` | Turns the analytics array into export sections. Pure mapper. |
| `app/Services/Export/ConsultationHistoryRows.php` | Turns history records into export rows. Pure mapper. |
| `app/Services/Export/ConsultationHistoryQuery.php` | The shared filter→query logic used by **both** the HTML page and the export. |

**"Pure mapper"** means those classes never call `Auth`, `auth()`, `request()`, or
the database. They receive plain values from the controller and return arrays. This
is why they are trivially testable and why the same data can feed both CSV and PDF
without any chance of the two disagreeing.

---

## 3. The core rule: the export always matches the screen

This is the single most important design decision, and the most likely thing to be
asked about.

**Problem:** if the export runs its own query, it will eventually drift from what
the page shows. A user filters to "Last 7 Days", exports, and gets different rows.

**Solution:** the HTML page and the export call the *same* code.

- **History:** both `ConsultationController::history()` (the page) and
  `historyExport()` (the export) call `ConsultationHistoryQuery::normalizeFilters()`
  and `forPatient()`. Same for the physician side. There is one implementation, so
  drift is impossible by construction.
- **Dashboard:** both call `DashboardAnalyticsService` with the same `DateRange`.
- **UI links:** the Export dropdown builds its URL from the same `$filters` /
  `$dateRange` object the page itself renders from — so the link carries the
  filters currently on screen.

This is enforced by tests that hit the page and the export with an identical query
string and assert the results match.

### Two date-filter vocabularies (deliberate)

| | Dashboard | History |
|---|---|---|
| Parameter | `range`, `start`, `end` | `date_filter` |
| Values | today, this_week, this_month, last_30_days, this_year, custom | today, last_7_days, last_30_days, all |

These are **intentionally not unified.** They were separate before the export
feature existed. Merging them would change what the existing pages display, which
is out of scope for an export feature. History has no "custom" range at all —
that's why history filenames never show a date span.

---

## 4. Security

### Authorization

| Export | Rule |
|---|---|
| Patient history | Must be role `patient`; only ever sees `patient_id = own id` |
| Physician (both) | `authorizePhysician()` — must be role `physician` **and** the route's `{physician}` id must equal the logged-in id |
| Nurse dashboard | `authorizeNurse()` — same pattern |
| Admin dashboard | Must be role `admin` |

The authorization check is **always the first statement** in every export action,
before the format is even read. So `?format=pdf` cannot be used to slip past a
check that `?format=csv` would apply.

### CSV formula injection — the most important security control

A spreadsheet treats a cell starting with `=`, `+`, `-`, `@`, TAB, or CR as a
**formula**, not text. Patient-entered symptom names are free text (the app only
validates them as `required|string`), so a patient could name a symptom
`=cmd|'/C calc'!A0`. If that landed unguarded in a CSV, opening the file could
execute it.

`CsvDownload` prefixes any such cell with a single quote `'`, forcing it to render
as literal text. The guard lives **inside** `CsvDownload` and runs on every cell of
every row, so no call site can forget it. Verified: nothing outside `CsvDownload`
calls `fputcsv` anywhere in the app.

### Other protections

- **Identity is never taken from the request.** Role, name, and `Generated By` come
  from the authenticated user. `?name=X&role=admin&generated_by=Y` has no effect —
  there are tests for exactly this.
- **Filenames are server-generated.** No user input reaches the filename.
- **`no-store` headers** on every export, because the files contain PII.
- **No clinical fields** are ever exported (see §6).

---

## 5. CSV vs PDF

|  | CSV | PDF |
|---|---|---|
| Row limit | **None** | **500 rows** |
| Memory | Streams row-by-row | Whole document held in memory |
| Library | PHP built-in `fputcsv` | `barryvdh/laravel-dompdf` v3.1.2 |
| Use for | Complete data, analysis | Printing, submitting, reading |

**Why the PDF cap?** dompdf builds the entire document in memory before writing it.
An unbounded PDF is a real resource risk; an unbounded *streamed* CSV is not. When
a PDF is truncated it shows a visible warning telling the user to download the CSV
for the full list. The cap is applied **at rendering**, never inside the query — so
the 500 rows shown are genuinely the first 500 in the page's own sort order.

**Why no charts in the dashboard PDF?** The dashboard's chart component already
renders an accessible `<table>` of the same numbers. The PDF reuses that tabular
form. Drawing real charts would need a headless browser or a client-side
canvas→image round trip — heavy and fragile for a format nobody zooms into.

**Why DejaVu Sans in the PDFs?** dompdf's default fonts don't reliably carry the
em dash (—), which the reports use in titles and to mean "no value" (never `0`).

---

## 6. What is exported

### Dashboard
Everything the dashboard shows: the operational metrics (clearly labelled
**"Not Date-Filtered"**, because they're live state and ignore the date filter), the
period metrics, and every chart flattened into a table. Admin also gets the symptom
analytics, **including `suppressed_terms_count`** — the k=3 privacy suppression
carries through untouched, so suppressed terms are counted but never named.

### Patient history (11 columns)
`Record Type`, `Consultation Type`, `Concern Category`, `Status`, `Submitted At`,
`Completed At`, `Updated At`, `Assigned Nurse`, `Assigned Physician`, `Symptoms`,
`Rejection Reason`

The patient page merges two different record types — consultations *and* rejected
follow-up requests — so the CSV uses a **`Record Type` column** to distinguish them
in one file. Fields that don't apply to a row are left blank rather than guessed.

### Physician history (8 columns)
`Patient`, `Symptoms`, `Assigned Nurse`, `Consultation Type`, `Status`,
`Completed At`, `Updated At`, `Has Existing Follow-up`

### Never exported
`assessment`, `plan`, `recommendations`, `diagnosis`, prescription metadata, file
paths, attachments, Cloudinary URLs.

**Why?** The export mirrors what the history *page* shows. Those clinical fields
live behind the consultation messaging view, not the history list. Adding them
would be a new disclosure, not an export.

> Note: history exports are **row-level**, so the dashboard's k=3 symptom
> suppression does *not* apply. That rule exists to protect individuals inside an
> *aggregate*; a patient's own history legitimately contains their own symptoms.

---

## 7. File naming

```
<Role> <Full Name> <Timeline> Report.csv          ← dashboard
<Role> <Full Name> <Timeline> History Report.pdf  ← history
```

Examples:
```
Physician Juan Dela Cruz Last 30 Days Report.csv
Nurse Maria Santos This Month Report.pdf
Physician Juan Dela Cruz Aug 01 2026 - Aug 27 2026 Report.pdf
Patient Juan Dela Cruz Last 30 Days History Report.csv
```

- **Name** = `first_name + last_name` from the authenticated user (this app has no
  `name` column — names are always stored split).
- **Timeline** = the *resolved* filter, reusing the same wording the filter
  dropdown shows. Custom ranges become `Aug 01 2026 - Aug 27 2026`.
- Characters unsafe in filenames (`/ : \ * ? " < > |`) are replaced.
- The same string is used as the visible report title inside the file.

Inside the file, a metadata block still records `Role`, `Generated By`, the active
filters, and the generation timestamp.

---

## 8. Testing

**264 tests** cover the export subsystem.

The approach worth explaining:

- **Filter parity tests** hit the page and the export with the same query string and
  assert they agree. This is what mechanically guarantees §3.
- **Characterization tests** were written *before* refactoring the history
  controllers, capturing the existing behaviour exactly. They passed before and
  after the refactor, unchanged — that is the proof nothing broke.
- **PDF content** is asserted against the rendered Blade view rather than the PDF
  bytes, because dompdf compresses its text streams. The endpoints are still tested
  end-to-end for status, content type, and `%PDF` magic bytes.
- **Malformed data**: `symptoms_desc` is unvalidated JSON, so exports are tested
  against `null`, plain strings, arrays of strings, and objects missing `name`. They
  must never throw.

---

## 9. Known limits (honest list)

1. **Rows are loaded into memory before CSV streaming.** The CSV *response* streams,
   but the source rows are fetched with `->get()` first — required because the
   patient export merges two models and sorts across them. Bounded to one user's own
   history, so acceptable here; would need revisiting for a system-wide export.
2. **PDF is capped at 500 rows.** By design (§5), with a visible warning.
3. **`ConsultationHistoryQuery` lives in the `Export` namespace** but is now also
   used by the HTML pages. The name understates its role; renaming was deferred to
   avoid a wide diff.
4. **A `whereNull('type')` branch is kept** in the history query for legacy rows,
   even though the column is `NOT NULL` today. Preserved deliberately so the
   refactor changed no behaviour; locked by a SQL-level test.

---

## 10. Quick answers for common questions

**"How do you know the export matches the screen?"**
They call the same query service, and tests assert identical results for identical
query strings.

**"What stops a user exporting someone else's data?"**
Authorization runs first in every action, and the query is scoped to the
authenticated user's own id. Route parameters are checked against the session, not
trusted.

**"What is CSV injection and how did you handle it?"**
A spreadsheet executes cells starting with `= + - @`. Patient symptom text is free
text, so it's guarded centrally in `CsvDownload` by prefixing a quote.

**"Why can PDF only show 500 rows?"**
dompdf holds the whole document in memory. CSV streams and stays unlimited; the PDF
warns and points to CSV.

**"Why two different date filters?"**
They pre-date this feature and belong to two different pages. Unifying them would
change existing behaviour, which an export feature has no business doing.
