# Indexing: route + date search

The most frequent query in the app is the flight search on
[flights.php](../flights.php): "flights from city A to city B on a date."
This note adds a composite index for that access path and shows the real
`EXPLAIN` output before and after, captured on the seeded database
(125 flight rows, MySQL 8.0).

```sql
KEY idx_flights_route_time (departure_city, arrival_city, departure_time)
```

## The index in action (equality on cities + date range)

A search written so the optimizer can use the index — exact city match
plus a half-open day range on `departure_time`:

```sql
EXPLAIN SELECT * FROM flights
WHERE departure_city = 'Delhi'
  AND arrival_city   = 'Mumbai'
  AND departure_time >= '2026-06-15 00:00:00'
  AND departure_time <  '2026-06-16 00:00:00'
ORDER BY departure_time;
```

**Before** (`ALTER TABLE flights DROP INDEX idx_flights_route_time`):

```
        type: ALL
possible_keys: NULL
         key: NULL
        rows: 125
       Extra: Using where; Using filesort
```

**After** (index present):

```
        type: range
possible_keys: idx_flights_route_time
         key: idx_flights_route_time
        rows: 8
       Extra: Using index condition
```

## What changed and why

The access `type` went from `ALL` (a full table scan reading every row)
to `range` (the engine seeks into the B-tree and walks only the matching
slice), and the estimated `rows` examined dropped from **125 to 8** — the
exact Delhi→Mumbai flights in that day's window. The `ORDER BY
departure_time` filesort also disappears, because the index already
returns rows in `departure_time` order once the two city columns are
pinned to constants.

**Column order matters.** A composite index is only usable left-to-right,
and a range scan can use a column only up to (and including) the first
range predicate. So the two equality columns come first
(`departure_city`, `arrival_city`) and the range column
(`departure_time`) comes last. Reverse that order — date first — and the
two equality filters could no longer be applied inside the index seek.

**Why not index `status` (or other low-cardinality columns) alone.** A
standalone index on a column with a handful of distinct values (the six
flight statuses, or `gender`) is rarely worth it: a `WHERE status =
'scheduled'` may still match a large fraction of the table, so the
optimizer prefers a full scan over jumping back and forth between index
and rows. Indexes pay off on selective predicates, which is why the
route+date composite — narrowing 125 rows to 8 — is a good one.

## Honest caveat: the current search query is non-sargable

The query [flights.php](../flights.php) actually runs uses
`departure_city LIKE '%Delhi%'` (leading wildcard) and
`DATE(departure_time) = ?` (a function wrapping the column). Both are
**non-sargable**: a leading `%` means the B-tree can't seek to a prefix,
and wrapping `departure_time` in `DATE()` hides the raw column from the
index. Its `EXPLAIN` is a full scan **with or without** the index:

```
        type: ALL
possible_keys: NULL
         key: NULL
        rows: 125
       Extra: Using where; Using filesort
```

At 125 seed rows this is irrelevant, so the search UX was left unchanged.
But the fix, if the flight table ever grew large, is to make the query
match the index: a prefix/exact city match instead of `%term%`, and a
half-open range (`departure_time >= d AND < d + 1 day`) instead of
`DATE(departure_time) = d`. The index is in place and ready for that
rewrite — this is the difference between an index *existing* and an index
being *used*.
