# PVModern — API Flow Documentation

This document complements [`openapi.yaml`](./openapi.yaml) (rendered via
[`swagger.html`](./swagger.html)) with end-to-end flow diagrams for the
non-obvious parts of the PVModern HTTP surface — checkout, payments, webhooks,
SSE polling, and admin review.

> Open `docs/swagger.html` in a browser to explore each endpoint
> interactively. The Mermaid diagrams below render natively in GitHub /
> GitLab / VS Code preview.

---

## 1. Route layout (frontName → controller)

PVModern registers six storefront `frontName`s plus one admin route:

| frontName | Router prefix | Purpose |
|---|---|---|
| `api` | `/api/...` | API aliases (currency, news, weather, banners, locations, qr, webhooks). Registered **before** `Magento_Webapi` so it wins. |
| `currency`, `weather`, `news`, `currency-rate` | `/currency/...` etc. | Dual-purpose: HTML page when called as a regular route, JSON when called as `/api/...`. |
| `pvmodern` | `/pvmodern/...` | Generic catch-all (`search/suggest`, `checkout/*`, `payments/*`, `paymentSessions/*`, `orders/*`, `qr/*`, `shipping/*`, `auth/*`). |
| `pvadmin` | `/pvadmin/admin/...` | Custom cookie-protected payment review dashboard. |
| `payment-confirmation`, `order-tracking` | `/payment-confirmation`, `/order-tracking` | Refresh-safe customer pages. |
| `pvmodern_payments` (adminhtml) | `/pvmodern_payments/payments/...` | Native Magento backend (ACL `YourVendor_PVModern::payments`). |

---

## 2. Storefront data dashboards

All four dashboards (currency, weather, news, banners) follow the same
pattern: upstream provider → in-process cache → JSON envelope with a
`mock: bool` flag so the frontend can show a "showing fallback data" badge.

```mermaid
sequenceDiagram
    autonumber
    participant Browser
    participant Magento as PVModern Controller
    participant Cache as var/cache/pvmodern/*
    participant Provider as Upstream (Vietcombank / OpenWeather / VnExpress / open-api.vn)

    Browser->>Magento: GET /api/{currency|weather|news|banners|locations}
    Magento->>Cache: read cached XML/JSON (TTL 5–86400s)
    alt cache fresh
        Cache-->>Magento: bytes
    else stale or missing
        Magento->>Provider: HTTP GET (cURL, 6–8s timeout)
        Provider-->>Magento: XML / JSON
        Magento->>Cache: write
    end
    Magento-->>Browser: { success, ..., mock: false }
    Note right of Magento: Sets Cache-Control: public, max-age=300–86400
```

---

## 3. Multi-step checkout & payment

The custom checkout deliberately bypasses Magento's default flow. The
storefront submits to three controllers in sequence, then **polls or
subscribes** for payment confirmation while the user pays in their wallet
app.

```mermaid
sequenceDiagram
    autonumber
    participant UI as Storefront (step 1→5)
    participant CO as /pvmodern/checkout/*
    participant Pay as /pvmodern/payments/*
    participant SSE as /pvmodern/paymentSessions/events
    participant DB as pv_payment_attempt + sales_order
    participant Provider as MoMo / VNPay / Stripe / Bank QR

    Note over UI: Step 1–2: cart + address
    UI->>CO: POST /quote (form_key + address)
    CO-->>UI: shipping_methods[]

    Note over UI: Step 3: choose payment
    UI->>CO: POST /placeOrder (form_key + addresses + items)
    CO->>DB: sales_order row (increment_id "000000037")
    CO-->>UI: { order_increment_id }

    Note over UI: Step 4: payment session
    UI->>Pay: POST /create { orderId, selectedMethod, wallet?, bank_id? }
    Pay->>Provider: PaymentManager::initialize()
    Provider-->>Pay: { qr_code_url, redirect_url, deeplink_url, reference }
    Pay->>DB: pv_payment_attempt (status=pending)
    Pay-->>UI: { paymentAttemptId, qrCodeUrl, paymentUrl, statusEndpoint, eventsEndpoint }

    par SSE preferred
        UI->>SSE: GET /events?paymentAttemptId=42 (EventSource)
        SSE-->>UI: event: status data:{status:"pending"}
        Note over SSE,Provider: SSE polls DB every 150ms + actively pulls SePay every 2s
    and HTTP fallback
        UI->>Pay: GET /pvStatus?orderId=000000037 (every ~3s)
    end

    Provider-->>+Webhook: HMAC-signed callback (see flow §5)
    Webhook->>DB: pv_payment_attempt.status = paid
    Webhook-->>-Provider: 200 OK

    SSE-->>UI: event: status data:{status:"paid", nextStepAllowed:true}
    Note over UI: Step 5: redirect to /payment-confirmation
```

