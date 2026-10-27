# AGENTS.md — Jhunlea Photography & Printing System

> Main guide for every developer and AI coding agent (Claude Code, etc.) working on this repository.
> Read this whole file before writing code. Keep it updated at the end of every phase.

- Full requirements: `docs/SYSTEM_SPEC.md` (source of truth for business rules)
- Phase plan and task split: `docs/DEVELOPMENT_PLAN.md`
- Git workflow: `docs/GIT_WORKFLOW.md`
- UI designs (Google Stitch exports): `design/` (each screen has `code.html` + `screen.png`; design tokens in `design/*/studio_atelier/DESIGN.md`)

---

## 1. Current status

| Phase | Name | Owner | Status |
|---|---|---|---|
| 0 | Repo cleanup and docs | Dev (Her) | Done (Bootstrap removed, Tailwind 4 setup) |
| 1 | Foundation: design system, layouts, authentication | Dev (Her) | Done (components, layouts, auth, roles, error pages) |
| 2 | Catalog: services, packages, themes, public pages | Dev (Her) | In progress: 2A services and 2B packages, gallery and themes built (owner side); 2C public pages not started |
| 3 | Printing orders | Dev (Her) | Not started |
| 4 | Photography bookings and schedule | Dev (Her) | Not started |
| 5 | Payments, notifications, dashboards | BF | Not started |
| 6 | Customers, reports, settings, profile | BF | Not started |
| 7 | AI customer assistant | BF | Not started |
| 8 | Testing, mobile pass, animation polish, deployment | BF (Her reviews) | Not started |

Update this table when a phase is approved and merged into `main`.

---

## 2. Project summary

A web system for **Jhunlea Photography & Printing** (Philippines). It handles printing orders (with file uploads), photography package browsing and bookings (with optional photo printing), schedule confirmation, request tracking with reference numbers, **face-to-face** payment recording, owner management tools, and a restricted AI customer assistant.

This is a **new system built from scratch**. Only build from the spec and from files that really exist. Never claim a file, table, route or feature exists unless you have checked it.

---

## 3. Tech stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Views | Blade templates + Blade components (`resources/views/components`) |
| CSS | **Tailwind CSS 4** via `@tailwindcss/vite`, matching the Stitch designs. **Do not use Bootstrap.** |
| JS | Vanilla JS modules via Vite. Alpine.js allowed for small interactivity (dropdowns, modals, wizard steps). GSAP for animations. |
| Icons | Material Symbols Outlined (same as the Stitch designs) |
| Fonts | Playfair Display (public headings), Plus Jakarta Sans (UI), JetBrains Mono (reference numbers, specs) |
| Database | MySQL (XAMPP), database `jhunlea` |
| Auth | Laravel built-in auth (session based), hand-made Blade views |
| Files | Private uploads on the `local` disk (`storage/app/private`), served only through authorized controller routes |
| Tests | PHPUnit (`php artisan test`) |
| Environment | Windows, XAMPP, VS Code, Claude Code, GitHub |

The Stitch HTML loads Tailwind from a CDN with an inline config. **Do not copy the CDN script.** Move the colors, fonts, radius and spacing into `resources/css/app.css` with the Tailwind 4 `@theme` block, and convert the screens into Blade layouts and components.

---

## 4. Commands

```bash
# first-time setup (after clone)
composer install
copy .env.example .env        # Windows (use cp on Git Bash)
php artisan key:generate
# create the "jhunlea" database in phpMyAdmin first
php artisan migrate --seed    # the seeder creates no users
php artisan jhunlea:create-owner   # asks for name, email and a hidden password; run it once to create the owner
npm install

# daily development (two terminals)
php artisan serve             # http://localhost:8000
npm run dev                   # Vite hot reload

# after pulling someone else's phase
composer install
npm install
php artisan migrate           # never migrate:fresh on a database that holds real data

# quality
php artisan test              # in-memory sqlite: needs pdo_sqlite and sqlite3 enabled in php.ini
./vendor/bin/pint --test      # code style check (./vendor/bin/pint fixes it)
npm run build                 # must exit 0
```

`php artisan migrate:fresh --seed` wipes the database. Use it only on a throwaway local database, never on one that has real data.

---

## 5. Folder conventions

