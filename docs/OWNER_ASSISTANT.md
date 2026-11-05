# The owner's Ask Lumi (Phase 7E-3a)

The shop owner asks Lumi about their own shop ("How much did we make today?", "Sino pa ang hindi nagbabayad?") and
gets an answer built ONLY from the shop's records. Lumi can read; it can never change anything. This part is the
backend; since 7E-3b the owner reaches it from a floating panel on every owner page, the full page `/owner/assistant`
(sidebar "Ask Lumi") and the "Today at a glance" card on the overview.

## How a question is answered

1. **Plan (one AI call).** The model gets the planning rules and the tool catalog (the stable part, cached by Claude),
   the current Manila date and time, and the owner's question with the last 6 turns of their own conversation. It must
   answer through a forced `plan` schema: `tools` (1 to 3 tools with their parameters), `clarify` (one question),
   `out_of_scope`, or `write_request`. An unknown tool or an unknown or invalid parameter is never executed: the owner
   gets a clarifying question instead.
2. **Tools (code, no AI).** Each tool only READS through the existing services (the payments ledger, the report,
   availability and schedule rules, the customers directory, the studio inbox) and returns rows (at most 10, with the
   true total), money in centavos shown as "₱1,200.00", links to the admin pages, and a list of **facts**: every
   number and date the answer may use.
3. **Phrase (one more AI call).** The model gets the tool results in a block labelled `OWNER DATA` (escaped: no line
   breaks, no label lookalikes; never a message text, a phone number or an email) and writes the answer.
   A clarification, a refusal and the briefing need no second call. At most two calls per question.

## Guards on every answer

- The leak check (the section names of both prompts) gives the fixed text.
- A sentence where Lumi says it DID something ("I recorded the payment", "na-record ko na") makes the whole answer the
  fixed "I can only read your records" text.
- **NumberGuard** removes every sentence with an amount, count, percentage, date, time or period word ("yesterday",
  "last month") that is not in the tool facts, the owner's question or the small set 1, 2, 3 and a year. When nothing
  is left, the owner gets a fixed text with the link to Reports.
- The redactor hides any phone number or email left, then the usual spacing cleanup and the dangling-colon cleanup.

## Limits and records

`assistant.limits.features.owner_ask`: 20 a minute (since 7E-3b) and 200 a day, counted on owner_ask calls only, and 15% of the
shop's daily budget. The usual counting rule (only a call the provider answered counts), failover to Gemini and the
cooldown are the same code as for customers (`ProviderChain`). Each call writes an `assistant_runs` row with the TOOL
NAMES used (`tools`), never parameters, results or text. The conversation is kept in `owner_assistant_messages`.

## Endpoints (owner role only)

| | |
|---|---|
| `POST /owner/assistant/ask` | `message` (as long as a customer's), optional `fresh` (ask without the earlier turns). Returns `reply`, `cards`, `links`, `tools_used`, `via_backup` |
| `GET /owner/assistant` | The full page (7E-3b) |
| `GET /owner/assistant/history` | The owner's own turns, 50 a page, newest page first; `?before=id` for older, `older` is the next cursor |
| `GET /owner/assistant/briefing` | Today at a glance, written by code: no AI call, nothing counted; `items` = each line with its owner page |

The ask answer also has `notice`: `limit_minute` ("You are asking quickly. Please wait a minute and try again."),
`limit_day` (today's limit) or `unavailable`; the page shows it as a calm notice. Links and cards are relative
`/owner/...` paths only. The fixed texts (refusals, limits, clarification) follow the language of the question.

## The UI (7E-3b)

`<x-owner.ask-lumi mode="compact|full">` and `resources/js/owner-ask.js`: greeting and chips
(`assistant.owner.suggested_prompts`), the owner's bubble right away, a typing line, Retry and Discard on a failed
question, "New question" (asks without the earlier turns), Load older, the Lumi Lite pill, Esc closes the panel and
focus goes back to the button, a phone sheet that keeps Tab inside, reduced motion respected, light and dark. Errors
in plain words: offline, 419 (reload), signed out, the minute limit, anything else ("Something went wrong").

## The tools

| Tool | Reads |
|---|---|
| `income_summary` | Recorded payments in a period (ledger totals); by method, by kind or by day; compared with the period before |
| `unpaid_balances` | The ledger's awaiting-payment rule: who, how much, how many days; ready for pickup but unpaid |
| `orders_overview` / `order_lookup` | Printing orders per status and the latest ones; one order by PR reference |
| `bookings_schedule` / `booking_lookup` | Sessions in a period (confirmed, waiting or all); one booking by PB reference |
| `slot_availability` | Free start times of one day for a package or a length (AvailabilityService) |
| `customer_search` / `customer_profile` | Customers by name (to tell two Marias apart); one customer's numbers and latest requests |
| `messages_overview` | Unanswered studio threads and the ones Lumi handed over (names and times only) |
| `catalog_performance` | The most requested services and packages in a period (the reports page's lists) |
| `lumi_usage` | Lumi's calls per feature and an estimated cost, from `assistant_runs` metadata |
| `how_to` | Fixed steps from `config/assistant.php` (`owner.how_to`) with the link to the page that does it |
| `briefing` | The owner overview's own numbers |

Periods are presets (today, yesterday, this_week, last_week, this_month, last_month, last_7_days, last_30_days,
all_time) or two ISO dates, at most 366 days, weeks from Monday, in Manila days (`DateRangeResolver`).

## Adding a tool (Phase 8 will add walk-in and income tools)

1. A class in `app/Services/Assistant/Owner/Tools` extending `OwnerTool`: `name()`, a one-line `description()`, a
   `parameters()` schema (the validation rules are built from it) and `handle()`, which only reads and returns a
   `ToolResult`. Put every number through `money()`, `count()`, `percent()`, `day()`, `moment()` or `number()` so it
   becomes a fact; never return a phone number, an email or a message text.
2. One line in `OwnerTools::TOOLS`.
3. A test that compares it with the existing admin query, and owner eval cases in `database/evals/cases/owner.json`.
