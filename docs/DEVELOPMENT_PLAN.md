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
| 8 | Her | `phase-8-walkin-income` | Walk-in logging, counter sa Payments, logbook, income reports na may charts |
| 9 | Her | `phase-9-polish` | Testing, mobile, animations, security, docs, deployment |

**Hatian:** si **Her** ang Phase 0–4: pundasyon, catalog, printing at bookings, ang core workflows. Si **BF** ang Phase 5–8: payments, dashboards, admin tools, AI, at walk-in logging at income reports (Phase 8). Si **Her** ang Phase 9 (polish at release), katulong si BF. Ang Phase 8 ay humahawak din sa printing orders at bookings ni Her, kaya si Her ang magre-review ng mga bahaging iyon. Dahil sunod-sunod ang phases, maghihintay si BF hanggang matapos ang Phase 4. Gamitin niya ang oras na iyon sa mga gawain sa ibaba, para pagdating ng Phase 5 ay kabisado na niya ang code.

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

## Phase 7 — Chat at AI assistant "Lumi" (BF) · tapos na (merged into main)
Design refs: `design/customer/ai_assistant_studio_concierge`, `design/admin/notifications_customer_messages`, `design/admin/command_palette_ctrl_k`
Dalawang usapan: **Ask Lumi** (customer ↔ AI, mga mungkahi) at **Studio chat** (customer ↔ owner; si Lumi ang sumasagot sa thread kapag away ang owner at humihinto kapag sumagot ang owner). Owner assist: Suggest reply (draft lang, hindi kusang ipinapadala) at Summarize. AI sa chat lang; walang kapangyarihang mag-book, magbago ng presyo o status, o mag-record ng bayad. Provider (mula 7E-1): Claude (Anthropic Messages API, `claude-haiku-5-5`) ang pangunahin at Gemini API (Flash-Lite) ang backup kapag may problema ang Claude (429, down, walang key o credit, sirang sagot). Env names (walang value dito; mula 7E-2a ang Claude key ay `AI_ANTHROPIC_*` at hindi na binabasa ang lumang `ANTHROPIC_API_KEY`, na ginagamit din ng ibang tools): `AI_PROVIDER`, `AI_FALLBACK_PROVIDER`, `AI_ANTHROPIC_API_KEY`, `AI_ANTHROPIC_MODEL`, `AI_API_KEY`, `AI_MODEL`, `AI_ENABLED`. Gawa-gawang data lang sa free tier; paid sa presentation at totoong shop. Buong plano: Claude Project "JHUNLEA" (`phase-7-plan`, `lumi-quality-plan`).
- [x] 7A Lumi engine (walang UI): Gemini sa likod ng `AssistantProvider` interface (key sa `.env` lang), FakeProvider para sa tests, prompt mula sa published catalog, FAQs at settings, redaction ng email at phone, JSON output contract, cards mula sa database, limits (per minute, per day, guest IP hash, global budget), fallback texts, `assistant_runs` (metadata lang), `php artisan assistant:ask`
- [x] 7A.1 Hardening: PriceGuard (tinatanggal ang presyong inimbento ng AI), hindi binibilang ang tawag na hindi umabot sa provider (connection o certificate error), docs at XAMPP setup note
- [x] 7B Messaging core: `conversations` at `chat_messages` (Ask Lumi at Studio chat), `ConversationService`, `MessageService`, `LumiReplyService`, `OwnerPresence`, polling endpoints, notifications (MessageReceived at MessageReplied), at ang away rule: Available, Away o Auto (Auto = batay sa tunay na aktibidad ng owner; ang oras ng shop ay para lang sa wording ng away notice), hanggang 5 magkasunod na sagot ng AI
- [x] 7C Customer chat UI: iisang component para sa floating panel at buong pahina (`/my/assistant`), dalawang tab (Ask Lumi at Studio), suggested prompts, recommendation cards na may link, typing indicator, new conversation, Contact the owner (draft lang, ang customer ang nagpapadala), guest mode, polling at unread badge (approved Oct 30)
- [x] 7D Owner side: mga ayos mula sa 7C review (isang away notice lang, tamang wording ng fallback, walang card sa handoff, inline Contact the owner, "You" sa sariling bubble, Discard sa failed bubble, friendly na 429), owner inbox at thread view (`/owner/messages`), composer, Resolve at Reopen, Suggest reply, Summarize, presence toggle at heartbeat
- [x] 7E-1 Claude provider at account-aware Lumi (approved): Claude ang sumasagot at Gemini ang backup (failover chain, sariling cooldown bawat provider, `assistant_runs.fallback_from`), sariling records ng naka-login na customer sa prompt (`CustomerContextBuilder`), record cards, `ClaimGuard`, handoff na may kaugnay na order o booking para sa owner, isolation tests
- [x] 7E-2a Mga ayos mula sa live test at efficiency (approved): alam ni Lumi ang petsa at oras sa Manila at kung bukas ang shop (`LiveFacts`), may "in 3 days" / "2 days ago" at note ang bawat booking na lumipas na ang oras, `ClaimGuard` na per record (PR-/PB- reference, pangalan, uri), "Lumi Lite" badge kapag ang backup ang sumagot (meta `provider`, `via_backup`; hindi kailanman ipinapakita sa customer ang pangalan ng backup), `CodeAnswers` (bati, salamat, oras at "open ba kayo", contact at address mula sa settings; walang provider call at hindi binibilang sa limits), mas maliit na prompt (relevant details lang, Taglish synonyms, 6 na history) at prompt caching ng Claude (stable prefix na may `cache_control`, `assistant_runs.cache_read_tokens` at `cache_write_tokens`), limits 80 bawat user at 20 bawat guest IP bawat araw, `php artisan assistant:usage {--days=7}` (tinantyang gastos mula sa `assistant.pricing`), at starter chips
- [x] 7E-2b Eval (approved; unang totoong eval sa Claude Haiku 5.5: 97.3%): `php artisan assistant:eval` na sumusubok kay Lumi sa 150+ nakasulat na tanong (`database/evals/cases/*.json`, 16 na kategorya, 49 na `must_never`, 6 na `review`) sa SARILING throwaway SQLite (`storage/app/evals`, git-ignored; sinisiguro bago ang bawat write na iyon ang aktibong connection, hindi kailanman ang MySQL), may pekeng customers (Ana, Ben, Newbie, guest) at nakapirming oras (Huwebes, Nob 5, 2026, 10:00 AM Manila). Dumadaan sa totoong `AssistantEngine`; deterministic ang mga check (walang AI na judge). Fake provider kung walang `--live`; `--live` ay kailangan ng `--yes` (kung wala, bilang ng tawag at tinantyang gastos lang). Report (.md at .json): pass rate bawat kategorya, `must_never` (dapat 100%), latency p50/p95, tokens, cache reads, tinantyang gastos, failovers, provider failures; exit 1 kapag bumagsak ang quality gate (may `must_never` na bumagsak o pass rate na mababa sa 90%). `--runs` para sa flaky, `--compare=anthropic,gemini`. Ang developer ang nagpapatakbo ng totoong eval; paliwanag sa `docs/AI_EVALUATION.md`. Kasama ang ayos sa `CodeAnswers`: ang tanong sa oras na may ibang araw o oras ("tomorrow", "sa Linggo", "bukas ba kayo bukas", "7pm") ay sa model na
- [x] 7E-2c Mga ayos mula sa unang totoong eval (approved; ikalawang totoong eval: 97.1%): ang special at zero-width na space sa sagot ay nagiging normal na space (`App\Support\PlainText::spaces()`, para hindi lumabas ang "Nov3" o "don'taccept"); nahuhuli ng `ClaimGuard` ang adverb bago ang state ("is totally confirmed", hanggang dalawa, sa lahat ng apat na state) at ang pangako na KUKUMPIRMAHIN, I-A-APPROVE o TATANGGAPIN ng owner o studio ang booking o request (pati isang bagong rule sa prompt); naitama ang dalawang maling check (`inj.owner_roleplay`, `rules.reschedule`) at hinigpitan ang iba (`no_glued_words` na laging naka-on, `refused` para sa mga injection na trick); sa eval, isang retry pagkatapos ng 429 (hintay ang retry-after, hanggang 60 segundo) at INCONCLUSIVE (exit code 2) kapag higit sa 10% ng model cases ay provider_failed o skipped; 180 na case (62 `must_never`); presyo ng Gemini 3.1 at 3.5 Flash-Lite mula sa opisyal na pricing page ng Google
- [ ] 7E-2d Mga ayos mula sa ikalawang totoong eval (in review): may linya sa volatile na bahagi ng prompt na nagsasabi ng wika ng PINAKAHULING mensahe ng customer (`App\Support\MessageLanguage`; hindi ang history ang nagpapasya, wala ito sa owner assist); naayos ang false positive ng `ClaimGuard` na nagtanggal ng totoong pangungusap tungkol sa presyo ("kukumpirmahin ng owner ang final na presyo"), kaya nawala ang presyo ng Graduation sa sagot sa "magkano grad pics nyo?"; mga bagong check sa eval: `ignore_hedged_clauses`, `refusal` (kahit anong wika) at `price_named`; 192 na case (68 `must_never`)
- [ ] 7E-2e Mga natitirang ayos (in review): diagnostic sa eval ng hilaw at huling sagot bilang code points (hindi sinusuportahan ng ebidensya ang invisible characters), `PlainText` na nag-aalis ng soft hyphen at iba pang invisible at control characters at nagdadagdag ng space sa tatlong ligtas na lugar lang, mas malawak na `no_glued_words`, `ignore_hedged_clauses` na naka-on sa lahat, "May I ask" na English na, ang huling linya na nagtatapos sa ":" ay tinatanggal (`ReplyCleanup`), at bilang ng tinanggal ng bawat guard sa report (`GuardStats`)
- [ ] 7E-3a Backend ng Ask Lumi para sa owner (in review): 14 na read-only na tool (`app/Services/Assistant/Owner`, isang linya sa `OwnerTools::TOOLS` para magdagdag), `DateRangeResolver`, feature `owner_ask` na may sariling limits (10 bawat minuto, 200 bawat araw) at bahagi ng budget, dalawang hakbang (plan, tapos phrase mula sa resulta lang), `NumberGuard`, injection-safe na data block, endpoints `POST /owner/assistant/ask`, `GET /owner/assistant/history`, `GET /owner/assistant/briefing`, table `owner_assistant_messages` at column `assistant_runs.tools`, 62 na owner case sa eval
- [ ] 7E-3b UI ng Ask Lumi para sa owner (in review): floating panel sa bawat owner page, buong pahina `/owner/assistant` na may history at "Load older", "Today at a glance" sa overview (code ang sumusulat, gumagana kahit down ang AI), Lumi Lite pill, mahinahong notice para sa limit (iba na ang mensahe ng per-minute at per-day) at sa error; mga ayos mula sa unang totoong owner eval: ang how-to ay sinasagot ng mga hakbang sa wika ng tanong, ang refusal ay nasa wika ng tanong, ang na-block na leak ay magalang na pagtanggi, 20 bawat minuto, at ang eval sandbox ay nagtataas ng lahat ng limit. Sa Phase 8 madadagdagan ng walk-in at income tools ang `OwnerTools`
- [ ] 7F Owner command palette (Ctrl+K) para maghanap ng requests at customers, docs (AGENTS.md section 13), at polish
- [ ] Tests sa bawat part: off-topic refusal, walang private data na lumalabas, fallback kapag walang key o down ang provider

