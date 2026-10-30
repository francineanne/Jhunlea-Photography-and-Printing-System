# Development Plan — Jhunlea Photography & Printing System

Dalawa ang developer: **Her** (lead dev) at **BF**. Sunod-sunod ang phases: isang tao lang ang nagko-code kada phase. Ang isa naman ay nagbabasa at nagte-test ng natapos na phase, at naghahanda ng susunod. Si Claude (sa chat) ang nagre-review ng bawat phase.

Bawat phase = isang branch → review ni Claude sa chat → kapag aprubado, ime-merge ng developer sa `main` (`git merge --ff-only`). Wala nang Pull Request at wala nang partner review. Ang aprubadong phase ang bagong `main`. Tingnan ang `docs/GIT_WORKFLOW.md`.

| Phase | Owner | Branch | Laman |
|---|---|---|---|
| 0 | Her | `phase-0-cleanup` | Docs, alisin ang Bootstrap, Tailwind setup |
| 1 | Her | `phase-1-foundation` | Design system, layouts, components, authentication, roles |
| 2 | Her | `phase-2-catalog` | Services, packages, themes (owner CRUD + public pages) |
| 3 | Her | `phase-3-printing` | Printing orders (customer + owner) |
| 4 | Her | `phase-4-bookings` | Photography bookings, schedule, conflicts |
| 5 | BF | `phase-5-payments` | Payments, notifications, dashboards, history |
| 6 | BF | `phase-6-admin-tools` | Customers, reports, settings, FAQ, profile |
| 7 | BF | `phase-7-assistant` | Chat (customer–owner) at AI "Lumi", command palette |
| 8 | Her at BF (itatakda) | `phase-8-walkin-income` | Walk-in logging, counter sa Payments, logbook, income reports na may charts |
| 9 | BF | `phase-9-polish` | Testing, mobile, animations, security, docs, deployment |

**Hatian:** si **Her** ang Phase 0–4: pundasyon, catalog, printing at bookings, ang core workflows. Si **BF** ang Phase 5–7 at 9: payments, dashboards, admin tools, AI, at polish. Ang Phase 8 (walk-in logging, counter, logbook at income reports) ay humahawak sa printing orders at bookings ni Her at sa payments at reports ni BF, kaya itatakda nila kung sino ang gagawa. Dahil sunod-sunod ang phases, maghihintay si BF hanggang matapos ang Phase 4. Gamitin niya ang oras na iyon sa mga gawain sa ibaba, para pagdating ng Phase 5 ay kabisado na niya ang code.

**Habang naghihintay si BF (Phase 0–4), gagawin niya:**
- I-setup ang project sa sarili niyang laptop (`docs/GIT_WORKFLOW.md`, section A)
- Basahin ang code ng bawat aprubadong phase ni Her at i-test ang mga natapos na feature, para makilala niya ang code na gagamitin niya sa Phase 5–9
- Kunin mula sa shop ang totoong data: presyo, packages, litrato, oras, policies
- Alamin ang Laravel basics: routes, controllers, Blade, Eloquent, migrations
- I-research at i-test ang AI provider para sa Phase 7 (Gemini at Groq free tiers)

**Habang nagko-code si BF (Phase 5–7 at 9), si Her ang magbabasa ng code at magte-test ng bawat natapos na phase niya. Si Claude ang nagre-review bago i-merge.**

**Pangkalahatang gawain ng hindi nagko-code:**
- binabasa ang code at tine-test ang phase na katatapos lang
- kinukuha mula sa shop ang totoong data: presyo, packages, litrato, oras, policies
- nag-iisip ng test cases para sa susunod na phase
- nagsusulat ng user manual o mga slide para sa presentation

---