Key endpoints:

| Step | Endpoint | Notes |
|---|---|---|
| Quote | `POST /pvmodern/checkout/quote` | `form_key` required; returns 422 on session expiry. |
| Place order | `POST /pvmodern/checkout/placeOrder` | Creates Magento order + populates `sales_order_payment.additional_information.pvmodern_payment_context`. |
| Create session | `POST /pvmodern/payments/create` | Dispatches `bank_transfer` vs `online_gateway`; injects `wallet_id` / `bank_id` overrides; supports `selectedMethod=momo|vnpay|stripe` shorthand. |
| Poll | `GET /pvmodern/payments/pvStatus?orderId=...` | Auto-expires after 30 min; merges latest attempt status. |
| Stream | `GET /pvmodern/paymentSessions/events?paymentAttemptId=...` | `text/event-stream`; closes on terminal status. |
| Manual recheck | `POST /pvmodern/paymentSessions/retryStatusCheck` | Forces `PaymentStatusPoller::pollAttempt`. |

---

## 4. SSE polling internals

The SSE controller is the linchpin of the "instant" payment confirmation
UX. It tears down Magento's output buffers, polls the DB every 150ms, and
actively pulls SePay's UserAPI every 2 seconds as a webhook fallback:

```mermaid
flowchart TD
    A[connect] --> B[loop while < 25 min and not aborted]
    B --> C{demo mode?}
    C -- yes --> D[skip SePay poll]
    C -- no --> E{attempt pending & SePay window elapsed >= 2s?}
    E -- yes --> F[SepayPoller::pollForTransferCode]
    F --> G[reload attempt from DB]
    E -- no --> G
    D --> G
    G --> H{status changed?}
    H -- yes --> I[emit event: status]
    I --> J{terminal status?}
    J -- yes --> Z[exit;]
    J -- no --> K[sleep 150ms]
    H -- no --> L{keepalive >= 10s?}
    L -- yes --> M[emit `: keepalive`]
    L -- no --> K
    M --> K
    K --> B
```

---

## 5. Payment webhooks — provider matrix

All webhooks use the same downstream pipeline (`CassoTransactionProcessor`
for bank credits, `PaymentAttemptService::applyProviderResult` for gateway
events) but each verifies a different signature scheme:

| Endpoint | Method | Auth scheme | Hashed material |
|---|---|---|---|
| `/api/webhooks/casso` | POST | `X-Casso-Signature: t=<ms>,v1=<hex>` | HMAC-SHA512 of `<t>.<canonical_json>` (recursively key-sorted, then re-encoded with `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`) |
| `/api/webhooks/sepay` | POST | `Authorization: Apikey <secret>` (also `Bearer`, `X-Webhook-Secret`, `X-Sepay-Token`) | constant-time string compare; accepts live or sandbox key |
| `/api/webhooks/momo` | POST | `signature` field in body | HMAC-SHA256 over canonical `accessKey=…&amount=…&…&transId=…` string |
| `/api/webhooks/vnpay/ipn` | **GET** | `vnp_SecureHash` query field | HMAC-SHA512 over `urlencode(k)=urlencode(v)` pairs (sorted, excluding hash fields) |
| `/api/webhooks/stripe` | POST | `Stripe-Signature: t=…,v1=…` | HMAC-SHA256 of `<t>.<raw_body>`; rejects timestamps older than 300s |
| `/pvmodern/payments/casso` | POST | Legacy `Secure-Token: <token>` header | constant-time compare against `pvmodern/casso_token` |
| `/pvmodern/payments/webhook` | POST | `X-PVModern-Signature` | HMAC-SHA256 of raw body using `PAYMENT_WEBHOOK_SECRET`; 501 if unset |

