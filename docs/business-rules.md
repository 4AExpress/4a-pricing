# Business Rules

> Rules the pricing engine MUST enforce. A violation = bug.

## R1 — Weight monotonicity (within a zone)

For any pricelist P, origin O, destination D, the cumulative price must be **non-decreasing** as weight increases:
