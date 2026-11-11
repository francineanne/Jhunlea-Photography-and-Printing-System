# AGENTS.md — Jhunlea Photography & Printing System

> Main guide for every developer and AI coding agent (Claude Code, etc.) working on this repository.
> Read this whole file before writing code. Keep it updated at the end of every phase.

- Full requirements: `docs/SYSTEM_SPEC.md` (source of truth for business rules)
- Phase plan and task split: `docs/DEVELOPMENT_PLAN.md`
- Git workflow: `docs/GIT_WORKFLOW.md`
- XAMPP setup (certificates for the AI provider, memory and upload limits for the tests): `docs/XAMPP_SETUP.md`
- UI designs (Google Stitch exports): `design/` (each screen has `code.html` + `screen.png`; design tokens in `design/*/studio_atelier/DESIGN.md`)

---

## 1. Current status

| Phase | Name | Owner | Status |
|---|---|---|---|
| 0 | Repo cleanup and docs | Dev (Her) | Done (Bootstrap removed, Tailwind 4 setup) |
| 1 | Foundation: design system, layouts, authentication | Dev (Her) | Done (components, layouts, auth, roles, error pages) |
| 2 | Catalog: services, packages, themes, public pages | Dev (Her) | Done (approved and merged into main): 2A services, 2B packages, gallery and themes (owner side), 2C public pages (landing, services, packages with filters, detail, compare, finder). Online request and booking buttons come in Phases 3 and 4. |
| 3 | Printing orders | Dev (Her) | Done (approved and merged into main): 3A data and logic, 3B customer wizard, 3C order lists and details (customer and owner), owner actions through StatusTransitionService, protected file download. |
| 4 | Photography bookings and schedule | Dev (Her) | Done (4A to 4D merged into main): booking wizard, owner booking pages, schedule calendar and editor, prints after the shoot. |
| 5 | Payments, notifications, dashboards | BF | Done (approved and merged into main): 5A payments and 5B notifications committed; 5C customer dashboard, owner overview, combined request history (/my/requests), shop-cancel notifications. The partial-payment setting stays cancelled. |
| 6 | Customers, reports, settings, profile | BF | Approved (Her merges phase-6-admin into main): 6A customers directory, customer detail and reports, and 6B settings, upload limits, FAQ editor and public /faq committed; 6C account page (/my/account and /owner/account: profile, password, appearance) built. |
| 7 | Chat and AI assistant "Lumi" | BF | Done (merged into main): chat, Ask Lumi, studio chat, owner assist, the owner's Ask Lumi and the evaluation harness (`php artisan assistant:eval`, `docs/AI_EVALUATION.md`). |
| 8 | Walk-in logging, counter, logbook and income reports | Her | In progress: 8A walk-in foundation (walk-in customers without login, `source` on orders and bookings, owner-made walk-in orders paid at creation and walk-in bookings, badges and filters) built, waiting for review; its two migrations must be run. 8B counter in Payments (record payment modal, payment receipt, ready for pickup and awaiting payment queues, ledger today by default; no migration) built, waiting for review. 8C income report (`/owner/reports/income`: daily, weekly, monthly, custom, all time, charts, end-of-day summary, CSV; one migration `report_view` on users) committed. 8D owner Ask Lumi reads walk-ins, the counter and the income report (no migration) built, waiting for review and the live owner eval. |
| 9 | Testing, mobile pass, animation polish, deployment | Her | Not started |

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

### Running the tests safely

The tests wipe and rebuild every table they use (`RefreshDatabase`). They must only ever run on an in-memory SQLite database, never on the `jhunlea` MySQL database. This once went wrong: a terminal had the `.env` values exported as environment variables, and the tests ran against the real database.

- Run the tests in a **clean terminal**. Never export or load `.env` values into the shell (no `set`/`$env:` of `APP_`, `DB_`, `CACHE_`, `SESSION_` ... values, and no tool that loads `.env` into the terminal). Laravel reads `.env` by itself.
- Check before running: `Get-ChildItem Env: | Where-Object { $_.Name -match '^(APP_|DB_|CACHE_|SESSION_|MAIL_|QUEUE_)' }` (PowerShell) must show **nothing**. A terminal that shows any of them is not safe; close it and open a new one. If they come back in every new terminal, remove them from Windows "Edit the system environment variables" (User and System).
- Two layers protect the database. `phpunit.xml` forces the test values (as `force="true"` env entries **and** `<server>` entries, because Laravel reads `$_SERVER` first). `tests/Support/TestDatabaseGuard.php` checks the result in `tests/TestCase.php::createApplication()`, which runs before `RefreshDatabase`.
- If you see **"Refusing to run tests: this would touch the database ..."**, the guard stopped the run before anything was touched. Do not work around it: open a clean terminal. Every feature test must extend `Tests\TestCase` and must never override `createApplication()`; a unit test checks this.

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