```
app/
  Enums/                 PrintingOrderStatus, BookingStatus, PaymentStatus, UserRole ...
  Http/Controllers/
    Public/              landing, services, packages (no login)
    Auth/                login, register, password reset
    Customer/            customer-only controllers
    Owner/               owner-only controllers
  Http/Middleware/       EnsureRole
  Http/Requests/         Form Request validation classes (one per form)
  Models/
  Policies/              record-level access (customer owns record)
  Services/              business logic: ReferenceNumberService, PricingService,
                         ScheduleConflictService, PaymentService, StatusTransitionService
resources/views/
  layouts/               public, auth, customer, owner
  components/            ui/* (button, badge, card, input, modal, toast, empty-state, stepper, timeline)
  public/ auth/ customer/ owner/ errors/
routes/web.php           grouped: public, auth, customer (prefix /my), owner (prefix /owner)
design/                  Stitch reference only; never served to users
```

Rules:
- Controllers stay thin: validate with a Form Request, authorize with a Policy, call a Service, return a view or redirect.
- No business logic or database queries inside Blade files.
- Use Enums for statuses and roles, never raw strings scattered in code.
- Reusable UI goes into Blade components; do not copy the same markup across pages.

---

## 6. Roles and access (exactly two roles)

| Role | Value | Can do |
|---|---|---|
| Print Shop Owner | `owner` | Manage services, packages, themes, schedule, all orders and bookings, prices, payments, customers, reports, settings |
| Customer | `customer` | Browse, submit printing orders, request bookings, see **only their own** requests and files, use the assistant, edit own profile and appearance |

- Public registration always creates `customer`. The owner account is created only with `php artisan jhunlea:create-owner` (hidden password prompt, refuses an existing email, asks for confirmation if an owner already exists). There is no setup screen.
- `users.role` is cast to `App\Enums\UserRole` and is **never mass-assignable**; server code sets it directly.
- Enforce access on the server: the `role` middleware alias (`role:owner`, `role:customer`, class `App\Http\Middleware\EnsureRole`) for route groups and Policies for every record and file. Guests go to login, a signed-in user with the wrong role gets 403. Hiding buttons is not security.
- Route groups: `auth` + `role:customer` for `/my/*`, `auth` + `role:owner` for `/owner/*`. After login, owner goes to `/owner` and customer to `/my/dashboard` (`UserRole::home()`).
- Customers can never: set owner-controlled statuses, set final prices, confirm their own bookings, or record payments.
- No Super Admin, Staff or other roles unless the team explicitly approves.

---

## 7. Business rules (must never break)

1. Submitting a request does not mean it is accepted.
2. A requested timeslot is not a confirmed appointment. Store and show **Requested** and **Confirmed** separately.
3. Show **Estimated** price and **Confirmed** price separately.
4. Payments are face-to-face only. The system records payments; it never processes them online. No checkout, no gateways.
5. Booking status, printing production status and payment status are separate fields.
6. A booking can be completed while its prints are still in production.
7. Customers see only their own records and files.
8. AI recommendations are suggestions, not bookings or quotations.
9. The chatbot uses only data configured in the system; it never invents prices, inclusions, schedules or policies.
10. Every status change is saved in the status history (who, when, from, to, note).
11. Never mark something as paid automatically. Only a recorded payment changes the paid amount.
12. Never charge printing twice when prints are linked to a booking.

Standard notice text: "Payment is made face-to-face at Jhunlea Photography & Printing."

---

## 8. Status models

**Printing order:** `submitted` → `under_review` → `accepted` | `rejected` → `processing` → `ready_for_pickup` → `completed`. Also `cancelled`. Owner can request more information while in review (`needs_info` flag + message). Rejection requires a reason.

**Photography booking:** `submitted`, `under_review`, `awaiting_customer_info`, `awaiting_schedule_confirmation`, `confirmed`, `reschedule_proposed`, `rejected`, `cancelled`, `photoshoot_completed`.

**Print production for a booking (only if prints were requested):** uses the printing order statuses on a linked printing order.

**Payment status (computed):** `unpaid` (paid = 0), `partially_paid` (0 < paid < total), `paid` (paid >= total). `refunded` only if refunds are implemented later.

Allowed transitions live in one place (`StatusTransitionService` or the Enum) and are validated on the server.

---

## 9. Database plan (draft — confirm in Phase 1, extend per phase)

