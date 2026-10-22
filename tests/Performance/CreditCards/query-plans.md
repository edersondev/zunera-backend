# Credit card read paths with 1,000 purchases

`CreditCardQueryPerformanceTest` seeds 1,000 owned purchases and installments on one statement. It warms each API 10 times, measures the next 100 requests separately, and asserts each p95 remains below 2,000 ms in the SQLite test database.

The same test inspects SQLite `EXPLAIN QUERY PLAN` for the owner-scoped purchase lookup, exact card/closing-date statement lookup, and ordered installment lookup. Each must report `SEARCH`, so these read paths use indexed lookup instead of scanning all 1,000 purchases or installments. The purchase lookup uses the primary key; statement identity uses the card/closing-date unique index; installment lookup uses the purchase/sequence unique index.

The benchmark measures application response time inside the PHP feature test process. It does not claim production network latency or MySQL timing. The targeted queries remain bounded by card and purchase identity, and the test documents the expected indexes for future query changes.
