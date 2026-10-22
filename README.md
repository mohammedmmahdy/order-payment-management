# Order & Payment Management API

A Laravel REST API for managing orders and payments, built so that **adding a new payment gateway is one class and one config entry**.

- JWT authentication (register / login / refresh / logout)
- Orders: create, update, delete, list (paginated, filter by status)
- Payments: process through a pluggable gateway, list per order or overall
- Strategy pattern + registry for payment gateways, configured through `.env`
- Form-request validation, API resources, consistent JSON errors
- Unit + feature test suite, Postman collection with success and error examples

---

## Contents

- [Order \& Payment Management API](#order--payment-management-api)
  - [Contents](#contents)
  - [Setup](#setup)
    - [Environment variables added by this project](#environment-variables-added-by-this-project)
    - [Quick try-out](#quick-try-out)
  - [API overview](#api-overview)
    - [Authentication](#authentication)
    - [Orders](#orders)
    - [Payments](#payments)
  - [Business rules](#business-rules)
  - [Payment gateway extensibility](#payment-gateway-extensibility)
    - [How it works](#how-it-works)
    - [Adding a new gateway (e.g. Stripe) – 2 steps](#adding-a-new-gateway-eg-stripe--2-steps)
  - [Simulated gateway behaviour](#simulated-gateway-behaviour)
  - [Error format](#error-format)
  - [Testing](#testing)
  - [Postman documentation](#postman-documentation)
  - [Project structure](#project-structure)
  - [Assumptions and design notes](#assumptions-and-design-notes)

---

## Setup

Requirements: PHP 8.2+ (with `pdo_sqlite` or `pdo_mysql`, `mbstring`, `xml`, `curl`), Composer 2.

```bash
git clone https://github.com/mohammedmmahdy/order-payment-management.git
cd order-payment-management

composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret        # writes JWT_SECRET to .env
php artisan migrate

php artisan serve             # http://localhost:8000
```

The default database is SQLite (`database/database.sqlite`, created by Laravel). To use MySQL, set the `DB_*` variables in `.env` and run `php artisan migrate`.

Base URL: `http://localhost:8000/api/v1`

### Environment variables added by this project

| Variable | Purpose | Default |
|---|---|---|
| `JWT_SECRET` | Signing key for tokens (`php artisan jwt:secret`) | – |
| `JWT_TTL` | Token lifetime in minutes | `60` |
| `PAYMENT_CURRENCY` | Currency stored on payments | `USD` |
| `PAYMENT_CREDIT_CARD_ENABLED` | Enable/disable the credit card gateway | `true` |
| `CREDIT_CARD_API_KEY`, `CREDIT_CARD_API_SECRET` | Credit card gateway credentials | – |
| `PAYMENT_PAYPAL_ENABLED` | Enable/disable the PayPal gateway | `true` |
| `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_MODE` | PayPal credentials / `sandbox` or `live` | `sandbox` |

### Quick try-out

```bash
# 1. register (returns a token)
curl -s -X POST localhost:8000/api/v1/auth/register -H 'Content-Type: application/json' \
  -d '{"name":"Jane","email":"jane@example.com","password":"secret-password"}'

# 2. create an order, 3. confirm it, 4. pay for it  (use the token from step 1)
TOKEN=...
curl -s -X POST localhost:8000/api/v1/orders -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"customer_name":"Jane","customer_email":"jane@example.com","items":[{"product_name":"Mouse","quantity":2,"price":10.5}]}'
curl -s -X PATCH localhost:8000/api/v1/orders/1 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"status":"confirmed"}'
curl -s -X POST localhost:8000/api/v1/payments -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"order_id":1,"payment_method":"credit_card","card_number":"4242424242424242","card_holder":"Jane Doe","expiry_month":12,"expiry_year":2030,"cvv":"123"}'
```

The Postman collection in `docs/postman/` does all of this for you.

---

## API overview

All endpoints are under `/api/v1` and return JSON. Everything except register/login requires `Authorization: Bearer <token>`.

### Authentication

| Method | Endpoint | Description |
|---|---|---|
| POST | `/auth/register` | Create a user, returns a token (201) |
| POST | `/auth/login` | Returns a token (rate limited: 10/min per IP) |
| GET | `/auth/me` | Current user |
| POST | `/auth/refresh` | New token, old one is invalidated |
| POST | `/auth/logout` | Invalidates the current token |

### Orders

| Method | Endpoint | Description |
|---|---|---|
| GET | `/orders` | Paginated list. Query: `status` (`pending`/`confirmed`/`cancelled`), `per_page` (1–100, default 15), `page` |
| POST | `/orders` | Create (201 + `Location` header). Total is calculated by the server |
| GET | `/orders/{id}` | Show with items |
| PUT / PATCH | `/orders/{id}` | Update customer details, items and/or `status` (all fields optional) |
| DELETE | `/orders/{id}` | Delete (204). Refused with 409 if the order has any payment |

Create body:

```json
{
  "customer_name": "Jane Doe",
  "customer_email": "jane@example.com",
  "customer_phone": "+201001234567",
  "items": [
    { "product_name": "Wireless Mouse", "quantity": 2, "price": 10.50 },
    { "product_name": "USB-C Cable", "quantity": 1, "price": 5.00 }
  ]
}
```

### Payments

| Method | Endpoint | Description |
|---|---|---|
| POST | `/payments` | Process a payment. **201** if approved, **402** if the gateway declines (the failed payment is stored and returned) |
| GET | `/payments` | Paginated list of your payments. Query: `status`, `order_id`, `per_page`, `page` |
| GET | `/payments/{id}` | Show one payment |
| GET | `/orders/{id}/payments` | Payments of one order (paginated, optional `status`) |
| GET | `/payment-gateways` | Enabled payment methods and the extra fields each requires |

Process-payment body (`order_id` + `payment_method` + the gateway's own fields):

```json
{
  "order_id": 1,
  "payment_method": "credit_card",
  "card_number": "4242424242424242",
  "card_holder": "Jane Doe",
  "expiry_month": 12,
  "expiry_year": 2030,
  "cvv": "123"
}
```

```json
{ "order_id": 1, "payment_method": "paypal", "paypal_email": "buyer@example.com" }
```

A payment has: `id`, `order_id`, `payment_method`, `status` (`pending` / `successful` / `failed`), `amount`, `currency`, `transaction_id`, `failure_reason`, `gateway_response`.

---

## Business rules

- **Payments only for `confirmed` orders.** Otherwise `422`.
- **Orders with payments cannot be deleted** (even failed ones) → `409`. The `payments.order_id` foreign key is also `RESTRICT`, so the database enforces it too.
- Order lifecycle: `pending → confirmed → cancelled` (and `pending → cancelled`). Anything else is `422`.
- Items can only be changed while the order is `pending` (the total is recalculated); cancelled orders are immutable (`409`).
- An order with a **successful** payment cannot be cancelled (`409`, no refund flow in scope).
- An order can be paid once: a second attempt after a successful payment, or while another payment is in flight, is `409`. A **failed** payment can be retried.
- Users only ever see their own orders and payments; someone else's resource is a `404` (not `403`) so existence is not leaked.
- Totals are computed in integer cents on the server; `status`, `total` and `user_id` sent on create are ignored.

---

## Payment gateway extensibility

### How it works

```
POST /payments ──► ProcessPaymentRequest ──► PaymentController ──► PaymentService
                     │  validates base fields +            │            │
                     │  gateway->rules()                   │            ▼
                     ▼                                     │   PaymentGatewayManager::gateway($method)
              config/payment.php  ◄────────────────────────┴────────────┘
              'paypal' => [driver => PayPalGateway::class, ...credentials from .env]
                                                                          │
                                                          PaymentGateway::charge($payment, $data)  ← strategy
```

- `App\Payments\Contracts\PaymentGateway` is the **strategy interface** (`rules()` and `charge()`).
- `App\Payments\PaymentGatewayManager` is the **registry/factory**. It reads `config/payment.php`, builds the right gateway through the container and injects that gateway's config block (API keys etc.).
- `App\Services\PaymentService` contains the payment workflow (business rules, pending → successful/failed bookkeeping, error handling). It never mentions a concrete gateway.
- Each gateway declares its own request validation rules, so `ProcessPaymentRequest`, the controller and the service never change when a gateway is added.

### Adding a new gateway (e.g. Stripe) – 2 steps

**1. Create the gateway class** (`app/Payments/Gateways/StripeGateway.php`):

```php
<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\AbstractGateway;
use App\Payments\GatewayResult;

class StripeGateway extends AbstractGateway
{
    // Extra fields the client must send for this method.
    public function rules(): array
    {
        return ['payment_token' => ['required', 'string']];
    }

    public function charge(Payment $payment, array $data): GatewayResult
    {
        $secret = $this->config('secret');   // from config/payment.php <- .env

        // Call the real API here, e.g. with Http::withToken($secret)->post(...)
        $approved = true;

        return $approved
            ? GatewayResult::success('ch_123', ['gateway' => 'stripe'])
            : GatewayResult::failure('Card declined', ['gateway' => 'stripe']);
    }
}
```

**2. Register it** in `config/payment.php` and add its variables to `.env`:

```php
'stripe' => [
    'driver'  => \App\Payments\Gateways\StripeGateway::class,
    'enabled' => env('PAYMENT_STRIPE_ENABLED', true),
    'secret'  => env('STRIPE_SECRET'),
],
```

```dotenv
PAYMENT_STRIPE_ENABLED=true
STRIPE_SECRET=sk_test_...
```

That's it. `payment_method: "stripe"` is now accepted, validated with the gateway's own rules, listed by `GET /payment-gateways`, and processed by `PaymentService`. No controller, request, service, model, route or migration changes.

Notes:
- `payment_method` is a plain string (not an enum) precisely so that no code needs editing for a new method.
- To disable a gateway without deleting code, set its `enabled` flag to `false`.
- Gateways may throw for unexpected failures (timeouts, 5xx); `PaymentService` catches the exception, logs it, stores the payment as `failed` and returns a generic message (no internal details leak).
- Never persist raw card data: only put safe values (last four digits, codes, references) in `GatewayResult::$response`.
- `tests/Unit/Payments/PaymentGatewayManagerTest.php` and `tests/Feature/PaymentTest.php` prove the point by registering a `FakeGateway` through config only.
- Configuration lives in `.env`/`config`. If gateway settings ever need to be editable at runtime, only `PaymentGatewayManager::definitions()` has to change (read the same array shape from a database table); nothing else depends on where the config comes from.

---

## Simulated gateway behaviour

No real money moves; both gateways are simulations so every outcome can be demonstrated:

| Gateway | Approved | Declined |
|---|---|---|
| `credit_card` | any valid card, e.g. `4242424242424242` | `4000000000000002`, or an expired card |
| `paypal` | any email | an email containing `decline`, e.g. `decline@example.com` |

---

## Error format

| Status | When | Body |
|---|---|---|
| 401 | Missing/invalid/expired token, bad login | `{"message": "Unauthenticated."}` |
| 402 | Gateway declined the payment | payment resource + `"message": "Payment failed."` |
| 404 | Unknown resource, or another user's resource, or unknown endpoint | `{"message": "Order not found."}` |
| 409 | Conflict with current state (already paid, has payments, cancelled…) | `{"message": "..."}` |
| 422 | Validation failure or business-rule violation | `{"message": "...", "errors": {"field": ["..."]}}` for validation, `{"message": "..."}` for rules |
| 429 | Rate limit exceeded | Laravel default |

All API errors are JSON regardless of the `Accept` header.

---

## Testing

```bash
php artisan test
```

The suite uses an in-memory SQLite database (`phpunit.xml`), so it needs no setup.

| Area | File | What it covers |
|---|---|---|
| Gateways (unit) | `tests/Unit/Payments/CreditCardGatewayTest.php`, `PayPalGatewayTest.php` | approve/decline paths, expired cards, validation rules, no card data in responses |
| Registry (unit) | `tests/Unit/Payments/PaymentGatewayManagerTest.php` | resolving gateways, unknown/disabled/invalid drivers, **adding a gateway through config only** |
| Payment workflow (unit) | `tests/Unit/Services/PaymentServiceTest.php` | confirmed-only rule, double payment, in-flight payment, retry after failure, gateway outage, nothing persisted for card data |
| Domain (unit) | `tests/Unit/OrderStatusTest.php`, `OrderTotalTest.php` | status transitions, cent-accurate totals |
| API (feature) | `tests/Feature/AuthTest.php` | register, login, token use, logout invalidation, refresh |
| API (feature) | `tests/Feature/OrderTest.php` | CRUD, validation, pagination, status filter, ownership, delete/cancel rules |
| API (feature) | `tests/Feature/PaymentTest.php` | processing for both gateways, 402 on decline, business rules, gateway-specific validation, listing/filtering, extensibility end-to-end |

Code style is PSR-12: `./vendor/bin/pint --test` (config in `pint.json`).

---

## Postman documentation

`docs/postman/` contains:

- `Order_Payment_API.postman_collection.json` – the collection (import it in Postman)
- `Order_Payment_API.postman_environment.json` – optional local environment

The collection is organised in three folders (**Authentication**, **Orders**, **Payments**). Every request has a description and saved example responses for success and error cases (validation, 401, 402, 404, 409, 422). Test scripts store `token`, `orderId` and `paymentId` automatically, so you can run the folders top to bottom: Register → Create order → Confirm order → Process payment → List payments.

---

## Project structure

```
app/
├── Enums/                    OrderStatus (with transition rules), PaymentStatus
├── Exceptions/               BusinessRuleException (renders itself as JSON), UnsupportedPaymentMethodException
├── Http/
│   ├── Controllers/Api/V1/   Auth, Order, Payment, PaymentGateway controllers (thin)
│   ├── Middleware/           ForceJsonResponse
│   ├── Requests/             Form requests per use case (+ gateway-specific rules for payments)
│   └── Resources/            JSON shapes
├── Models/                   User (JWTSubject), Order, OrderItem, Payment
├── Payments/                 ← extensibility lives here
│   ├── Contracts/PaymentGateway.php
│   ├── AbstractGateway.php
│   ├── GatewayResult.php
│   ├── PaymentGatewayManager.php
│   └── Gateways/             CreditCardGateway, PayPalGateway
└── Services/                 OrderService, PaymentService (business rules)
config/payment.php            gateway registry + credentials (from .env)
database/                     migrations, factories
routes/api.php
tests/                        Unit/, Feature/, Support/ (fake gateways), Concerns/
docs/postman/                 collection + environment
```

---

## Assumptions and design notes

- **Ownership:** every order belongs to the authenticated user; there are no admin roles, so users only see their own data.
- **"User details" on an order** are the customer name, email and optional phone supplied in the request (independent of the account that placed the order).
- **How an order becomes `confirmed`:** the brief does not say, so it is an explicit status update (`PATCH /orders/{id}` with `{"status":"confirmed"}`), validated by a small state machine in `OrderStatus`.
- **Process payment endpoint:** `POST /payments` with `order_id` in the body (matching the brief's payment fields), plus `GET /orders/{id}/payments` for the per-order view.
- **Failed payments** are stored (for auditing and retries) and returned with HTTP 402, rather than being swallowed or returned as 200.
- **Concurrency:** the order row is locked while a pending payment is created, so two simultaneous requests cannot both charge. A payment left `pending` by a crash would block new attempts for that order until it is resolved (a reconciliation job would handle this in production; out of scope).
- **PUT vs PATCH:** both route to the same partial update (all fields optional).
- **Money:** `decimal(12,2)` columns; totals computed in integer cents. Amounts are returned as JSON numbers.
- **Gateways are simulations.** A real integration would live entirely inside `charge()` of its gateway class (HTTP call using the credentials from config) and would typically also need webhooks for asynchronous confirmation, which is out of scope.
- **Auth library:** `tymon/jwt-auth`
