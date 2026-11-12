# Mobile and accessibility QA (Phase 9C)

Branch `phase-9c-mobile`, from `main` after 9B-2. Every demo-critical screen was opened at 360, 375, 390, 414, 768,
1024 and 1280 px, each in light and dark mode, on a throwaway SQLite database with fake data (PHP's built-in server
started inside `public`, production build). No behaviour, route, data or copy changed except where a fix needed it.

## Method

- A small script (not committed) loaded each page in a same-origin iframe of the target width, switched light and dark
  with the `dark` class (transitions off), and measured: horizontal overflow (scrollWidth against clientWidth), every
  visible link, button, input, select, summary and tab under 44 x 44 px (at 768 px and below), inputs under 16 px (at
  414 px and below), images without `alt`, controls without an accessible name, duplicate ids, the number of `h1` and
  skipped heading levels, and text contrast under 4.5:1 (3:1 for large text) on a solid background.
- Screens that need interaction (wizard steps, the Record payment modal, the chat tabs and chooser, the booking
  calendar, the income charts) were also opened by hand at 360 px in the browser pane.
- JavaScript off: each page was loaded in an iframe sandboxed WITHOUT scripts and checked for its content and forms.
- Reduced motion: `prefers-reduced-motion: reduce` was emulated and the computed animation and transition times read.
- axe-core was not used (no new package; the checks above cover the same basics). The numbers are approximate where a
  text sits on an image or a gradient (those are skipped, not counted as passes).

## Results: 66 screens x 7 widths x 2 themes (924 checks)

ok = nothing found; fixed = fixed in 9C (the fix applies at every width); accepted = see "Accepted leftovers".

| Area | Screens | 360 | 375 | 390 | 414 | 768 | 1024 | 1280 | Result |
|---|---|---|---|---|---|---|---|---|---|
| Public | landing, packages list, package detail, compare, finder, services, FAQ | fixed | fixed | fixed | fixed | accepted | ok | ok | breadcrumb and footer links widened to 44 px; services search 16 px; compare heading order; dark brand-text contrast |
| Auth | login, register, forgot password | ok | ok | ok | ok | ok | ok | ok | labels, errors announced (fixed for all forms) |
| Customer | dashboard, new printing order (4 steps), my printing list and detail, new booking (6 steps), my bookings list and detail, notifications, requests, account | fixed | fixed | fixed | fixed | accepted | ok | ok | header logo link 44 px; status pill contrast; wizard step labels wrap; table scroll boxes contained |
| Customer chat | panel, `/my/assistant` Ask Lumi and Studio | ok | ok | ok | ok | accepted | ok | ok | ARIA tabs pattern (fixed); chooser and panel heights verified |
| Owner work | overview, printing orders list and detail, bookings list and detail, schedule month, week, day | fixed | fixed | fixed | fixed | accepted | ok | fixed | 134 px horizontal overflow of the orders list at 1280 (hidden labels escaping the table's scroll box); schedule Edit link; amber chip and internal-notes contrast |
| Owner counter and reports | Payments counter, Record payment modal, payment slip, ready and awaiting queues, walk-in order and booking forms, income report daily, weekly, monthly, end-of-day | fixed | ok | ok | ok | accepted | ok | ok | reference links 44 px tall on phones; header fits 320 px |
| Owner people and tools | customers list and detail, Settings, Activity log, owner Ask Lumi page and panel, notifications, inbox and thread | fixed | ok | ok | ok | accepted | ok | ok | header fits 320 px; one h1 in the open thread on phones |
| Tier 2 | services, options, packages (image upload), themes, FAQ editor, schedule editor and blocked dates, owner account, ledger filters, reports page, 403, 404 (500 is static, 429 has its own view) | fixed | ok | ok | ok | accepted | ok | ok | FAQ editor breadcrumb link 44 px |

Also checked: 320 px (the owner and customer headers fit), 640 px for 200% zoom (14 tier 1 screens: no overflow, nothing unlabelled), and the hand checks listed under Method.

## Fixes

| Area | Fix | File |
|---|---|---|
| Overflow | A table's scroll box is `relative`, so the screen-reader-only labels inside its rows no longer escape it (the owner orders list scrolled 134 px sideways at 1280) | owner orders, bookings, customers, payments, services, income report; customer printing, requests, bookings lists; owner inbox tabs |
| Tap targets | `min-w-11` on short links: breadcrumb "Home", footer links, header logo links, schedule "Edit", FAQ editor breadcrumb | `public/*`, `layouts/public`, `layouts/customer`, `layouts/owner`, `owner/schedule/index`, `owner/faqs/create`, `edit` |
| Tap targets | Ledger and queue reference links 44 px tall on phones | `owner/payments/index`, `_queues`, `owner/reports/income` |
| Header | Owner header shows only the mark below 420 px, so bell, theme and account fit 320 px | `layouts/owner` |
| Inputs | Services search 16 px on phones | `public/services/index` |
| Contrast | Dark mode: brand-red text uses the light brand tint (#ffb4ac) unless the element sets its own dark colour or sits on a white chip | `resources/css/app.css` |
| Contrast | Light mode: a status pill or notice with its own tint uses a darker shade of its hue (amber, green, red) | `resources/css/app.css` |
| Contrast | Calendar request chips and the internal-notes heading (tint at 60%) use the darker amber in light mode | `owner/schedule/_chip`, `owner/bookings/show` |
| Focus | Focus ring uses the light brand tint on dark surfaces (the owner sidebar was 2.3:1) | `resources/css/app.css` |
| Headings | Empty state takes a `level` (2 on the compare page); a hidden h1 in the owner thread view on phones | `components/ui/empty-state`, `public/packages/compare`, `owner/messages/index` |
| Wizards | Step labels wrap on phones instead of "Choose..."; long file names show in full on hover or long press | `customer/printing/create`, `customer/bookings/create` |
| Chat | ARIA tabs: ids, `aria-controls`, a `tabpanel` labelled by the chosen tab, roving `tabindex`, Left/Right/Home/End | `components/chat/window` |
| Errors | A field error is `role="alert"` (it was already linked with `aria-describedby` and `aria-invalid`) | `components/ui/field` |
| No JavaScript | `<noscript>` notice on both wizards and the chat page | `customer/printing/create`, `customer/bookings/create`, `customer/assistant/page` |

## Accessibility, reduced motion, JavaScript off

- Already in place and verified: `lang` and the viewport meta on every layout and the static 500 page; a skip link;
  modals trap focus, close on Escape and return focus; toasts are `role="status"` or `alert` in an `aria-live`
  region; every icon-only button has an accessible name (no unnamed control found on any screen); no image without
  `alt`; no duplicate id; status pills always carry their text.
- Reduced motion: the global rule at the end of `app.css` cuts every animation and transition to 0.01 ms and hides the
  page loader; the chat typing dots also stop on their own. (The browser pane cannot emulate the media query, so the
  compiled rule was checked instead.)
- JavaScript off (scripts blocked in a sandboxed frame): every public page, the sign-in forms, the customer and owner
  lists and details render their content and keep their plain POST forms (cancel, accept, owner actions without a
  dialog); the page loader stays hidden. Need JavaScript: the phone menu drawer, the theme toggle, the "show password"
  eye, owner actions that open a dialog (reasons, prices), the wizards and the chat (the last three now say so).

## Accepted leftovers

| What | Where | Why |
|---|---|---|
| 32 to 36 px tall buttons, chips and tabs | from 640 px up (tablet, desktop) | The design's compact size (DESIGN.md: buttons 44 px, compact 36 px). Phones (below 640 px) always get 44 px. |
| Short links inside a sentence ("Log in", "Message the studio" in a line of text) | footers and notes | Inline text links are exempt from the target size rule (WCAG 2.5.8); they keep a 44 px tall hit area on phones. |
| Text on photos (package card labels) | package cards | Not measurable automatically; checked by eye: the labels sit on a solid chip. |
| Wizards and the chat need JavaScript | `/my/printing/new`, `/my/bookings/new`, `/my/assistant` | They now show a clear `<noscript>` notice instead of a half-working page. |

## Real-phone checklist (about 15 minutes, for the developer)

Use the shop's real address over https if it is hosted, or the laptop's address on the same Wi-Fi
(`php artisan serve --host=0.0.0.0` and `http://<laptop-ip>:8000`). Use a QA customer and the owner account; never a
real customer's account.

1. **Customer, printing order with a photo (Android Chrome and iPhone Safari):** log in, open `/my/printing/new`,
   choose Photo Printing, set options, then in step 3 tap "Choose the files" and pick a photo from the GALLERY and one
   from the CAMERA. Check: the keyboard never zooms the page when a field is tapped; the Continue buttons are not
   hidden by the Chat button; the file name shows (long names end with "..." and the full name appears on long press);
   send the order and land on the "sent" page.
2. **Customer, book a photoshoot:** `/my/bookings/new`, pick a package, a theme, a day in the calendar (each day is
   easy to tap), a free time, add a reference photo from the gallery, send.
3. **Customer, chat with the soft keyboard up:** `/my/assistant`, choose "Message the studio", tap the message box.
   Check: the box stays above the keyboard, the last message is visible, Send is tappable; rotate the phone once.
4. **Owner, record a payment at the counter:** `/owner/payments`, search the order number, open Record payment. Check:
   the dialog fits the screen and scrolls inside, the Cash/GCash choice and the checkbox are easy to tap, Continue
   works; then open the payment slip and use the browser's Print preview (A4, one page).
5. **Owner, income report:** `/owner/reports/income`, switch Daily, Weekly, Monthly; scroll the charts and the table
   sideways inside their box (the page itself must not move sideways); open the end-of-day summary and its Print preview.
6. **Both themes:** switch dark mode with the header button on one customer and one owner page.
7. **Accessibility spot check:** turn on TalkBack (Android) or VoiceOver (iPhone) on the login page: each field reads its
   label, a wrong password reads the error; in the chat the two tabs read as "tab, 1 of 2".

## Tests

`tests/Feature/MobileAccessibilityTest.php`: the viewport meta, `lang` and one `h1` on every layout (public, auth, error
page, customer, owner); the chat tabs' ARIA attributes, roving tabindex and arrow keys; the `<noscript>` notices on both
wizards and the chat page; every field of the login, register and forgot-password forms has a label; a form error is
`role="alert"` and linked with `aria-describedby`; the compare page keeps the heading order.