Money columns: `decimal(10,2)`. Never use float. Totals and balances are calculated on the backend.

| Table | Key columns | Phase |
|---|---|---|
| `users` | name, email, phone, password, `role` (owner/customer), `theme` (light/dark/system) | 1 |
| `printing_services` | name, slug, description, category, pricing_type (per_page/per_copy/quotation), base_price null, allowed_file_types json, max_file_size_mb, instructions, is_published, sort_order | 2 |
| `printing_service_options` | printing_service_id FK, group (paper_size/paper_type/color_mode/sides/print_size/finish), label, price_modifier, is_active | 2 |
| `photography_packages` | name, slug, category, short_description, description, price null, price_requires_confirmation, duration_minutes, max_people, digital_outputs, inclusions json, terms, is_published, is_featured | 2 |
| `package_images` | photography_package_id FK, path, is_cover, sort_order | 2 |
| `themes` | name, description, image_path, color_swatches json, is_published | 2 |
| `package_theme` | pivot | 2 |
| `printing_orders` | reference_no unique (PR-000001), customer_id FK, printing_service_id FK, photography_booking_id null FK, status, specs json, special_instructions, estimated_total, confirmed_total null, needs_info, rejection_reason, customer_remarks, internal_notes, timestamps | 3 |
| `order_files` | printing_order_id FK, path, original_name, mime, size_bytes, page_count null, width null, height null | 3 |
| `status_histories` | morphs trackable, from_status, to_status, note, changed_by FK, created_at | 3 |
| `photography_bookings` | reference_no unique (PB-000001), customer_id FK, photography_package_id null FK, status, contact_number, people_count, venue, notes, theme_id null, custom_theme, background, color_motif, outfit_notes, requested_start_at, confirmed_start_at null, confirmed_end_at null, proposed_start_at null, photography_fee_estimate, photography_fee_confirmed null, wants_prints, rejection_reason, customer_remarks, internal_notes | 4 |
| `booking_reference_images` | photography_booking_id FK, path, original_name | 4 |
| `availability_rules` | weekday, is_open, open_time, close_time, slot_minutes | 4 |
| `blocked_dates` | date, reason | 4 |
| `payments` | morphs payable (printing order or booking), amount, method, received_at, recorded_by FK, receipt_reference, note | 5 |
| `notifications` | Laravel database notifications table | 5 |
| `faqs` | question, answer, is_active, sort_order | 6 |
| `settings` | key unique, value json (business info, hours, booking rules, upload rules) | 6 |
| `chat_sessions`, `chat_messages` | optional, only if chat history is stored | 7 |

Decision to confirm: when prints are linked to a booking, the linked printing order is billed **under the booking** (payments are recorded on the booking, and the print order shows "Billed with PB-xxxx"). This prevents double charges.

Reference numbers are generated inside a database transaction so two requests can never get the same number.

---

## 10. Route plan (summary)

| Area | Prefix | Middleware | Examples |
|---|---|---|---|
| Public | `/` | none | `/`, `/services`, `/packages`, `/packages/{slug}`, `/packages/compare`, `/package-finder` |
| Auth | `/` | guest | `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` |
| Customer | `/my` | auth, role:customer | `/my/dashboard`, `/my/printing/new`, `/my/printing/{order}`, `/my/bookings/new`, `/my/bookings/{booking}`, `/my/history`, `/my/notifications`, `/my/settings` |
| Owner | `/owner` | auth, role:owner | `/owner`, `/owner/printing-orders`, `/owner/bookings`, `/owner/services`, `/owner/packages`, `/owner/themes`, `/owner/schedule`, `/owner/customers`, `/owner/payments`, `/owner/reports`, `/owner/settings` |
| Files | `/files/{file}` | auth + policy | streams private files after an ownership check |
| AI | `/assistant/messages` | auth (or throttled guest), throttle | POST only, server-side API key |

After login: owner → `/owner`, customer → `/my/dashboard`.

---

## 11. Design system (from `design/*/studio_atelier/DESIGN.md`)

