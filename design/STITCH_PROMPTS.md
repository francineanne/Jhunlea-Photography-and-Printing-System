# JHUNLEA — UI Generator Prompts (Google Stitch)

## Paano gamitin
1. Gumawa ng bagong project sa Stitch (stitch.withgoogle.com).
2. I-paste muna ang **PART A — Master Style**. Ito ang "design system" ng buong site.
3. Pagkatapos, i-paste ang **PART B batches isa-isa** (Batch 1, tapos Batch 2, at iba pa) sa parehong project para consistent ang style.
4. Kapag maayos na ang isang batch, i-export ang HTML at PNG ng bawat screen sa `design\<pangalan-ng-screen>\`. Isama rin ang `DESIGN.md`.
5. Static lang kadalasan ang gawa ng Stitch, kaya hindi pa gagalaw ang animations sa preview. Ang **Motion spec** sa PART A ay gagamitin ni Claude para i-code ang animations sa Blade at Bootstrap. Ang hinihingi natin sa Stitch ay ang mga "state" (hover, active, loading, success) para malinaw ang itsura ng bawat galaw.

---

## PART A — Master Style (i-paste muna ito)

```
You are designing the complete web app for "JHUNLEA — Photography & Printing", a small photo studio and print shop in the Philippines. Keep every screen in this one consistent design system.

BRAND
- Wordmark: "JHUNLEA" (bold, wide letter-spacing) with "PHOTOGRAPHY & PRINTING" in small caps below. Optional minimal mark: a camera aperture merged with a print/paper corner.
- Personality: modern, cool, confident, premium photo studio — energetic but clean. Photos are the hero.

COLORS
- Black #171717 (text, dark surfaces), White #FFFFFF (surfaces), Red #C62828 (the ONLY accent: primary buttons, active nav, key highlights, focus rings).
- Neutral grays: #F5F5F5, #E5E5E5, #A3A3A3, #525252, #262626.
- Status badges (soft tinted pill, darker text of same hue): amber = Submitted/Under review, blue = Awaiting schedule/Reschedule proposed, violet = Processing, green = Ready for pickup/Confirmed/Paid, gray = Completed/Cancelled, red = Rejected/Unpaid.
- Dark mode: background #0F0F0F, cards #171717, borders #262626, text #FAFAFA, red stays #C62828 (use #E53935 for text-on-dark if contrast needs it). Design every screen in BOTH light and dark.

TYPOGRAPHY
- UI font: Plus Jakarta Sans (400, 500, 600). Large editorial headings on public pages: an elegant serif (e.g. Playfair Display or Fraunces), used sparingly.
- Reference numbers (PR-0012, PB-0007) in a monospace font.

SHAPE AND SURFACES
- Rounded corners 12px cards, 10px inputs/buttons, full pill for badges. Thin 1px borders, soft layered shadows, generous white space, 8px spacing grid.
- Glassmorphism ONLY on: login/register panel over a full-bleed photo, sticky landing header after scroll, modals/drawers, chatbot window, floating mobile bottom nav. Blur 12–16px, semi-solid fill (white 70% / black 60%) behind text, 1px light border. Never on tables or long forms.
- Avoid: neumorphism, rainbow gradients, neon glow, clutter. A subtle red-to-black gradient or soft red glow is allowed only on the landing hero and primary CTA hover.

MOTION (show hover/active/loading/success states so the motion is clear)
- Feel: smooth, snappy, photographic. Ease-out curves. Micro-interactions 150–250ms, element entrances 400–700ms, staggered 60–80ms.
- Signature motif: a camera APERTURE / SHUTTER — used for the page loader, the button loading spinner, and the success animation.
- Public pages: hero text reveals line-by-line; hero photo slow "Ken Burns" zoom; section content fades/slides up on scroll; bento cards lift and tilt slightly on hover with image zoom inside; infinite horizontal marquee of sample work photos; primary buttons have a red fill sweep and a subtle magnetic pull on hover; header turns into glass and shrinks on scroll.
- App pages: smooth page transitions (fade + slight slide); sidebar collapses with animated icons; stat numbers count up from real data; status timeline line "draws" itself; stepper progress bar fills between steps; file dropzone pulses on drag-over and shows animated upload progress; skeleton shimmer while loading; toasts slide in from the top-right; modals scale-in with backdrop blur; success screen draws a checkmark with a short, small confetti burst (booking/order submitted only).
- Dark/light toggle: circular reveal transition from the toggle button.
- Chatbot: launcher bubble gently bounces once on first visit; typing indicator with three dots; recommendation cards slide in one by one.
- Owner (admin) screens use calmer, faster motion than public pages — efficiency first.
- Everything respects reduced-motion: show a static alternative.