## Phase 0 — Cleanup (Her) · maliit lang
- [x] I-commit ang `AGENTS.md`, `CLAUDE.md`, `docs/`, at `design/`
- [x] Alisin ang Bootstrap (`npm uninstall bootstrap @popperjs/core`), at tanggalin ang import nito sa `app.css` at `app.js`
- [x] Setup ng Tailwind 4 sa `resources/css/app.css` na may `@theme` tokens mula sa DESIGN.md
- [x] I-load ang fonts (Playfair Display, Plus Jakarta Sans, JetBrains Mono) at Material Symbols
- [x] Gawing `Jhunlea Photography & Printing` ang `APP_NAME` sa `.env.example`
- [ ] Desisyon: burahin ang `ui_reference/`, dahil nasa Git history pa rin naman ito
- [ ] Desisyon: burahin ang lumang `dev` branch, o huwag na lang gamitin

## Phase 1 — Foundation (Her)
Design refs: `design/login/*`, ang sidebar at header mula sa `design/admin/owner_overview`, at `design/public/landing_page` (header at footer lang)
- [x] `users.role` at `users.theme` migration, `UserRole` enum, `EnsureRole` middleware
- [x] Layouts: `public`, `auth`, `customer`, `owner` (sidebar), lahat may dark mode toggle
- [x] UI components: button, input, select, textarea, checkbox, badge/status pill, card, modal, toast, empty state, stepper, timeline, notice banner, aperture spinner, page loader
- [x] Auth: login, register (customer lang), logout, forgot password, reset password, at throttle sa login
- [x] Owner setup: `php artisan jhunlea:create-owner` command
- [x] Redirect ayon sa role; customer ay `/my/dashboard` at owner ay `/owner` (placeholder lang ang laman muna, walang pekeng numbers)
- [x] Error pages: 403, 404, 500
- [x] Tests: puwedeng mag-register, laging customer ang role ng nagre-register, hindi makakapasok ang customer sa `/owner`, at gumagana ang login at logout

## Phase 2 — Catalog (Her)
Design refs: `design/public/*`, `design/admin/services_printing_catalog_management`, `photography_packages_studio_tier_manager`, `themes_and_backgrounds_backdrops_sets`
- [x] Migrations, models at seeders na may labeled sample data: printing_services, printing_service_options, photography_packages, package_images, themes, package_theme
- [x] Owner CRUD: services kasama ang options, packages kasama ang gallery upload, themes, at publish/unpublish
- [x] Public pages: landing (galing sa database ang featured packages), services, packages na may filters, package detail, compare (2–3), package finder
- [x] Ang published items lang ang makikita ng publiko
- [x] Tests: hindi makakagawa ng service ang customer, at hindi lumalabas sa publiko ang unpublished

## Phase 3 — Printing orders (Her)
Design refs: `design/customer/request_printing_wizard_estimator`, `my_printing_orders`, `printing_order_detail_tracking`, `design/admin/printing_orders_list`, `printing_order_detail_owner`
- [x] Migrations: printing_orders, order_files, order_status_histories (3A; plus `reference_counters` for safe numbering)
- [x] `ReferenceNumberService` (PR-000001), `PricingService` para sa estimate, `StatusTransitionService`, policies at private file storage (3A, naghihintay ng review)
- [x] Customer 4-step wizard na may secure upload (type, MIME at size checks; private storage), review step, at success screen (3B, naghihintay ng review)
- [x] Customer: listahan at detail na may timeline, re-upload kapag humingi ng info ang owner, at cancel habang `submitted` pa (3C)
- [x] Owner: listahan na may filters at tabs; detail na may accept, reject (may reason), request info, update status, at confirmed price (3C)
- [x] Protected file download gamit ang policy (3C)
- [x] Tests: hindi makikita ng customer A ang order o file ni customer B, at hindi puwedeng magpalit ng status ang customer (3C)

