# Security audit (Phase 9A)

Branch `phase-9a-security`, from `main` after Phase 8E. Scope: who can reach every route, separation between
customers, throttles, mass assignment, uploads, headers and session, raw output and injection, error and enumeration
leaks, dependencies and committed secrets, and a production preflight. Feature behaviour for legitimate users is
unchanged. No migration.

How this is kept true:

- `tests/Feature/Security/RouteAccessMatrixTest.php` lists EVERY route with its access class and fails when a route
  is added without a line (or a line's route is gone). It also checks each route's middleware against its class,
  sends a guest and the wrong role to every route, and proves customer B (and a guest) cannot reach customer A's
  records on every route that takes one.
- `tests/Feature/Security/SecurityHardeningTest.php`: headers, session cookie, Host check, mass assignment over HTTP,
  production error pages, the new limiters, the closed storage route, no scripts under `public/`.
- `tests/Feature/Security/PreflightCommandTest.php`: `php artisan jhunlea:preflight` under faked settings.

## 1. Rules

| Topic | Rule |
|---|---|
| Roles | Guest, customer, owner (one). `role:owner` / `role:customer` (EnsureRole) run BEFORE route model binding, so the wrong role gets 403 even for an id that does not exist. A guest is sent to `/login`. |
| Another customer's record | **403 Forbidden** from the record's policy (PrintingOrderPolicy, PhotographyBookingPolicy, OrderFilePolicy, BookingReferenceImagePolicy). Two kept exceptions answer **404**: a conversation (ConversationPolicy denies as not found; the chat's JSON `not_found` code depends on it) and a notification (only the user's own rows are searched). See F-03. |
| Owner records | The one owner may open every order, booking, payment and customer; customers never reach `/owner/*` (403). |
| Walk-in customers | No email and no password: they can never sign in, reset a password or be a login target (`tests/Feature/WalkIn/WalkInCustomerTest.php`). |
| Throttles | Every route except Laravel's own (`/up`, `/ui-kit`, the 404 fallback) has its own named counter (`throttle:N,1,name`, inline in `routes/web.php`). `ThrottleCountersTest` refuses a shared or missing name; the matrix test refuses a missing throttle. |

## 2. Findings

| Id | Area | Finding | Risk | Status | Where |
|---|---|---|---|---|---|
| F-01 | Throttles | 36 routes had no throttle: the whole owner catalog (services, options, packages, package image uploads, themes with their image upload), the owner order list and detail, logout and the four guest form pages. An unthrottled upload route lets a stolen or scripted owner session fill the disk quickly. | Medium | Fixed: a named limiter on each (writes 20 to 60 a minute, uploads 20, pages 60 to 120). | `routes/web.php` |
| F-02 | Files | The private `local` disk had `serve => true`, which registers Laravel's signed `GET/PUT /storage/{path}` routes for private files. Nothing used them (no `temporaryUrl`), but they were a second, unthrottled way into the private disk (a valid signature was still needed). | Low | Fixed: `serve => false`; the routes are gone. Private files are only served by `files.download` and `booking-images.*` after a policy check. | `config/filesystems.php` |
| F-03 | Isolation | Another customer's order, booking, file or image answers 403, a conversation or notification answers 404, and a missing id answers 404: a customer can tell "exists, not yours" from "does not exist" for orders and bookings. The ids are sequential anyway and no data is shown. | Low | Accepted (documented rule in section 1, asserted by the matrix test). Changing it would change the chat's JSON contract and many existing tests right before the defense. | `app/Policies/*` |
| F-04 | Headers | No security headers were sent. | Medium | Fixed: `SecurityHeaders` (web group) sends `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`, and `Strict-Transport-Security: max-age=31536000` over https only. A header the response set itself is kept. | `app/Http/Middleware/SecurityHeaders.php`, `bootstrap/app.php` |
| F-05 | Session | `session.secure` was `env('SESSION_SECURE_COOKIE')`, so production over https still sent the cookie over http unless someone set the variable. | Medium | Fixed: SESSION_SECURE_COOKIE wins; without it the cookie is https-only exactly when APP_URL is https (local `http://` keeps working). `http_only` true and `same_site` lax confirmed. | `config/session.php`, `.env.example` |
| F-06 | Host header | URLs the app builds (the password reset link in the email) use the Host header of the request. A forged Host could put another site's address into a reset email (also Symfony advisory "Generated URLs can point to a host chosen by the client"). | Medium | Fixed: `$middleware->trustHosts()` accepts only the APP_URL host and its subdomains outside `local` (Laravel skips it in local and in tests, so XAMPP and the ngrok tunnel are unchanged). | `bootstrap/app.php` |
| F-07 | Dependencies | `composer audit`: 7 Symfony 7.4 advisories (section 6). npm: 0. | Medium/low | Accepted for the defense; the patch releases fix them (`composer update "symfony/*" -W`, stays on 7.4, no major). | `composer.lock` |
| F-08 | Enumeration | Registration says "The email has already been taken." so it reveals a registered email. Login ("These credentials do not match our records.") and forgot password (one answer for every outcome, the mail sent after the response) do not. | Low | Accepted: a clear message helps real customers; the form is throttled (10 a minute per IP). | `RegisterRequest` |
| F-09 | Uploads | `storage/app/public` (served as `/storage`) holds the package and theme images. They are validated as images by content and saved under random names, but Apache had no rule against running a script there. | Low | Fixed (defense in depth): `storage/app/public/.htaccess` refuses php, phtml, phar, cgi, shell, html and svg files. Apache only; another web server needs the same rule. | `storage/app/public/.htaccess` |
| F-10 | CSP | No Content-Security-Policy. | Low | Accepted: an enforcing CSP is not added (section 4). | - |
| F-11 | Redirects | `back()` follows the Referer header first. It is only used after a POST, which CSRF protection already limits to the site's own pages; the login and register `url.intended` go through `UserRole::afterLogin()` (same host, own area, plain path; tested in `AfterLoginRedirectTest`). | Low | Accepted. | controllers |
| F-12 | Proxies | Trusted proxies are 127.0.0.1 and ::1 only (the local ngrok tunnel). Behind a hosting proxy that ends https, the app would see http and the client IP of the proxy (rate limits shared by everyone). | Info | The preflight warns; set the host's proxy addresses in `bootstrap/app.php` when hosting (never `*`). | `bootstrap/app.php` |
| F-13 | Mass assignment | No model has `$guarded = []`; `role`, `is_walk_in`, `theme`, `report_view`, `source`, statuses, prices of orders and bookings, amounts, owner ids and hashes are never fillable. No controller fills from `$request->all()`. Registration and the profile form ignore `role` and `is_walk_in` (now tested over HTTP). | - | No change needed. | `app/Models/*` |
| F-14 | Validation | Every state-changing route validates with a Form Request or `validate()`, except 24 that read no input at all (publish and feature toggles, deletes, cover, mark read, presence heartbeat, Lumi, owner assist, resolve and reopen, new Ask Lumi conversation): they act on the bound record only. | - | No change needed. | - |

## 3. Uploads

| Upload | Disk | Checks (server side) | Stored name | Served by |
|---|---|---|---|---|
| Printing files | private `local` | extension list per service, `mimes` on content, size and count from ShopSettings | `putFile` random name; the customer's name kept as text only | `files.download`: OrderFilePolicy, `application/octet-stream`, `attachment`, nosniff, `Content-Security-Policy: default-src 'none'; sandbox`, cleaned name (`DownloadName`) |
| Booking reference images | private `local` | `image`, `mimes`, size and count from ShopSettings | `putFile` random name | `booking-images.show` (inline only when finfo reads a real JPEG, PNG or WebP) and `.download`; BookingReferenceImagePolicy, nosniff |
| Package images, theme images | `public` | `image` (no SVG), `mimes` on content; a renamed .php is refused (`PackageImageTest`, `ThemeManagementTest`) | `store()` random name, extension from the real type | Apache from `public/storage`, `.htaccess` refuses scripts |
| Chat | - | no attachments exist | - | - |

Path traversal: every path is built by the app from the record id and a random name; the original name is display
text only (tests with `../` names in `OrderFileServiceTest`, `BookingImageRouteTest`, `PackageImageTest`). Nothing
executable is under `public/` except `index.php` (tested).

## 4. Headers and Content-Security-Policy

An enforcing CSP is not added in 9A: Alpine.js evaluates its `x-` expressions with `new Function` (needs
`'unsafe-eval'`), Vite's dev server injects scripts, and `layouts/app` has one inline script for the first-paint
theme. A wrong policy would break every page days before the defense. A Report-Only policy to try after the defense
(all assets are local; no outside origins are loaded):

```
Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' 'unsafe-eval' 'sha256-<hash of the theme script>';
  style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none';
  base-uri 'self'; form-action 'self'; frame-ancestors 'self'
```

Then watch the browser console for reports, and only switch to the enforcing header when nothing is reported. Alpine's
CSP build (`@alpinejs/csp`) would remove `'unsafe-eval'`, at the cost of rewriting inline expressions.

## 5. Raw output and injection

| Where | Verdict |
|---|---|
| `{!! !!}` in Blade: only `components/chat/prompts.blade.php:18` | Safe: `Js::from()` JSON with HEX_TAG/APOS/QUOT/AMP inside an attribute whose template is a component prop written by developers. |
| `orderByRaw` in `Customer/MyBookingController.php:47,48` | Safe: fixed SQL, values bound. |
| `selectRaw('status, COUNT(*)...')` in `MyBookingController:57`, `MyOrderController:49`, `Owner/PhotographyBookingController:64`, `Owner/PrintingOrderController:54`, `ReportService:70`, `Tools/OrdersOverview:44`, `Tools/WalkInSummary:49` | Safe: fixed SQL. |
| `selectRaw` catalog stats in `Owner/PackageController:43,62`, `Owner/ServiceController:61,62`, `Owner/ThemeController:57,64`, `Public/HomeController:18,19`, `Public/PackageController:22`, `Public/ServiceController:40` | Safe: fixed SQL. |
| `whereRaw('LOWER(email) = ?')` `UpdateProfileRequest:48`, `whereRaw('LOWER(question) = ?')` `FaqService:131` | Safe: bound value. |
| `whereRaw` `Chat/StudioInbox:26` | Safe: column comparison only. |
| `selectRaw` money sums `PaymentLedgerService:84,161`, `IncomeReportService:36,139,212`, `CustomerDirectoryService:123`, `NotificationFeed:63`, `Tools/LumiUsage:44` | Safe: fixed SQL. |
| `IncomeReportService:210` `selectRaw("{$columns}, amount")` | Safe: `$columns` is built only from code constants (`grouped()` callers at lines 128, 158, 189), never from the request. |
| `ReportService:86` `selectRaw("{$column} as item ...")` | Safe: `$column` is `printing_service_id` or `photography_package_id` from code. |
| `RequestHistoryService:104` `DB::raw("'{$kind}' as kind")` | Safe: `$kind` is the literal `printing` or `photography` from code. |
| `CounterService:153,154` `DB::raw("'printing_order' as kind")` | Safe: literal. |
| `PaymentService:201` `DB::raw('updated_at')` | Safe: literal. |
| `PackageFilters:85,86` `orderByRaw('price is null')` | Safe: literal; the sort key is matched against a fixed list. |
| `unserialize`, `eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` | None in `app`, `routes`, `config` or views. |
| `Storage` / `file_get_contents` with paths | Paths come from database rows written by the app (record id + random name); `CaseBank` reads the eval case files from a fixed folder (console only). |
| Redirects with input | `afterLogin()` validated (F-11); `PaymentActionRequest::backUrl()`, `AccountRequest::pageUrl()`, `NotificationController::indexUrl()` and `WalkInController::refuse()` build `route()` addresses only; `back()` see F-11. |
| CSV exports | Both exports (`owner.reports.csv`, `owner.activity.csv`) pass every cell through `IncomeReportController::cell()` (leading apostrophe before = + - @ tab CR); the header rows are constants. No other export exists. |

## 6. Dependencies and secrets

`npm audit --omit=dev` and `npm audit`: 0 vulnerabilities.

`composer audit` (installed Symfony 7.4.19 to 7.4.20; each is fixed in 7.4.22 or 7.4.23):

| Package | Advisory | Reachable here? |
|---|---|---|
| symfony/http-foundation | CVE-2026-118514, HEAD requests bypass `security.access_control` methods | No: symfony/security is not used; Laravel routes check methods themselves. |
| symfony/http-foundation | Path rules in front of PHP bypassed by appending the path to the script name | Only with web-server path rules; the app protects routes in PHP. |
| symfony/http-foundation | The `private_ranges` trusted-proxy shortcut trusts mapped addresses | No: explicit addresses are used. |
| symfony/http-foundation | Generated URLs can point to a host chosen by the client | Yes (password reset link): closed by `trustHosts()` (F-06). |
| symfony/http-kernel | CVE-2026-123339, inline fragments and forwarded headers | No: no fragments or ESI. |
| symfony/mime (medium) | CVE-2026-45070, email header injection through MIME parameter names | Low: only the reset email, no user-controlled MIME parameters. |
| symfony/routing | CVE-2026-118516, host placeholders in compiled matchers | No: no host routes. |

Fix after the defense (patch level only): `composer update "symfony/*" -W`, then the full test suite.

Committed secrets (all tracked and untracked, not ignored, files; not `.env`, `vendor`, `node_modules`): five
matches, all fake test keys (27 characters, a real key is about 100), in tests that block real HTTP:
`tests/Feature/Assistant/Eval/AssistantEvalCommandTest.php:148`, `:176`,
`tests/Feature/Assistant/Eval/EvalCaseBankTest.php:132`, `tests/Feature/Assistant/Eval/EvalRetryTest.php:33`,
`tests/Feature/Assistant/GradPicsPriceTest.php:31`. `.env` is ignored and was never committed (`git log --all -- .env`
is empty). No private key, GitHub, Slack or AWS token pattern was found.

## 7. Errors and enumeration

- Production errors (`APP_DEBUG=false`): the static 500 page and `{"message":"Server Error"}` for JSON, never the
  exception text, class, stack trace or a path (tested).
- Login and forgot password never say whether an email exists; registration does (F-08).
- Ids in addresses are always behind a policy or the owner role (the matrix test); reference numbers (PR-, PB-, PAY-)
  are never an address, only shown or searched by the owner.

## 8. Preflight

`php artisan jhunlea:preflight` (read only; exit 1 on any FAIL; keys only "set" or "missing", a database error only
its kind). Checks: APP_ENV production, APP_DEBUG false, APP_KEY valid, APP_URL https, session cookie secure and http
only, trusted proxies (FAIL for `*`, WARN for none or loopback only), `storage` and `bootstrap/cache` writable, the
`public/storage` link (also a Windows junction), PHP upload limits against the shop's (`ShopSettings::capped()`),
extensions (curl, openssl, mbstring, fileinfo, pdo_mysql, gd or imagick), MySQL (not SQLite) and the connection, the
audit chain (`AuditChain::verify()`, which stores its result in the cache as `audit:verify` does), the Lumi keys and a
real provider when AI_ENABLED, the CA bundle when AI_ENABLED (WARN), the scheduler reminder (WARN), and the config
and route caches (WARN).

## 9. Route inventory

Generated from `php artisan route:list --json` (180 routes). Cost: upload, export, download, AI, search.

| Method | URI | Name | Middleware | Authorization | Who | Changes data | Cost | Named throttle |
|---|---|---|---|---|---|---|---|---|
| GET | `/` | `home` | throttle:60,1,public-home | published records only | anyone | no |  | yes |
| POST | `assistant/messages` | `assistant.guest` | guest, throttle:10,1,assistant-guest | - | guest only | yes | AI | yes |
| GET | `booking-images/{image}` | `booking-images.show` | auth, throttle:240,1,booking-image-show | BookingReferenceImagePolicy | owner or the record's customer | no | download | yes |
| GET | `booking-images/{image}/download` | `booking-images.download` | auth, throttle:30,1,booking-image-download | BookingReferenceImagePolicy | owner or the record's customer | no | download | yes |
| GET | `faq` | `faq` | throttle:60,1,public-faq | published records only | anyone | no |  | yes |
| GET | `files/{file}` | `files.download` | auth, throttle:30,1,files-download | OrderFilePolicy | owner or the record's customer | no | download | yes |
| GET | `forgot-password` | `password.request` | guest, throttle:60,1,password-request-form | - | guest only | no |  | yes |
| POST | `forgot-password` | `password.email` | guest, throttle:6,1,password-email | - | guest only | yes |  | yes |
| GET | `login` | `login` | guest, throttle:60,1,login-form | - | guest only | no |  | yes |
| POST | `login` | - | guest, throttle:20,1,login | - | guest only | yes |  | yes |
| POST | `logout` | `logout` | auth, throttle:30,1,logout | - | owner or the record's customer | yes |  | yes |
| GET | `my/account` | `customer.account.show` | auth, role:customer, throttle:60,1,customer-account | queries start from the signed-in user | customer | no |  | yes |
| POST | `my/account/password` | `customer.account.password` | auth, role:customer, throttle:6,1,customer-account-password | queries start from the signed-in user | customer | yes |  | yes |
| POST | `my/account/profile` | `customer.account.profile` | auth, role:customer, throttle:10,1,customer-account-profile | queries start from the signed-in user | customer | yes |  | yes |
| POST | `my/account/theme` | `customer.account.theme` | auth, role:customer, throttle:30,1,customer-account-theme | queries start from the signed-in user | customer | yes |  | yes |
| GET | `my/assistant` | `customer.assistant.page` | auth, role:customer, throttle:60,1,chat-page | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/assistant/conversations` | `customer.assistant.index` | auth, role:customer, throttle:60,1,chat-assistant-list | queries start from the signed-in user | customer | no |  | yes |
| POST | `my/assistant/conversations` | `customer.assistant.store` | auth, role:customer, throttle:10,1,chat-assistant-new | queries start from the signed-in user | customer | yes |  | yes |
| GET | `my/assistant/conversations/{conversation}/handoff-draft` | `customer.assistant.handoff-draft` | auth, role:customer, throttle:20,1,chat-handoff-draft | ConversationPolicy (404) | customer | no |  | yes |
| GET | `my/bookings` | `customer.bookings.index` | auth, role:customer, throttle:60,1,booking-list | queries start from the signed-in user | customer | no |  | yes |
| POST | `my/bookings` | `customer.bookings.store` | auth, role:customer, throttle:10,1,booking-store | queries start from the signed-in user | customer | yes | upload | yes |
| GET | `my/bookings/new` | `customer.bookings.create` | auth, role:customer, throttle:60,1,booking-new | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/bookings/slots` | `customer.bookings.slots` | auth, role:customer, throttle:120,1,booking-slots | queries start from the signed-in user | customer | no | search | yes |
| GET | `my/bookings/{booking}` | `customer.bookings.show` | auth, role:customer, throttle:60,1,booking-show | PhotographyBookingPolicy | customer | no |  | yes |
| POST | `my/bookings/{booking}/accept` | `customer.bookings.accept` | auth, role:customer, throttle:10,1,booking-accept | PhotographyBookingPolicy | customer | yes |  | yes |
| POST | `my/bookings/{booking}/another-time` | `customer.bookings.another-time` | auth, role:customer, throttle:10,1,booking-another-time | PhotographyBookingPolicy | customer | yes |  | yes |
| POST | `my/bookings/{booking}/cancel` | `customer.bookings.cancel` | auth, role:customer, throttle:10,1,booking-cancel | PhotographyBookingPolicy | customer | yes |  | yes |
| GET | `my/bookings/{booking}/sent` | `customer.bookings.sent` | auth, role:customer, throttle:60,1,booking-sent | PhotographyBookingPolicy | customer | no |  | yes |
| GET | `my/dashboard` | `customer.dashboard` | auth, role:customer, throttle:60,1,customer-dashboard | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/messages/studio` | `customer.messages.studio` | auth, role:customer, throttle:30,1,chat-open | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/messages/unread` | `customer.messages.unread` | auth, role:customer, throttle:60,1,chat-unread | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/messages/{conversation}` | `customer.messages.show` | auth, role:customer, throttle:60,1,chat-poll | ConversationPolicy (404) | customer | no |  | yes |
| POST | `my/messages/{conversation}` | `customer.messages.store` | auth, role:customer, throttle:20,1,chat-send | ConversationPolicy (404) | customer | yes | AI (away) | yes |
| POST | `my/messages/{conversation}/lumi` | `customer.messages.lumi` | auth, role:customer, throttle:12,1,chat-lumi | ConversationPolicy (404) | customer | yes | AI | yes |
| POST | `my/messages/{conversation}/read` | `customer.messages.read` | auth, role:customer, throttle:60,1,chat-read | ConversationPolicy (404) | customer | yes |  | yes |
| GET | `my/notifications` | `customer.notifications.index` | auth, role:customer, throttle:60,1,customer-notifications | queries start from the signed-in user | customer | no |  | yes |
| POST | `my/notifications/read-all` | `customer.notifications.read-all` | auth, role:customer, throttle:20,1,customer-notifications-read-all | queries start from the signed-in user | customer | yes |  | yes |
| GET | `my/notifications/{notification}` | `customer.notifications.go` | auth, role:customer, throttle:60,1,customer-notification-go | own rows only (404) | customer | no |  | yes |
| POST | `my/notifications/{notification}/open` | `customer.notifications.open` | auth, role:customer, throttle:60,1,customer-notification-open | own rows only (404) | customer | yes |  | yes |
| POST | `my/notifications/{notification}/read` | `customer.notifications.read` | auth, role:customer, throttle:60,1,customer-notification-read | own rows only (404) | customer | yes |  | yes |
| GET | `my/printing` | `customer.printing.index` | auth, role:customer, throttle:60,1,printing-list | queries start from the signed-in user | customer | no |  | yes |
| POST | `my/printing` | `customer.printing.store` | auth, role:customer, throttle:10,1,printing-store | queries start from the signed-in user | customer | yes | upload | yes |
| GET | `my/printing/estimate` | `customer.printing.estimate` | auth, role:customer, throttle:120,1,printing-estimate | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/printing/new` | `customer.printing.create` | auth, role:customer, throttle:60,1,printing-new | queries start from the signed-in user | customer | no |  | yes |
| GET | `my/printing/{order}` | `customer.printing.show` | auth, role:customer, throttle:60,1,printing-show | PrintingOrderPolicy | customer | no |  | yes |
| POST | `my/printing/{order}/cancel` | `customer.printing.cancel` | auth, role:customer, throttle:10,1,printing-cancel | PrintingOrderPolicy | customer | yes |  | yes |
| POST | `my/printing/{order}/files` | `customer.printing.files.store` | auth, role:customer, throttle:10,1,printing-files-add | PrintingOrderPolicy | customer | yes | upload | yes |
| DELETE | `my/printing/{order}/files/{file}` | `customer.printing.files.destroy` | auth, role:customer, throttle:10,1,printing-files-remove | OrderFilePolicy | customer | yes |  | yes |
| GET | `my/printing/{order}/placed` | `customer.printing.placed` | auth, role:customer, throttle:60,1,printing-placed | PrintingOrderPolicy | customer | no |  | yes |
| GET | `my/requests` | `customer.requests.index` | auth, role:customer, throttle:60,1,customer-requests | queries start from the signed-in user | customer | no |  | yes |
| GET | `owner` | `owner.dashboard` | auth, role:owner, throttle:120,1,owner-dashboard | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/account` | `owner.account.show` | auth, role:owner, throttle:60,1,owner-account | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/account/password` | `owner.account.password` | auth, role:owner, throttle:6,1,owner-account-password | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/account/profile` | `owner.account.profile` | auth, role:owner, throttle:10,1,owner-account-profile | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/account/theme` | `owner.account.theme` | auth, role:owner, throttle:30,1,owner-account-theme | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/activity` | `owner.activity.index` | auth, role:owner, throttle:60,1,owner-activity | role:owner (one owner, all records) | owner | no | search | yes |
| GET | `owner/activity/csv` | `owner.activity.csv` | auth, role:owner, throttle:10,1,owner-activity-csv | role:owner (one owner, all records) | owner | no (logs an audit row) | export | yes |
| GET | `owner/assistant` | `owner.assistant.index` | auth, role:owner, throttle:60,1,owner-ask-page | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/assistant/ask` | `owner.assistant.ask` | auth, role:owner, throttle:20,1,owner-ask-question | role:owner (one owner, all records) | owner | yes | AI | yes |
| GET | `owner/assistant/briefing` | `owner.assistant.briefing` | auth, role:owner, throttle:30,1,owner-ask-briefing | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/assistant/history` | `owner.assistant.history` | auth, role:owner, throttle:60,1,owner-ask-history | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/bookings` | `owner.bookings.index` | auth, role:owner, throttle:120,1,owner-booking-list | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/bookings/walk-in` | `owner.bookings.walk-in.store` | auth, role:owner, throttle:20,1,owner-walkin-booking-store | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/bookings/walk-in/new` | `owner.bookings.walk-in.create` | auth, role:owner, throttle:60,1,owner-walkin-booking-form | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/bookings/walk-in/slots` | `owner.bookings.walk-in.slots` | auth, role:owner, throttle:120,1,owner-walkin-booking-slots | role:owner (one owner, all records) | owner | no | search | yes |
| GET | `owner/bookings/{booking}` | `owner.bookings.show` | auth, role:owner, throttle:120,1,owner-booking-show | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/bookings/{booking}/cancel` | `owner.bookings.cancel` | auth, role:owner, throttle:30,1,owner-booking-cancel | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/complete` | `owner.bookings.complete` | auth, role:owner, throttle:30,1,owner-booking-complete | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/confirm` | `owner.bookings.confirm` | auth, role:owner, throttle:30,1,owner-booking-confirm | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/notes` | `owner.bookings.notes` | auth, role:owner, throttle:30,1,owner-booking-notes | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/payments` | `owner.bookings.payments.store` | auth, role:owner, throttle:20,1,owner-booking-payment-record | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/payments/{payment}/void` | `owner.bookings.payments.void` | auth, role:owner, throttle:20,1,owner-booking-payment-void | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/price` | `owner.bookings.price` | auth, role:owner, throttle:30,1,owner-booking-price | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/propose` | `owner.bookings.propose` | auth, role:owner, throttle:30,1,owner-booking-propose | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/bookings/{booking}/reject` | `owner.bookings.reject` | auth, role:owner, throttle:30,1,owner-booking-reject | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/customers` | `owner.customers.index` | auth, role:owner, throttle:120,1,owner-customers | role:owner (one owner, all records) | owner | no | search | yes |
| GET | `owner/customers/{customer}` | `owner.customers.show` | auth, role:owner, throttle:120,1,owner-customer-show | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/faqs` | `owner.faqs.index` | auth, role:owner, throttle:60,1,owner-faqs | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/faqs` | `owner.faqs.store` | auth, role:owner, throttle:20,1,owner-faq-store | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/faqs/create` | `owner.faqs.create` | auth, role:owner, throttle:60,1,owner-faq-create | role:owner (one owner, all records) | owner | no |  | yes |
| DELETE | `owner/faqs/{faq}` | `owner.faqs.destroy` | auth, role:owner, throttle:20,1,owner-faq-destroy | role:owner (one owner, all records) | owner | yes |  | yes |
| PUT | `owner/faqs/{faq}` | `owner.faqs.update` | auth, role:owner, throttle:20,1,owner-faq-update | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/faqs/{faq}/delete` | `owner.faqs.confirm-delete` | auth, role:owner, throttle:60,1,owner-faq-confirm-delete | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/faqs/{faq}/edit` | `owner.faqs.edit` | auth, role:owner, throttle:60,1,owner-faq-edit | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/faqs/{faq}/move` | `owner.faqs.move` | auth, role:owner, throttle:60,1,owner-faq-move | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/faqs/{faq}/publish` | `owner.faqs.publish` | auth, role:owner, throttle:30,1,owner-faq-publish | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/messages` | `owner.messages.index` | auth, role:owner, throttle:60,1,owner-chat-inbox | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/messages/presence` | `owner.messages.presence` | auth, role:owner, throttle:60,1,owner-chat-presence | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/messages/presence` | `owner.messages.presence.update` | auth, role:owner, throttle:20,1,owner-chat-presence-set | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/messages/presence/heartbeat` | `owner.messages.presence.heartbeat` | auth, role:owner, throttle:6,1,owner-chat-heartbeat | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/messages/{conversation}` | `owner.messages.show` | auth, role:owner, throttle:60,1,owner-chat-poll | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/messages/{conversation}` | `owner.messages.store` | auth, role:owner, throttle:30,1,owner-chat-send | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/messages/{conversation}/assist/suggest` | `owner.messages.assist.suggest` | auth, role:owner, throttle:6,1,owner-chat-suggest | role:owner (one owner, all records) | owner | yes | AI | yes |
| POST | `owner/messages/{conversation}/assist/summarize` | `owner.messages.assist.summarize` | auth, role:owner, throttle:6,1,owner-chat-summarize | role:owner (one owner, all records) | owner | yes | AI | yes |
| POST | `owner/messages/{conversation}/read` | `owner.messages.read` | auth, role:owner, throttle:60,1,owner-chat-read | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/messages/{conversation}/reopen` | `owner.messages.reopen` | auth, role:owner, throttle:30,1,owner-chat-reopen | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/messages/{conversation}/resolve` | `owner.messages.resolve` | auth, role:owner, throttle:30,1,owner-chat-resolve | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/notifications` | `owner.notifications.index` | auth, role:owner, throttle:60,1,owner-notifications | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/notifications/read-all` | `owner.notifications.read-all` | auth, role:owner, throttle:20,1,owner-notifications-read-all | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/notifications/{notification}` | `owner.notifications.go` | auth, role:owner, throttle:60,1,owner-notification-go | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/notifications/{notification}/open` | `owner.notifications.open` | auth, role:owner, throttle:60,1,owner-notification-open | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/notifications/{notification}/read` | `owner.notifications.read` | auth, role:owner, throttle:60,1,owner-notification-read | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/packages` | `owner.packages.index` | auth, role:owner, throttle:120,1,owner-package-list | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/packages` | `owner.packages.store` | auth, role:owner, throttle:30,1,owner-package-store | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/packages/create` | `owner.packages.create` | auth, role:owner, throttle:60,1,owner-package-create | role:owner (one owner, all records) | owner | no |  | yes |
| DELETE | `owner/packages/{package}` | `owner.packages.destroy` | auth, role:owner, throttle:30,1,owner-package-destroy | role:owner (one owner, all records) | owner | yes |  | yes |
| PUT | `owner/packages/{package}` | `owner.packages.update` | auth, role:owner, throttle:30,1,owner-package-update | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/packages/{package}/edit` | `owner.packages.edit` | auth, role:owner, throttle:120,1,owner-package-edit | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/packages/{package}/feature` | `owner.packages.feature` | auth, role:owner, throttle:30,1,owner-package-feature | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/packages/{package}/images` | `owner.packages.images.store` | auth, role:owner, throttle:20,1,owner-package-image-upload | role:owner (one owner, all records) | owner | yes | upload | yes |
| DELETE | `owner/packages/{package}/images/{image}` | `owner.packages.images.destroy` | auth, role:owner, throttle:60,1,owner-package-image-destroy | role:owner (one owner, all records) | owner | yes |  | yes |
| PUT | `owner/packages/{package}/images/{image}` | `owner.packages.images.update` | auth, role:owner, throttle:60,1,owner-package-image-update | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/packages/{package}/images/{image}/cover` | `owner.packages.images.cover` | auth, role:owner, throttle:60,1,owner-package-image-cover | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/packages/{package}/publish` | `owner.packages.publish` | auth, role:owner, throttle:30,1,owner-package-publish | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/payments` | `owner.payments.index` | auth, role:owner, throttle:120,1,owner-payments-ledger | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/payments/counter` | `owner.payments.counter.record` | auth, role:owner, throttle:30,1,owner-counter-record | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/payments/counter/search` | `owner.payments.counter.search` | auth, role:owner, throttle:120,1,owner-counter-search | role:owner (one owner, all records) | owner | no | search | yes |
| GET | `owner/payments/counter/target` | `owner.payments.counter.target` | auth, role:owner, throttle:120,1,owner-counter-target | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/payments/{payment}/receipt` | `owner.payments.receipt` | auth, role:owner, throttle:60,1,owner-payment-receipt | role:owner (one owner, all records) | owner | no | export | yes |
| GET | `owner/printing-orders` | `owner.orders.index` | auth, role:owner, throttle:120,1,owner-order-list | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/printing-orders/walk-in` | `owner.orders.walk-in.store` | auth, role:owner, throttle:20,1,owner-walkin-order-store | role:owner (one owner, all records) | owner | yes | upload | yes |
| GET | `owner/printing-orders/walk-in/new` | `owner.orders.walk-in.create` | auth, role:owner, throttle:60,1,owner-walkin-order-form | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/printing-orders/walk-in/price` | `owner.orders.walk-in.price` | auth, role:owner, throttle:120,1,owner-walkin-order-price | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/printing-orders/{order}` | `owner.orders.show` | auth, role:owner, throttle:120,1,owner-order-show | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/printing-orders/{order}/accept` | `owner.orders.accept` | auth, role:owner, throttle:30,1,owner-order-accept | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/cancel` | `owner.orders.cancel` | auth, role:owner, throttle:30,1,owner-order-cancel | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/complete` | `owner.orders.complete` | auth, role:owner, throttle:30,1,owner-order-complete | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/info` | `owner.orders.info` | auth, role:owner, throttle:30,1,owner-order-info | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/payments` | `owner.orders.payments.store` | auth, role:owner, throttle:20,1,owner-order-payment-record | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/payments/{payment}/void` | `owner.orders.payments.void` | auth, role:owner, throttle:20,1,owner-order-payment-void | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/price` | `owner.orders.price` | auth, role:owner, throttle:30,1,owner-order-price | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/processing` | `owner.orders.processing` | auth, role:owner, throttle:30,1,owner-order-processing | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/ready` | `owner.orders.ready` | auth, role:owner, throttle:30,1,owner-order-ready | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/reject` | `owner.orders.reject` | auth, role:owner, throttle:30,1,owner-order-reject | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/printing-orders/{order}/review` | `owner.orders.review` | auth, role:owner, throttle:30,1,owner-order-review | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/printing-orders/{order}/walk-in-saved` | `owner.orders.walk-in.done` | auth, role:owner, throttle:60,1,owner-walkin-order-done | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/reports` | `owner.reports.index` | auth, role:owner, throttle:60,1,owner-reports | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/reports/income` | `owner.reports.income` | auth, role:owner, throttle:60,1,owner-income-report | role:owner (one owner, all records) | owner | no |  | yes |
| GET | `owner/reports/income/csv` | `owner.reports.csv` | auth, role:owner, throttle:10,1,owner-income-csv | role:owner (one owner, all records) | owner | no (logs an audit row) | export | yes |
| GET | `owner/reports/income/end-of-day` | `owner.reports.end-of-day` | auth, role:owner, throttle:30,1,owner-income-end-of-day | role:owner (one owner, all records) | owner | no | export | yes |
| GET | `owner/schedule` | `owner.schedule.index` | auth, role:owner, throttle:120,1,owner-schedule-calendar | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/schedule/blocked` | `owner.schedule.block` | auth, role:owner, throttle:20,1,owner-schedule-block | role:owner (one owner, all records) | owner | yes |  | yes |
| DELETE | `owner/schedule/blocked/{blockedDate}` | `owner.schedule.unblock` | auth, role:owner, throttle:30,1,owner-schedule-unblock | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/schedule/edit` | `owner.schedule.edit` | auth, role:owner, throttle:60,1,owner-schedule-edit | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/schedule/hours` | `owner.schedule.hours` | auth, role:owner, throttle:20,1,owner-schedule-hours | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/schedule/settings` | `owner.schedule.settings` | auth, role:owner, throttle:20,1,owner-schedule-settings | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/services` | `owner.services.index` | auth, role:owner, throttle:120,1,owner-service-list | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/services` | `owner.services.store` | auth, role:owner, throttle:30,1,owner-service-store | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/services/create` | `owner.services.create` | auth, role:owner, throttle:60,1,owner-service-create | role:owner (one owner, all records) | owner | no |  | yes |
| DELETE | `owner/services/{service}` | `owner.services.destroy` | auth, role:owner, throttle:30,1,owner-service-destroy | role:owner (one owner, all records) | owner | yes |  | yes |
| PUT | `owner/services/{service}` | `owner.services.update` | auth, role:owner, throttle:30,1,owner-service-update | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/services/{service}/edit` | `owner.services.edit` | auth, role:owner, throttle:120,1,owner-service-edit | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/services/{service}/options` | `owner.services.options.store` | auth, role:owner, throttle:60,1,owner-option-store | role:owner (one owner, all records) | owner | yes |  | yes |
| DELETE | `owner/services/{service}/options/{option}` | `owner.services.options.destroy` | auth, role:owner, throttle:60,1,owner-option-destroy | role:owner (one owner, all records) | owner | yes |  | yes |
| PUT | `owner/services/{service}/options/{option}` | `owner.services.options.update` | auth, role:owner, throttle:60,1,owner-option-update | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/services/{service}/publish` | `owner.services.publish` | auth, role:owner, throttle:30,1,owner-service-publish | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/settings` | `owner.settings.index` | auth, role:owner, throttle:60,1,owner-settings | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/settings/business` | `owner.settings.business` | auth, role:owner, throttle:20,1,owner-settings-business | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/settings/uploads` | `owner.settings.uploads` | auth, role:owner, throttle:20,1,owner-settings-uploads | role:owner (one owner, all records) | owner | yes |  | yes |
| POST | `owner/settings/uploads/reset` | `owner.settings.uploads.reset` | auth, role:owner, throttle:10,1,owner-settings-uploads-reset | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/themes` | `owner.themes.index` | auth, role:owner, throttle:120,1,owner-theme-list | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/themes` | `owner.themes.store` | auth, role:owner, throttle:20,1,owner-theme-store | role:owner (one owner, all records) | owner | yes | upload | yes |
| GET | `owner/themes/create` | `owner.themes.create` | auth, role:owner, throttle:60,1,owner-theme-create | role:owner (one owner, all records) | owner | no |  | yes |
| DELETE | `owner/themes/{theme}` | `owner.themes.destroy` | auth, role:owner, throttle:30,1,owner-theme-destroy | role:owner (one owner, all records) | owner | yes | upload | yes |
| PUT | `owner/themes/{theme}` | `owner.themes.update` | auth, role:owner, throttle:20,1,owner-theme-update | role:owner (one owner, all records) | owner | yes | upload | yes |
| GET | `owner/themes/{theme}/edit` | `owner.themes.edit` | auth, role:owner, throttle:120,1,owner-theme-edit | role:owner (one owner, all records) | owner | no |  | yes |
| POST | `owner/themes/{theme}/publish` | `owner.themes.publish` | auth, role:owner, throttle:30,1,owner-theme-publish | role:owner (one owner, all records) | owner | yes |  | yes |
| GET | `owner/walk-in/customers` | `owner.walk-in.customers` | auth, role:owner, throttle:120,1,owner-walkin-customers | role:owner (one owner, all records) | owner | no | search | yes |
| GET | `packages` | `packages.index` | throttle:60,1,public-packages | published records only | anyone | no |  | yes |
| GET | `packages/compare` | `packages.compare` | throttle:60,1,public-packages-compare | published records only | anyone | no |  | yes |
| GET | `packages/finder` | `packages.finder` | throttle:60,1,public-packages-finder | published records only | anyone | no |  | yes |
| GET | `packages/{slug}` | `packages.show` | throttle:60,1,public-package-show | published records only | anyone | no |  | yes |
| GET | `register` | `register` | guest, throttle:60,1,register-form | - | guest only | no |  | yes |
| POST | `register` | - | guest, throttle:10,1,register | - | guest only | yes |  | yes |
| POST | `reset-password` | `password.update` | guest, throttle:6,1,password-reset | - | guest only | yes |  | yes |
| GET | `reset-password/{token}` | `password.reset` | guest, throttle:60,1,password-reset-form | - | guest only | no |  | yes |
| GET | `services` | `services.index` | throttle:60,1,public-services | published records only | anyone | no |  | yes |
| GET | `ui-kit` | - |  | 404 unless APP_ENV=local | framework | no |  | n/a |
| GET | `up` | - |  | - | framework | no |  | n/a |
| GET | `{fallbackPlaceholder}` | - |  | - | framework | no |  | n/a |

## 10. Open items

- Composer patch update (F-07), after the defense.
- Trusted proxies and the Report-Only CSP on the real host (F-12, F-10).
- The `.htaccess` rule is Apache-only (F-09): another web server needs its own rule.
- Real lock contention (audit chain head), real https cookies and the Host check need the real host: the preflight
  and the developer steps in the Phase 9A report.
