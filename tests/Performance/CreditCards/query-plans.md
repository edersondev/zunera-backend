# Credit card read paths with 1,000 purchases

`CreditCardQueryPerformanceTest` seeds 1,000 owned purchases and installments on one statement. It warms each API 10 times, measures the next 100 requests separately, and asserts each p95 remains below 2,000 ms in the isolated MySQL test database.

The same test inspects MySQL `EXPLAIN` for the owner-scoped purchase lookup, exact card/closing-date statement lookup, and ordered installment lookup. It checks the chosen primary key and unique indexes so these read paths avoid scanning all 1,000 purchases or installments.

The benchmark measures application response time inside the PHP feature test process. It does not claim production network latency. The targeted queries remain bounded by card and purchase identity, and the test documents the expected indexes for future query changes.
