# UI audit (Phase 10D)

The final visual and experience pass before the defense. Branch `phase-10d-ui`. No business rule, money logic or data
was changed; one migration only adds `shop_settings.facebook_url`.

## How it was checked

- A throwaway QA site: a SQLite file outside the project, PHP's built-in server started inside `public`, the log mailer,
  the fake AI provider. Fake data only: 3 customers, 1 walk-in, 1 owner, 18 bookings and 21 orders in every status
  (Requested, Expired, New time proposed, Awaiting downpayment, Confirmed, Completed, Rejected, Cancelled; Submitted to
  Unclaimed), payments of every kind (full, downpayment, balance, voided), notifications, a studio chat thread,
  packages with and without photos, one very long package name, long notes.
- **By script, every screen:** 12 public and guest pages, 16 customer pages and 29 owner pages were loaded at 375, 768,
  1024 and 1280 px (228 page loads) and measured for: sideways scroll, anything wider than its card, tap targets under
  44 px and inputs under 16 px on phones, fields of one row that do not line up, heading count, tables on phones and
  blocks much narrower than the page.
- **By eye, the screens named in the findings and the worst ones:** landing, services, packages (list, detail, filter
  sheet), booking wizard, customer dashboard and booking page, owner schedule editor and calendar, payments and counter,
  income report, booking page with the confirmation dialog. At 375 px and 1280 px, light and dark for the public pages
  and the schedule editor.
- **Not done:** a screenshot of every screen at every width in both themes (57 screens x 4 widths x 2 themes), and a
  real phone. Dark mode was checked by eye on the landing page, the packages list and the schedule editor only; the
  new components use the existing theme tokens.

Severity: **A** ugly or broken, **B** uneven, **C** nice to have.

## Findings and fixes