## Phase 4 — Photography bookings (Her)
Design refs: `design/customer/book_a_photoshoot`, `my_photography_bookings`, `booking_detail_tracking`, `design/admin/photography_bookings_list`, `booking_detail_owner`, `schedule_and_availability`
- [x] Migrations: photography_bookings, booking_reference_images, availability_rules, blocked_dates (4A, plus booking_settings at booking_status_histories)
- [x] Customer 6-step booking: package, details, schedule (galing sa availability rules ang mga slot), theme/moodboard na may custom theme, optional prints, at review (4B: request lang; "digital only" o "I may want prints", walang presyo ng prints)
- [x] Optional prints: gumagawa ng linked printing order (gamit ang Phase 3); hiwalay ang photography fee sa printing fee (4D: "Order prints" sa Completed booking lang; server code lang ang nagse-set ng `photography_booking_id`; may link sa dalawang panig)
- [x] `ScheduleConflictService`; owner confirm, propose alternative at reject; customer accept o ask for another time (4C; ang "Confirm anyway" ay para lang sa schedule rules, hindi kailanman sa conflict o sa oras na lumipas na, 4D)
- [x] Owner schedule calendar na galing sa totoong bookings (month, week, day, at agenda sa phone), at editor ng opening hours, booking settings at blocked dates na may safety net para sa confirmed bookings (4D)
- [x] Mark photoshoot completed (4C). Walang hiwalay na print production status: ginagamit ng linked printing order ang sarili nitong Phase 3 statuses (4D)
- [x] Tests: conflict detection, walang dagdag na printing charge kapag "digital only" (hiwalay na order ang prints, at walang nagagawang order nang kusa), at hindi makakapag-confirm ang customer ng sariling booking

## Phase 5 — Payments, notifications, dashboards (BF)
Design refs: `design/admin/payments_in_store_ledger_counter_cashier`, `owner_overview`, `notifications_customer_messages`, `design/customer/customer_dashboard`, `notifications_center`, `request_history_printing_photography`
**Mga patakaran ng shop sa bayad (galing sa may-ari; huwag baguhin):** walang partial payment at walang downpayment. Buong confirmed price ang binabayaran, face to face sa shop (sa photoshoot, sa araw mismo ng shoot). Cash at GCash lang. Ire-release ang order o ang mga litrato kapag bayad na nang buo. Nire-record at mino-monitor lang ng system ang bayad; hindi ito tumatanggap ng bayad online. **Kanselado** na ang dating "partial payments on/off" setting.

- [x] payments migration (polymorphic, morph map), `PaymentService` (ang confirmed price ang amount, walang tina-type na halaga), derived payment status (awaiting price, unpaid, paid, no charge), at record payment modal na may confirm step (5A)
- [x] Release gate (Completed lang kapag bayad o ₱0.00) at price lock habang may naka-record na bayad; void na may dahilan hanggang ma-release (5A)
- [x] Payment card sa customer order at booking detail; payments ledger ng owner na may filters, totals ng recorded payments at listahan ng naghihintay ng bayad (5A)
- [x] Database notifications (5B): `NotificationDispatcher` lang ang nagpapasya kung sino ang sasabihan; tinatawag ito ng `writeHistory()` ng dalawang status service, kaya walang notification kung walang history row. Customer: accepted (may confirmed price), rejected, more info, processing, ready for pickup, completed, price changed; booking confirmed, new time, rejected, price changed, completed; payment recorded at payment record cancelled. Lahat ng owner: bagong order, bagong booking request, cancel ng customer, tinanggap ang proposed time, humingi ng ibang oras, nag-add ng files bilang sagot. Bell na may unread badge (99+), dropdown ng huling 8, notifications page (All at Unread) sa customer at owner
- [x] Customer dashboard at owner overview na totoong data ang counts (5C: `DashboardService`, gamit ang parehong rules ng lists at ng ledger; walang revenue chart o trend)
- [x] Request history (5C: `/my/requests`, pinagsamang printing at bookings sa iisang UNION query, may filters at pagination)
- [x] Notification kapag ang shop o studio ang nag-cancel (5C)

**Tapos na ang Phase 5** (5A payments, 5B notifications, 5C dashboards at history). Nananatiling kanselado ang partial-payment setting.
- [x] Tests: tama ang derived status at ang amount, owner lang ang makakapag-record, at hiwalay ang payment status sa order status (5A; walang balance dahil walang partial payment)

