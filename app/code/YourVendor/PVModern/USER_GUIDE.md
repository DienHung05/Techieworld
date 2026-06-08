# PVModern — Complete System Documentation & User Guide

This guide documents every module, feature, integration and workflow that has been developed and added by the **`YourVendor_PVModern`** module to a Magento 2 storefront ("Techieworld"). It is built strictly from the source code in [app/code/YourVendor/PVModern/](app/code/YourVendor/PVModern/) so every claim below references the actual file that implements it.

---

## 1. Module overview

| Item | Value |
| --- | --- |
| Vendor | YourVendor |
| Module name | `YourVendor_PVModern` |
| Registration | [registration.php](app/code/YourVendor/PVModern/registration.php) |
| Manifest | [etc/module.xml](app/code/YourVendor/PVModern/etc/module.xml) — `setup_version="1.0.0"`, sequenced after `Magento_Catalog`, `Magento_Cms`, `Magento_Checkout`, `Magento_Customer`, `Magento_Payment`, `Magento_Shipping` |
| Default config | [etc/config.xml](app/code/YourVendor/PVModern/etc/config.xml) |
| DI wiring | [etc/di.xml](app/code/YourVendor/PVModern/etc/di.xml), [etc/frontend/di.xml](app/code/YourVendor/PVModern/etc/frontend/di.xml) |
| Cron schedule | [etc/crontab.xml](app/code/YourVendor/PVModern/etc/crontab.xml) |
| ACL | [etc/acl.xml](app/code/YourVendor/PVModern/etc/acl.xml) |
| Admin config | [etc/adminhtml/system.xml](app/code/YourVendor/PVModern/etc/adminhtml/system.xml), [etc/adminhtml/menu.xml](app/code/YourVendor/PVModern/etc/adminhtml/menu.xml), [etc/adminhtml/routes.xml](app/code/YourVendor/PVModern/etc/adminhtml/routes.xml) |
| Frontend routes | [etc/frontend/routes.xml](app/code/YourVendor/PVModern/etc/frontend/routes.xml) |
| CSP whitelist | [etc/csp_whitelist.xml](app/code/YourVendor/PVModern/etc/csp_whitelist.xml) — allows `api.qrserver.com`, `img.vietqr.io`, `*.momo.vn`, `*.vnpayment.vn`, `*.vnpay.vn`, `*.stripe.com` as image sources |

PVModern extends a Magento 2 storefront with:

1. A **custom checkout flow** (step 1–5 wizard) with shipping/payment provider abstractions.
2. **Verified server-side payments** (MoMo, VNPay, Stripe, BIDV bank transfer via Casso/SePay) with idempotent webhooks, status polling, expiration, fulfillment and Server-Sent Events (SSE) live status.
3. A **payment-admin micro-app** at `/pvadmin` and a Magento backend menu at *Sales → Xác nhận thanh toán*.
4. Info dashboards: **News**, **Weather**, **Currency**, **Order Tracking** with safe API proxies.
5. **Warranty lookup** (phone+code, IMEI, order code) and a **purchase code generator**.
6. **Hero banner slider** sourced from CMS block, env JSON, product catalog, or fallback.
7. **GitHub OAuth login** and **username/email login** (`pv_username` customer attribute) plugins.
8. A **dynamic shipping carrier** (`pvmodernshipping`) that gathers GHN/GHTK/SPX rates plus an in-store pickup pseudo-method.
9. **Catalog seeding & enrichment** patches (`SeedTechProducts`, `EnrichTechCatalog`, `AddCustomerUsername`) and a **product visual resolver** with SKU/brand image maps.
10. A **storefront search suggester** and category mega-menu config.
11. **QR proxies** (SePay & QR Server) plus an optional **demo-mode "scan-to-pay"** trigger.

---

## 2. Routes & frontName overview

Declared in [etc/frontend/routes.xml](app/code/YourVendor/PVModern/etc/frontend/routes.xml) and [etc/adminhtml/routes.xml](app/code/YourVendor/PVModern/etc/adminhtml/routes.xml):

| frontName | Module route id | Purpose |
| --- | --- | --- |
| `deals` | `deals` | Deals landing page (CMS template) |
| `terms` | `terms` | Legal page |
| `privacy` | `privacy` | Privacy page |
| `cookies` | `cookies` | Cookies page |
| `pcbuilder`, `pc-builder` | `pcbuilder`, `pc_builder` | PC Builder configurator |
| `warranty` | `warranty` | Warranty lookup |
| `news` | `news` | News dashboard |
| `weather` | `weather` | Weather dashboard |
| `currency`, `currency-rate` | `currency`, `currency_rate` | Currency dashboard |
| `order-tracking` | `order_tracking` | Order tracking dashboard |
| `pvmodern` | `pvmodern` | Generic JSON endpoints (`/pvmodern/api/*`, `/pvmodern/payments/*`, `/pvmodern/checkout/*`, etc.) |
| `api` | `api` (before `Magento_Webapi`) | Aliases for the dashboard APIs and payment endpoints (`/api/...`) |
| `pvadmin` | `pvadmin` | Self-hosted lightweight admin (separate from Magento backend) |
| `payment-confirmation` | `payment_confirmation` | Refresh-safe step-4/5 page |
| Admin: `pvmodern_payments` | `pvmodern_payments` | Magento backend "Xác nhận thanh toán" page |

---

## 3. Configuration & environment

The module reads provider keys from two sources, in this priority order, via [Model/IntegrationConfig.php](app/code/YourVendor/PVModern/Model/IntegrationConfig.php):

1. PHP env (`$_ENV`, `$_SERVER`, `getenv()`).
2. **File env** `BP/pvmodern.env` (KEY=VALUE lines, `#` for comments, automatic mtime-based cache busting per FPM worker).

### Admin-managed config (System → PVModern Settings)

Defined in [etc/adminhtml/system.xml](app/code/YourVendor/PVModern/etc/adminhtml/system.xml) and consumed by the GitHub OAuth controllers:

- `pvmodern/github_oauth/client_id`
- `pvmodern/github_oauth/client_secret` (encrypted)

### Env keys (full list)

| Key | Used by | Purpose |
| --- | --- | --- |
| `APP_BASE_URL` | `IntegrationConfig::absoluteUrl()` | Used to absolutize relative redirect/IPN URLs |
| `PVMODERN_CHECKOUT_MOCK`, `PVMODERN_SHIPPING_MOCK`, `PVMODERN_PAYMENT_MOCK` | `IntegrationConfig::isMockModeEnabled()` | Per-scope mock toggles |
| `PVMODERN_PAYMENT_DEMO` | `Block/Checkout/Flow`, `VietQrBuilder`, `PaymentSessions/Events`, `Qr/ScanPaid` | Enables demo "scan-to-pay" trigger (`/api/qr/scanpaid`) |
| `PVMODERN_QR_PROVIDER` | `VietQrBuilder` | `sepay` (default) or `vietqr` |
| `PVMODERN_PUBLIC_BASE_URL` | `VietQrBuilder::buildPublicUrl()` | Override public host used in QR demo URL |
| `PVMODERN_BANK_ACCOUNT_NAME` / `_NUMBER` / `_NAME` / `_CODE` / `_BIN` / `_BRANCH` / `_TRANSFER_PREFIX` | Bank transfer & VietQR | Beneficiary details (defaults: `NGUYEN VAN A` / `0000000000` BIDV BIN `970418`) |
| `PVMODERN_STATIC_QR_BASE_URL` | `IntegrationConfig::getStaticQrBaseUrl()` | Optional folder of static QR images |
| `PVMODERN_GATEWAY_MERCHANT_CODE` / `_PUBLIC_KEY` / `_CALLBACK_URL` / `_RETURN_URL` / `_IPN_URL` | Generic gateway config | Used as fallbacks for MoMo/VNPay redirect+IPN |
| `MOMO_*` (`ENDPOINT`/`CREATE_URL`, `QUERY_URL`, `PARTNER_CODE`, `ACCESS_KEY`, `SECRET_KEY`, `REDIRECT_URL`, `IPN_URL`, `REQUEST_TYPE`) | MoMo provider, IPN, polling | Live MoMo credentials |
| `VNPAY_*` (`PAYMENT_URL`, `TMN_CODE`, `HASH_SECRET`, `RETURN_URL`, `LOCALE`) | VNPay provider, IPN, return | Live VNPay credentials |
| `STRIPE_*` (`SECRET_KEY`, `PUBLISHABLE_KEY`, `WEBHOOK_SECRET`, `CHECKOUT_SESSIONS_URL`, `API_BASE_URL`, `SUCCESS_URL`, `CANCEL_URL`) | Stripe provider & webhook | Live Stripe credentials |
| `CASSO_API_KEY`, `CASSO_WEBHOOK_SECRET` / `PVMODERN_CASSO_TOKEN`, `CASSO_API_BASE_URL`, `CASSO_SANDBOX_MODE` | Casso webhook + cron reconciliation | Bank transfer reconciliation |
| `SEPAY_API_KEY`, `SEPAY_SANDBOX_API_KEY`, `SEPAY_SANDBOX_MODE`, `SEPAY_API_TOKEN` | SePay webhook + active poller | Real-time bank-transfer detection |
| `GHN_API_TOKEN` / `GHN_SHOP_ID`, `GHTK_API_TOKEN`, `SPX_API_TOKEN` (uppercase code + `_API_TOKEN` / `_SHOP_ID`) | Shipping providers | Live shipping credentials (mock used when missing) |
| `VNEXPRESS_BUSINESS_RSS_URL` | News API proxy | Optional override for the VnExpress Kinh doanh RSS feed (`https://vnexpress.net/rss/kinh-doanh.rss` by default) |
| `OPENWEATHER_API_KEY` or legacy `WEATHER_API_KEY` | Weather API proxy | OpenWeatherMap API key |
| `OPENWEATHER_API_BASE_URL` | Weather API proxy | Optional OpenWeatherMap base URL override (`https://api.openweathermap.org` by default) |
| `VIETCOMBANK_RATES_URL` | Currency API proxy | Optional override for Vietcombank XML rates (`https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx?b=68` by default) |
| `PVMODERN_HERO_BANNERS`, `PVMODERN_HERO_BANNERS_JSON` | `HeroBannerProvider` | Optional JSON banner override (file path or inline JSON) |
| `PVMODERN_PICKUP_STORES_JSON` | `PickupLocationProvider` | Override pickup-store list |
| `PAYMENT_WEBHOOK_SECRET` | Generic `/api/payments/webhook` | HMAC-SHA256 signature key (501 if not set) |
| `GITHUB_OAUTH_CLIENT_ID`, `GITHUB_OAUTH_CLIENT_SECRET` | GitHub OAuth | Used as fallback when admin config is blank |