| # | Screen | Problem | Sev. | Fix |
|---|---|---|---|---|
| F1 | Owner schedule editor, Block a day | The two dates sat at different heights ("Last day (optional)" wrapped), the date inputs were cut ("dd/mm/y"), Ask Lumi covered the form | A | One form grid (`form-grid`): the inputs of a row line up whatever the label length. Shorter labels, "Optional" moved to the help text. Date inputs always fill their column. The owner pages keep bottom room and the Ask Lumi button is a round icon on phones |
| F2 | Owner schedule editor, Booking rules | The right column ran past the card; help texts of different lengths made the grid uneven | A | Same form grid; the two cards sit side by side only from 1536 px, so each has room |
| F3 | Owner time fields | Native scrolling time wheel | A | `<x-ui.time-picker>`: a button showing "9:00 AM" and a grid of times (AM and PM tabs, nothing scrolls inside). Keyboard: arrows, Home, End, Enter, Esc. The same `H:i` value is posted; without JavaScript it is a plain list of times. Used for the 14 photoshoot-hours fields and the downpayment deadline |
| F4 | Top navigation | Items wrapped to two lines between 768 and 1100 px, cramped logo, items shifted | A | Items never wrap; the public bar shows short labels below 1280 px; the customer bar drops its icons (six items fit at 1280 px); more space after the logo; the active item differs by colour only (no shadow, same padding and weight) |
| F5 | Public pages | The policy message was a banner (three lines on phones), a callout on the landing and services pages and a line in the splash, and was out of date since 10B | A | ONE short line built from the payment rules ("No online payment. Pay at the shop or by GCash; photoshoots need a 50% downpayment."), one line on phones with a "Details" link. The callouts on the landing, services, packages and package pages and the line in the splash are removed. The owner's payments page no longer says "paid in full, once" |
| F6 | Package cards, landing hero | A package without a photo showed a large empty box | A | A designed placeholder: soft gradient, two lens rings, the category's icon and the package name |
| F7 | Public images | Absolute addresses from APP_URL broke under another host | A | `PublicFile::url()`: root-relative `/storage/...` for package, backdrop and profile photos; tested |
| F8 | Phones | The floating Chat button sat over content | B | A round icon on phones; hidden while a field is focused or a dialog is open; on the packages list it moves up only while the compare bar is showing |
| F9 | Phones, packages and services | A long intro, the callout and all filters came before the first result | B | The intro is one sentence on phones, the callout is gone, and the filters fold into a "Filters" button that opens a bottom sheet |
| F10 | Every page | A full-screen loading splash | B | Only on the first page of a browser session, at most 600 ms, never with reduced motion, and it takes no clicks |
| F11a | Landing | No FAQ | B | The first 6 published questions as an accordion, with "See all questions" |
| F11b | Package photos | Could not be opened full size | B | A photo viewer (`<x-ui.lightbox>`): full size, previous and next, "2 of 5", Esc, arrows, swipe. Package photos and backdrop photos, on the public pages and in the booking wizard |
| F11c | Package and service cards | Uneven; the price line used two fonts and sizes | B | One package card and one price component: the amount in one size and weight, "Final price confirmed after review" as small muted text under it; titles clamped to two lines; the same chips; buttons pinned to the bottom |
| F11d | Reports | Large empty areas under the shorter card of a row | B | The cards of a row are the same height on both report pages |
| F11e | Booking wizard, step 1 | Package cards looked worse than the landing cards | B | The same photo area, placeholder, chips and price as the public card |
| F11f | Owner sidebar | Plain scrollbar, no way to fold it | C | A thin scrollbar in both themes and a collapse button (icons with tooltips), remembered in the browser |
| F11g | Public site | No Facebook, contacts, address or map | B | "Visit us" on the landing page (address, shop hours, phone, email, Facebook, a lazy Google map with "Open in Google Maps"), each only when set. Facebook page is a new field in Settings, Business info; the footer and Lumi read it too |
| F11h | FAQ | No questions about using the system | C | "Add suggested questions" in the FAQ editor adds 16 hidden drafts worded with the current settings; never twice. Text in `docs/FAQ_DRAFTS.md` |
| F11i | Signed-in pages | Changes by the other side needed a refresh | B | Live updates by polling every 15 seconds (`/my/live`, `/owner/live`): the bell, a toast for the newest notification, and the lists, details and dashboards refresh themselves. A page being typed in is never replaced |
| F12a | Owner calendar | Did not show which requests hold time | B | "Held request" and "Held: time offered" (thicker dashed border and a lock), awaiting downpayment dotted violet, and a legend that says who holds time |
| F12b | Chat away notice | Used the photoshoot hours as the shop's hours | B | When the shop's opening hours text is set, the notice gives it as written and never works out "open now" |
| N1 | My account, 375 px | The picture form made the page scroll sideways (found by the script) | A | The file field shrinks to its column |
| N2 | Customer navigation, 1280 px | "Request printing", "Book a photoshoot" and the other items wrapped to two lines | A | See F4 |
| N3 | Customer booking page | The payment card said the downpayment deadline as a date only | C | A live countdown for both sides ("Due in 19 hours 23 min"), red under 24 hours |
| N4 | Important actions | Rejecting, cancelling, voiding, waiving, extending, marking unclaimed, blocking days, unpublishing, changing a password and sending a request posted at once | B | ONE confirmation dialog, named by the action, red for destructive ones. The existing catalog delete dialogs and the two-step Record payment dialog already confirm in the same style and were kept |
| N5 | Result messages | A long error could vanish after 8 seconds; no warning style; no link | C | Errors stay until closed, at most three toasts, warning style, an optional link, a tick animation |
| N6 | After sending a request | "What happens next" was the same static list for every request | C | A step timeline driven by the real status, on the sent and placed pages and on the customer's booking and order pages |
| N7 | Owner payments page | "Each order or photoshoot is paid in full, once." was untrue since 10B | A | Reworded (a photoshoot may be a downpayment and a balance) |
| N8 | Schedule editor | The text under the blocked-day fields touched the help text above it | B | A fixed gap under a form grid |
| N9 | Package finder, 768 px | The three question fields did not line up (one heading wrapped) | B | Two columns at tablet width, three from 1024 px |

## Counts

- **A:** 10 found, 10 fixed.
- **B:** 14 found, 14 fixed.
- **C:** 6 found, 6 fixed.

## Accepted as they are, and why

| Screen | What the script or the brief raised | Why it stays |
|---|---|---|
| Tables on phones (reports, lists) | Decision 6 asked for stacked cards | Since 9C every wide table scrolls inside its own box, so no page scrolls sideways and nothing is cut; the report tables have 2 or 3 short columns. Turning each table into cards is a large change with a high chance of breaking the print and CSV pages just before the defense |
| FAQ form, walk-in forms, notifications at 1280 px | Narrower than the page | These are reading-width forms and lists on purpose; a form stretched to 1,000 px is harder to fill in |
| Booking wizard summary on phones | Decision 31 asked for a collapsible summary | The summary is under the steps on phones and sticky on desktop already; not collapsed |
| Inline validation | Asked for email, mobile and password | Email and mobile are checked while typing on the register and account pages; the password rules already had live feedback (`<x-ui.password-feedback>`). Other forms keep server validation only |
| Page header pattern | Decision 2 | The pages already share one pattern (breadcrumb, eyebrow, title, one line, actions on the right, stacked on phones) from earlier phases; no page was found that breaks it |
| Empty states | Decision 32 | The lists already use `<x-ui.empty-state>` with an icon, a sentence and a next action (earlier phases and 9C); none was found missing in the seeded and empty accounts checked |
| Material Symbols font | 4 MB | It is preloaded now, so icons arrive with the page; cutting the font down to the icons used is a build change for after the defense |

## Width by screen (script results after the fixes)

| Area | Pages | 375 | 768 | 1024 | 1280 |
|---|---|---|---|---|---|
| Public and guest | 12 | no issue | no issue | no issue | no issue |
| Customer | 16 | no issue | no issue | no issue | no issue |
| Owner | 29 | tables scroll in their own box on the two report pages | no issue | no issue | 4 reading-width pages (accepted above) |

The customer run was repeated after the account-page fix; the owner run was done after the form-grid and time-picker
changes. "No issue" means none of the measured problems; it is not a claim about taste.

## Phase 10E: fixes from the live system

| # | Screen | Problem | Sev. | Fix |
|---|---|---|---|---|
| E1 | Schedule editor, Blocked days (2 columns, sidebar open) | "First day" and "Last day" overlapped and left the card | A | The form grid follows its card's width (container query), not the screen; date inputs give up their intrinsic width |
| E2 | Owner overview tiles | Uppercase labels collided with their badges and wrapped badly | A | `.eyebrow` labels (balanced wrap), the badge drops below the label when the tile is narrow, numbers never wrap, tiles sized by container |
| E3 | Owner overview | The Phase 5 callout ("never takes payment online") was out of date | A | The one current payment line with a link to Payment rules |
| E4 | Receipt, booking page, order page, wizard, dashboard, notification | Said "full payment", "on the day of the shoot", "pay the full amount when you pick it up" | B | Reworded to the 10B rules |
| E5 | Verification | A link opened on a phone did not verify the desktop session | A | Works on any device; the waiting page notices within 5 seconds |
| E6 | Chat | No Seen, no pill for new messages, links not clickable | B | Sent / Seen marks, "New messages" pill, safe https links |
| E7 | Splash | Too quick to see | C | 2 seconds, skippable |

Checked by script (frame check): every control inside its card and form grid, no two controls overlapping, no date input under 120 px, no text cut without an ellipsis, no label colliding with its badge, at 360, 768, 1024, 1280, 1440 and 1700 px, owner pages with the sidebar open and folded.