## Phase 6 — Admin tools and settings (BF)
Design refs: `design/admin/customers_client_directory_profiles`, `reports_analytics_atelier_performance`, `settings_studio_configuration_rules`, `design/customer/profile_account_settings`
- [x] Customers directory at detail (6A: `CustomerDirectoryService`; customers lang, pinakabago muna, 20 kada page, search sa name, email at phone, filter na "may awaiting payment", tiles; detail na read only na may orders at bookings (kasama ang mga na-remove, walang link) at payments)
- [x] Reports (6A: `ReportService`; period sa Manila days, default ngayong buwan; bawat status kasama ang zero; top 5 services at packages mula sa snapshot name; "Recorded payments" sa period at hiwalay na "Awaiting payment right now" na hindi limitado sa period)
- **Patakaran sa salita ng pera (6A):** "Recorded payments" (ang talagang natanggap ng shop) at "Awaiting payment" (confirmed price na wala pang bayad) lang. Hindi kailanman "revenue", "sales", "profit" o "income", walang average order value, growth o comparison, at hindi kailanman binibilang ang estimates. Hindi pinagsasama ang dalawa sa iisang total. **Binago sa Phase 8:** puwede nang gamitin ang "Income" para sa eksaktong kabuuan ng recorded at hindi naka-void na bayad sa napiling panahon, kasama ang average kada transaksiyon at paghahambing sa nakaraang panahon; nananatiling bawal ang "revenue", "sales" at "profit", hindi binibilang ang estimates, at hindi idinadagdag ang "Awaiting payment" sa income.
- [x] Settings (6B, `/owner/settings`): business info (shop name, address, phone, email; makikita sa public footer kapag may laman lang), upload rules (anim na limit, hindi lalampas sa kaya ng server), at fixed na payment rules (read only, walang switch). Ang partial payments on/off ay kanselado na sa 5A.
- **Ang hours at booking rules ay nananatili sa Schedule editor (`/owner/schedule/edit`).** Ipinapakita lang ng Settings page ang mga value nila (read only) at may link papunta sa editor; walang form field para sa kanila sa Settings.
- [x] Mga bagong table (6B): `shop_settings` (isang row, id 1, NULL = gamitin ang default sa config) at `faqs` (question, answer, position 1..N, is_published).
- [x] Isang class lang ang nagbabasa ng upload limits: `App\Support\ShopSettings` (ang naka-save na value, kung wala ang default sa config, at hindi lalampas sa `ServerUploadLimits` na galing sa php.ini). May test na babagsak kapag may ibang code na nagbasa ng `config('printing.max_*')` o `config('booking.max_image*')`.
- [x] FAQ editor (6B, `/owner/faqs`): add, edit, delete na may confirm, show/hide, move up/down (POST, gumagana kahit walang JavaScript), hanggang 50, bawal ang dobleng tanong. Public `/faq` page na published lang, at FAQ link sa footer kapag may published.
- [x] Account page (6C) para sa customer (`/my/account`) at sa owner (`/owner/account`): iisang controller, service at Blade component. Name, email (kailangan ang current password para palitan), phone (parehong rule ng registration), password (parehong `PasswordRules` ng registration at reset; pagkatapos magpalit, bagong session id at naka-sign out ang lahat ng ibang device), at appearance (Light, Dark, System) na naka-save sa `users.theme`.
- [x] Sa `/owner/account` na rin pinapalitan ng owner ang sarili niyang password.
- **Kanselado pa rin ang notification preferences:** in-app lang ang notifications at walang email o SMS channel na puwedeng i-on o i-off.

**Tapos na ang Phase 6** (6A customers at reports, 6B settings, upload limits at FAQ, 6C account page).
- [x] Tests: owner lang ang makakakita ng reports at settings (6A at 6B)