### Deployment config (env.php)

Used by the self-hosted `/pvadmin` micro-app:

- `pvmodern/admin_password` (default `pvadmin123`) — used by [Controller/Admin/Login.php](app/code/YourVendor/PVModern/Controller/Admin/Login.php).
- `pvmodern/telegram_bot_token`, `pvmodern/telegram_chat_id` — Telegram notifier for screenshot upload (legacy path).
- `pvmodern/casso_token` — Legacy Casso token used by [Controller/Payments/Casso.php](app/code/YourVendor/PVModern/Controller/Payments/Casso.php).

---

## 4. Database schema

Tables are created by two schema patches:

### 4.1 `pv_payment_order`

[Setup/Patch/Schema/CreatePaymentTables.php](app/code/YourVendor/PVModern/Setup/Patch/Schema/CreatePaymentTables.php) creates the base table; [Setup/Patch/Schema/AddVerifiedPaymentIntegrationTables.php](app/code/YourVendor/PVModern/Setup/Patch/Schema/AddVerifiedPaymentIntegrationTables.php) adds verified-payment columns.

Columns: `id`, `magento_increment_id`, `transfer_code`, `customer_name`, `customer_email`, `customer_phone`, `total_amount`, `payment_method`, `payment_status` (pending/paid/failed/expired/manual_review/cod_pending/awaiting_payment), `screenshot_url`, `screenshot_token` (legacy), `current_step`, `expires_at`, `paid_at`, `admin_note`, `created_at`, `updated_at`, **+ verified columns**: `payment_attempt_id`, `provider_order_id`, `provider_transaction_id`, `provider_session_id`, `currency`, `checkout_url`, `payment_url`, `qr_code_url`, `qr_code_payload`, `deeplink_url`, `signature_verified`, `raw_create_response`, `status_version`, `last_status_change_at`.

### 4.2 `pv_payment_verification`

Audit trail for verifications — `pv_order_id`, `source` (`casso`, `casso_unmatched`, `manual_admin`, `customer_upload`), `amount`, `transaction_id`, `raw_payload`, `admin_user`, `note`, `created_at`.

### 4.3 `pv_payment_attempt`

Per-checkout payment attempt — `pv_order_id`, `magento_increment_id`, `provider` (`bank_transfer`/`momo`/`vnpay`/`stripe`/...), `provider_order_id`, `provider_transaction_id`, `provider_session_id`, `status` (pending/awaiting_payment/paid/failed/expired/manual_review), `amount`, `currency`, `checkout_url`, `payment_url`, `qr_code_url`, `qr_code_payload`, `deeplink_url`, `client_secret`, `signature_verified`, `raw_create_response`, `expires_at`, `paid_at`, `status_version`, `last_status_change_at`.

### 4.4 `pv_payment_event`

Append-only log of all webhook / IPN / poll / manual events — `payment_attempt_id`, `pv_order_id`, `magento_increment_id`, `provider`, `event_type`, `provider_event_id`, `provider_transaction_id`, `signature_verified`, `amount`, `currency`, `raw_payload`, `processing_result` (`accepted`/`rejected`/`duplicate`/`manual_review`/`error`), `error_message`, `received_at`, `processed_at`.

### 4.5 `pv_bank_transfer_review`

Manual-review queue for Casso/SePay transactions that didn't match — `casso_transaction_id`, `amount`, `description`, `transaction_time`, `matched_order_id`, `status` (`unmatched`/`suggested`/`resolved`/`ignored`), `review_reason`, `resolved_by`, `resolved_at`, `raw_payload`.

### 4.6 `pv_fulfillment_job`

Idempotent fulfillment-after-payment queue (unique index on `magento_increment_id`) — `order_entity_id`, `status` (pending/processed/error), `provider`, `raw_context`, `result_payload`, `processed_at`.

### 4.7 Customer attribute `pv_username`

[Setup/Patch/Data/AddCustomerUsername.php](app/code/YourVendor/PVModern/Setup/Patch/Data/AddCustomerUsername.php) adds a unique varchar attribute used by the username login plugin.

### 4.8 Catalog seeds

- [Setup/Patch/Data/SeedTechProducts.php](app/code/YourVendor/PVModern/Setup/Patch/Data/SeedTechProducts.php) — Seeds GPU/CPU/RAM/SSD/Monitor/Laptop/Mainboard/PSU/Cooler/Accessories categories and ~80 demo SKUs, adds `brand` attribute.
- [Setup/Patch/Data/EnrichTechCatalog.php](app/code/YourVendor/PVModern/Setup/Patch/Data/EnrichTechCatalog.php) — Adds `brand` and `imei` attributes, ensures category tree, upserts curated products, enriches existing catalog (depends on `SeedTechProducts`).

The repository ships a `Helper` for table access: [Helper/PaymentDb.php](app/code/YourVendor/PVModern/Helper/PaymentDb.php) exposes typed CRUD methods (`findByIncrementId`, `createAttempt`, `transitionAttempt`, `logPaymentEvent`, `hasAcceptedEvent`, `listOrders`, `listReviews`, etc.) used by every controller and service in the module.

---

## 5. Checkout flow (5-step wizard)

### 5.1 Block & template

- Block: [Block/Checkout/Flow.php](app/code/YourVendor/PVModern/Block/Checkout/Flow.php) — exposes `getSerializedBootstrap()` and the flags `isPaymentMockMode()`, `isPaymentConfirmationOnly()`, `isPaymentDemoMode()` (driven by `PVMODERN_PAYMENT_DEMO`).
- Layout: [view/frontend/layout/payment_confirmation_index_index.xml](app/code/YourVendor/PVModern/view/frontend/layout/payment_confirmation_index_index.xml) renders `Magento_Checkout::onepage/pv-checkout.phtml` with `is_payment_confirmation_only=true` — refresh-safe step 4/5 page that does not require an active quote.

### 5.2 Service & endpoints

[Model/Checkout/CheckoutService.php](app/code/YourVendor/PVModern/Model/Checkout/CheckoutService.php) is the heart of the wizard. `buildCheckoutBootstrap()` returns:

```json
{
  "endpoints": {
    "quote": "/pvmodern/checkout/quote",
    "place_order": "/pvmodern/checkout/placeOrder",
    "locations": "/pvmodern/api/locations",
    "payment_status": "/api/payments/status",
    "payment_create": "/api/payments/create",
    "payment_events": "/api/paymentSessions/events"
  },
  "customer": { ... },
  "cart": { ... },
  "pickup_locations": [ ... ],
  "payment_methods": [ ... ],
  "defaults": { "receiving_method": "delivery", "country_id": "VN", "note": "" }
}
```

#### Controllers

| URL | Controller | Function |
| --- | --- | --- |
| `POST /pvmodern/checkout/quote` | [Controller/Checkout/Quote.php](app/code/YourVendor/PVModern/Controller/Checkout/Quote.php) | Returns shipping quotes + payment methods for a normalized address |
| `POST /pvmodern/checkout/placeOrder` | [Controller/Checkout/PlaceOrder.php](app/code/YourVendor/PVModern/Controller/Checkout/PlaceOrder.php) | Places the Magento order and initializes payment |
| `GET /pvmodern/api/locations` | [Controller/Api/Locations.php](app/code/YourVendor/PVModern/Controller/Api/Locations.php) | Vietnam province → district → ward list (cached in `var/pvmodern/vietnam-locations.json`, falls back to `https://provinces.open-api.vn/api/`) |
| `POST /pvmodern/checkout/paymentSession` | [Controller/Checkout/PaymentSession.php](app/code/YourVendor/PVModern/Controller/Checkout/PaymentSession.php) | Thin forward to `Payments\Create` |