LAYOUT AND RESPONSIVENESS
- Desktop 1440px and mobile 390px versions for each screen.
- Customer side is mobile-first: floating glass bottom navigation on mobile (Home, Requests, Book, Assistant, Profile).
- Owner side: collapsible left sidebar on desktop; off-canvas drawer on mobile; tables turn into stacked cards on mobile.

CONTENT RULES
- Currency: Philippine peso (₱). Use clearly fake sample data with Filipino names.
- Always distinguish "Estimated price" vs "Confirmed price", and "Requested schedule" vs "Confirmed schedule".
- Payment notice wherever money appears: "Payment is made face-to-face at Jhunlea Photography & Printing." No online payment, no checkout, no card fields.
- Only two roles exist: Customer and Owner.
- Friendly empty states with a small line illustration and one action button. Clear inline form errors.
```

---

## PART B — Screens (i-paste isa-isa, sunod-sunod)

### Batch 1 — Public site
```
Using the same JHUNLEA design system, create the public website:

1. LANDING PAGE
- Sticky header: wordmark, links (Services, Packages, How it works, FAQ), Login (ghost) and "Book a shoot" (red) buttons. Turns to glass on scroll. Dark/light toggle.
- Hero: full-bleed studio photo with slow zoom, big serif headline "Capture it. Print it. Keep it.", short subtext, two CTAs "Book a photoshoot" (red) and "Request printing" (outline). Small floating glass chip "Track your order with a reference number".
- Bento grid section: large card "Photography packages" with photo; cards "Document printing", "Photo printing", "ID & passport photos", "Track my request", "Ask our AI assistant".
- "How it works" 3–4 animated steps: Submit request → Owner reviews → We shoot / print → Pay and pick up in store.
- Sample work: infinite horizontal photo marquee (labelled "Sample photos").
- Featured packages: 3 package cards (photo, name, inclusions, price or "Price to be confirmed", "View package").
- FAQ accordion; contact/location strip with shop hours placeholder and map placeholder; footer.

2. SERVICES PAGE — printing services grid with icon, short description, starting price or "Quotation", and "Request this service".

3. PHOTOGRAPHY PACKAGES PAGE — filter chips (Family, Portrait, Graduation, Events, ID), package cards with image hover zoom, "Compare" checkbox on each card, sticky compare bar at the bottom when 2+ selected.

4. PACKAGE DETAIL PAGE — gallery (large image + thumbnails, lightbox), name, price/quotation notice, duration, people covered, digital outputs, optional prints, available themes as visual chips, add-ons, notes/FAQ, sticky "Book this package" card on the right (bottom bar on mobile). Note: "Selecting a package does not confirm a booking."

5. PACKAGE COMPARISON PAGE — 2–3 packages side by side with aligned rows and a "Book" button per column.

6. SMART PACKAGE FINDER — playful 4-question flow (purpose, number of people, digital/printed, theme) with animated option cards, then a results screen recommending matching packages with a "why this fits" line.
```

### Batch 2 — Authentication
```
Same design system. Create:
1. LOGIN — full-bleed studio photo background with slow zoom, centered glass panel: wordmark, "Welcome back", email, password with show/hide, remember me, forgot password, red "Log in" button with aperture loading state, link to register. Error state with shake animation and inline message.
2. REGISTER (customer only) — glass panel: full name, email, mobile number (+63), password with strength meter, confirm password, terms checkbox, "Create account". Success state.
3. FORGOT PASSWORD and RESET PASSWORD screens.
4. OWNER FIRST-TIME SETUP — a simple secure setup screen for creating the first owner account (not public).
5. 404, 403 (no access) and 500 error pages with a playful camera/film illustration and a "Go back home" button.
6. Full-screen page loader: animated aperture opening into the JHUNLEA wordmark.
```

### Batch 3 — Customer dashboard and printing
```
Same design system, customer side (mobile-first, with desktop version). Create:
1. CUSTOMER DASHBOARD — greeting, quick action tiles (Request printing, Book photoshoot, Track request, Ask assistant), summary cards (Awaiting review, In progress, Ready for pickup, Upcoming shoot, Balance due) with count-up numbers, "Your active requests" list with mini status timeline, upcoming confirmed photoshoot card, recent notifications. Also an empty state for a new customer.
2. PRINTING REQUEST — 4-step stepper with animated progress bar:
   Step 1 Choose service (selectable cards).
   Step 2 Upload file (drag-and-drop zone with pulse on drag-over, upload progress, file chip showing name, type, size, page count/dimensions, remove button; error state for wrong type/too big; allowed: PDF, JPG, PNG).
   Step 3 Specifications — only fields relevant to the service: copies (stepper input), paper size, paper type, color or black-and-white toggle, single/double-sided, special instructions. Live "Estimated price" panel that updates.
   Step 4 Review and submit — summary, estimate note "Final price will be confirmed by the owner", face-to-face payment notice.
   Success screen: aperture/checkmark animation, reference number PR-0012 with copy button, "Track this order".
