# Credit-card query-plan evidence

Captured against the SQLite test database for the 10,000 combined-movement
regression (5,000 cash transactions plus 5,000 card purchases/installments).

| Read shape | Evidence | Decision |
| --- | --- | --- |
| Statement installment aggregate by card and statement | `credit_card_installments_card_statement_index` | Existing composite index is used; no new index is warranted. |
| Card list by owner/status | `credit_cards_owner_status_index` | Existing owner/status index scopes the list before summaries are calculated. |
| Statement list by card and owner | `credit_card_statements_card_closing_unique` | Existing card/closing index supports the card statement read. |

The automated regression asserts the first plan and the two-second response
budget for card listing, statement detail, and the credit-card dashboard
projection. Revisit this evidence before adding an index if future query shape
or production data distribution changes.