## Phase 7 — Chat at AI assistant "Lumi" (BF)
Design refs: `design/customer/ai_assistant_studio_concierge`, `design/admin/notifications_customer_messages`, `design/admin/command_palette_ctrl_k`
Dalawang usapan: **Ask Lumi** (customer ↔ AI, mga mungkahi) at **Studio chat** (customer ↔ owner; si Lumi ang sumasagot sa thread kapag away ang owner at humihinto kapag sumagot ang owner). Owner assist: Suggest reply (draft lang, hindi kusang ipinapadala) at Summarize. AI sa chat lang; walang kapangyarihang mag-book, magbago ng presyo o status, o mag-record ng bayad. Provider: Gemini API (Flash-Lite); libre habang nagde-develop (gawa-gawang data lang), paid sa presentation at totoong shop. Buong plano: Claude Project "JHUNLEA" (`phase-7-plan`, `lumi-quality-plan`).
- [x] 7A Lumi engine (walang UI): Gemini sa likod ng `AssistantProvider` interface (key sa `.env` lang), FakeProvider para sa tests, prompt mula sa published catalog, FAQs at settings, redaction ng email at phone, JSON output contract, cards mula sa database, limits (per minute, per day, guest IP hash, global budget), fallback texts, `assistant_runs` (metadata lang), `php artisan assistant:ask`
- [x] 7A.1 Hardening: PriceGuard (tinatanggal ang presyong inimbento ng AI), hindi binibilang ang tawag na hindi umabot sa provider (connection o certificate error), docs at XAMPP setup note
- [x] 7B Messaging core: `conversations` at `chat_messages` (Ask Lumi at Studio chat), `ConversationService`, `MessageService`, `LumiReplyService`, `OwnerPresence`, polling endpoints, notifications (MessageReceived at MessageReplied), at ang away rule: Available, Away o Auto (Auto = batay sa tunay na aktibidad ng owner; ang oras ng shop ay para lang sa wording ng away notice), hanggang 5 magkasunod na sagot ng AI
- [x] 7C Customer chat UI: iisang component para sa floating panel at buong pahina (`/my/assistant`), dalawang tab (Ask Lumi at Studio), suggested prompts, recommendation cards na may link, typing indicator, new conversation, Contact the owner (draft lang, ang customer ang nagpapadala), guest mode, polling at unread badge (approved Oct 30)
- [ ] 7D Owner side: mga ayos mula sa 7C review (isang away notice lang, tamang wording ng fallback, walang card sa handoff, inline Contact the owner, "You" sa sariling bubble, Discard sa failed bubble, friendly na 429), owner inbox at thread view, composer, Resolve, Suggest reply, Summarize, presence toggle at heartbeat
- [ ] 7E Lumi quality pass: mapa ng mga tanong at eval set (`php artisan assistant:eval`), ClaimGuard, Taglish synonyms, live facts, prompt at paghahambing ng model; ang totoong eval ay pinapatakbo ng developer
- [ ] 7F Owner command palette (Ctrl+K) para maghanap ng requests at customers, docs (AGENTS.md section 13), at polish
- [ ] Tests sa bawat part: off-topic refusal, walang private data na lumalabas, fallback kapag walang key o down ang provider