3. MY PRINTING ORDERS — tabs (All, Active, Ready for pickup, Completed, Cancelled), search, list cards with reference no., service, date, status badge, price; "Reorder" action on completed orders.
4. PRINTING ORDER DETAIL — header with reference no. and status, vertical status timeline (only events that happened, line animates), specifications, uploaded file preview, estimated vs confirmed price, payment card (total, amount paid, remaining balance, payment status) with face-to-face notice, owner remarks, "More info requested" alert with reply/re-upload action, cancel request (only while Submitted).
```

### Batch 4 — Customer photography booking
```
Same design system, customer side. Create:
1. BOOK A PHOTOSHOOT — multi-step flow with progress bar:
   Step 1 Choose package (cards, or "Not sure? Try the package finder").
   Step 2 Details — contact number (prefilled), photoshoot type, number of people, location/venue, notes.
   Step 3 Schedule — calendar with available days highlighted, timeslot chips; clear badge "Requested — not yet confirmed".
   Step 4 Theme and moodboard — visual theme cards, background options, color motif swatches, outfit notes, inspiration image upload grid, "Other / custom theme" card with text field; live moodboard summary preview.
   Step 5 Photo printing — question "Do you also want to have your photos printed?" with two big toggle cards (Yes / No, digital only). If Yes: print sizes, quantity per size, finish. Separate price lines: Photography fee, Printing fee, Combined estimate.
   Step 6 Review and submit. Success screen with reference PB-0007.