`CheckoutService::placeOrder()`:

1. Validates payload (full name, email, phone, address or pickup).
2. Sets `pvmodern_checkout_processing` session flag for idempotency.
3. Imports billing / shipping with `pickupLocationProvider` lookup if `receiving_method=pickup`.
4. Selects shipping method via `ShippingManager::getQuotes()`.
5. Loads the chosen payment provider via `PaymentManager::getProvider($payment_method)`.
6. Calls `placeOrder` on the Magento `CartManagementInterface`.
7. Calls `paymentProvider->initialize(...)` and registers an attempt via `PaymentAttemptService::registerAttemptForOrder()` for non-COD methods.
8. Stores extensive additional info on `$order->getPayment()` (`pvmodern_payment_status`, `pvmodern_payment_context`, `gateway_channel`, `wallet_id`, `pvmodern_shipping_provider`, `pvmodern_shipping_context`).
9. For COD: creates a shipment immediately. For other methods: defers fulfillment to a verified webhook (see Section 6.6).
10. Generates a customer-facing purchase code via [Model/PurchaseCodeGenerator.php](app/code/YourVendor/PVModern/Model/PurchaseCodeGenerator.php) (`sha256(increment_id|entity_id|email|techieworld-purchase)[:10]` formatted `XXXXX-XXXXX`).
11. Returns the full payment payload (status, QR url, redirect, statusEndpoint, eventsEndpoint, expiresAt, success_url).

---

## 6. Payment subsystem

### 6.1 Payment methods registered in Magento

Defined in [etc/config.xml](app/code/YourVendor/PVModern/etc/config.xml) and modelled as offline payment methods:

| Code | Title | Class |
| --- | --- | --- |
| `pvmodern_cod` | Cash on Delivery | [Model/Payment/Method/Cod.php](app/code/YourVendor/PVModern/Model/Payment/Method/Cod.php) |
| `pvmodern_banktransfer` | Bank Transfer / VietQR | [Model/Payment/Method/BankTransfer.php](app/code/YourVendor/PVModern/Model/Payment/Method/BankTransfer.php) |
| `pvmodern_onlinegateway` | Online Payment Gateway | [Model/Payment/Method/OnlineGateway.php](app/code/YourVendor/PVModern/Model/Payment/Method/OnlineGateway.php) |

### 6.2 Payment providers (custom interface)

Interface: [Api/PaymentProviderInterface.php](app/code/YourVendor/PVModern/Api/PaymentProviderInterface.php) (methods `getCode`, `getLabel`, `getMethodCode`, `getInitialStatus`, `isAvailable`, `describeCheckoutMethod`, `initialize`).

Manager: [Model/Payment/PaymentManager.php](app/code/YourVendor/PVModern/Model/Payment/PaymentManager.php) — wired via [etc/di.xml](app/code/YourVendor/PVModern/etc/di.xml) with three providers:

- `cod` → [Model/Payment/Provider/CodPaymentProvider.php](app/code/YourVendor/PVModern/Model/Payment/Provider/CodPaymentProvider.php) — initial status `cod_pending`, always mock.
- `bank_transfer` → [Model/Payment/Provider/BankTransferPaymentProvider.php](app/code/YourVendor/PVModern/Model/Payment/Provider/BankTransferPaymentProvider.php) — generates a transfer code `ORD<digits>` and a VietQR PNG via `VietQrBuilder`.
- `online_gateway` → [Model/Payment/Provider/OnlineGatewayPaymentProvider.php](app/code/YourVendor/PVModern/Model/Payment/Provider/OnlineGatewayPaymentProvider.php) — branches by `gateway_channel` (`momo`/`vnpay`/`stripe`/`card`):
  - **VNPay**: builds signed `vnp_*` query and `https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?...&vnp_SecureHash=<sha512>`.
  - **MoMo**: POSTs signed `accessKey/amount/extraData/ipnUrl/orderId/...` to MoMo create API (`MOMO_CREATE_URL`), returns `payUrl`, `qrCodeUrl`, `deeplink`.
  - **Stripe**: POSTs `mode=payment&line_items[...]` to `https://api.stripe.com/v1/checkout/sessions` with Bearer `STRIPE_SECRET_KEY`, returns the Checkout URL and `payment_intent`.
  - **VietQR fallback**: when no credentials or mock mode, returns a dynamic BIDV VietQR (via `VietQrBuilder`) regardless of the chosen channel; the same transfer code `ORD<digits>` lets Casso/SePay flip the order paid.

Base class: [Model/Payment/Provider/AbstractPaymentProvider.php](app/code/YourVendor/PVModern/Model/Payment/Provider/AbstractPaymentProvider.php).

### 6.3 QR generation

[Model/Payment/VietQrBuilder.php](app/code/YourVendor/PVModern/Model/Payment/VietQrBuilder.php):

- Default (`PVMODERN_QR_PROVIDER=sepay`) — returns `/api/qr/sepay?acc=...&bank=BIDV&amount=...&des=ORD...&template=compact`, served by the same-origin proxy [Controller/Qr/Sepay.php](app/code/YourVendor/PVModern/Controller/Qr/Sepay.php) that wraps `https://qr.sepay.vn/img` (cached 5 min, sanitizes params, returns 1×1 PNG on errors so `<img>` stays valid).
- Demo mode (`PVMODERN_PAYMENT_DEMO=true`) — wraps a scannable URL `/api/qr/scanpaid?order=ORD...` through `/api/qr/url?size=540&data=...` (proxy at [Controller/Qr/Url.php](app/code/YourVendor/PVModern/Controller/Qr/Url.php), upstream `api.qrserver.com`, far-future cache).
- Fallback — uses `https://img.vietqr.io/image/<BIN>-<acc>-compact2.png?amount=&addInfo=&accountName=`.

[Controller/Qr/ScanPaid.php](app/code/YourVendor/PVModern/Controller/Qr/ScanPaid.php) — **DEMO ONLY**. When the phone scans a demo QR, the camera opens `/api/qr/scanpaid?order=ORD<n>`, this controller fires a synthetic Casso-style transaction through `CassoTransactionProcessor`, the order flips to `paid`, and the open SSE on the desktop emits the `paid` frame. Returns a styled mobile landing page.

### 6.4 Attempt service

[Model/Payment/PaymentAttemptService.php](app/code/YourVendor/PVModern/Model/Payment/PaymentAttemptService.php) is the only allowed writer of payment status. Public API:

- `registerAttemptForOrder(Order, provider, paymentInit, frontendMethod)` — Ensures a `pv_payment_order` exists, creates a `pv_payment_attempt`, returns it merged with `statusEndpoint`, `eventsEndpoint`, `expiresAt`.
- `findAttemptForProvider(provider, providerOrderId, sessionId, transactionId, incrementId)` — Cascades lookups by order id → session id → transaction id → latest-by-increment.
- `recordEvent(...)` — Appends to `pv_payment_event` with signature flag and raw payload.
- `applyProviderResult(attempt, eventId, provider, status, ...)` — Validates signature, amount (VND-rounded, ±0.5 tolerance), duplicate state, expiration, then transitions attempt + pv_payment_order + Magento order in a single DB transaction.
- `confirmPaid(attempt, ...)` — Transitions to `paid`, sets `total_paid`, moves Magento order to `Processing`, adds history comment, then calls `triggerFulfillmentOnce()`.
- `markFailed(...)`, `moveToManualReview(...)`, `expireAttempt(...)`, `finishEvent(eventId, result, message)`.
- `triggerFulfillmentOnce(Order)` — Inserts into `pv_fulfillment_job` (unique by increment_id so duplicate webhooks can't double-fulfill); when not pickup, calls `ShippingManager::createShipment(provider, context)` (context restored from `pvmodern_shipping_context` additional info); marks job processed/error and sets `pvmodern_fulfillment_triggered=1`.

### 6.5 Webhooks & IPN

| Endpoint | Controller | Signature scheme |
| --- | --- | --- |
| `POST /api/webhooks/stripe` | [Controller/Webhooks/Stripe.php](app/code/YourVendor/PVModern/Controller/Webhooks/Stripe.php) | `Stripe-Signature: t=...,v1=hmac_sha256(t.payload, STRIPE_WEBHOOK_SECRET)` with 5-min skew window |
| `POST /api/webhooks/momo` | [Controller/Webhooks/Momo.php](app/code/YourVendor/PVModern/Controller/Webhooks/Momo.php) | `hmac_sha256(accessKey=...&amount=...&...&transId=..., MOMO_SECRET_KEY)` |
| `GET /api/webhooks/vnpay/ipn` | [Controller/Webhooks/Vnpay/Ipn.php](app/code/YourVendor/PVModern/Controller/Webhooks/Vnpay/Ipn.php) | `hmac_sha512(ksort(query), VNPAY_HASH_SECRET)` — responds with `RspCode=00/04/97` |
| `POST /api/webhooks/casso` | [Controller/Webhooks/Casso.php](app/code/YourVendor/PVModern/Controller/Webhooks/Casso.php) | Casso Flow Webhook V2: `X-Casso-Signature: t=...,v1=hmac_sha512(t.canonicalSortedJson, CASSO_WEBHOOK_SECRET)` plus legacy `Secure-Token`/`Authorization` fallback |
| `POST /api/webhooks/sepay` | [Controller/Webhooks/Sepay.php](app/code/YourVendor/PVModern/Controller/Webhooks/Sepay.php) | `Authorization: Apikey <SEPAY_API_KEY or SEPAY_SANDBOX_API_KEY>` (also accepts Bearer, `X-Webhook-Secret`, `X-Sepay-Token`) |
| `POST /api/payments/webhook` | [Controller/Payments/Webhook.php](app/code/YourVendor/PVModern/Controller/Payments/Webhook.php) | Generic placeholder — `hmac_sha256(body, PAYMENT_WEBHOOK_SECRET)` in `X-PVModern-Signature`. Returns `501` if `PAYMENT_WEBHOOK_SECRET` is unset. |
| `POST /pvmodern/payments/casso` | [Controller/Payments/Casso.php](app/code/YourVendor/PVModern/Controller/Payments/Casso.php) | Legacy Casso path — same processor pipeline as the modern endpoint |
| `POST /pvmodern/checkout/momoIpn` | [Controller/Checkout/MomoIpn.php](app/code/YourVendor/PVModern/Controller/Checkout/MomoIpn.php) | Legacy MoMo IPN (same HMAC scheme) |

Browser-return handlers (NOT trusted for marking paid — they only redirect with `payment_result=pending` and log):

- `GET /pvmodern/checkout/momoReturn` → [Controller/Checkout/MomoReturn.php](app/code/YourVendor/PVModern/Controller/Checkout/MomoReturn.php)
- `GET /pvmodern/checkout/vnpayReturn` → [Controller/Checkout/VnpayReturn.php](app/code/YourVendor/PVModern/Controller/Checkout/VnpayReturn.php)

### 6.6 Casso / SePay processor

[Model/Payment/CassoTransactionProcessor.php](app/code/YourVendor/PVModern/Model/Payment/CassoTransactionProcessor.php) is the unified pipeline for **all** bank-transfer events (webhook or active poll, demo or live). It:

1. Records the event with `hasAcceptedEvent()` deduplication.
2. Extracts the transfer code via regex `\bORD[0-9]{4,}\b` from `description|memo|content`.
3. Looks up the attempt across providers (`bank_transfer` → `momo` → `vnpay` → `stripe`) — because in the unified-QR flow the bank-transfer arrives no matter which channel the customer "picked".
4. Amount tolerance: VND-rounded, ±0.5đ.
5. Creates a `pv_bank_transfer_review` row when unmatched (status `unmatched`) or when matched-but-mismatched amount (status `suggested`).
6. Calls `PaymentAttemptService::applyProviderResult()` on match.

### 6.7 Real-time status push (SSE)

[Controller/PaymentSessions/Events.php](app/code/YourVendor/PVModern/Controller/PaymentSessions/Events.php) is a Server-Sent Events stream:

- URL: `GET /api/paymentSessions/events?paymentAttemptId=<id>` or `?orderId=<inc>`.
- Headers: `text/event-stream`, `X-Accel-Buffering: no`, `Connection: keep-alive`.
- Loop (≤ 25 min): every 150 ms re-reads `pv_payment_attempt`; emits `event: status` when `status` or `provider_transaction_id` changes; emits `: keepalive` every 10 s. Exits on `paid`/`failed`/`cancelled`/`expired`.
- In non-demo mode, every 2 s it also calls [Model/Payment/SepayPoller.php](app/code/YourVendor/PVModern/Model/Payment/SepayPoller.php) → `GET https://my.sepay.vn/userapi/transactions/list` and feeds matches through `CassoTransactionProcessor` (resilience net for SePay free-tier webhook delays).

Status endpoints:

- `GET /api/paymentSessions/status?paymentAttemptId=<id>` → [Controller/PaymentSessions/Status.php](app/code/YourVendor/PVModern/Controller/PaymentSessions/Status.php) — single-shot snapshot.
- `POST /api/paymentSessions/retryStatusCheck?paymentAttemptId=<id>` → [Controller/PaymentSessions/RetryStatusCheck.php](app/code/YourVendor/PVModern/Controller/PaymentSessions/RetryStatusCheck.php) — forces `PaymentStatusPoller` to call the provider's query API (MoMo `query_url` or Stripe `/checkout/sessions/{id}`).
- `GET /pvmodern/payments/pvStatus?orderId=...` → [Controller/Payments/PvStatus.php](app/code/YourVendor/PVModern/Controller/Payments/PvStatus.php) — auto-creates a `pv_payment_order` if missing, returns full snapshot including `paymentContext` from Magento order additional info; **3s in-process APCu rate limit per order**.

### 6.8 Polling and cron

[Model/Payment/PaymentStatusPoller.php](app/code/YourVendor/PVModern/Model/Payment/PaymentStatusPoller.php) calls MoMo's query and Stripe Checkout-session APIs for `provider=momo|stripe`.

Cron jobs ([etc/crontab.xml](app/code/YourVendor/PVModern/etc/crontab.xml)):

| Schedule | Job | File |
| --- | --- | --- |
| `*/5 * * * *` | `pvmodern_payment_expiration` | [Model/Cron/PaymentExpiration.php](app/code/YourVendor/PVModern/Model/Cron/PaymentExpiration.php) → calls `PaymentAttemptService::expireAttempt()` for every row in `listExpiredPendingAttempts()` |
| `*/10 * * * *` | `pvmodern_payment_fallback_polling` | [Model/Cron/PaymentFallbackPolling.php](app/code/YourVendor/PVModern/Model/Cron/PaymentFallbackPolling.php) → calls `PaymentStatusPoller::pollAttempt()` for pending attempts older than 120s |
| `*/10 * * * *` | `pvmodern_bank_transfer_reconciliation` | [Model/Cron/BankTransferReconciliation.php](app/code/YourVendor/PVModern/Model/Cron/BankTransferReconciliation.php) → GETs Casso `transactions?page=1&pageSize=50` with `Authorization: Apikey <CASSO_API_KEY>` and feeds rows through `CassoTransactionProcessor` |

### 6.9 Customer-facing payment endpoints

| URL | Controller | Purpose |
| --- | --- | --- |
| `POST /api/payments/create` | [Controller/Payments/Create.php](app/code/YourVendor/PVModern/Controller/Payments/Create.php) | Creates a payment attempt for an existing or pending Magento order; accepts `selectedMethod` = `bank`/`card`/`wallet`/`momo`/`vnpay`/`stripe` |
| `GET /api/payments/status?orderId=&paymentId=` | [Controller/Payments/Status.php](app/code/YourVendor/PVModern/Controller/Payments/Status.php) | Reads `pvmodern_payment_status` from order additional info and returns normalized status |
| `GET /pvmodern/payments/pvStatus?orderId=` | [Controller/Payments/PvStatus.php](app/code/YourVendor/PVModern/Controller/Payments/PvStatus.php) | Returns full pv_payment_order + latest attempt + Magento payment context |
| `POST /pvmodern/payments/upload` | [Controller/Payments/Upload.php](app/code/YourVendor/PVModern/Controller/Payments/Upload.php) | **Disabled** — returns HTTP 410: "Screenshot upload has been removed. Payments are now confirmed automatically via Casso webhook." Legacy code below the early return remains as historical reference. |
| `POST /pvmodern/payments/casso` | [Controller/Payments/Casso.php](app/code/YourVendor/PVModern/Controller/Payments/Casso.php) | Legacy Casso path (use `/api/webhooks/casso` for V2 signatures) |

---

## 7. Shipping subsystem

### 7.1 Magento carrier

[Model/Carrier/PvmodernShipping.php](app/code/YourVendor/PVModern/Model/Carrier/PvmodernShipping.php) — code `pvmodernshipping`, registered in [etc/config.xml](app/code/YourVendor/PVModern/etc/config.xml). `collectRates(RateRequest)` calls `ShippingManager::getQuotes(...)` and appends each provider as a separate Magento rate method plus a `pickup` pseudo-method (free, 2h).

Allowed methods: `ghn`, `ghtk`, `spx`, `pickup`.

### 7.2 Shipping providers

Interface: [Api/ShippingProviderInterface.php](app/code/YourVendor/PVModern/Api/ShippingProviderInterface.php) (`getCode`, `getLabel`, `isAvailable`, `quote`, `createShipment`, `track`, `cancel`).

Manager: [Model/Shipping/ShippingManager.php](app/code/YourVendor/PVModern/Model/Shipping/ShippingManager.php) wired via [etc/di.xml](app/code/YourVendor/PVModern/etc/di.xml) with three providers (all extend [Model/Shipping/Provider/AbstractShippingProvider.php](app/code/YourVendor/PVModern/Model/Shipping/Provider/AbstractShippingProvider.php)):

| Provider | Base | ETA | Note |
| --- | --- | --- | --- |
| `ghn` ([GhnProvider.php](app/code/YourVendor/PVModern/Model/Shipping/Provider/GhnProvider.php)) | $4.20 | 1–2 days | "Giao Hang Nhanh" |
| `ghtk` ([GhtkProvider.php](app/code/YourVendor/PVModern/Model/Shipping/Provider/GhtkProvider.php)) | $3.60 | 2–3 days | "Giao Hang Tiet Kiem" |
| `spx` ([SpxProvider.php](app/code/YourVendor/PVModern/Model/Shipping/Provider/SpxProvider.php)) | $3.85 | 2–4 days | "Shopee Express" |

Quote formula (from `AbstractShippingProvider::quote()`):

```
amount = (base + items * 0.85 + weight * 0.55) * distanceMultiplier
distanceMultiplier = 1.0 for Hanoi/HCM, else 1.18
```

Each provider becomes `mock=true` when its `<CODE>_API_TOKEN` env is not set or `PVMODERN_SHIPPING_MOCK=true`. `createShipment`, `track`, `cancel` return deterministic mock payloads (real adapter calls go here when credentials are present).

### 7.3 Pickup locations

[Model/Shipping/PickupLocationProvider.php](app/code/YourVendor/PVModern/Model/Shipping/PickupLocationProvider.php) — three default stores (`hcm-flagship`, `hn-showroom`, `dn-service-hub`) overridable by `PVMODERN_PICKUP_STORES_JSON`. Returned to the storefront via `CheckoutService::buildCheckoutBootstrap()`.

### 7.4 Customer-facing shipping endpoints

| URL | Controller | Purpose |
| --- | --- | --- |
| `GET /pvmodern/orders/tracking?order_id=&provider=&tracking_number=` | [Controller/Orders/Tracking.php](app/code/YourVendor/PVModern/Controller/Orders/Tracking.php) | Returns normalized status, timeline, ETA, carrier label |
| `GET /pvmodern/shipping/track` | [Controller/Shipping/Track.php](app/code/YourVendor/PVModern/Controller/Shipping/Track.php) | Thin pass-through |
| `POST /pvmodern/shipping/cancel` | [Controller/Shipping/Cancel.php](app/code/YourVendor/PVModern/Controller/Shipping/Cancel.php) | Cancels a shipment |

---

## 8. Magento backend "Xác nhận thanh toán"

### 8.1 Menu & route

- Menu: [etc/adminhtml/menu.xml](app/code/YourVendor/PVModern/etc/adminhtml/menu.xml) adds *Sales → Xác nhận thanh toán* with action `pvmodern_payments/payments/index` and ACL resource `YourVendor_PVModern::payments`.
- Route: [etc/adminhtml/routes.xml](app/code/YourVendor/PVModern/etc/adminhtml/routes.xml) registers `pvmodern_payments` frontName for the `YourVendor\PVModern\Controller\Adminhtml\Payments\*` controllers.

### 8.2 Controllers

| URL | Controller | Function |
| --- | --- | --- |
| `pvmodern_payments/payments/index` | [Controller/Adminhtml/Payments/Index.php](app/code/YourVendor/PVModern/Controller/Adminhtml/Payments/Index.php) | Renders the dashboard block ([view/adminhtml/layout/pvmodern_payments_payments_index.xml](app/code/YourVendor/PVModern/view/adminhtml/layout/pvmodern_payments_payments_index.xml) + [view/adminhtml/templates/payments/dashboard.phtml](app/code/YourVendor/PVModern/view/adminhtml/templates/payments/dashboard.phtml)) |
| `pvmodern_payments/payments/orders` | [Controller/Adminhtml/Payments/Orders.php](app/code/YourVendor/PVModern/Controller/Adminhtml/Payments/Orders.php) | JSON list with `?tab=pending_review|all|paid|casso_log&method=&date=&search=` |
| `pvmodern_payments/payments/approve` | [Controller/Adminhtml/Payments/Approve.php](app/code/YourVendor/PVModern/Controller/Adminhtml/Payments/Approve.php) | Approves a `pv_payment_order` (calls `PaymentAttemptService::confirmPaid` if an attempt exists, else direct DB update + verification log) |
| `pvmodern_payments/payments/reject` | [Controller/Adminhtml/Payments/Reject.php](app/code/YourVendor/PVModern/Controller/Adminhtml/Payments/Reject.php) | Marks payment status `failed`, writes verification log |
| `pvmodern_payments/payments/screenshot?id=` | [Controller/Adminhtml/Payments/Screenshot.php](app/code/YourVendor/PVModern/Controller/Adminhtml/Payments/Screenshot.php) | Streams the saved proof image (if `screenshot_url` exists in pv_payment_order) |
| `pvmodern_payments/payments/simulateWebhook?pv_order_id=` | [Controller/Adminhtml/Payments/SimulateWebhook.php](app/code/YourVendor/PVModern/Controller/Adminhtml/Payments/SimulateWebhook.php) | Fires a synthetic Casso event for a pending order — **only allowed when `PVMODERN_PAYMENT_MOCK`/`PVMODERN_CHECKOUT_MOCK=true`** (returns 403 in production) |

### 8.3 Block & template

- Block: [Block/Adminhtml/Payments/Dashboard.php](app/code/YourVendor/PVModern/Block/Adminhtml/Payments/Dashboard.php) exposes counts (`countByStatus`, `countAll`), URLs (approve, reject, orders JSON, screenshot, simulateWebhook), mock-mode flag and form key.
- Template: [view/adminhtml/templates/payments/dashboard.phtml](app/code/YourVendor/PVModern/view/adminhtml/templates/payments/dashboard.phtml) — single-file admin UI with stats, tabs (Cần xét duyệt / Tất cả / Đã thanh toán / Lịch sử Casso), filters (status, method, date, search), per-row approve/reject buttons, screenshot modal and a "Simulate webhook" button in mock mode.

### 8.4 ACL & roles

[etc/acl.xml](app/code/YourVendor/PVModern/etc/acl.xml) creates `YourVendor_PVModern::pvmodern` parent resource and `YourVendor_PVModern::payments` child. Assign these to admin roles to grant access.

---

## 9. Self-hosted micro-admin (`/pvadmin`)

Independent password-gated admin running **outside the Magento backend** (no MFA / no admin user required). Used as a quick portal for ops who only need to approve/reject bank-transfer payments.

| URL | Controller | Notes |
| --- | --- | --- |
| `GET/POST /pvadmin/admin/login` | [Controller/Admin/Login.php](app/code/YourVendor/PVModern/Controller/Admin/Login.php) | Single-password gate (`pvmodern/admin_password` in env.php, default `pvadmin123`). Sets HMAC cookie `pv_admin` (24h, httpOnly, SameSite=Strict). |
| `GET /pvadmin/admin/dashboard` | [Controller/Admin/Dashboard.php](app/code/YourVendor/PVModern/Controller/Admin/Dashboard.php) | Renders a 600-line self-contained dark-mode HTML page with stats, tabs, filters, modal, toast notifications and Casso log. |
| `GET /pvadmin/admin/orders` | [Controller/Admin/Orders.php](app/code/YourVendor/PVModern/Controller/Admin/Orders.php) | JSON orders + stats (incl. `casso_today`) |
| `POST /pvadmin/admin/approve` | [Controller/Admin/Approve.php](app/code/YourVendor/PVModern/Controller/Admin/Approve.php) | Same verified-confirm pipeline as the Magento backend |
| `POST /pvadmin/admin/reject` | [Controller/Admin/Reject.php](app/code/YourVendor/PVModern/Controller/Admin/Reject.php) | Marks failed |
| `GET /pvadmin/admin/screenshot?id=` | [Controller/Admin/Screenshot.php](app/code/YourVendor/PVModern/Controller/Admin/Screenshot.php) | Streams screenshot from `pub/media/pvmodern/proofs/...` |
| `GET /pvadmin/admin/paymentReviews?status=` | [Controller/Admin/PaymentReviews.php](app/code/YourVendor/PVModern/Controller/Admin/PaymentReviews.php) | Manual-review queue (`pv_bank_transfer_review`) |
| `POST /pvadmin/admin/resolvePaymentReview` | [Controller/Admin/ResolvePaymentReview.php](app/code/YourVendor/PVModern/Controller/Admin/ResolvePaymentReview.php) | `action=ignore` or attach a `pv_order_id` to confirm-paid via attempt service |
| `GET /pvadmin/admin/logout` | [Controller/Admin/Logout.php](app/code/YourVendor/PVModern/Controller/Admin/Logout.php) | Clears the cookie |

> **Tip**: change the admin password in `app/etc/env.php` under `pvmodern/admin_password` before going live.

---

## 10. Storefront dashboards

All three dashboards share the same template family ([view/frontend/templates/pages/realtime-dashboard.phtml](app/code/YourVendor/PVModern/view/frontend/templates/pages/realtime-dashboard.phtml)) referenced from the page-specific files [news.phtml](app/code/YourVendor/PVModern/view/frontend/templates/pages/news.phtml), [weather.phtml](app/code/YourVendor/PVModern/view/frontend/templates/pages/weather.phtml), [currency-rate.phtml](app/code/YourVendor/PVModern/view/frontend/templates/pages/currency-rate.phtml). They consume server-side JSON APIs so provider keys never leave the server.

### 10.1 News (`/news`)

- Page: [Controller/News/Index.php](app/code/YourVendor/PVModern/Controller/News/Index.php) (extends the API class so the same path serves both HTML and JSON depending on context).
- API: `GET /pvmodern/api/news?category=technology&page=1&q=&region=global&sort=latest` → [Controller/Api/News.php](app/code/YourVendor/PVModern/Controller/Api/News.php).
- Provider: VnExpress Kinh doanh RSS (`https://vnexpress.net/rss/kinh-doanh.rss`, override with `VNEXPRESS_BUSINESS_RSS_URL`). Falls back to normalized seed articles if the feed cannot be fetched.
- Output: `breaking`, `lead`, `top`, `items` (12 per page), `popular`, `topics`, `filters.regions`, `filters.sorts`. Categories: all/general/business/technology/science/health/sports/entertainment/politics/world/finance/ai/local/startup/mobile/gadgets/cybersecurity/software/gaming/fintech.

### 10.2 Weather (`/weather`)

- Page: [Controller/Weather/Index.php](app/code/YourVendor/PVModern/Controller/Weather/Index.php).
- API: `GET /pvmodern/api/weather?city=&lat=&lon=&unit=metric|imperial` → [Controller/Api/Weather.php](app/code/YourVendor/PVModern/Controller/Api/Weather.php).
- Provider: OpenWeatherMap (`OPENWEATHER_API_KEY`, legacy fallback `WEATHER_API_KEY`). If the key is missing or OpenWeatherMap fails, the page returns a marked reference fallback instead of calling another weather provider.
- Output: `current` (temp, condition, feels-like, humidity, wind, pressure, visibility, UV, high/low, sunrise/sunset, icon), OpenWeatherMap `aqi`, `map`, `alert`, `hourly`, `daily`, `news`.

### 10.3 Currency (`/currency` and legacy `/currency-rate`)

- Pages: [Controller/Currency/Index.php](app/code/YourVendor/PVModern/Controller/Currency/Index.php), [Controller/CurrencyRate/Index.php](app/code/YourVendor/PVModern/Controller/CurrencyRate/Index.php).
- API: `GET /pvmodern/api/currency?mode=latest|convert|history&from=&to=&amount=&range=` → [Controller/Api/Currency.php](app/code/YourVendor/PVModern/Controller/Api/Currency.php).
- Aliases: `/pvmodern/currency/latest`, `/pvmodern/currency/convert`, `/pvmodern/currency/history` (forward to the same controller with `mode` preset).
- Provider: Vietcombank XML (`https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx?b=68`, override with `VIETCOMBANK_RATES_URL`). The controller caches the XML for 5 minutes because the provider requests only one call every 5 minutes.

### 10.4 Banners

- API: `GET /pvmodern/api/banners?placement=homepage-hero` → [Controller/Api/Banners.php](app/code/YourVendor/PVModern/Controller/Api/Banners.php).
- Provider: [Model/Banner/HeroBannerProvider.php](app/code/YourVendor/PVModern/Model/Banner/HeroBannerProvider.php) tries sources in order: **CMS block** `hero_banner_slider` (JSON array/object inside the CMS WYSIWYG content) → env JSON (`PVMODERN_HERO_BANNERS_JSON`/`PVMODERN_HERO_BANNERS`) → product catalog (featured SKU rank, themed, deduped on usable images) → static fallback (`pvmodern/gaming-setup-hero.jpg`, `pvmodern/hero-circuit-board.jpg`).
- Max 6 slides, each with `title`, `subtitle`, `badge`, `image`, `mobileImage`, `ctaLabel`, `ctaLink`, `targetType`, `isActive`, `order`, `startDate`, `endDate`, `alt`, `price`, `theme`, `source`.

### 10.5 Order tracking (`/order-tracking`)

- Page: [Controller/OrderTracking/Index.php](app/code/YourVendor/PVModern/Controller/OrderTracking/Index.php) (layout [order_tracking_index_index.xml](app/code/YourVendor/PVModern/view/frontend/layout/order_tracking_index_index.xml) + template [order-tracking.phtml](app/code/YourVendor/PVModern/view/frontend/templates/pages/order-tracking.phtml)).
- API: `GET /pvmodern/orders/tracking?order_id=&provider=&tracking_number=` (see Section 7.4).

### 10.6 Static pages

CMS-template pages handled by the generic [Controller/Index/Index.php](app/code/YourVendor/PVModern/Controller/Index/Index.php) (layout chooses the template):

- `/deals` → `Magento_Cms::deals.phtml`
- `/pcbuilder`, `/pc-builder` → `Magento_Cms::pc-builder.phtml`
- `/terms`, `/privacy`, `/cookies` → `Magento_Cms::{terms,privacy,cookies}.phtml`

---

## 11. Warranty & purchase code

### 11.1 Warranty lookup (`/warranty`)

- Block: [Block/Warranty/Lookup.php](app/code/YourVendor/PVModern/Block/Warranty/Lookup.php).
- Template: [view/frontend/templates/warranty/lookup.phtml](app/code/YourVendor/PVModern/view/frontend/templates/warranty/lookup.phtml).
- Layout: [view/frontend/layout/warranty_index_index.xml](app/code/YourVendor/PVModern/view/frontend/layout/warranty_index_index.xml).

Three lookup modes (auto-detected by querystring or explicit `?lookup=`):

| Mode | Inputs | File method |
| --- | --- | --- |
| `card` | `?phone=&purchase_code=` | `WarrantyCardProvider::findByPhoneAndCode` |
| `imei` | `?imei=` | `WarrantyCardProvider::findByImei` |
| `order_code` | `?order_code=ORD000000037` | `WarrantyCardProvider::findByOrderCode` (resolves the Magento order, returns the matching card with an order-level purchase code) |

[Model/WarrantyCardProvider.php](app/code/YourVendor/PVModern/Model/WarrantyCardProvider.php) — pulls the latest 24 visible products and computes deterministic phone, purchase code, IMEI (with Luhn check digit), purchase date, warranty months (18/24/30) and expiry per SKU, so cards are stable across page loads.

### 11.2 Purchase code

[Model/PurchaseCodeGenerator.php](app/code/YourVendor/PVModern/Model/PurchaseCodeGenerator.php) — `sha256(increment|entity|email|techieworld-purchase)[:10]` formatted `XXXXX-XXXXX`. Used by `CheckoutService::placeOrder()` and printed on the warranty card.

---

## 12. Catalog helpers

### 12.1 Mega-menu config

[Model/CategoryNavigation.php](app/code/YourVendor/PVModern/Model/CategoryNavigation.php) — returns a four-pillar menu (Desktop, Laptop, Monitor, Apple) with curated child links and url overrides (e.g. PC Builder, Warranty Lookup, Deals).

### 12.2 Product visual resolver

[Model/ProductVisualResolver.php](app/code/YourVendor/PVModern/Model/ProductVisualResolver.php) — Three-tier image resolution:

1. Local media directories `pub/media/pvmodern/products/` or `pub/media/import/pvmodern-image-sync/` (slug match on SKU).
2. Magento `image_helper->init($product, $imageId)` (skipped if it returns a placeholder).
3. Exact SKU map (Apple, AMD, ASUS, Samsung URLs) → otherwise returns the placeholder asset `YourVendor_PVModern::images/placeholder.jpg`.

Used by `HeroBannerProvider`, `WarrantyCardProvider`, `Search\Suggest`.

### 12.3 Storefront search suggester

[Controller/Search/Suggest.php](app/code/YourVendor/PVModern/Controller/Search/Suggest.php) — `GET /pvmodern/search/suggest?q=<query>`. Up to 10 in-stock products, scored by prefix/match position/SKU prefix; returns `name`, `sku`, `url`, `image`, `price`, `original_price`, `discount`.

---

## 13. Authentication features

### 13.1 GitHub OAuth login

- Start: `GET /pvmodern/auth/github` → [Controller/Auth/Github.php](app/code/YourVendor/PVModern/Controller/Auth/Github.php).
- Callback: `GET /pvmodern/auth/githubCallback` → [Controller/Auth/GithubCallback.php](app/code/YourVendor/PVModern/Controller/Auth/GithubCallback.php).

Flow: state random nonce stored in session → redirect to `github.com/login/oauth/authorize` → token exchange at `github.com/login/oauth/access_token` → fetch primary verified email from `api.github.com/user/emails` → upsert Magento customer (`accountManagement->createAccountWithPasswordHash` with a random 32-char password) → `customerSession->setCustomerDataAsLoggedIn`.

Credentials: admin config `pvmodern/github_oauth/client_id` & `client_secret` (or `GITHUB_OAUTH_CLIENT_ID` / `GITHUB_OAUTH_CLIENT_SECRET` env).

### 13.2 Username login

- Plugin: [Model/Customer/UsernameAuthPlugin.php](app/code/YourVendor/PVModern/Model/Customer/UsernameAuthPlugin.php) registered in [etc/frontend/di.xml](app/code/YourVendor/PVModern/etc/frontend/di.xml) on `Magento\Customer\Api\AccountManagementInterface::beforeAuthenticate`.
- Behaviour: if the login identifier is not a valid email, the plugin searches `pv_username` for the current website and rewrites the email argument to the matching customer's email before delegating to Magento.

### 13.3 CAPTCHA on customer login

Disabled by default in [etc/config.xml](app/code/YourVendor/PVModern/etc/config.xml) (`customer/captcha/enable=0`) because the custom login template does not render CAPTCHA inputs. The default also keeps CAPTCHA on `user_forgotpassword` only.

---

## 14. JSON endpoint quick reference

All endpoints below are exposed under both `/pvmodern/*` and the `/api/*` alias.

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/pvmodern/api/news` (`/api/news`) | GET | News dashboard data |
| `/pvmodern/api/weather` (`/api/weather`) | GET | Weather dashboard data |
| `/pvmodern/api/currency` (`/api/currency`) | GET | Currency dashboard data; `mode=latest\|convert\|history` |
| `/pvmodern/api/banners` (`/api/banners`) | GET | Hero banner slides |
| `/pvmodern/api/locations` (`/api/locations`) | GET | VN province/district/ward tree |
| `/pvmodern/checkout/quote` | POST | Shipping quote |
| `/pvmodern/checkout/placeOrder` | POST | Place order + initialize payment |
| `/pvmodern/checkout/paymentSession` | POST | Forwards to `payments/create` |
| `/pvmodern/payments/create` (`/api/payments/create`) | POST | Create payment attempt |
| `/pvmodern/payments/status` (`/api/payments/status`) | GET | Order payment status (Magento additional info) |
| `/pvmodern/payments/pvStatus` | GET | PV payment-order snapshot |
| `/pvmodern/paymentSessions/status` (`/api/paymentSessions/status`) | GET | Attempt snapshot |
| `/pvmodern/paymentSessions/events` (`/api/paymentSessions/events`) | GET | SSE stream |
| `/pvmodern/paymentSessions/retryStatusCheck` | POST | Force provider query |
| `/pvmodern/webhooks/stripe` (`/api/webhooks/stripe`) | POST | Stripe webhook |
| `/pvmodern/webhooks/momo` (`/api/webhooks/momo`) | POST | MoMo webhook |
| `/pvmodern/webhooks/vnpay/ipn` (`/api/webhooks/vnpay/ipn`) | GET | VNPay IPN |
| `/pvmodern/webhooks/casso` (`/api/webhooks/casso`) | POST | Casso V2 webhook |
| `/pvmodern/webhooks/sepay` (`/api/webhooks/sepay`) | POST | SePay webhook |
| `/pvmodern/payments/casso` | POST | Legacy Casso (still active) |
| `/pvmodern/payments/webhook` (`/api/payments/webhook`) | POST | Generic HMAC-SHA256 webhook (501 unless `PAYMENT_WEBHOOK_SECRET`) |
| `/pvmodern/payments/upload` | POST | **HTTP 410 Gone** (screenshot upload disabled) |
| `/pvmodern/orders/tracking` | GET | Order tracking proxy |
| `/pvmodern/shipping/track` | GET | Shipment track passthrough |
| `/pvmodern/shipping/cancel` | POST | Shipment cancel |
| `/pvmodern/search/suggest` | GET | Search suggester |
| `/pvmodern/qr/url` (`/api/qr/url`) | GET | Same-origin QR PNG proxy (any data) |
| `/pvmodern/qr/sepay` (`/api/qr/sepay`) | GET | SePay QR proxy |
| `/pvmodern/qr/scanPaid` (`/api/qr/scanpaid`) | GET | Demo-mode scan-to-pay (HTTP 403 unless `PVMODERN_PAYMENT_DEMO=true`) |
| `/pvadmin/admin/*` | GET/POST | Lightweight admin micro-app |
| `/pvmodern/auth/github`, `/pvmodern/auth/githubCallback` | GET | OAuth flow |

CSRF is bypassed (`validateForCsrf=true`, `createCsrfValidationException=null`) on every JSON/webhook endpoint that accepts external POSTs — they use form-key / signature checks instead.

---

## 15. Frontend assets

Layouts under [view/frontend/layout/](app/code/YourVendor/PVModern/view/frontend/layout/) remove breadcrumbs / sidebars / page-title on all PVModern pages for a full-bleed canvas. Shared CSS / JS:

- [view/frontend/web/css/pv-info-pages.css](app/code/YourVendor/PVModern/view/frontend/web/css/pv-info-pages.css)
- [view/frontend/web/js/pv-info-pages.js](app/code/YourVendor/PVModern/view/frontend/web/js/pv-info-pages.js)

The checkout block ([view/frontend/layout/payment_confirmation_index_index.xml](app/code/YourVendor/PVModern/view/frontend/layout/payment_confirmation_index_index.xml)) pulls in `css/pv-cart-checkout-final.css` and reuses Magento's `Magento_Checkout::onepage/pv-checkout.phtml` template.

---

## 16. End-to-end workflows

### 16.1 Customer pays via VietQR (no provider creds required)

1. Customer opens `/checkout`. Block boot data lists payment methods (`cod`, `bank_transfer`, `online_gateway`).
2. They submit `POST /pvmodern/checkout/placeOrder` with `payment_method=bank_transfer` (or `online_gateway` + `wallet_id=bank_qr|momo|vnpay|card`).
3. `CheckoutService` places the Magento order, calls `BankTransferPaymentProvider::initialize()` (or `OnlineGatewayPaymentProvider::initializeVietQrFallback()`), receives `qr_code_url=/api/qr/sepay?...&des=ORD000000076`, `reference=ORD000000076`, `expires_at=now+30min`.
4. `PaymentAttemptService::registerAttemptForOrder()` records `pv_payment_order` + `pv_payment_attempt`.
5. Storefront connects to `GET /api/paymentSessions/events?paymentAttemptId=...`. The SSE controller polls SePay every 2 s.
6. Customer scans the QR with their banking app. Money lands on the BIDV account, SePay scrapes the transaction within ~3 s.
7. EITHER (a) SePay POSTs `/api/webhooks/sepay` → `CassoTransactionProcessor::process()` flips the attempt → next SSE tick emits `paid`; OR (b) the SSE's `SepayPoller::pollForTransferCode()` finds the transaction first and does the same.
8. `PaymentAttemptService::confirmPaid()` transitions Magento order to *Processing*, calls `triggerFulfillmentOnce()` → `ShippingManager::createShipment(provider, context)`.
9. Browser receives `paid`, advances to step 5 and shows the purchase code.

### 16.2 Customer pays MoMo (live creds)

1. Same as above with `wallet_id=momo`.
2. `OnlineGatewayPaymentProvider::initializeMomo()` POSTs to MoMo create endpoint, returns `payUrl` + `deeplink`.
3. Storefront opens `payUrl`. Customer pays in MoMo, MoMo sends IPN to `/api/webhooks/momo`.
4. Signature verified (HMAC-SHA256), `applyProviderResult()` flips status; SSE emits `paid`.
5. Browser return URL `/pvmodern/checkout/momoReturn` only redirects to `/checkout?payment_result=pending&gateway=momo` — it does NOT mark paid.
6. If MoMo's IPN doesn't arrive within 120s, the cron `pvmodern_payment_fallback_polling` calls MoMo's query API to reconcile.

### 16.3 Customer pays VNPay (live creds)

1. `gateway_channel=vnpay` → `OnlineGatewayPaymentProvider::initializeVnpay()` builds signed `vnp_*` URL.
2. After VNPay redirects back, `MerchantReturn` only redirects; `Webhooks/Vnpay/Ipn` confirms.

### 16.4 Customer pays Stripe (card)

1. `gateway_channel=card` or `stripe` → `OnlineGatewayPaymentProvider::initializeStripe()` creates a Checkout Session via Stripe API.
2. Stripe redirects to `STRIPE_SUCCESS_URL` (`/checkout?payment_result=pending`).
3. Stripe webhook `checkout.session.completed` / `payment_intent.succeeded` confirms; signature `Stripe-Signature` validated with 5-min skew window.

### 16.5 Admin manually approves a pending bank transfer

1. Bank transfer arrives without the `ORD<n>` memo (unmatched). Casso/SePay → `CassoTransactionProcessor::process()` writes a `pv_bank_transfer_review` row with status `unmatched`.
2. Admin opens **Magento backend → Sales → Xác nhận thanh toán** OR **`/pvadmin/admin/dashboard`** (depending on permissions).
3. Admin selects the order, clicks ✓ Xác nhận. The controller calls `PaymentAttemptService::confirmPaid()` if an attempt exists (which then runs the full fulfillment pipeline) or directly updates `pv_payment_order` + logs verification.
4. Toast confirms, table reloads, badge counters update.

### 16.6 Admin resolves a manual-review queue item

1. `/pvadmin/admin/paymentReviews?status=unmatched` (or modal in the dashboard).
2. Admin attaches the matching `pv_order_id` via `POST /pvadmin/admin/resolvePaymentReview` (with `action=resolve` and `pv_order_id`). The endpoint enforces amount equality, then runs `confirmPaid()`.
3. Or `action=ignore` → row status becomes `ignored`, no order flipped.

### 16.7 Cron lifecycle

Every 5 min: `pvmodern_payment_expiration` cancels stale attempts past `expires_at`, also cancels the Magento order if `canCancel()`.
Every 10 min: `pvmodern_payment_fallback_polling` queries MoMo/Stripe for any pending attempt > 120s old.
Every 10 min: `pvmodern_bank_transfer_reconciliation` pulls the latest 50 Casso transactions and reprocesses through the same pipeline (idempotent thanks to `hasAcceptedEvent`).

### 16.8 Demo flow (`PVMODERN_PAYMENT_DEMO=true`)

1. Bank QR encodes `/api/qr/scanpaid?order=ORD<n>` instead of a Napas-247 string.
2. Phone scans → opens URL → `Controller/Qr/ScanPaid` injects a synthetic Casso transaction → order flips paid.
3. SSE on the customer's desktop emits the `paid` frame (no SePay round-trip needed). The mobile lands on a styled "Đây là chế độ demo" confirmation page.
4. **Refuses to run when demo mode is disabled** — safe to leave the route in production.

---

## 17. Security model

Documented at the top of [README.md](app/code/YourVendor/PVModern/README.md) and enforced in code:

- API keys / payment secrets are always read server-side (`IntegrationConfig`); never leak into the bootstrap JSON or browser code.
- Payment status is only flipped after signature verification (`PaymentAttemptService::applyProviderResult` returns `rejected` when `signatureVerified=false`).
- Browser return endpoints (`momoReturn`, `vnpayReturn`, Stripe `success_url`) are navigation-only — they redirect with `payment_result=pending` but never write `paid`.
- Webhook idempotency via `pv_payment_event` (`hasAcceptedEvent` by `provider`+`provider_event_id`+`provider_transaction_id`).
- Fulfillment idempotency via `pv_fulfillment_job` (unique on `magento_increment_id`).
- CSP `img-src` whitelisted only for the QR/wallet hosts listed in [etc/csp_whitelist.xml](app/code/YourVendor/PVModern/etc/csp_whitelist.xml); everything else routes through same-origin `/api/qr/*` proxies.
- Amount tolerance: VND-rounded, ±0.5đ — anything else falls into manual review.
- Cookie `pv_admin` is HMAC, httpOnly, SameSite=Strict, rolling 24h.
- Form-key required on all checkout JSON endpoints (`assertFormKey` in `Quote.php` and `PlaceOrder.php`).
- Admin endpoints in the `/pvadmin` micro-app re-check `Login::isAuthenticated()` on every request.
- The Adminhtml `SimulateWebhook` endpoint is gated behind `isMockModeEnabled('payment')` so it cannot run on a live deployment.

---

## 18. File map (every file documented above)

```
app/code/YourVendor/PVModern/
├── README.md
├── USER_GUIDE.md                          ← this document
├── registration.php
├── Api/
│   ├── PaymentProviderInterface.php
│   └── ShippingProviderInterface.php
├── Block/
│   ├── Adminhtml/Payments/Dashboard.php
│   ├── Checkout/Flow.php
│   └── Warranty/Lookup.php
├── Controller/
│   ├── Admin/                             # /pvadmin/* micro-app
│   │   ├── Approve.php  Dashboard.php  Login.php  Logout.php
│   │   ├── Orders.php  PaymentReviews.php  Reject.php
│   │   ├── ResolvePaymentReview.php  Screenshot.php
│   ├── Adminhtml/Payments/                # Magento backend
│   │   ├── Approve.php  Index.php  Orders.php
│   │   ├── Reject.php  Screenshot.php  SimulateWebhook.php
│   ├── Api/                               # JSON dashboards & helpers
│   │   ├── Banners.php  Currency.php  Locations.php  News.php  Weather.php
│   ├── Auth/                              # GitHub OAuth
│   │   ├── Github.php  GithubCallback.php
│   ├── Banners/Index.php                  # /banners alias
│   ├── Checkout/                          # custom checkout flow
│   │   ├── MomoIpn.php  MomoReturn.php  PaymentSession.php
│   │   ├── PlaceOrder.php  Quote.php  VnpayReturn.php
│   ├── Currency/                          # /currency/* aliases
│   │   ├── Convert.php  History.php  Index.php  Latest.php
│   ├── CurrencyRate/Index.php             # legacy /currency-rate
│   ├── Index/Index.php                    # generic CMS page renderer
│   ├── News/Index.php                     # /news
│   ├── Orders/Tracking.php
│   ├── OrderTracking/Index.php
│   ├── PaymentConfirmation/Index.php
│   ├── Payments/                          # checkout-side payment endpoints
│   │   ├── Casso.php  Create.php  PvStatus.php  Status.php  Upload.php  Webhook.php
│   ├── PaymentSessions/                   # session-status + SSE
│   │   ├── Events.php  RetryStatusCheck.php  Status.php
│   ├── Qr/                                # QR proxies
│   │   ├── ScanPaid.php  Sepay.php  Url.php
│   ├── Search/Suggest.php
│   ├── Shipping/Cancel.php  Track.php
│   ├── Weather/Index.php                  # /weather
│   ├── Webhooks/                          # verified provider webhooks
│   │   ├── Casso.php  Momo.php  Sepay.php  Stripe.php
│   │   └── Vnpay/Ipn.php
├── etc/
│   ├── acl.xml  config.xml  crontab.xml  csp_whitelist.xml
│   ├── di.xml  module.xml
│   ├── adminhtml/
│   │   ├── menu.xml  routes.xml  system.xml
│   ├── frontend/
│   │   ├── di.xml  routes.xml
├── Helper/PaymentDb.php
├── Model/
│   ├── Banner/HeroBannerProvider.php
│   ├── Carrier/PvmodernShipping.php
│   ├── CategoryNavigation.php
│   ├── Checkout/CheckoutService.php  Checkout/OrderPaymentStatus.php
│   ├── Cron/                              # BankTransferReconciliation, PaymentExpiration, PaymentFallbackPolling
│   ├── Customer/UsernameAuthPlugin.php
│   ├── IntegrationConfig.php
│   ├── Payment/                           # CassoTransactionProcessor, PaymentAttemptService,
│   │   │                                  # PaymentManager, PaymentStatusPoller, SepayPoller, VietQrBuilder
│   │   ├── Method/ (Cod, BankTransfer, OnlineGateway)
│   │   └── Provider/ (Abstract, Cod, BankTransfer, OnlineGateway)
│   ├── ProductVisualResolver.php
│   ├── PurchaseCodeGenerator.php
│   ├── Shipping/                          # PickupLocationProvider, ShippingManager,
│   │   └── Provider/ (Abstract, Ghn, Ghtk, Spx)
│   └── WarrantyCardProvider.php
├── Setup/Patch/
│   ├── Data/AddCustomerUsername.php  EnrichTechCatalog.php  SeedTechProducts.php
│   └── Schema/AddVerifiedPaymentIntegrationTables.php  CreatePaymentTables.php
└── view/
    ├── adminhtml/
    │   ├── layout/pvmodern_payments_payments_index.xml
    │   └── templates/payments/dashboard.phtml
    └── frontend/
        ├── layout/  (deals, terms, privacy, cookies, pcbuilder, pc_builder, warranty,
        │             news, weather, currency, currency_rate, order_tracking,
        │             payment_confirmation)
        ├── templates/
        │   ├── pages/  (currency-rate, news, order-tracking, realtime-dashboard, weather)
        │   └── warranty/lookup.phtml
        └── web/
            ├── css/pv-info-pages.css
            └── js/pv-info-pages.js
```

---

## 19. Quick-start checklist for ops

1. Drop credentials into `BP/pvmodern.env` or set the corresponding env vars.
2. Run `bin/magento setup:upgrade` to apply the data/schema patches (creates pv_* tables and `pv_username` customer attribute).
3. Verify cron is running (`crontab -l` includes `bin/magento cron:run`).
4. (Optional) Open **Admin → Stores → Configuration → PVModern → GitHub OAuth Login** and paste the Client ID/Secret.
5. (Optional) Update `app/etc/env.php` with `pvmodern/admin_password` (and, if desired, `pvmodern/telegram_bot_token` + `pvmodern/telegram_chat_id`).
6. Configure SePay/Casso to POST to `https://<host>/api/webhooks/sepay` (Apikey header) or `https://<host>/api/webhooks/casso` (Casso V2 signature).
7. (Live MoMo/VNPay/Stripe) Set the corresponding webhook URLs in each merchant dashboard:
   - MoMo IPN → `/api/webhooks/momo`
   - VNPay IPN → `/api/webhooks/vnpay/ipn`
   - Stripe → `/api/webhooks/stripe`
8. Test in mock mode first (`PVMODERN_PAYMENT_MOCK=1`); approve a synthetic payment from the admin via *Simulate webhook* to confirm the fulfillment chain works end-to-end.
9. Switch off mock mode (`PVMODERN_PAYMENT_MOCK=0`) — live QR/redirects are now generated and orders only flip via verified callbacks.