## Phase 8 — Walk-in logging, counter, logbook at income reports (Her at BF, itatakda)
Core requirement na sinabi sa prof: manual ang pag-log ng shop ng walk-in at ng bayad, at walang malinaw na kabuuang kita. Design refs: `design/admin/payments_in_store_ledger_counter_cashier`, `reports_analytics_atelier_performance`, `printing_order_detail_owner`, `booking_detail_owner`. Buong plano: Claude Project "JHUNLEA" (`phase-8-walkin-and-income`). Rekomendadong pagkakasunod: 7D, Phase 8, 7E, 7F, Phase 9.
- [ ] 8A Walk-in foundation: customer na walang login (pangalan at numero), printing order at photography booking na ginagawa ng owner para sa walk-in (gumagana ang parehong conflict checks, kaya nagba-block ng oras ang walk-in booking), `source` (online o walk_in) sa bawat order at booking na may "Walk-in" badge sa mga listahan, at walang notification sa customer na walang account
- [ ] 8B Counter sa Payments module (`/owner/payments`, walang bagong item sa sidebar): "Record payment" modal (hanapin ang order o booking, buong halaga lang, Cash o GCash, cash tendered at change due, GCash reference, physically-collected checkbox, printable payment slip) at "New walk-in" na may Quick walk-in (order at bayad sa isang transaksiyon). Walang Maya QR, POS/Debit o gawa-gawang pangalan mula sa design. Hindi opisyal na resibo ng BIR ang slip. Hindi puwedeng ma-record nang dalawang beses ang parehong bayad
- [ ] 8C Reports and Analytics (`/owner/reports`): kita ng araw (default ngayon) na may Previous at Next, date picker at mga preset (Today, Yesterday, This week, This month, Custom, All time); kabuuan (income, bilang ng transaksiyon, average, cash, GCash, natitirang balanse, voided), listahan ng mga transaksiyon ng panahong iyon na may mga detalye at link, mga chart (kita sa paglipas ng panahon, cash vs GCash, online vs walk-in, printing vs photography, kada serbisyo at package), paghahambing sa nakaraang panahon, CSV at print. Ang Payments logbook at ang Reports ay iisang pinagmulan ng data
- [ ] Kinukuwenta sa backend (sentimo, araw sa Asia/Manila); walang CDN sa charts; may katumbas na table ang bawat chart; may pagination
- [ ] Tests: hindi kasama ang void sa kita, hangganan ng araw, kabuuan ng report = kabuuan ng logbook = kabuuan ng listahan, walk-in conflicts, atomic na Quick walk-in at walang double-record, release lang kapag fully paid, owner-only
- [ ] Bago ang disenyo: suriin ang customer model, ang payments table at ang 6A reports, at sagutin ang mga tanong (walang account ba ang walk-in, ano ang itatala, 24 oras na abiso, refund, format ng mga ulat, lumang log, official receipt, cash drawer count)

## Phase 9 — Polish and release (BF, may review mula kay Claude at kay Her) · dating Phase 8
- [ ] Mobile responsive pass sa lahat ng screen (puwedeng gamitin ang mobile prompt sa Stitch bilang reference)
- [ ] Animations gamit ang GSAP (scroll reveal, count-up, timeline draw, page loader) at reduced-motion support
- [ ] Buong manual test (kasama si Her) ng bawat workflow, security checklist ng AGENTS.md section 12, at pag-aayos ng bugs
- [ ] Deployment guide, backup notes, owner user manual, at presentation
- [ ] Server php.ini upload_max_filesize and post_max_size must be at least the values in config/printing.php
- [ ] Galing sa Phase 4 (hindi kayang subukan nang buo sa SQLite tests o sa browser pane):
  - [ ] Totoong MySQL race na dalawang sabay na submit: ang limit sa open booking requests, at ang sabay na confirm ng dalawang booking sa iisang slot (schedule lock)
  - [ ] JavaScript off (wizard, schedule editor, owner actions) at `prefers-reduced-motion` sa lahat ng booking at schedule screen
  - [ ] Totoong phones (iOS Safari at Android Chrome): booking wizard, calendar agenda, owner booking detail
  - [ ] Pagpili ng files mula sa disk sa totoong file picker (reference images at printing files)
  - [ ] Wording sa calendar ng customer: "Closed" (sarado ang araw o blocked) laban sa "Fully booked" (bukas pero puno)
  - [ ] Siguraduhing walang developer terminal na naka-export ang `.env` values (APP_, DB_, CACHE_, SESSION_, MAIL_, QUEUE_) bago patakbuhin ang tests
- [ ] Galing sa Phase 5A:
  - [ ] Totoong MySQL race na dalawang payment record sa iisang sandali (iisang order, at iisang GCash reference sa dalawang order); sa 5A, sa SQLite file lang ito nasubukan
  - [ ] Tingnan ang payments ledger at ang payment card sa totoong phone
- [ ] Galing sa Phase 5C:
  - [ ] Tingnan ang dashboards (customer at owner) sa totoong phones
  - [ ] Index sa notifications (notifiable, created_at) kung bumagal ang bell kapag marami nang rows
  - [ ] Totoong MySQL check ng combined history query (UNION) na maraming rows
- [ ] Galing sa Phase 6A:
  - [ ] Totoong MySQL check ng customer directory at report queries na maraming rows (subselects, grouped counts, at ang LIKE search na may % at _ na literal)
  - [ ] Tingnan ang customer directory, customer detail at reports sa totoong phone
- [ ] Galing sa Phase 6B:
  - [ ] Sa totoong server, itaas nang sabay ang php.ini `upload_max_filesize` at `post_max_size` kapag itataas ang upload limits sa Settings (ipinapakita ng Settings page ang cap ng server)
  - [ ] Tingnan ang Settings, FAQ editor at public FAQ page sa totoong phone
  - [ ] Totoong file picker ng phone at ng desktop sa upload na nasa limit at lampas sa limit
- [ ] Galing sa Phase 6C:
  - [ ] Email verification at kumpirmasyon ng email change kapag may mail channel na
  - [ ] Optional na two-factor authentication
  - [ ] Patakaran sa pag-delete ng account (wala pang delete account sa system)
  - [ ] Tingnan ang account page sa totoong phone
- [ ] Galing sa Phase 5B:
  - [ ] Retention policy para sa lumang notifications (gaano katagal itatago, at paano lilinisin)
  - [ ] Live na pag-refresh ng bell nang hindi nagre-reload ng page
  - [ ] Tingnan ang bell dropdown sa totoong phone (iOS Safari at Android Chrome)
- [ ] Galing sa Phase 7:
  - [ ] Totoong MySQL test ng chat: sabay na paggawa ng studio thread, dobleng `client_id`, at history na maraming rows
  - [ ] JavaScript off, `prefers-reduced-motion`, totoong phones at ang tunay na soft keyboard (hindi pa nasusubukan ang sheet na sumusunod sa keyboard), at ang native file picker sa chat
  - [ ] Trusted proxies at tunay na client IP sa hosting (kung hindi, iisa ang limit ng lahat ng guest)
  - [ ] APP_DEBUG=false, bagong APP_KEY (magbabago ang guest hashes, walang masama) at tamang `php.ini` limits
  - [ ] 3 kilalang failing upload tests (antivirus sa %TEMP% at `finfo`): PackageImageTest "php renamed to jpg" at "php with a double extension", at isang ThemeManagementTest data set
  - [ ] Retention policy ng chat messages; tingnan ang sessions cleanup
  - [ ] Backup AI provider (Groq, opsyonal), paid-tier billing sa account ng may-ari, at budget alert
  - [ ] Tabs accessibility sa chat (`aria-controls` at arrow keys); thumbs up at down sa mga sagot ni Lumi at handoff rate
  - [ ] Totoong shop data para kay Lumi (packages, services, 25 hanggang 40 FAQ, mga patakaran), KnowledgeBuilder truncation sa napakalaking catalog, Redactor false positives (halimbawa "10 20 30 40"), at ang `?q=` at `?theme=` card links sa browser
- [ ] Paghahanda para sa defense: gabay kada phase, mga posibleng tanong ng panel, demo script at plan B

---

## Mga kailangang itanong sa shop owner (bago mag-Phase 2)
1. Totoong listahan ng printing services, paper options at presyo
2. Totoong photography packages, presyo, inclusions, add-ons, at mga litrato na puwedeng gamitin
3. Oras ng shop, mga araw na puwede ang shoot, haba ng session, at cancellation/reschedule policy
4. ~~Pinapayagan ba ang partial payment o downpayment?~~ Sagot (5A): hindi; buong bayad lang, at ire-release lang kapag bayad na.
5. ~~Anong payment methods ang tinatanggap?~~ Sagot (5A): cash at GCash, face to face.
6. Kailangan ba ng account bago makapag-request? (Assumption natin: oo)