```mermaid
sequenceDiagram
    autonumber
    participant P as Provider (MoMo/VNPay/Stripe/Casso/SePay)
    participant WH as /api/webhooks/<provider>
    participant Svc as PaymentAttemptService / CassoTransactionProcessor
    participant DB as pv_payment_attempt + sales_order
    participant SSE as Active /events stream(s)

    P->>WH: signed POST/GET
    WH->>WH: verify signature (HMAC-SHA256/512)
    alt invalid signature
        WH-->>P: 401 (recordEvent rejected)
    else valid
        WH->>Svc: findAttemptForProvider() + recordEvent()
        Svc->>Svc: applyProviderResult(success|failed)
        Svc->>DB: UPDATE pv_payment_attempt SET status='paid', paid_at=NOW()
        Svc->>DB: sales_order.payment.additional_information['pvmodern_payment_status']='paid'
        WH-->>P: provider-specific ack envelope
    end
    Note over DB,SSE: Next 150ms SSE tick observes the change and emits `event: status data:{status:"paid"}`.
```

---

## 6. Browser-return vs. server-IPN

For wallet flows (MoMo, VNPay), the user's browser is **redirected back**
from the wallet app with a signed query string. Crucially:

- `/pvmodern/checkout/momoReturn` and `/pvmodern/checkout/vnpayReturn` are
  **navigation-only** — they verify the signature for logging but **never
  flip the order to paid**. Confirmation is always driven by the verified
  IPN.
- The return controller redirects to `/checkout?payment_result=pending|failed`
  so the storefront can present an appropriate transient state until the
  IPN/SSE chain catches up.

```mermaid
sequenceDiagram
    participant Browser
    participant Wallet as MoMo/VNPay app
    participant Return as /pvmodern/checkout/*Return
    participant IPN as /api/webhooks/* (or /pvmodern/checkout/momoIpn)
    participant DB

    Browser->>Wallet: redirect_url (from /payments/create)
    Wallet-->>Browser: 302 → /pvmodern/checkout/momoReturn?signature=...
    Browser->>Return: GET with signed params
    Return->>Return: verify signature (log only, no DB write)
    Return-->>Browser: 302 → /checkout?payment_result=pending&gateway=momo
    par
        Wallet->>IPN: server-to-server signed POST
        IPN->>DB: status=paid
    and
        Browser->>SSE: still listening
        SSE-->>Browser: status:paid
    end
```

---

## 7. Demo-mode scan-to-pay

When `PVMODERN_PAYMENT_DEMO=true`, the storefront emits QR codes that
encode an HTTPS URL (via `/api/qr/url?data=...`) pointing at
`/api/qr/scan-paid?order=ORD000000076`. A phone scan opens that URL, which
invokes the **same** `CassoTransactionProcessor::process()` pipeline as a
real SePay webhook — bypassing the bank but exercising the production code
path end-to-end. The endpoint refuses with **403** when demo mode is off.

```mermaid
sequenceDiagram
    participant Desk as Desktop browser (step 4)
    participant Phone as Phone camera
    participant Scan as /api/qr/scan-paid
    participant Proc as CassoTransactionProcessor
    participant DB
    participant SSE as /paymentSessions/events

    Desk->>Desk: render QR(image=/api/qr/url?data=https://.../scan-paid?order=ORD…)
    Phone->>Scan: GET /api/qr/scan-paid?order=ORD000000076
    Scan->>Scan: verify PVMODERN_PAYMENT_DEMO=true
    Scan->>Proc: process({tid:DEMO-SCAN-..., amount, kind:1})
    Proc->>DB: pv_payment_attempt.status='paid'
    Scan-->>Phone: HTML landing ("Đã ghi nhận")
    DB-->>SSE: next tick observes status change
    SSE-->>Desk: event: status data:{status:"paid"}
    Desk->>Desk: advance to step 5
```