2. MY PHOTOGRAPHY BOOKINGS — tabs (Upcoming, Pending, Completed, Cancelled), cards with package image, date (Requested vs Confirmed label), status badge.
3. BOOKING DETAIL — status timeline; requested schedule vs confirmed schedule side by side; "Reschedule proposed" banner with Accept / Ask for another time buttons; package and theme summary with moodboard; separate tracking for photoshoot status and print production status; itemized prices; payment card; owner remarks.
```

### Batch 5 — Customer extras
```
Same design system, customer side. Create:
1. REQUEST HISTORY — combined timeline/list of all printing and photography requests with filters by type, status and date.
2. NOTIFICATIONS — list grouped by Today / Earlier, unread dot, icons per type (accepted, quotation updated, schedule proposed, booking confirmed, ready for pickup), mark all as read; notification bell dropdown in the header with slide-down animation.
3. AI ASSISTANT — (a) floating launcher bubble bottom-right, (b) expanded glass chat window: header "Jhunlea Assistant", suggested prompt chips ("Recommend a photoshoot package", "What printing options are available?", "How do I book?"), chat bubbles, typing indicator, clickable package recommendation cards with "View package" / "Start request", out-of-scope reply style, "Contact the owner" handoff button, "New conversation" button, small note "Suggestions are not confirmed bookings or final prices"; (c) full-page version on mobile.
4. PROFILE AND SETTINGS — profile info, change password, appearance (Light / Dark / System with animated preview cards), notification preferences, log out.
```

### Batch 6 — Owner core
```
Same design system, OWNER side (desktop-first, calmer motion). Layout: collapsible left sidebar with wordmark and nav — Overview, Printing orders, Photography bookings, Services, Photography packages, Themes and backgrounds, Schedule and availability, Customers, Payments, Notifications, Reports, Settings — plus top bar with global search (Ctrl+K command palette), notification bell, theme toggle, owner avatar menu. Create:
1. OWNER OVERVIEW — greeting and date, stat cards (New requests to review, Printing in process, Ready for pickup, Shoots this week, Awaiting schedule confirmation, Unpaid balances ₱) with count-up, "Needs action" list (reference no., customer, short description, status badge, quick action), today's schedule mini-timeline, recently updated requests, a small bar chart of orders this week.
2. PRINTING ORDERS LIST — status tabs with counts, filters (service, date range, payment status), search, data table (Ref no., Customer, Service, Submitted, Est./Confirmed price, Status, Payment, actions), bulk select, pagination; mobile card view.
3. PRINTING ORDER DETAIL (owner) — customer card, file preview with download, specifications, status history timeline, action bar: Accept, Reject (modal requiring reason), Request more info (modal), Update status (dropdown with next allowed statuses), Set confirmed price; payment panel with "Record payment" button; internal notes vs customer-visible remarks.
4. COMMAND PALETTE (Ctrl+K) — glass overlay with search results for requests, customers, and actions.
```

### Batch 7 — Owner photography and schedule
```
Same design system, owner side. Create:
1. PHOTOGRAPHY BOOKINGS LIST — tabs (New, Awaiting schedule, Confirmed, Reschedule proposed, Completed, Cancelled/Rejected), filters, table/cards with package, requested date, confirmed date, status, payment.
2. BOOKING DETAIL (owner) — customer info, package, theme and moodboard with reference images, notes; schedule panel showing requested slot with an automatic conflict check (green "No conflict" or red "Conflicts with PB-0005 at 2:00 PM"); actions: Confirm schedule, Propose alternative (date/time picker modal), Reject with reason, Mark photoshoot completed, Record release of digital files/prints; separate photography fee, printing fee, combined total; linked print order card; payment panel; history timeline.
3. SCHEDULE AND AVAILABILITY — month/week/day calendar with confirmed bookings (solid) and requested ones (dashed outline), drag to view details, side panel for availability rules (open days, hours, slot length, blocked dates/holidays).
```

### Batch 8 — Owner catalog management
```
Same design system, owner side. Create:
1. SERVICES — list of printing services with published/unpublished toggle switch, edit, delete; ADD/EDIT SERVICE form: name, description, icon, pricing type (per page, per copy, quotation), base prices, configurable specification options (paper sizes, paper types, color modes, sides — each with optional price), allowed file types, max file size, customer instructions, live customer-facing preview card.
2. PHOTOGRAPHY PACKAGES — grid of packages with status; ADD/EDIT PACKAGE form: name, category, short and full description, price or "Price requires confirmation" toggle, duration, people covered, digital outputs, optional printed outputs, inclusions list builder, add-ons, terms/notes, drag-and-drop gallery uploader with reorder and cover selection, linked themes, live preview.
3. THEMES AND BACKGROUNDS — visual card grid with image, name, color swatches, published toggle; add/edit modal.
```

### Batch 9 — Owner customers, payments, reports, settings
```
Same design system, owner side. Create:
1. CUSTOMERS — searchable table (name, contact, total requests, outstanding balance, last activity); CUSTOMER DETAIL with profile, request history, payment history.
2. PAYMENTS — table of recorded face-to-face payments (date, ref no., customer, amount, method, recorded by), filters, totals summary "Recorded payments" vs "Outstanding balances" (clearly separate from estimates); RECORD PAYMENT modal: linked order/booking search, total, already paid, amount received, method (Cash, GCash shown in store, Other), receipt/reference note, auto-calculated remaining balance, confirm step; success toast. Banner: "Record a payment only after receiving it in person."
3. NOTIFICATIONS / CUSTOMER MESSAGES — inbox-style list with customer replies and clarification requests.
4. REPORTS — date range picker, cards and simple charts: printing orders by period, bookings by period, popular services and packages, status summary, payments recorded by period, outstanding balances; export button.
5. SETTINGS — tabs: Business info (name, address, contact, hours), Booking rules (cancellation/reschedule policy, partial payments on/off), Upload rules, AI assistant knowledge (FAQ list editor with question/answer, on/off), Appearance (light/dark/system), Account and security.
```

### Batch 10 — Shared components sheet
```
Same design system. Create one component sheet showing, in light and dark: buttons (primary red, secondary, ghost, destructive, icon, loading with aperture spinner), inputs, selects, date/time pickers, toggles, checkboxes, radio cards, file dropzone (idle, drag-over, uploading, error, done), status badges for every status, stat cards, data table, pagination, tabs, stepper, vertical timeline, alerts/banners, toasts, modal, confirm dialog, drawer, dropdown menu, tooltip, skeleton loaders, empty states, avatar, notification item, package card, service card, chat bubble and recommendation card, sidebar item states, bottom mobile nav.
```
