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
| 7 | BF | `phase-7-assistant` | AI assistant, command palette |
| 8 | BF | `phase-8-polish` | Testing, mobile, animations, security, docs, deployment |

**Hatian:** si **Her** ang Phase 0–4: pundasyon, catalog, printing at bookings, ang core workflows. Si **BF** ang Phase 5–8: payments, dashboards, admin tools, AI, at polish. Dahil sunod-sunod ang phases, maghihintay si BF hanggang matapos ang Phase 4. Gamitin niya ang oras na iyon sa mga gawain sa ibaba, para pagdating ng Phase 5 ay kabisado na niya ang code.

**Habang naghihintay si BF (Phase 0–4), gagawin niya:**
- I-setup ang project sa sarili niyang laptop (`docs/GIT_WORKFLOW.md`, section A)
- Basahin ang code ng bawat aprubadong phase ni Her at i-test ang mga natapos na feature, para makilala niya ang code na gagamitin niya sa Phase 5–8
- Kunin mula sa shop ang totoong data: presyo, packages, litrato, oras, policies
- Alamin ang Laravel basics: routes, controllers, Blade, Eloquent, migrations
- I-research at i-test ang AI provider para sa Phase 7 (Gemini at Groq free tiers)

**Habang nagko-code si BF (Phase 5–8), si Her ang magbabasa ng code at magte-test ng bawat natapos na phase niya. Si Claude ang nagre-review bago i-merge.**

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
- [ ] Migrations, models at seeders na may labeled sample data: printing_services, printing_service_options, photography_packages, package_images, themes, package_theme
- [ ] Owner CRUD: services kasama ang options, packages kasama ang gallery upload, themes, at publish/unpublish
- [ ] Public pages: landing (galing sa database ang featured packages), services, packages na may filters, package detail, compare (2–3), package finder
- [ ] Ang published items lang ang makikita ng publiko
- [ ] Tests: hindi makakagawa ng service ang customer, at hindi lumalabas sa publiko ang unpublished

## Phase 3 — Printing orders (Her)
Design refs: `design/customer/request_printing_wizard_estimator`, `my_printing_orders`, `printing_order_detail_tracking`, `design/admin/printing_orders_list`, `printing_order_detail_owner`
- [ ] Migrations: printing_orders, order_files, status_histories
- [ ] `ReferenceNumberService` (PR-000001), `PricingService` para sa estimate, `StatusTransitionService`
- [ ] Customer 4-step wizard na may secure upload (type, MIME at size checks; private storage), review step, at success screen
- [ ] Customer: listahan at detail na may timeline, re-upload kapag humingi ng info ang owner, at cancel habang `submitted` pa
- [ ] Owner: listahan na may filters at tabs; detail na may accept, reject (may reason), request info, update status, at confirmed price
- [ ] Protected file download gamit ang policy
- [ ] Tests: hindi makikita ng customer A ang order o file ni customer B, at hindi puwedeng magpalit ng status ang customer

## Phase 4 — Photography bookings (Her)
Design refs: `design/customer/book_a_photoshoot`, `my_photography_bookings`, `booking_detail_tracking`, `design/admin/photography_bookings_list`, `booking_detail_owner`, `schedule_and_availability`
- [ ] Migrations: photography_bookings, booking_reference_images, availability_rules, blocked_dates
- [ ] Customer 6-step booking: package, details, schedule (galing sa availability rules ang mga slot), theme/moodboard na may custom theme, optional prints, at review
- [ ] Optional prints: gumagawa ng linked printing order (gamit ang Phase 3); hiwalay ang photography fee sa printing fee
- [ ] `ScheduleConflictService`; owner confirm, propose alternative at reject; customer accept o ask for another time
- [ ] Owner schedule calendar na galing sa totoong bookings, at editor ng availability rules
- [ ] Mark photoshoot completed, at hiwalay na print production status
- [ ] Tests: conflict detection, walang dagdag na printing charge kapag "digital only", at hindi makakapag-confirm ang customer ng sariling booking

## Phase 5 — Payments, notifications, dashboards (BF)
Design refs: `design/admin/payments_in_store_ledger_counter_cashier`, `owner_overview`, `notifications_customer_messages`, `design/customer/customer_dashboard`, `notifications_center`, `request_history_printing_photography`
- [ ] payments migration (polymorphic), `PaymentService` (sa backend kinukuwenta ang total, paid at balance), at record payment modal na may confirm step
- [ ] Payment card sa customer order at booking detail; payments ledger ng owner
- [ ] Database notifications sa bawat status change, quotation update, schedule proposal at ready for pickup; bell dropdown at notifications page
- [ ] Customer dashboard at owner overview na totoong data ang counts
- [ ] Request history (pinagsamang printing at bookings)
- [ ] Tests: tama ang balance computation, owner lang ang makakapag-record, at hiwalay ang payment status sa order status

## Phase 6 — Admin tools and settings (BF)
Design refs: `design/admin/customers_client_directory_profiles`, `reports_analytics_atelier_performance`, `settings_studio_configuration_rules`, `design/customer/profile_account_settings`
- [ ] Customers directory at detail (history at payments)
- [ ] Reports: orders at bookings kada period, popular services at packages, status summary, recorded payments vs outstanding balance (hindi revenue ang estimates)
- [ ] Settings: business info at hours, booking rules (kasama ang partial payments on/off), upload rules, FAQ editor
- [ ] Customer profile at settings: info, password, appearance, notification preferences
- [ ] Tests: owner lang ang makakakita ng reports at settings

## Phase 7 — AI assistant (BF)
Design refs: `design/customer/ai_assistant_studio_concierge`, `design/admin/command_palette_ctrl_k`
- [ ] Pumili ng provider (Gemini o Groq) pagkatapos i-check ang kasalukuyang free tier; ilagay ang key sa `.env`
- [ ] Prompt na binubuo ng server mula sa published catalog, FAQs at settings; topic gate sa code; rate limit
- [ ] Chat UI: launcher, suggested prompts, recommendation cards na may link, typing indicator, new conversation, at handoff sa owner
- [ ] Fallback kapag walang key o down ang provider
- [ ] Owner command palette (Ctrl+K) para maghanap ng requests at customers
- [ ] Tests: off-topic refusal, walang private data na lumalabas, at fallback kapag walang key

## Phase 8 — Polish and release (BF, may review mula kay Claude at kay Her)
- [ ] Mobile responsive pass sa lahat ng screen (puwedeng gamitin ang mobile prompt sa Stitch bilang reference)
- [ ] Animations gamit ang GSAP (scroll reveal, count-up, timeline draw, page loader) at reduced-motion support
- [ ] Buong manual test (kasama si Her) ng bawat workflow, security checklist ng AGENTS.md section 12, at pag-aayos ng bugs
- [ ] Deployment guide, backup notes, owner user manual, at presentation

---

## Mga kailangang itanong sa shop owner (bago mag-Phase 2)
1. Totoong listahan ng printing services, paper options at presyo
2. Totoong photography packages, presyo, inclusions, add-ons, at mga litrato na puwedeng gamitin
3. Oras ng shop, mga araw na puwede ang shoot, haba ng session, at cancellation/reschedule policy
4. Pinapayagan ba ang partial payment o downpayment, at puwede bang i-release kahit may balance pa?
5. Anong payment methods ang tinatanggap sa counter (cash, GCash na ipinapakita sa shop, at iba pa)?
6. Kailangan ba ng account bago makapag-request? (Assumption natin: oo)
