# MarketData namespace — the wall (market/funnel side)

**This is the public/opt-in reservoir.** Repositories here own the vendor
directory, the comparison engine, the supplier-lead outbox, and the paid
promotion slots.

**This data feeds the supplier funnel to GreenDock.**
**It MUST NEVER touch the restaurant's private POS/menu/cost data.**

## Rules

1. Code outside `App\MarketData\*` that needs to read or write a market
   table must do so through a repository in this namespace.
2. Nothing in this namespace may `use App\PrivateData\*`. Funnel code is
   forbidden from importing private repositories — enforced by the test in
   `tests/DataWall/DataWallTest.php`.
3. Funnel-side code must not mention private table names (`pos_sales`,
   `menu_items`, `recipes`, `plate_costs`, `labor_shifts`,
   `recommendations`, `goals`, `plans_sandbox`, `pos_integrations`) in
   raw SQL or string literals. Same test enforces this.
4. The only path from a restaurant into the funnel is `comparison_requests`
   → `supplier_leads` — both populated from the restaurant's *explicit*
   opt-in act of running a vendor comparison. Never from passive POS data.
5. `supplier_leads` rows may only be inserted by `App\MarketData\LeadFunnelService`.
   The test asserts this is the sole `INSERT … INTO supplier_leads` site.

Lead handoff to GreenDock is an outbound HMAC webhook via
`App\Services\WebhookDispatcher::dispatch()` — see spec §1a Pipe B.
Carafe does not talk to GreenDock's database; GreenDock subscribes to
the `lead.handoff` event like any third party.

See: spec §1.5, §7, §1a.