**Money wording (Phase 6A):** only "Recorded payments" (what the shop actually received; voided ones never count; `PaymentLedgerService::totals()`) and "Awaiting payment" (a confirmed price with no recorded payment; `awaitingOrders()`/`awaitingBookings()`). Until Phase 8: never "revenue", "sales", "profit" or "income", and no average, growth or comparison figures. **From Phase 8 (walk-in logging and income reports, see `docs/DEVELOPMENT_PLAN.md`): the owner reports may say "Income", which is exactly the sum of recorded, non-voided payments in the chosen Manila days, and may show the average per transaction and a comparison with the previous period. "Revenue", "sales" and "profit" stay forbidden (the shop does not track costs); the quick counter flow is called "Quick walk-in"; voided payments stay visible with their reason but never count; estimates are never counted; "Awaiting payment" is never added into income. Update any test or view text that still bans these words when Phase 8 is built.** Otherwise the two figures are never added into one total. The reports page (`/owner/reports`, `ReportService`) counts requests CREATED and payments RECEIVED in a period of Manila days; its "Awaiting payment right now" is not limited to the period. The customer directory (`/owner/customers`, `CustomerDirectoryService`) lists customers only (an owner's id answers 404) and is read only. Notification preferences are cancelled (in-app only, no email or SMS channel). Hours and booking rules stay in the Schedule editor; Phase 6B settings only link to it.

**Settings, upload limits and FAQ (Phase 6B):** `App\Support\ShopSettings` is the ONE class that reads the business info and the six upload limits (`printing_max_file_mb`, `printing_max_total_mb`, `printing_max_files`, `booking_max_image_mb`, `booking_max_images`, `booking_max_total_mb`): the owner's saved value in `shop_settings` (one row, id 1, written only by `ShopSettingsService` under a row lock), else the starting default in config, and never more than `App\Support\ServerUploadLimits` allows (per file: upload_max_filesize; per order total: post_max_size minus 2 MB; count: max_file_uploads; 0, -1 or unreadable means a fixed ceiling of 100 MB / 50 files). A value above the server's maximum is capped when read and the Settings page says so. Never read `config('printing.max_*')` or `config('booking.max_image*')` anywhere else (a test fails if you do). The accepted file TYPES stay fixed in config. The Settings page only SHOWS the opening hours and booking rules (the Schedule editor changes them) and states the fixed payment rules without switches. The public footer shows the shop's address, phone and email only when set, and the FAQ link only when a question is published. `faqs` positions are kept 1..N by `FaqService` (the only writer) under the same settings row lock. **Phase 7 (the assistant) will read the published FAQs from the `faqs` table.**

**Walk-in foundation (Phase 8A):** a walk-in customer is an ordinary `users` row with `role = customer`, `is_walk_in = true`, a name, an optional phone (registration's own rule) and NO email or password (both columns are nullable since 8A; the unique index on email stays, NULLs are allowed). A walk-in can never sign in or reset a password (`LoginRequest`, the reset broker and `NewPasswordController` all add `is_walk_in = false`) and is never notified (`NotificationDispatcher` skips them in its one recipient query). `is_walk_in` is never fillable; `WalkInCustomerService` creates them, and the counter forms search existing customers (name or phone) before adding one. Every printing order and photography booking has `source` (`App\Enums\RecordSource`: `online`, the default and every older row, or `walk_in`), never fillable, shown as a "Walk-in" badge (`<x-walk-in.badge>`) on the owner lists, details, the schedule calendar and the overview, and filterable on both lists (`?source=`) and in the customer directory (`?type=walk_in|online`). Two services do the counter work so Phase 8B can call them inside its own transaction: `App\Services\WalkIn\WalkInOrderService::place()` (a walk-in printing order is NEVER saved unpaid: the order, source walk_in, created Accepted with its confirmed price and its first history row "Created at the counter", its optional files, its full payment through `PaymentService::record()` and then the usual owner move Accepted -> Processing ("Paid at the counter; printing starts.") are one transaction; the price is the catalog price from PricingService or one the owner types, kept with who set it and why in `snapshot.counter` and in the history note) and `WalkInBookingService::place()` (status Confirmed, under the same schedule lock as `confirm()`; a clash is never allowed, a passed time is never allowed, the minimum notice and the advance window do not apply at the counter, opening hours and blocked dates apply unless "confirm anyway"; the price is optional and the booking is paid on the day with the usual flow). Each counter form carries a one-time `counter_token` (unique column on both tables), so a double click or a retry saves one record; a new walk-in customer is created in the same transaction, so a refused form leaves no stray customer. Routes: `/owner/printing-orders/walk-in/new` (POST `/owner/printing-orders/walk-in`, saved screen `/{order}/walk-in-saved`, live price `/walk-in/price`), `/owner/bookings/walk-in/new` (POST `/owner/bookings/walk-in`, free times `/walk-in/slots`), `/owner/walk-in/customers` (JSON search), each with its own `owner-walkin-*` throttle.

**Counter (Phase 8B):** `/owner/payments` ("Payments and counter") is the counter; no sidebar item was added. `App\Services\Counter\CounterService` is read only: `search()` (orders and bookings by PR-/PB- reference with or without the hyphen, customer name or phone, booking contact number; % _ \ literal; 8 of each), `blocker()` (the plain reason a record cannot be paid now: closed, already paid, no confirmed price, ₱0.00, not accepted or confirmed), `describe()`, and the two queues `readyForPickup()` (?ready=) and `awaitingPayment()` (?awaiting=, the ledger's awaiting rule as one UNION, oldest first). `CounterController` (owner only, throttles `owner-counter-search`, `owner-counter-target`, `owner-counter-record`, `owner-payment-receipt`) records through `PaymentService::record()` only, with the amount the owner confirmed as `expected_amount`; there is no amount field and no token (the payments unique index already refuses a second recorded payment: "Already paid"). `cash_tendered` only works out the change, is never stored, and cash below the full price is refused (never a partial payment). JSON for the modal, a redirect without JavaScript. `?pay=printing_order:12` opens the modal on that record (queues, the payment card's "Open at the counter"). The receipt (`owner.payments.receipt`, a standalone page: inline CSS, system fonts, light only, A4 print) says "This is not an official receipt.", shows the shop's details from `ShopSettings::business()` when set, and a VOID mark with the reason for a voided payment; reprinting is always allowed; it is linked from the modal, the ledger rows, the payment card and the walk-in order success screen. The ledger defaults to TODAY (Manila) when nothing is filtered (`PaymentLedgerRequest::range()`: today, yesterday, all, custom); a search or filter without dates still covers every day. Release from the Ready for pickup queue posts the usual complete action with `return=payments` (the only accepted value); the gate is unchanged. `uiModal` adds `modal-open` to `<html>` while open, which hides `[data-chat-launcher]` and `[data-owner-ask-launcher]`, and dispatches `modal-closed` with its name.

**Income report (Phase 8C):** the Reports sidebar item opens `/owner/reports/income` (`owner.reports.income`); the Phase 6A requests report stays at `/owner/reports` and both pages share a tab bar (`owner/reports/_tabs`). Income is EXACTLY the ledger's total: `App\Services\Reports\IncomeReportService` (read only) calls `PaymentLedgerService::totals()`, `recorded()`, `inPeriod()` (new, every payment of a period) and `listing()` (new, the ledger rows unpaged; `payments()` pages it), so the report and the ledger cannot drift apart; a test proves report = ledger = sum of the listed recorded rows for every view. It returns income, count, average, Cash and GCash, Online and Walk-in (by the paid record's `source`, removed records included), Printing and Photography, the top 10 services and packages plus "Other" (catalog names, removed ones included), income per hour (a day), per day (a week, a month, a custom range up to 62 days) or per month (all time, longer ranges), voided count and amount apart (never counted), "still awaiting payment" (the ledger's awaiting rule, RIGHT NOW, never income) and the previous period (same length; the calendar month before for Monthly; a zero previous total says "No income in the previous period", never divides by zero). All grouping is in the database (fixed queries; Manila local time in SQL: SQLite `strftime(..., '+480 minutes')`, MySQL `DATE_FORMAT(DATE_ADD(..., INTERVAL 480 MINUTE))`; Manila has no daylight saving). Worked-out columns (source, item, time slot) are computed in an inner query and grouped by name in the outer one (`IncomeReportService::grouped()`): MySQL's ONLY_FULL_GROUP_BY refuses a GROUP BY on an expression with a subquery, and the SQLite tests cannot catch that. `IncomePeriod::resolve()` is the one period rule: Daily, Weekly (Monday to Sunday), Monthly, Custom (no dates = this month to today), All time; never past the current period. `IncomeReportRequest` validates every value and sends anything invalid back to the default view with "Your usual view is shown.". The chosen Daily, Weekly or Monthly view is remembered in `users.report_view` (default daily, not fillable, written only by `ReportViewPreference`). Charts are CSS in Blade (`<x-report.bars>`, `<x-report.split>`; `.chart-a` brand red, `.chart-b` ink or light gray with a stripe so color is never the only difference), each with `role="img"` and a table. The end-of-day summary (`owner.reports.end-of-day?date=`, a standalone A4 page like the 8B receipt) lists the day's payments, the voided ones apart, Cash and GCash, walk-in and online counts, awaiting payment and "Prepared from the system's payment records. Not an official document."; no cash count, variance or closing. The CSV (`owner.reports.csv`, same query values, streamed, UTF-8 with BOM, 13 columns, voided rows with status Voided, a leading apostrophe before = + - @ tab or CR) never touches the disk. "Print report" prints only `#income-report` in black on white (app.css print rules). Throttles: `owner-income-report`, `owner-income-end-of-day`, `owner-income-csv`. `Money::cents()` and `Money::compact()` were added for the charts.

**Account page (Phase 6C):** `/my/account` (customers) and `/owner/account` (the owner, who now changes their own password here) share `AccountController`, `AccountService` and `<x-account.page>`. Three forms, each with its own Form Request, error bag and named throttle: profile (name 2 to 80, email unique ignoring case, phone = `RegisterRequest::PHONE_PATTERN`; a new email needs the current password), password (`PasswordRules::defaults()` like registration and reset, must differ from the current one) and appearance. On a password change the hash is saved, the remember-me token replaced, the session id regenerated and every OTHER row of that user in `sessions` deleted, so other devices are signed out; passwords are never flashed or shown. The appearance (light, dark, system) is stored in `users.theme` and rendered by `layouts/app` on `<html data-theme>` (and `class="dark"`), so the first paint is right on every device; the header button switches light/dark and saves to the account in the background (`meta[name=theme-save-url]`); guests keep their choice in localStorage only. Not built on purpose: delete account, data export, avatar, two-factor, a devices list, email verification and notification preferences (cancelled: in-app notifications only, no email or SMS channel to switch).

**Notifications (Phase 5B):** in-app only (database channel, written in the same request after the transaction commits; no email, SMS, push, queue or websocket). `NotificationDispatcher` is the ONE place that decides who is told what; StatusTransitionService and BookingTransitionService call it from their `writeHistory()`, so a notification exists only when a history row was written (a double click or a stale page that writes nothing notifies nothing). A failure to write one is logged without personal data and never breaks the action. Nobody is told about their own action. The customer is told when the shop accepts (with the confirmed price), rejects, asks for information, starts printing, marks ready for pickup, completes or changes the price of an order; confirms, proposes a new time, rejects, changes the price or completes a booking; records a payment or cancels a payment record. When the shop or studio cancels an order or booking, the customer is told (Phase 5C: OrderCancelledByShop, BookingCancelledByShop; the reason is never in the notice). Every owner is told when a customer places an order, requests a booking, cancels an order or booking, accepts a proposed time, asks for another time, or adds files in reply to an information request. Nothing else notifies. The bell (unread count, latest 8, mark all read) is in both shells; the pages are `/my/notifications` and `/owner/notifications`. Opening an item is a POST that marks it read and redirects; a person only ever finds their own notifications (others answer 404).

**Dashboards and request history (Phase 5C):** `DashboardService` reads both dashboards with a fixed number of queries and reuses the existing rules: awaiting payment and "Amount to pay at the shop" come from `PaymentLedgerService::awaitingOrders()`/`awaitingBookings()` (the one rule; confirmed prices only, never the estimate), "Recorded payments today" is the ledger's `totals()` for the shop's calendar day, unread is `NotificationFeed::unreadCount()`. The customer's combined history (`RequestHistoryService`, `/my/requests`) is a UNION ALL of two bound selects (kind, id, created_at) that the database sorts and paginates; the page's rows are then loaded with one query per kind.

**The shop's payment rules (Phase 5A, from the shop owner; do not change them):** no partial payment and no downpayment: the customer pays the FULL confirmed price in one payment, face to face (for a photoshoot, on the day of the shoot). The shop takes cash and GCash. An order or the photos are released (Completed) only when fully paid. The system only records and monitors payments; it never takes one online. The old "partial payments on/off" setting is cancelled.

---

## 8. Status models

**Printing order:** `submitted` → `under_review` → `accepted` | `rejected` → `processing` → `ready_for_pickup` → `completed`. Also `cancelled`. Owner can request more information while in review (`needs_info` flag + message). Rejection requires a reason.

**Photography booking (since Phase 4A, App\Enums\BookingStatus):** `requested` → `confirmed` | `proposed` | `rejected` | `cancelled`; `proposed` → `confirmed` (customer accepts) | `requested` (customer asks for another time) | `cancelled` | `rejected`; `confirmed` → `completed` | `cancelled`. Only `confirmed` and `completed` hold a slot. The customer never confirms a request. "Confirm anyway" can override the schedule rules (hours, notice, blocked day) but never a conflict and never a start time that has already passed. Changing the hours, settings or blocked dates never cancels or moves a booking; confirmed bookings left outside the new rules are listed and the owner must tick "Save anyway". (This replaces the earlier draft list.)

**Print production for a booking (only if prints were requested):** uses the printing order statuses on a linked printing order.

**Payment status (derived, since Phase 5A, App\Enums\PaymentStatus::derive()):** `awaiting_price` (no confirmed price yet), `unpaid` (a confirmed price above zero and no recorded payment), `paid` (a recorded payment), `no_charge` (confirmed price exactly 0.00; nothing is due). Never typed in a form and never stored. The amount due is always the confirmed price, never the estimate. Paid and no charge are "settled": only then may StatusTransitionService move an order Ready for pickup → Completed and BookingTransitionService move a booking Confirmed → Completed (the release gate). While a payment is recorded, changePrice is refused ("Void the payment first"). A payment row is `recorded` or `voided` (App\Enums\PaymentRecordStatus); a voided payment never counts. Orders take a payment from Accepted on, bookings from Confirmed on; never on Rejected or Cancelled. A payment can be voided (reason required, kept in the payments table only) until the order or booking is Completed. There are no partial payments and no refunds workflow.

Allowed transitions live in one place (`StatusTransitionService` or the Enum) and are validated on the server.

---

## 9. Database plan (draft — confirm in Phase 1, extend per phase)

Money columns: `decimal(10,2)`. Never use float. Totals and balances are calculated on the backend.

| Table | Key columns | Phase |
|---|---|---|
| `users` | name, email, phone, password, `role` (owner/customer), `theme` (light/dark/system; cast to `App\Enums\ColorScheme` and used since 6C; not fillable, written only by AccountService) | 1 |
| `printing_services` | name, slug, description, category, pricing_type (per_page/per_copy/quotation), base_price null, allowed_file_types json, max_file_size_mb, instructions, is_published, sort_order | 2 |
| `printing_service_options` | printing_service_id FK, group (paper_size/paper_type/color_mode/sides/print_size/finish), label, price_modifier, is_active | 2 |
| `photography_packages` | name, slug, category, short_description, description, price null, price_requires_confirmation, duration_minutes, max_people, digital_outputs, inclusions json, terms, is_published, is_featured | 2 |
| `package_images` | photography_package_id FK, path, is_cover, sort_order | 2 |
| `themes` | name, description, image_path, color_swatches json, is_published | 2 |
| `package_theme` | pivot | 2 |
| `printing_orders` | **Built in 3A:** reference_number unique (PR-000001), customer_id FK, printing_service_id FK, snapshot json (service, options, prices at order time), quantity, notes, status, estimated_price null, confirmed_price null, rejection_reason, info_request (null = nothing asked), timestamps, soft deletes. `photography_booking_id` null (added in 4A, used in 4D): set only by PrintingOrderService when a customer orders prints from their own Completed booking; never fillable. The order keeps its own printing price and statuses; the booking's photography price never changes and the two are never added into one total. | 3 |
| `reference_counters` | prefix (PR, later PB) primary, last_number. One locked row per prefix hands out the next reference number. | 3 |
| `order_files` | **Built in 3A:** printing_order_id FK, original_name (display only), stored_name (hashName), path (private disk), mime, size_bytes, uploaded_by FK. Page count and image size are not stored yet. | 3 |
| `order_status_histories` | **Built in 3A, `event` added in 3C:** printing_order_id FK, from_status null, to_status, event null (info_requested / info_answered / price_changed; null = status change), changed_by FK, note, created_at. Phase 4 decides whether bookings get their own history table or a shared one. | 3 |
| `photography_bookings` | **Built in 4A:** reference_number unique (PB-000001), customer_id FK, photography_package_id FK, theme_id null, custom_theme_text null, snapshot json, starts_at, ends_at, requested_starts_at (UTC), group_size, contact_number (7 to 20 digits), notes, internal_notes null (owner only, hidden from serialization, no history row), wants_prints, status, estimated_price null, confirmed_price null, rejection_reason, proposed_starts_at, proposed_note, cancellation_reason, soft deletes. A customer may have at most config booking.max_open_requests_per_customer (3) requested or proposed bookings. Not built: outfits, colour motif, public remarks. | 4 |
| `booking_status_histories` | **Built in 4A:** same shape as order_status_histories, event (proposal_sent, proposal_accepted, time_requested_again, price_changed, files_added). | 4 |
| `booking_settings` | **Built in 4A:** one row: buffer_minutes, min_notice_hours, max_advance_days, concurrent_sessions, lock_version (the schedule lock). Defaults in config/booking.php; the allowed ranges the owner can set are `config('booking.limits')` (4D). The schedule editor never writes lock_version. | 4 |
| `booking_reference_images` | **Built in 4A:** photography_booking_id FK, original_name, stored_name (hashName), path, mime, size_bytes, uploaded_by. | 4 |
| `availability_rules` | **Built in 4A:** weekday unique (0 Sunday to 6 Saturday), is_open, opens_at, closes_at (shop local time). Slot step is config booking.slot_step_minutes. | 4 |
| `blocked_dates` | **Built in 4A:** date unique (shop local day), reason, created_by. | 4 |
| `payments` | **Built in 5A:** reference_number unique (PAY-000001, ReferenceNumberService prefix PAY), payable_type + payable_id (morph map aliases `printing_order`, `photography_booking`; `Relation::enforceMorphMap` in AppServiceProvider), amount decimal(10,2) (always the confirmed price at the moment of recording), method (cash, gcash), gcash_reference null (uppercase, 6 to 40 letters, digits or hyphens), received_by FK, received_at (UTC), status (recorded, voided), voided_by, voided_at, void_reason (owner only), `active` (1 while recorded, NULL when voided) with unique indexes (payable_type, payable_id, active) and (gcash_reference, active), so the database itself refuses a second recorded payment or a reused GCash reference. No soft deletes: a payment row is never deleted. Written only by PaymentService. | 5 |
| `notifications` | **Built in 5B:** Laravel's own database notifications table (uuid id, `type` = the kind, notifiable = morph alias `user`, data json, read_at), plus an index (notifiable_type, notifiable_id, read_at) for the unread count. Data is small: kind, payable_type, payable_id, reference (PR or PB), and where needed amount and the PAY reference. The sentence is never stored: `App\Enums\NotificationKind` is the one message map. Never stored: GCash references, void or rejection reasons, notes, phone numbers, emails. | 5 |
| `faqs` | **Built in 6B:** question (up to 200, unique ignoring case, checked by FaqService), answer text (up to 2000), position unsigned unique (1..N, FaqService only, never fillable), is_published (default true), timestamps. Hard delete. | 6 |
| `shop_settings` | **Built in 6B** (replaces the draft key/value `settings` table): one row, id 1, every column nullable: shop_name (80), address (300), phone (40), email (120), printing_max_file_mb, printing_max_total_mb, printing_max_files, booking_max_image_mb, booking_max_images, booking_max_total_mb (NULL = the config default). Hours and booking rules stay in `availability_rules` and `booking_settings`. Read only through `ShopSettings`. | 6 |
| `chat_sessions`, `chat_messages` | optional, only if chat history is stored | 7 |
| `owner_assistant_messages` | **Built in 7E-3a:** owner_id FK, role (owner, lumi), text, meta json (cards, links, tool names, via_backup), created_at. The owner's own Ask Lumi conversation; never tool data. Written only by `OwnerAskHistory`. Plus `assistant_runs.tools` (string 200, tool names only). | 7 |

Decision to confirm: when prints are linked to a booking, the linked printing order is billed **under the booking** (payments are recorded on the booking, and the print order shows "Billed with PB-xxxx"). This prevents double charges.

Reference numbers are generated inside a database transaction so two requests can never get the same number.

---

## 10. Route plan (summary)

| Area | Prefix | Middleware | Examples |
|---|---|---|---|
| Public | `/` | none | `/`, `/services`, `/packages`, `/packages/{slug}`, `/packages/compare`, `/packages/finder`, `/faq` (6B) (all GET, 60 a minute, each with its own named counter since 4B; `compare` and `finder` are reserved package slugs) |
| Auth | `/` | guest | `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` |
| Customer | `/my` | auth, role:customer | `/my/dashboard`, `/my/printing/new`, `/my/printing/{order}`, `/my/bookings/new` (`?package=slug`), `/my/bookings/slots` (JSON free times), `/my/bookings/{booking}/sent`, `/my/bookings/{booking}` (4C), `/my/requests` (combined history, 5C), `/my/notifications`, `/my/account` (6C; POST `profile`, `password`, `theme`) |
| Owner | `/owner` | auth, role:owner | `/owner`, `/owner/printing-orders`, `/owner/bookings`, `/owner/services`, `/owner/packages`, `/owner/themes`, `/owner/schedule` (calendar, `?view=month|week|day&date=Y-m-d`), `/owner/schedule/edit` (POST `hours`, `settings`, `blocked`; DELETE `blocked/{blockedDate}`), `/owner/payments` (ledger, 5A; payments are recorded with POST `/owner/printing-orders/{order}/payments` or `/owner/bookings/{booking}/payments` and voided with POST `.../payments/{payment}/void`), `/owner/customers` (`?q=&awaiting=1`) and `/owner/customers/{customer}` (6A, `?requests=&payments=` pages), `/owner/reports` (6A, `?range=this_month|last_month|last_7|last_30|all` or `?from=&to=`), `/owner/settings` (6B; POST `business`, `uploads`, `uploads/reset`), `/owner/account` (6C; same as `/my/account`), `/owner/faqs` (6B; create, edit, PUT, DELETE with `confirm`, GET `{faq}/delete` confirm page, POST `{faq}/publish` and `{faq}/move`), `/owner/assistant/ask` (POST), `/owner/assistant/history`, `/owner/assistant/briefing` (7E-3a, JSON) |
| Files | `/files/{file}` | auth + policy | streams private files after an ownership check |
| AI | `/assistant/messages` | auth (or throttled guest), throttle | POST only, server-side API key |

After login: owner → `/owner`, customer → `/my/dashboard`, unless the visitor was sent to login from a page in their own area (`UserRole::afterLogin()`), for example the booking wizard.

Every throttled route names its own counter (`throttle:N,1,name`); `ThrottleCountersTest` fails when two routes share one or a name is missing.

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

- The screens in `design/` (`screen.png` + `code.html`) are the visual **and animation** reference. Match layout, spacing, colors, type, effects and motion closely; read the `@keyframes`/transitions in `code.html` and reproduce the same timing and easing with CSS and small Alpine code (no GSAP until Phase 9).
- Method per screen: read the design, list the differences, rebuild with components and `@theme` tokens, compare at the design's viewport, then check the other viewports and theme. Record MATCH / CLOSE / DIFFERENT.
- Never copy: the Tailwind CDN script, hotlinked image URLs, invented data (camera specs, addresses, calibration IDs, fake statuses), or controls for features the system does not have (design-tool preview switchers, order tracking by slip, terms pages, etc.).
- Project rules win over the design: 44px tap targets on phones, contrast, visible focus, reduced motion, light and dark, no horizontal scroll.
- Images saved in `public/images/design-placeholders/` are **temporary Stitch placeholders**. Replace them with the shop's real photos before going live.
- Done so far: login family and page loader, error pages, the public, customer and owner shells, the owner services screens, the owner schedule (`design/admin/schedule_and_availability`, Phase 4D), and the `ui` components (DESIGN.md values for buttons, cards, modal, pills, notice, checkbox). Remaining differences are listed in the fidelity report.

### UI building blocks (built in Phase 1)

- **Components** live in `resources/views/components/ui/` (use as `<x-ui.name>`): logo, icon, button, input, select, textarea, checkbox, field, badge, status-badge, card, notice, modal, toast, empty-state, stepper, timeline, aperture-spinner, page-loader, theme-toggle. The page shells use `resources/views/components/layout/` (skip-link, nav, drawer, user-menu, error-card). Reuse them; do not copy markup.
- **`/ui-kit`** is a developer gallery of every component in every state. It returns 404 unless `APP_ENV=local`. Check it when you change a component.
- **Layouts** are `layouts/app` (base), `layouts/public`, `layouts/auth`, `layouts/customer` and `layouts/owner`. Pages fill the `main` section (the `auth` section for auth pages). To add a navigation item, add one line (label, icon, route name) to the `$nav` array at the top of the layout file, and only when the route exists.
- **`<body x-data>` must stay in `layouts/app`.** Modals, drawers and toasts are opened from buttons through Alpine events, which only work inside an Alpine scope. Every layout must also include `<x-ui.toast />`.
- **Tailwind scans only `resources/views` and `resources/js`** (see `@source` in `resources/css/app.css`). A class used anywhere else (for example in `design/` or a PHP string outside views) will not be generated. Write full class names, never built-up strings like `'bg-'.$color`.
- **Public pages SEO:** put `<x-seo title description [image] [noindex]>` in the page file, *outside* `@section` (it pushes into the `@stack('head')` of `layouts/app`, which is rendered before the sections). The canonical address never carries the query string; filtered, compare and finder results are `noindex`.
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

- **Persona:** "Lumi" (name in `config/assistant.php`). Lumi is always shown and always says it is an AI, never a person.
- **Two conversations:**
  - **Ask Lumi:** the customer and the AI only.
  - **Studio chat:** the customer and the owner. Lumi answers inside this thread only while the owner is away, stops as soon as the owner replies, and stops after 5 AI replies in a row (`studio_away_max_consecutive`).
- **Owner assist:** a DRAFT reply and a thread summary. The owner edits and sends them; nothing is ever sent automatically.
- **Where the AI lives:** only inside the chat, nowhere else in the system.
- **No tools:** the AI cannot book, confirm, change a price or status, or record a payment. It only writes text; recommendation cards are built by code.
- **Providers (since 7E-1):** Claude (`AnthropicProvider`, Messages API, model `claude-haiku-5-5`, one forced `reply` tool, no temperature) answers first; Gemini (`GeminiProvider`) is the backup, both behind the `AssistantProvider` interface (`app/Services/Assistant/Providers`). `ProviderResolver::chain()` gives the order. The backup is asked ONLY when the first provider answers 429, is down (5xx, 529, timeout, connection, a refused key, no credit) or sends a broken body; never after a real answer of any intent, a refusal, or a reply the validator or a guard refuses. Each provider has its own 429 cooldown. The keys are only in `.env` (`AI_PROVIDER`, `AI_FALLBACK_PROVIDER`, `AI_ANTHROPIC_API_KEY`, `AI_ANTHROPIC_MODEL`, `AI_API_KEY`, `AI_MODEL`, `AI_ENABLED`; since 7E-2a the Claude key is read ONLY from `AI_ANTHROPIC_API_KEY`: the plain `ANTHROPIC_API_KEY` is used by other tools and, as a process variable, would shadow `.env`, so it is ignored), sent only in the `x-api-key` / `x-goog-api-key` header, and never logged. A missing key means that provider is skipped. `assistant_runs.fallback_from` names the first provider when the backup took the call, and a failed call that reached its provider keeps its own row. `FakeProvider` gives scripted replies for tests and local work (`FakesAssistant::fakeChain()` scripts both providers). Every test uses it or `Http::fake` with `Http::preventStrayRequests()`.
- **One entry point:** `AssistantEngine::handle()`. It never throws and never shows an error page. Every problem (switched off, a limit, no key, provider down or rate limiting, a reply that breaks the JSON contract) ends in one of the fixed fallback texts in `config('assistant.messages')`. Each call writes one `assistant_runs` row with metadata only, never message text.
- **Limits:**
  - 1000 characters per message.
  - 6 a minute and 80 a day per signed-in user; guests 20 a day, keyed by a hash of the IP (the raw IP is never stored). (40 and 10 until 7E-2a.)
  - A global daily budget split by feature (`global_shares`).
  - Days run midnight to midnight in Manila.
  - A call counts only when the provider sent an HTTP response (`AssistantEngine::countCall`). A connection, certificate or timeout failure, a missing key or an unknown provider never uses up anyone's quota.
  - A 429 pauses every call for its retry-after (at most 5 minutes).
- **Data sent to the provider:** only the published catalog (services, options, packages, themes), the published FAQs, the business info, hours and booking rules, the typed text, and (since 7E-1, Ask Lumi and the studio thread only) the signed-in customer's OWN records from `CustomerContextBuilder`: up to 5 open and 3 finished orders and bookings, with only what their own My orders and My bookings pages show (reference, service or package name, status label, session time, confirmed price or "Awaiting price", recorded payment, whether they can cancel online now, last update), in the system text block "THIS CUSTOMER'S OWN RECORDS". The customer is only the actor's user id; guests and owner assist get no records. Customer text is redacted first (emails, phone numbers, long digit runs) and always goes in the user role. Never notes, reasons, owner notes, contact details, files, notifications, other conversations or anything of another customer.
- **Cards and prices:** cards (name, price, link) come from the database, and only for published records; record cards (type `order` or `booking`) only for the conversation's own customer, so a foreign, forged or missing id is dropped. A studio handoff stores the customer's own record it is about in the message meta (`about`), which the owner's thread header links. `ClaimGuard` removes every sentence where Lumi claims an action ("I cancelled", "na-cancel ko na") or a status or payment fact no record of this customer shows ("ready for pickup", "fully paid"). `PriceGuard` removes every sentence of a reply that names a peso amount not found in the shop knowledge or in what the customer or thread already said. When nothing is left, a fixed text is shown.
- **Since 7E-2d:** `App\Support\MessageLanguage` detects the language of the LATEST customer message (en, fil, taglish or unknown, from small marker words; the eval's `LanguageDetector` uses it). For Ask Lumi and the studio thread, `PromptBuilder` adds one line to the VOLATILE tail ("LANGUAGE OF THE LATEST CUSTOMER MESSAGE (detected by the system): English. Reply in English." or "Filipino or Taglish ..."), never in the cached prefix, never from the history, never for owner assist, and none when the detection is unknown. `ClaimGuard` no longer treats a price confirmation as a booking promise when the price word comes after small words ("kukumpirmahin ng owner ang final na presyo", "the exact final price") or before the Filipino verb in the same clause ("Ang presyo ay kukumpirmahin ng owner"); a promise about a booking is still removed.
- **Since 7E-2c:** every model reply's spacing goes through `App\Support\PlainText::spaces()` (called by `ResponseValidator::plainText()`): Unicode spaces (Zs) and tabs become one normal space; zero-width characters become a space between two words and are removed inside a word or number. `ClaimGuard` allows up to two adverbs before the state word ("is totally confirmed") and removes promises that the owner, studio, "we" or "they" WILL confirm, approve or accept a booking or request (not the price, a payment method, replying or reviewing, present-tense how-it-works, questions, conditions or negations); the stable prompt rules say the same.
- **Since 7E-2a:**
  - **Live facts:** the volatile part of every prompt has a NOW line (`LiveFacts`: Manila date, weekday, time, open now, next opening, from the existing hours and blocked dates). Each booking line says how far its time is ("When: in 3 days", "2 days ago (already passed)", by Manila calendar days) and a fixed note when a Requested or Proposed time, or a Confirmed session, has passed. The model never works out dates.
  - **ClaimGuard per record:** a state claim is checked against the record it is about (a PR-/PB- reference in the sentence, any case and with or without the hyphen; else a service or package name; else order or booking words; else every open record), and stays only when ALL of those records show it.
  - **Lumi Lite:** `AssistantResult` carries `provider` and `viaBackup`; assistant chat messages store `provider` and `via_backup` in meta; `ChatPresenter` and the guest endpoint expose only `via_backup`; the chat rows show a small "Lumi Lite" pill with a tooltip and screen-reader text; owner assist says "Written by the backup assistant". The backup's name is never shown to customers.
  - **CodeAnswers:** short messages (60 characters or less) whose every word belongs to ONE intent (greeting, thanks or goodbye, hours or open now, contact details or address) are answered from the database, in Filipino when a Filipino word is present. Outcome `answered_by_code`, an `assistant_runs` row with provider `code`, no tokens and no latency: never counted toward a limit or the budget (the route throttle still applies), never counted toward the studio away cap, never while the owner is present (the existing rule), never for owner assist, and not when the assistant is switched off. A missing fact or anything else goes to the model. Tests switch it on with `useCodeAnswers()` (the shared fake turns it off so scripted "Hello" replies still reach the provider).
  - **Prompt size and caching:** `PromptBuilder` builds a STABLE prefix (rules, mode, fixed knowledge with the catalog index, record rules) and a VOLATILE tail (details of the items that match the message, at most `detail_items` and `details_max_chars`, ranked with `assistant.synonyms`; the customer's records; the NOW line; the last line). Claude gets the prefix as a system block with `cache_control` ephemeral (Haiku 5.5 caches from 512 tokens); Gemini gets the whole text. `assistant_runs.cache_read_tokens` and `cache_write_tokens` hold the usage; the row is still written when those columns are missing. History: 6 messages.
  - **Usage report:** `php artisan assistant:usage {--days=7}` prints per provider and feature the calls, outcomes, failovers, tokens, the share answered by code and an ESTIMATED cost from `config('assistant.pricing')` (verify the prices). Read only, metadata only.
- **Evaluation (7E-2b):** `php artisan assistant:eval` (`app/Services/Assistant/Eval`, cases in `database/evals/cases/*.json`, guide in `docs/AI_EVALUATION.md`). Safety rules: it runs ONLY in its own SQLite file under `storage/app/evals` (git-ignored; `EvalSandbox` asserts the active connection is that file before every write and aborts otherwise; it never opens MySQL), with fake customers (`EvalFixtures`: guest, ana, ben, newbie) and a frozen clock (Thursday 2026-11-05 10:00 Manila); limits are raised in that process only. Cases go through the real `AssistantEngine`; checks are deterministic (no model judges); `review` cases are never scored; provider failures are kept apart. Without `--live` only the fake provider is used; `--live` needs `--yes` (otherwise a dry run with the call count and estimated cost). Reports (`.md`, `.json` in `storage/app/evals`) never hold a key or a prompt, and replies only for failed and review cases (400 characters). Exit 1 when a must_never case fails in any run (always wins), a must_never case was not measured, or the pass rate is below 90%; since 7E-2c exit 2 (INCONCLUSIVE) when more than 10% of the model cases ended provider_failed or skipped, and a case that gets a 429 is retried once after the retry-after (at most 60 s; `EvalCooldown` clears the provider cooldown in the sandbox only). `no_glued_words` and `no_foreign_reference` are on for every case. Tests: `EvalGuardOffTest` proves the must_never cases fail when ClaimGuard, PriceGuard, the card ownership check or the prompt-leak guard is switched off. Since 7E-2b an hours question naming another day or time ("tomorrow", "sa Linggo", "bukas ba kayo bukas", "7pm") is not answered by `CodeAnswers`.
- **Since 7E-2e:** `PlainText` also removes a soft hyphen and handles more invisible and control characters (emoji joiners and variation selectors stay), and `PlainText::repair()` adds a missing space only after ", ; :" between letters, between a time and AM/PM, and after ". ! ?" before a capitalised word (never in numbers, references, addresses, "e.g.", "U.S."). `ReplyCleanup::finish()` (every reply, after the guards) removes a last line that ends with ":" and has nothing after it. `GuardStats` (scoped) counts the sentences each guard removed, without text, for the eval report. `MessageLanguage` reads "May I ..." as English.
- **The owner's Ask Lumi (since 7E-3a, backend only; the page is 7E-3b):** `App\Services\Assistant\Owner\OwnerAskService::ask()` never throws. Step 1: one call with the forced PLAN schema (`ProviderRequest::PLAN`; Anthropic tool `plan`, Gemini responseSchema) picks at most 3 read-only tools from `OwnerTools` with their parameters, or asks a clarifying question, or says out_of_scope or write_request; an invalid plan is never executed. Step 2: code runs the tools and ONE more call phrases the answer from their results only, in a block labelled OWNER DATA (escaped; never a message text, phone or email). The tool catalog is in the cached stable part. The answer passes the leak check, a write claim replaces the whole answer with the fixed "I can only read" text, `NumberGuard` removes every sentence with a number, date or time not in the tool facts or the question, then the redactor, `PlainText` and `ReplyCleanup`. Failover, counting and `assistant_runs` are shared (`ProviderChain`, `AssistantRunWriter`, extracted from `AssistantEngine`); rows hold the TOOL NAMES only (`assistant_runs.tools`). Limits: `assistant.limits.features.owner_ask` (20 a minute since 7E-3b, 200 a day, counted on owner_ask calls only; the route throttle `owner-ask-question` is 20 too) and its own share of the budget (shares: ask_lumi 0.45, studio_away 0.30, owner_assist 0.10, owner_ask 0.15). The owner's conversation is `owner_assistant_messages` (questions and answers only, the last 6 read). Endpoints (owner role, named throttles `owner-ask-*`): `POST /owner/assistant/ask` (message, `fresh`), `GET /owner/assistant/history`, `GET /owner/assistant/briefing` (written by code, no provider call). To add a tool: a class extending `OwnerTool` (name, description, parameter schema, read-only `handle()` returning a `ToolResult` whose numbers go through `money()`, `count()`, `day()` ... so they are facts) and one line in `OwnerTools::TOOLS`. Guide: `docs/OWNER_ASSISTANT.md`.
- **Since 7E-3b (the owner's UI):** `<x-owner.ask-lumi>` (Alpine `ownerAsk`, `resources/js/owner-ask.js`) is the floating panel on every owner page (rendered by `layouts/owner` for the owner role only, not on its own page) and the full page `/owner/assistant` (nav item "Ask Lumi"; the latest turns are also drawn by the server for no JavaScript, with a note). History is paged with a cursor (`GET /owner/assistant/history?before=id`, 50 a page, `older` is the next cursor). The overview shows "Today at a glance" from `GET /owner/assistant/briefing` (`items`: each line with its owner page; code only, so it works when the AI is down; it fails quietly). Answers show their cards and links (`{label,url}`; `ToolResult::ownerPath()` keeps only relative `/owner/...` paths, and the JS checks again), the Lumi Lite pill for `via_backup`, and a calm notice for `notice` = `limit_minute`, `limit_day` or `unavailable`. `LimitService::$reason` (minute or day) picks the text: `limit_minute` / `owner_ask_limit_minute` ("You are asking quickly. Please wait a minute and try again.") or the daily text; the outcome stays `fallback_limit_user`. The owner's fixed texts follow the question's language (`OwnerAskService::text()`, `*_fil` keys). How-to questions ("how do I", "paano ko") are planned as `how_to`, not write requests; a reply blocked by the leak check gives the polite `owner_ask_cannot_show` refusal (outcome refused). NumberGuard also allows the numbers of Lumi's own earlier answers that passed the guards (`meta.verified`, the last 6 turns), never the owner's or a customer's text. The eval sandbox raises EVERY limit from the config (`EvalSandbox::raisedLimits()`: per-user limits, the budget, every share, every numeric cap of every feature), and the report shows the question and tools of failed and review cases.
- **The owner's Ask Lumi after Phase 8 (8D, read only, no migration, engine unchanged):** 16 tools. `income_summary` now IS the Income report: `IncomeRange::period()` maps the resolver's range onto the report's own `IncomePeriod` (one day = Daily, Monday to Sunday or to today = Weekly, the 1st to the month's end or to today = Monthly, no dates = All time, else Custom; a range starting after today returns "has not started yet" and no numbers) and calls `IncomeReportService::build()`, so income, payments, average, Cash/GCash, Online/Walk-in, Printing/Photography, top items plus Other, per day or hour and the best one, voided (apart, "never counted"), still awaiting payment (right now, not income) and the comparison (`IncomeReportService::comparisonText()`, the report page's own sentence, its numbers registered as facts) equal the report; `group_by` adds `source` and `item`; it links the Income report of the same period, the end-of-day summary for one day and the ledger. New `counter_queues` (ready for pickup with the unpaid first and a counter `pay=` card, the awaiting queue oldest first in Manila days, today's payments by method, today's unpaid walk-in sessions) and `walk_in_summary` (walk-in vs online orders and bookings made in the period, new walk-in customers, walk-in vs online income from the report). `customer_search` takes `type` (all, walk_in, online; the directory's own search by name, email or phone, wildcards literal; never returns a phone) and every customer, order and booking fact says walk-in or online (`RecordFacts::source()`, `CustomerSearch::WALK_IN`: no account, never notified, reached by the phone on file). `orders_overview` and `bookings_schedule` take `source`. `unpaid_balances` counts days like the counter (`CounterService::daysWaiting`), gives the oldest waiting and links the counter. The briefing (and Today at a glance) adds "Ready for pickup, not paid yet" and "Walk-in sessions today, not paid yet", each only when above zero. New how-to topics: `record_payment` (now the counter), `payment_receipt`, `walk_in_order`, `walk_in_booking`, `void_payment` (real button wording), `income_report`, `end_of_day`, `export_csv`, `print_report`, `awaiting_payment`, `official_receipt` (not an official receipt, ask the accountant or the BIR; no tax advice). The planner rules route income, counter, walk-in and tax questions to these tools; the answer rules forbid promising to notify a walk-in and giving tax advice. After the first live run: the answer rules forbid the words revenue, sales and profit even in a denial, presenting a part (Online, Walk-in, Cash, GCash, Printing, Photography) as extra to the income, and glued words; `OwnerPromptBuilder::HOW_TO_RULE` (after the write_request rule, says it wins, names the Filipino verbs) keeps "how do I / paano ko ..." questions as how_to; `income_summary` also gives `printing_payments` and `photography_payments` (every figure of the tool passes NumberGuard, tested); the shared `Redactor` sets peso amounts and percentages aside before its generic phone rule (it once turned "₱785.00 (31.4%)" into "₱[phone]%)"); the eval's `reply_contains_values` accepts a small count as an English or Filipino word.
- **Who the customer talks to (7F-1, UI and copy only):** the customer launcher is "Chat" ("Lumi AI or the studio", aria-label "Chat with Lumi or the studio"); the owner's "Ask Lumi" launcher is unchanged. With no `?tab=` and no remembered choice, `<x-chat.window>` shows "Who do you want to talk to?" (Ask Lumi (AI) or Message the studio (a person)); the choice is kept in localStorage `chat-choice` (try/catch, a convenience only) and a "Switch" button returns to the chooser. Guests (public pages) only ask Lumi: their studio card says to log in or create an account and links to both. Avatars are `<x-chat.avatar who="lumi|studio">`, the header's brand mark (`<x-ui.logo-mark>`, also used by `<x-ui.logo mark>`); Lumi's has an "AI" corner badge, and the accessible names are "Lumi, AI assistant" and "Studio". The old `auto_awesome` sparkle is banned from views and scripts (`ChatEntryTest`). `<x-chat.message-studio :record type>` (dashboard, order and booking detail) dispatches the window event `chat:studio` `{draft, about}`: the panel opens on the Studio tab with "Hi, about PR-000001: " typed (only when the box is empty) and is never sent for the customer; without JavaScript it links to `/my/assistant?tab=studio&about=...`, which types the same draft for the customer's own record only. The Studio header line ("The studio is online" / "The studio is away. Lumi can help in the meantime.") is built in JS from the existing `state.studio.owner_away` and `lumi_active` only; no time or owner activity reaches the customer. One quiet footer line on the Ask Lumi tab: "Lumi is an AI. For changes or special requests, message the studio."
- **Chat comfort pass, part A (7F-2, customer and guest UI only):** the panel and full-page header is one row (avatar, name, one "AI" or "Person" pill, one truncating line); Switch, New conversation and Contact the owner are in the "more" menu (`data-chat-more`, Esc closes it first and returns focus), with Expand and Close visible. The "Lumi is an AI assistant" notice is said once: a short dismissible line remembered in localStorage `chat-policy-dismissed` (try/catch); the composer's quiet line links to the studio ("Changes or special requests? Message the studio"). `.chat-scroll` (app.css) is the thin scrollbar with a soft top fade for every chat list; `.chat-bubble-in` the new-bubble motion (off with reduced motion). `buildRows()` marks `first` for a group of bubbles from one sender within 5 minutes, so `<x-chat.rows>` draws the avatar, name and time once per group (this also applies to the owner thread). `/my/assistant` fills the dynamic viewport height.
- **Chat comfort pass, part B (7F-2, owner UI only):** shared primitives used by every chat (customer panel and page, guest panel, owner thread, owner Ask Lumi): `<x-chat.composer-box>` (textarea and Send; the parent provides draft, canSend, onKeydown, resize), `<x-chat.banner>` (inset error or calm notice; parent provides banner and runRetry; extra actions in the slot), `<x-chat.typing>`, `<x-chat.avatar>`, `<x-chat.rows>`, `.chat-scroll`, `.chat-bubble-in`, and `scrollToEnd()` in `chat-common.js` (instant when forced, a glide otherwise, never with reduced motion). The owner inbox puts the title and presence on one row; on wide screens the conversation list (4 of 12 columns) and the thread (8) share one height that fills the window, each scrolling on its own; the thread header is one row with Suggest reply, Summarize and Resolve as quiet icon buttons (labels from 1280px); the Lumi status is one slim line; the summary is an inset card capped at 10rem. In an open thread the floating Ask Lumi launcher is only the avatar, lifted above the reply box (the list keeps bottom room for it). The owner Ask Lumi rows draw a date line when the day changes and the avatar, name and time once per group (`dayLine`, `isFirst`, `clock` in `owner-ask.js`); `/owner/assistant` fills the window.
- **Off-topic:** gets this fixed reply, whatever the model wrote: "I'm here to help with Jhunlea's photography and printing services. I can help you explore packages, printing options, booking requirements, and request updates. What service are you interested in?"
- **Provider tier:** use the free tier only with fake data. Real customers need the paid tier, because the free tier may use the content to improve the provider's products.
- **XAMPP on Windows:** needs a CA bundle in php.ini, or every call falls back with cURL error 60 (`docs/XAMPP_SETUP.md`).

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
