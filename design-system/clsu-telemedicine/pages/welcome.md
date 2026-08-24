# Page Override: Welcome / Landing

> Overrides `MASTER.md` for this page. MASTER's auto-generated match (query:
> "university infirmary telemedicine…") resolved to a **Research Lab / Portfolio
> Grid** pattern with academic serif typography (EB Garamond/Crimson Text) and a
> navy/gold palette — a mismatch for a healthcare service, and a different visual
> language than the rest of the app already uses. This override replaces those
> choices with tokens grounded in what the codebase already ships, per
> CLAUDE.md's "use existing brand colors if present" rule.

## Why these tokens (not MASTER's)

- **Color** — `green`/`emerald` (Tailwind defaults) are already the app's brand
  color: used in `layouts/guest.blade.php`, `auth/login`, `auth/register`, every
  role dashboard's action buttons, and the current welcome page. The official
  CLSU seal (`public/images/clsu_logo.png`) is also green + gold, confirming
  green as the correct institutional color rather than MASTER's navy. Neutrals
  stay `gray-*` (not `slate-*`) because that's what the rest of the app uses.
- **Typography** — `font-sans` → Figtree, loaded the same way `layouts/app.blade.php`
  and `layouts/guest.blade.php` already load it (bunny.net, weights 400/500/600).
  The current welcome page was the odd one out, hard-coding `font-['Inter']`;
  this override removes that drift instead of adding a third font family
  (MASTER's academic serif, or the search tool's separate "Corporate Trust"
  Lexend/Source Sans suggestion). One sans-serif system-wide.
- **Icons** — Heroicons outline, 24×24, `stroke-width="2"`, matching
  `layouts/navigation.blade.php` exactly (that file is the icon source of truth).
- **Hero visual** — no photo. `public/images/{img1.webp,slide1-3.jpg}` are stock
  flower/sunset photos with no relevance to a clinic and no available real
  photography of students/staff/CLSU infirmary spaces to use honestly. An
  abstract SVG panel (soft green surfaces + the seal + a status strip using the
  app's real request states: Pending → Reviewed → Connected) fits the brief's
  "abstract medical communication visualization" option without fabricating a
  photo of real people.

## Tokens used

| Role | Value | Notes |
|---|---|---|
| Primary | `green-700` / `green-600` | CTAs, links, icon accents |
| Primary hover | `green-800` | |
| Institutional accent (sparing) | `amber-500` | Echoes seal's gold; used only for the eyebrow rule/small dot, never as a button color |
| Neutral text | `gray-900` / `gray-600` | Headings / body |
| Border / surface | `gray-200` / `gray-50` / `white` | |
| Destructive | `red-*` | Unused on this page (no error states) |

- **Radius**: `rounded-lg` for buttons/cards/inputs, `rounded-full` only for
  icon badges and pills, `rounded-2xl` reserved for the single hero visual
  panel. No maximal-rounding.
- **Shadow**: `shadow-sm` resting, `shadow-md` on hover for interactive cards
  only — no `shadow-2xl`/glow, no glassmorphism.
- **Motion** (dial 3/10): 150–250ms `transition` on hover/focus states; one
  short fade/slide-up on hero content on load. All motion gated behind
  `prefers-reduced-motion`. No scroll-triggered animation library — the app has
  no GSAP dependency and one landing page doesn't justify adding one.
- **Spacing** (dial 3/10, spacious): section rhythm `py-20`/`py-24`, consistent
  `max-w-6xl` container matching the current welcome page.

## Section order

Nav → Hero (+ visual) → Trust indicators (3) → Core services (4) → How it
works (4 steps) → University identity strip → Entry points (sign in / create
account) → Footer.

Future pages: read this file's tokens before `MASTER.md`'s for anything
patient/staff-facing; `MASTER.md`'s navy/serif direction should not be reused
elsewhere in this app without a deliberate reason.

## Revision: section-level color (2026-08-24)

The first pass read as flat/white — plain-Tailwind `green-*`/`gray-*`
tokens, every content surface white. Revised to use color as a structural
tool, section by section, with the app's real `brand-*` tokens (now
registered in `tailwind.config.js`, see MASTER.md):

| Section | Background | Why |
|---|---|---|
| Nav | `white/95` (sticky, unchanged) | Chrome stays neutral and stable while the page scrolls under it. |
| Hero | `bg-gradient-to-br from-brand-green-soft via-white to-brand-gold-soft` | Reuses the exact wash already used for the patient dashboard's welcome banner (`patient/dashboard.blade.php`) — first-impression band carries visible brand color, tying this page to the authenticated app. |
| Trust indicators | `bg-brand-muted` | A settled, warm-neutral band (not stark white) for the "why trust this" content; icon chips are white cards on top so the copy stays high-contrast. |
| Core services | `bg-white` | Deliberate breather between two tinted bands. Service icons split 2 green / 2 gold — green for the two primary actions (request, chat), gold for the two secondary/status ones (follow-up, updates) — a real category distinction, not decoration. |
| How it works | `bg-brand-green-soft`, bordered | Own identity, breaking from the white services section. Step circles are solid `brand-green`; each step card gets a `border-t-2 border-brand-gold` accent line instead of a literal connecting line (cheaper, same effect at this variance level). |
| University identity | `bg-brand-gold-soft` | Short, centered band. Alternates green→gold against the section before it, echoing the CLSU seal's own green+gold pairing. |
| Entry points | `bg-white`, one card tinted | "Sign in" card stays neutral; "Create account" card is `bg-brand-green-soft` to visually lead toward the primary conversion action. |
| Footer | `bg-brand-green-deep` with `border-t-2 border-brand-gold` | Dark institutional bookend — closes the page on full brand color at high text contrast (white/`brand-green-soft` text on `#0a4d2d`), rather than another light-gray band. |

Net effect: roughly a third of the page's vertical area now carries visible
brand tint (hero, how-it-works, identity, footer), the rest stays white or
`brand-muted` neutral, and no single card is arbitrarily recolored — matches
the "colorful but controlled" bar without turning into a rainbow.
