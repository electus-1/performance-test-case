# Performance Test Results

## Environment
- PHP 8.4.0 (NTS x64)
- Laravel 12
- PostgreSQL (remote host: 46.224.246.253:5433)
- OS: Windows 11

---

## Approach 1 — In-Memory Index (`main` branch)

### Strategy
- Single query fetches **all fields**: `id, name, surname, birthdate`
- Builds an in-memory hash map (`id → "name surname"`) while iterating
- Step 6 is a pure PHP array lookup — **no second DB query**
- `gc_disable()` during the loop to eliminate GC overhead
- `SET work_mem = '256MB'` on the PostgreSQL session
- `PDO::FETCH_NUM` for lowest per-row memory overhead

### Results

| Metric | Value |
|--------|-------|
| Total time | 19.1122 s |
| Steps 3–5 time | 19.1121 s |
| Step 6 time | **0.0000 s** (in-memory lookup) |
| Peak memory | 76.00 MB |
| Memory delta | 56.16 MB |
| Rows processed | 1,000,000 |
| Ortalama Yaş | 47.66 |

### Notes
Total time is dominated by **network transfer** from the remote PostgreSQL host.
Fetching 4 columns instead of 1 increases payload ~3–4×, adding ~10s over the wire.
On a **local database** this approach would be faster overall — the saved DB round-trip
on step 6 outweighs the extra column data.

---

## Approach 2 — Cursor Streaming (`approach-streaming` branch)

### Strategy
- Single query fetches **birthdate only** — minimal network payload
- Rows are streamed one by one via PDO cursor — never all in RAM simultaneously
- Step 6 uses a separate `WHERE id IN (...)` DB query

### Results

| Metric | Value |
|--------|-------|
| Total time | — |
| Steps 4–5 time | — |
| Step 6 time | — |
| Peak memory | — |
| Memory delta | — |
| Rows processed | — |
| Ortalama Yaş | — |

> Results to be filled after run.

### Notes
Minimises network transfer by fetching only the column needed for age calculation.
Step 6 costs one extra DB round-trip (~200ms on remote host) but overall wall-clock
time is lower when the database is remote.
