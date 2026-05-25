# PrivateData namespace — the wall (private side)

**This is the private reservoir.** Repositories here own the restaurant's
own connected data: POS sales, menu items, recipes, plate costs, labor,
recommendations, goals, planning sandbox scenarios, POS OAuth tokens.

**This data EARNS the $0 trust and powers the money engine.**
**It MUST NEVER feed the GreenDock supplier funnel.**

## Rules

1. Code outside `App\PrivateData\*` that needs to read or write a private
   table must do so through a repository in this namespace.
2. Nothing in this namespace may `use App\MarketData\*`. Private code is
   ignorant of the funnel; it does its job without ever looking sideways.
3. Conversely, `App\MarketData\*` must never `use App\PrivateData\*` and
   must never reference any private-table name in raw SQL. The
   `tests/DataWall/DataWallTest.php` test enforces this.
4. If a feature genuinely needs to combine private + market data (rare —
   none in Phase 1), do it in the controller layer by calling both
   repositories and composing in PHP. Never in SQL. Never with a join.

See: spec §1.5 (sacred data reservoirs), §7 (data model), and the data
wall test for the enforced list of private table names.
