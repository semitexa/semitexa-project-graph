### Graph diff against `main` — `fixture`

**+2 / −6 edges**

> Coverage: 1 dynamic reference. Edges the graph could not see are not in this diff.

#### Wiring

| | Edge | From | To |
|---|---|---|---|
| − | listens_to | `SendReceiptListener` | `OrderPlaced` |
| − | serves_route | `PlaceOrderPayload` | `POST /orders` |
| + | serves_route | `PlaceOrderPayload` | `POST /orders/place` |

#### Code references

| | Edge | From | To |
|---|---|---|---|
| − | accepts | `SendReceiptListener` | `OrderPlaced` |
| + | accepts | `PlaceOrderHandler` | `OrderPlaced` |

<sub>Also changed: imports +0/−2, intent_for +0/−1.</sub>