- Colors: accent red `#C62828` (hover `#B71C1C`), ink `#171717`, white `#FFFFFF`, surface `#FBFBFB`, card `#F5F5F5`, border `#E5E5E5`, text secondary `#525252`, muted `#A3A3A3`.
- Status pills (text / background): amber `#D97706/#FEF3C7` under review, blue `#2563EB/#EFF6FF` awaiting schedule, violet `#7C3AED/#F5F3FF` processing, green `#16A34A/#DCFCE7` ready/confirmed/paid, gray `#4B5563/#F3F4F6` completed/cancelled, red `#DC2626/#FEE2E2` rejected/unpaid.
- Radius: cards 12px, buttons and inputs 10px, pills full. Buttons 44px tall (compact 36px). Inputs 44px with red focus ring `0 0 0 3px rgba(198,40,40,.12)`.
- Layout: max width 1280px, 12-column grid desktop, 8 tablet, 4 mobile.
- Elevation: thin borders + very soft shadows (levels 0–3 in DESIGN.md).
- Glassmorphism only on: sticky public header after scroll, login/register panel, modals, chatbot window. Blur 12–16px with semi-solid fill.
- Dark mode: class-based (`dark` on `<html>`), saved per user (`users.theme`) and in localStorage for guests. Every component must support both themes.
- Motion: card lift `translateY(-3px)` + image `scale(1.03)` over 280ms `cubic-bezier(0.16,1,0.3,1)`; red fill sweep on primary buttons; aperture spinner for loading; scroll reveal, count-up numbers, timeline line draw, toast slide-in. Owner pages use calmer motion. Always respect `prefers-reduced-motion`.
- Copy: simple, friendly English. The Stitch screens contain invented details (camera models, "Atelier", fake specs, prices, addresses). **Never hard-code them.** Real business content comes from the database and settings; seeders use clearly labelled sample data.
- Currency: `₱1,250.00` with tabular numbers.
- Never leave buttons or links that do nothing. If a feature is not built yet, do not show its button.

### Designs are the visual and motion source of truth

- The screens in `design/` (`screen.png` + `code.html`) are the visual **and animation** reference. Match layout, spacing, colors, type, effects and motion closely; read the `@keyframes`/transitions in `code.html` and reproduce the same timing and easing with CSS and small Alpine code (no GSAP until Phase 8).
- Method per screen: read the design, list the differences, rebuild with components and `@theme` tokens, compare at the design's viewport, then check the other viewports and theme. Record MATCH / CLOSE / DIFFERENT.
- Never copy: the Tailwind CDN script, hotlinked image URLs, invented data (camera specs, addresses, calibration IDs, fake statuses), or controls for features the system does not have (design-tool preview switchers, order tracking by slip, terms pages, etc.).
- Project rules win over the design: 44px tap targets on phones, contrast, visible focus, reduced motion, light and dark, no horizontal scroll.
- Images saved in `public/images/design-placeholders/` are **temporary Stitch placeholders**. Replace them with the shop's real photos before going live.
- Done so far: login family and page loader, error pages, the public, customer and owner shells, the owner services screens, and the `ui` components (DESIGN.md values for buttons, cards, modal, pills, notice, checkbox). Remaining differences are listed in the fidelity report.

### UI building blocks (built in Phase 1)

- **Components** live in `resources/views/components/ui/` (use as `<x-ui.name>`): logo, icon, button, input, select, textarea, checkbox, field, badge, status-badge, card, notice, modal, toast, empty-state, stepper, timeline, aperture-spinner, page-loader, theme-toggle. The page shells use `resources/views/components/layout/` (skip-link, nav, drawer, user-menu, error-card). Reuse them; do not copy markup.
- **`/ui-kit`** is a developer gallery of every component in every state. It returns 404 unless `APP_ENV=local`. Check it when you change a component.
- **Layouts** are `layouts/app` (base), `layouts/public`, `layouts/auth`, `layouts/customer` and `layouts/owner`. Pages fill the `main` section (the `auth` section for auth pages). To add a navigation item, add one line (label, icon, route name) to the `$nav` array at the top of the layout file, and only when the route exists.
- **`<body x-data>` must stay in `layouts/app`.** Modals, drawers and toasts are opened from buttons through Alpine events, which only work inside an Alpine scope. Every layout must also include `<x-ui.toast />`.
- **Tailwind scans only `resources/views` and `resources/js`** (see `@source` in `resources/css/app.css`). A class used anywhere else (for example in `design/` or a PHP string outside views) will not be generated. Write full class names, never built-up strings like `'bg-'.$color`.
- **Error pages** are in `resources/views/errors/` (403, 404, 419 use the public layout; 500 is fully static on purpose, with no layout, session or database).
- **Every phase includes backend + tests + browser QA** at 360x640, 390x844, 768x1024, 1024x768, 1280x800, 1440x900 and 1920x1080, each in light and dark mode: no horizontal scroll, nothing clipped, tap targets of at least 44px on phones.

---

## 12. Security checklist

- Hash passwords with Laravel's default hasher. Use Eloquent / query builder only (no raw string SQL).
- CSRF on every form (`@csrf`). Escape output with `{{ }}`; avoid `{!! !!}` for user data.
- Validate every input in Form Requests, including prices, statuses and IDs sent from the browser.
- Uploads: check extension, MIME and size on the server; store with generated names in private storage; never execute; serve via an authorized route.
- Policies on every customer record and file (prevent IDOR).
- Rate limit login, uploads and the AI endpoint.
- Secrets only in `.env`. Never commit `.env`. Never expose API keys in JS.
- Customer-facing errors never show stack traces (`APP_DEBUG=false` in production).

---

## 13. AI assistant rules (Phase 7)

- Provider chosen in Phase 7 after checking current free tiers (Gemini or Groq). Key in `.env` (`AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL`).
- Server builds the prompt from published services, packages, themes, FAQs and settings. The model never receives other customers' data or uploaded files.
- Topic restriction is enforced in application code (a classifier/keyword gate before calling the model), not only in the prompt. Off-topic reply: "I'm here to help with Jhunlea's photography and printing services. I can help you explore packages, printing options, booking requirements, and request updates. What service are you interested in?"
- The assistant cannot confirm bookings, change prices or statuses, or record payments.
- Missing key or provider error → friendly fallback message and a link to contact the owner; the app keeps working.

---

## 14. Definition of done (every phase)

- [ ] All screens in the phase scope work end to end with real database data (no fake counts, no dead buttons).
- [ ] Server-side validation and authorization for every action.
- [ ] Migrations run cleanly: `php artisan migrate` (and from scratch on a throwaway database).
- [ ] Feature tests for the main success path, validation errors, and access control.
- [ ] `php artisan test` passes; `./vendor/bin/pint --test` passes; `npm run build` exits 0.
- [ ] Browser QA at the 7 viewports in light and dark mode; desktop layout matches the design; mobile not broken.
- [ ] This file's status table and `docs/DEVELOPMENT_PLAN.md` checklist updated.
- [ ] Reviewed and approved by Claude in the chat, then merged to `main` by the developer (`docs/GIT_WORKFLOW.md`, section F).

---

## 15. Rules for AI coding agents

### Git rules for agents

- The developer handles ALL git write operations herself: creating branches, `git add`, `git commit`, `git push`, `git pull`, merging and untracking files. Claude Code (the agent) only writes code, runs tests, and reports.
- Read-only git is allowed: `git status`, `git diff`, `git log`, `git branch --show-current`, `git check-ignore`.
- Never run: `git add`, `commit`, `checkout`, `switch`, `merge`, `rebase`, `reset`, `stash`, `push`, `pull`, `fetch`, `rm`, or anything that changes history or the working branch.
- At the start of every task run `git branch --show-current`. If it is `main` or not the branch the developer named, stop, edit nothing, and tell her.
- Workflow: one branch per phase, reviewed by Claude in the chat; Claude's approval is final (no partner review, no Pull Request). After approval the developer merges the branch into `main` with `git merge --ff-only` and the next phase starts from that `main`. Large phases may be split into parts on the same branch with backup pushes. See `docs/GIT_WORKFLOW.md`.
- Never run `php artisan migrate:fresh`, `migrate:reset` or `db:wipe`, and never delete the developer's real accounts or data. Create your own QA users and delete only those.

### General rules

- Work only inside the current phase scope. Ask before adding features from later phases.
- Before editing a file, read it. Before adding a table, check existing migrations.
- Use the matching `design/<area>/<screen>/screen.png` and `code.html` as the visual reference, converted to Blade + Tailwind 4 + components.
- Prefer small, reviewable commits with clear messages.
- Report honestly at the end: what is done, what was tested, what is missing.
- Do not install Laravel Boost or other packages without the team's approval.