---

## 8. Admin review (manual fallback)

When the auto-matcher in `CassoTransactionProcessor` can't tie a bank
credit to a `pv_payment_order` (amount mismatch, missing transfer code,
etc.), the transaction lands in `pv_payment_review`. Two dashboards can
resolve it:

- **`/pvadmin/admin/*`** — custom dashboard, cookie-protected
  (`pv_admin` cookie set by `POST /pvadmin/admin/login`). Quickest UX.
- **`/pvmodern_payments/payments/*`** (Magento backend) — ACL-gated by
  `YourVendor_PVModern::payments`. Records the admin username from
  `$this->_auth->getUser()`.

```mermaid
sequenceDiagram
    participant Cust as Customer pays bank transfer
    participant Casso as Casso webhook
    participant Proc as CassoTransactionProcessor
    participant Review as pv_payment_review row
    participant Admin as Admin (UI of choice)
    participant DB

    Cust->>Casso: ATM transfer "ORD000000037 ..."
    Casso->>Proc: POST /api/webhooks/casso (signed)
    Proc->>Proc: regex /\bORD\d+\b/ → match
    alt amount matches pv_payment_order
        Proc->>DB: status=paid
    else amount mismatch / no match
        Proc->>Review: insert review row
        Note over Review: status=pending_review
    end

    Admin->>Admin: open /pvadmin/admin/dashboard
    Admin->>Review: GET /pvadmin/admin/paymentReviews
    Admin->>DB: POST /pvadmin/admin/resolvePaymentReview { transactionId, pv_order_id }
    DB->>DB: confirmPaid + mark review resolved
```

---

## 9. Order tracking

Customer-facing tracking lookups are thin wrappers around `ShippingManager`,
which dispatches to one of three adapters (GHN, GHTK, SPX):

- `GET /pvmodern/orders/tracking?order_id=...&provider=spx` — humanised payload
  for the storefront tracking page.
- `GET /pvmodern/shipping/track?provider=...&tracking_number=...` — raw
  adapter response (admin/internal use).
- `POST /pvmodern/shipping/cancel?provider=...&shipment_id=...` —
  carrier-side cancellation.

Tracking numbers are auto-generated as `<PROVIDER>-<orderid>-MOCK` when not
supplied, so the page renders even before the carrier has a label.

---

## 10. Auth (GitHub OAuth bridge)

- `GET /pvmodern/auth/github` redirects to `https://github.com/login/oauth/authorize?...`
  with a generated `state` saved in the Magento session.
- `GET /pvmodern/auth/githubCallback?code=...&state=...` exchanges the
  code, fetches the GitHub user (and falls back to `/user/emails` for the
  primary verified email), and either logs in the existing customer or
  creates one (`accountManagement::createAccountWithPasswordHash` with a
  random 32-char hash).

```mermaid
sequenceDiagram
    User->>Magento: GET /pvmodern/auth/github
    Magento-->>GitHub: 302 /login/oauth/authorize?state=...
    GitHub-->>User: consent screen
    User->>GitHub: approve
    GitHub-->>Magento: 302 /pvmodern/auth/githubCallback?code=...&state=...
    Magento->>GitHub: POST /login/oauth/access_token
    GitHub-->>Magento: { access_token }
    Magento->>GitHub: GET /user (+/user/emails if needed)
    Magento->>Magento: customerRepository.get(email) or createAccountWithPasswordHash()
    Magento->>User: 302 /customer/account
```

---

## How to view this documentation

```bash
# Pretty Swagger UI (requires any static server)
cd /var/www/Magento/docs
python3 -m http.server 8088
# open http://localhost:8088/swagger.html
```

Or with the Magento dev server already running:

```
https://<your-store-host>/docs/swagger.html
```

(Make sure the web server is allowed to serve `docs/` — Magento ships with
`docs/` not in the public web root by default; either symlink it under
`pub/docs` or open `swagger.html` directly from disk.)

The Mermaid diagrams above render in GitHub, GitLab, VS Code, and most
modern markdown viewers without additional tooling.