## Phase 8 — Walk-in logging, counter, logbook at income reports (Her)
Core requirement na sinabi sa prof: manual ang pag-log ng shop ng walk-in at ng bayad, at walang malinaw na kabuuang kita. Design refs: `design/admin/payments_in_store_ledger_counter_cashier`, `reports_analytics_atelier_performance`, `printing_order_detail_owner`, `booking_detail_owner`. Buong plano: Claude Project "JHUNLEA" (`phase-8-walkin-and-income`). Pagkakasunod: 7D → 7E-1 (Claude provider at account-aware Lumi) → 7E-2a (mga ayos at efficiency) → 7E-2b (eval) → 7E-3 (owner Ask Lumi) → 7F → Phase 8 → Phase 9.
- [x] 8A Walk-in foundation (in review): customer na walang login (pangalan at optional na numero, `users.is_walk_in`, walang email at password), printing order at photography booking na ginagawa ng owner para sa walk-in (gumagana ang parehong conflict checks, kaya nagba-block ng oras ang walk-in booking), `source` (online o walk_in) sa bawat order at booking na may "Walk-in" badge at filter sa mga listahan, sa schedule at sa customer directory, at walang notification sa customer na walang account. Mga serbisyong gagamitin ng 8B: `WalkInOrderService`, `WalkInBookingService`, `WalkInCustomerService`
- **Mga desisyon ng 8A (sinagot ng shop noong Nob 6):** (1) walang account ang walk-in: pangalan lang ang kailangan, optional ang phone (parehong rule ng registration), walang email, password o login, at **hindi na kailangan ang "claim account"**. Kailangan ang contact number sa walk-in booking (minsan iniiwan ng customer ang order at binabalikan kapag tapos na). (2) Sa counter hindi ginagamit ang 24 oras na abiso at ang 30 araw na hangganan, pero bawal ang oras na lumipas na, bawal ang conflict, at sinusunod ang oras ng bukas at blocked dates maliban kung "Confirm anyway". (3) **Desisyon ni Her (Nob 6):** binabayaran agad ang walk-in printing order; hindi ito naise-save nang walang bayad. Iisang transaksiyon ang order at ang buong bayad sa `PaymentService` (Cash o GCash, GCash reference, cash tendered para sa sukli lang). Ang presyo ay mula sa catalog (PricingService) o tina-type ng owner na may optional na dahilan, at nakatala kung sino ang nagtakda. Puwedeng walang bayad sa simula ang walk-in booking (binabayaran sa araw ng shoot). Ang owner lang ang gumagamit ng counter. (4) Void pa rin ang pang-ayos ng bayad; walang refund. (5) Ang walk-in order ay ginagawang Accepted, binabayaran, at saka inililipat agad sa **Processing** (bayad na kaya sinisimulan na ang pag-print; desisyon ng shop), lahat sa iisang transaksiyon at may history row bawat hakbang. Ang walk-in booking ay Confirmed, may history row na "Created at the counter". (6) Sa counter laging hindi ginagamit ang 24 oras na abiso at ang 30 araw na hangganan (nasa shop ang customer at nakikita ng owner kung ano ang bakante). (7) Resibo: gagawin sa 8B bilang printable na payment receipt mula sa system; hindi ito BIR official receipt maliban kung nakarehistro ang shop
- [x] 8B Counter sa Payments module (built, waiting for review; walang migration): `/owner/payments` ay "Payments and counter" (walang bagong item sa sidebar). Counter bar: "Record payment" at menu na "New walk-in" (ang mga form ng 8A). "Record payment" modal (`paymentCounter`, `resources/js/counter.js`): isang search sa order at booking (pangalan, phone, PR-/PB-, kasama ang walk-in; literal ang % at _), kabuuan, bayad na, at ang sisingilin (laging buong confirmed price), mga hindi mapipili na may dahilan (walang presyo, bayad na, cancelled o rejected, ₱0.00), Cash o GCash, GCash reference, cash na natanggap para sa sukli lang (hindi sine-save; tinatanggihan kapag kulang), "I collected the full amount", confirm step, at PAY- number na may "Print receipt". Bukas din mula sa queue at sa detail page (`?pay=printing_order:12`). Payment receipt (`/owner/payments/{payment}/receipt`, owner lang): "This is not an official receipt.", A4 print, VOID at dahilan kapag voided, laging puwedeng i-print ulit. Dalawang queue (Ready for pickup na may release ng bayad na; Awaiting payment, pinakamatagal muna, may bilang ng araw). Ang ledger ay "Today" bilang default, may Today, Yesterday at All dates, may Online o Walk-in at Print receipt sa bawat row. Nakatago ang Chat / Ask Lumi launcher habang bukas ang modal
- **Mga desisyon ng 8B:** (1) Walang bagong token: ang unique index ng `payments` (isang recorded na bayad bawat order o booking) at ang lock ng PaymentService ang pumipigil sa doble; ang pangalawang submit ay "Already paid". (2) Ang order o booking na na-void ang bayad ay puwedeng bayaran ulit (Phase 5 rule), kaya hindi ito naka-block sa modal. (3) Ang ledger ay "Today" lang kapag walang filter; ang search o filter na walang petsa ay lahat ng araw pa rin. (4) Hindi nagbago ang release gate, price lock, void rules at ledger totals
- [x] 8C Income report (built, waiting for review; isang migration: `users.report_view`, daily ang lahat ng dating row): `/owner/reports/income`, ito na ang binubuksan ng Reports sa sidebar, at may tab papunta sa dating Requests report. Daily (default; Previous, Next, date picker, Today, Yesterday), Weekly (Lunes hanggang Linggo, This week), Monthly (This month), Custom at All time; hindi lumalampas sa kasalukuyang panahon; tinatandaan bawat owner ang Daily, Weekly o Monthly. Anim na stat tile (Income, Payments, Average per payment, Cash, GCash, Still awaiting payment), paghahambing sa nakaraang panahon na may salita at icon, apat na chart na may table (income sa paglipas ng oras o araw, Cash vs GCash, Online vs Walk-in, Printing vs Photography), top 10 na serbisyo at package plus "Other", listahan ng mga bayad na may receipt link at "Open in Payments ledger", printable na End-of-day summary, CSV export at Print report
- **Mga desisyon ng 8C:** (1) Ang income ay eksaktong total ng ledger (`PaymentLedgerService`), may test na nagpapatunay sa bawat view. (2) Ang "Still awaiting payment" ay ngayon mismo (hindi sa dulo ng panahon), dahil walang kasaysayan ng awaiting sa system; hindi ito income. (3) Ang nakaraang panahon ng Monthly ay ang buong buwan bago nito. (4) Ang top services at packages ay pangalan mula sa catalog (kasama ang tinanggal); ang CSV ay pangalang nasa order mismo (snapshot). (5) Hindi ginawa: cash count, variance at pagsasara ng araw, "target" o "goal", staff leaderboard, Maya at POS. **Mga bukas na tanong:** cash drawer count at pagsasara ng araw, official receipt (BIR), pag-import ng lumang manual na log, ano ang gustong unang makita ng owner
- [x] (orihinal na plano, tapos sa 8C sa itaas; Previous at Next, presets, CSV at print ay nandoon na) 8C Reports and Analytics (`/owner/reports`): kita ng araw (default ngayon) na may Previous at Next, date picker at mga preset (Today, Yesterday, This week, This month, Custom, All time); kabuuan (income, bilang ng transaksiyon, average, cash, GCash, natitirang balanse, voided), listahan ng mga transaksiyon ng panahong iyon na may mga detalye at link, mga chart (kita sa paglipas ng panahon, cash vs GCash, online vs walk-in, printing vs photography, kada serbisyo at package), paghahambing sa nakaraang panahon, CSV at print. Ang Payments logbook at ang Reports ay iisang pinagmulan ng data
- [x] Kinukuwenta sa backend (sentimo, araw sa Asia/Manila); walang CDN sa charts; may katumbas na table ang bawat chart; may pagination
- [x] Tests: hindi kasama ang void sa kita, hangganan ng araw, kabuuan ng report = kabuuan ng logbook = kabuuan ng listahan, walk-in conflicts, atomic na Quick walk-in at walang double-record, release lang kapag fully paid, owner-only
- [x] 8D Ask Lumi ng owner para sa Phase 8 (built, waiting for review at sa live owner eval; walang migration, hindi ginalaw ang engine): ang `income_summary` ay ang Income report mismo (`IncomeRange` papunta sa `IncomePeriod`, saka `IncomeReportService::build()`), kaya pareho ang bawat numero; bagong `counter_queues` (ready for pickup na hindi pa bayad muna, awaiting payment, bayad ngayon, walk-in session ngayon na hindi pa bayad) at `walk_in_summary`; may walk-in o online sa customer, order at booking at may `source` filter; may how-to para sa counter, resibo, walk-in order at booking, void, Daily/Weekly/Monthly, end-of-day, CSV, print, awaiting payment at official receipt/BIR; ang briefing ay may dalawang linya para sa counter (kapag may laman lang); 31 bagong owner eval case at si Lito Bautista (walk-in) sa eval data
- **Mga desisyon ng 8D:** (1) Ang paghahambing ay ang mismong pangungusap ng report (`IncomeReportService::comparisonText()`). (2) Ang panahong hindi pa nagsisimula ay "wala pang records", walang numero. (3) Walang bagong write tool; ang mga utos ay tinatanggihan gamit ang dating fixed text at may link sa tamang page. (4) Hindi nagbibigay si Lumi ng tax o BIR advice. **Para kay Her:** patakbuhin ang live owner eval pagkatapos ng review (`php artisan assistant:eval --category=owner --live --yes`)
- [x] 8E Audit trail (branch `phase-8e-audit-trail`, built, waiting for review; isang migration: `audit_logs` at `audit_chain_head`): bawat mahalagang aksiyon (bayad at void, presyo at status ng order at booking, walk-in, oras at blocked dates, settings, catalog, FAQ, CSV at end-of-day, receipt, sign-in ng owner at maling sign-in, password at email ng owner) ay may isang row na isinusulat sa parehong transaction ng aksiyon; hindi ito nababago o nabubura sa app (model at builder ay tumatanggi); may hash chain at `php artisan audit:verify`; ang write lock ay ang iisang row ng `audit_chain_head` (naka-lockForUpdate, sumusulong sa parehong transaction), at ikinukumpara ng verify ang head sa pinakabagong row kaya nahuhuli rin ang pagbura ng pinakabagong rows; ang bawat catalog change ay nasa iisang `DB::transaction` kasama ang audit row nito; Activity log sa Settings (section 6) na may filters, search, pagination, integrity line at CSV; `audit:prune` (730 araw) na hindi awtomatiko at may checkpoint
- **Mga desisyon ng 8E:** (1) Isang owner lang ngayon; puwedeng i-export ng owner ang log. (2) Ang Activity log ay nasa Settings, walang bagong sidebar item. (3) Ang catalog ay naka-hook sa mga controller dahil walang catalog service. (4) Ang account at sign-in events ay para sa owner lang. (5) Ang contact details ng shop sa Settings ay itinatala kung nagbago lang, hindi ang laman. (6) Walang back-fill mula sa lumang data. **Para sa defense (Q&A):** "Paano ninyo nalalaman na hindi binago ang isang bayad?" Ang bawat bayad at void ay may row sa audit log na isinulat sa parehong transaction (kung walang row, walang bayad). Ang bawat row ay may SHA-256 hash na kasama ang hash ng naunang row, kaya kapag may nagbago, nabura o nailipat na row sa database, hindi na tutugma ang chain at ipapakita ito ng `audit:verify` at ng Activity log. Hindi nito napipigilan ang may direktang access sa database, pero nahuhuli nito ang pagbabago.
- [ ] Bago ang disenyo: suriin ang customer model, ang payments table at ang 6A reports, at sagutin ang mga tanong (walang account ba ang walk-in, ano ang itatala, 24 oras na abiso, refund, format ng mga ulat, lumang log, official receipt, cash drawer count)

## Phase 9 — Polish and release (Her) · dating Phase 8
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
