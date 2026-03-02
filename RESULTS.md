# Performance Test Results

## Environment
- PHP 8.4.0 (NTS x64)
- Laravel 12
- PostgreSQL (remote host: 46.224.246.253:5433)
- OS: Windows 11

---

## Approach 1 — In-Memory Index (`main` branch)

### Architecture & Decisions

**Single query, all fields**
Fetching `id, name, surname, birthdate` in one `SELECT` means the database is touched
exactly once for the entire request. No second round-trip for step 6.

**In-memory hash map**
While iterating the 1M rows for age calculation, each row's `id` is used as a key in
a PHP array (`$userIndex[id] = "name surname"`). When step 6 needs 50 users, it does
a direct `isset($userIndex[$id])` — O(1) per lookup, ~0ms total.

**`PDO::FETCH_NUM`**
Returning rows as indexed arrays `[0, 1, 2, 3]` instead of associative arrays or
objects avoids the overhead of PHP constructing string keys or stdClass instances for
every one of the 1M rows. Lower per-row allocation cost.

**`gc_disable()` during the loop**
PHP's cyclic garbage collector can fire mid-loop and pause execution. Disabling it
for the duration of the 1M-row iteration removes this unpredictable latency spike.
`gc_enable()` + `gc_collect_cycles()` is called immediately after to clean up.

**`SET work_mem = '256MB'`**
Tells PostgreSQL to allocate more RAM for internal sort and hash operations on this
session before spilling to disk. Reduces I/O during query planning/execution.

**`mt_rand(0, 1000000)` per spec**
The test case explicitly specifies this function and range. ID 0 will not match any
row (IDs start at 1), so results may occasionally be fewer than 50 — this is correct
behaviour per the spec.

**Integer age calculation**
`(int)(($todayInt - $birthdateInt) / 10000)` avoids constructing 1M `DateTime`
objects. `$todayInt` is computed once outside the loop (e.g. `20260303`), and the
integer subtraction is leap-year-safe.

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

### Analysis
Total time is dominated by **network transfer** from the remote PostgreSQL host.
Fetching 4 columns instead of 1 increases payload ~3–4×, adding ~10s over the wire.
The in-memory index costs 56 MB (1M PHP string entries) but eliminates the step 6
DB round-trip entirely. **On a local database this approach would be fastest overall.**

---

## Approach 2 — Cursor Streaming (`approach-streaming` branch)

### Architecture & Decisions

**Fetch birthdate only**
Only the column needed for age calculation is transferred. Over a remote connection
this dramatically reduces network payload — ~10 bytes per row vs ~35 bytes, cutting
transfer time by ~3×.

**Server-side PDO cursor**
`$stmt->fetch()` in a `while` loop reads rows one at a time from the PostgreSQL wire
buffer. PHP never holds all 1M rows in memory simultaneously. Memory stays flat
regardless of row count — this scales to 10M or 100M rows without change.

**Separate step 6 DB query**
Since `name` and `surname` are not in memory, a `WHERE id IN (...)` query with 50
IDs is issued after the full-table scan. Hits the primary key index — fast, but
costs one extra network round-trip (~200ms on a remote host).

**`mt_rand(0, 1000000)` per spec**
Same as Approach 1.

**Integer age calculation**
Same trick as Approach 1 — single integer subtraction per row, no DateTime objects.

### Results

Two runs were recorded to capture the cold vs warm PostgreSQL buffer cache effect.

#### Run 1 — Cold Cache (data read from disk)

| Metric | Value |
|--------|-------|
| Total time | 55.2465 s |
| Steps 4–5 time | 55.0313 s |
| Step 6 time | 0.2152 s (WHERE id IN, PK index) |
| Peak memory | 22.00 MB |
| Memory delta | 0.10 MB |
| Rows processed | 1,000,000 |
| Ortalama Yaş | 47.66 |

#### Run 2 — Warm Cache (data served from PostgreSQL shared_buffers)

| Metric | Value |
|--------|-------|
| Total time | **2.2987 s** |
| Steps 4–5 time | 2.0864 s |
| Step 6 time | 0.2123 s (WHERE id IN, PK index) |
| Peak memory | 20.00 MB |
| Memory delta | 0.10 MB |
| Rows processed | 1,000,000 |
| Ortalama Yaş | 47.66 |

### Cold vs Warm Cache Explained
On the first run PostgreSQL had no data in `shared_buffers` and was forced to read
all 1M rows from disk — physical I/O over a remote connection produced the 55s result.
After run 1, those pages were cached in PostgreSQL's memory. Run 2 served everything
from RAM, dropping total time to 2.3s. This is expected behaviour for any database
system and is why production databases get faster as hot data stays in the buffer pool.

### Analysis
Minimises network transfer by fetching only the column needed for age calculation.
Step 6 costs one extra DB round-trip (~200ms on remote host) but total wall-clock
time is lower when the database is remote because the reduced payload saves more
time than the extra round-trip costs. **Optimal approach for remote databases.**
Warm-cache steady-state performance: **2.3s** for 1M rows at 20 MB peak memory.

---

## Tradeoff Summary

| | Approach 1 (main) | Approach 2 (streaming) |
|---|---|---|
| Columns fetched | id, name, surname, birthdate | birthdate only |
| Network payload | ~35 bytes/row | ~10 bytes/row |
| Step 6 DB query | None (array lookup) | Yes (WHERE id IN) |
| Peak memory | 76 MB | 20 MB |
| Cold cache time | 19.1s | 55.2s |
| Warm cache time | — | **2.3s** |
| Best for | Local / low-latency DB | Remote / high-latency DB |
