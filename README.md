# Multi-Tenant Microservices System with Saga Pattern

[![Order Service Tests](https://img.shields.io/badge/Order%20Service-14%20tests%20passing-green)](#testing)
[![Inventory Service Tests](https://img.shields.io/badge/Inventory%20Service-5%20tests%20passing-green)](#testing)
[![Payment Service Tests](https://img.shields.io/badge/Payment%20Service-9%20tests%20passing-green)](#testing)
[![Notification Service Tests](https://img.shields.io/badge/Notification%20Service-10%20tests%20passing-green)](#testing)

---

## Overview

This repository implements a **production-quality microservices system** demonstrating:

| Concept | Implementation |
|---|---|
| **Multiple independent services** | 4 domain services + API Gateway |
| **Heterogeneous tech stacks** | Laravel (PHP), Node.js/Express, Python/Flask |
| **Multiple databases** | MySQL, PostgreSQL, SQLite |
| **Loose coupling** | HTTP REST APIs between services |
| **Vertical scalability** | PostgreSQL indices, connection pooling |
| **Horizontal scalability** | Nginx upstream groups, Docker Compose `--scale` |
| **Cross-service CRUD** | Full CRUD across all services with relational queries |
| **Distributed transactions** | Orchestration-based Saga pattern |
| **Compensating rollback** | Automatic rollback with per-step compensation |

---

## Architecture

```
┌────────────────────────────────────────────────────────────┐
│                      API GATEWAY (Nginx)                    │
│                    http://localhost:8080                    │
└───────┬────────────────┬────────────────┬──────────────────┘
        │                │                │
        ▼                ▼                ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│ Order Service│  │  Inventory   │  │   Payment    │  │Notification  │
│  (Laravel)   │  │  Service     │  │   Service    │  │  Service     │
│  PHP 8.2     │  │  (Laravel)   │  │  (Node.js)   │  │  (Python)    │
│  MySQL       │  │  PHP 8.2     │  │  Express 4   │  │  Flask 3     │
│  port: 8001  │  │  PostgreSQL  │  │  PostgreSQL  │  │  SQLite      │
└──────┬───────┘  │  port: 8002  │  │  port: 8003  │  │  port: 8004  │
       │          └──────────────┘  └──────────────┘  └──────────────┘
       │  Saga Orchestrator              ▲                    ▲
       │  (calls in order) ─────────────┘────────────────────┘
       │
       ▼
   ┌────────┐
   │ Redis  │   (async queue for order-worker)
   └────────┘
```

### Service Responsibilities

| Service | Technology | Database | Port | Responsibilities |
|---|---|---|---|---|
| **API Gateway** | Nginx 1.25 | — | 8080 | Route, load-balance, rate-limit |
| **Order Service** | Laravel 10 / PHP 8.2 | MySQL 8.0 | 8001 | Orders CRUD, **Saga Orchestrator** |
| **Inventory Service** | Laravel 10 / PHP 8.2 | PostgreSQL 15 | 8002 | Products CRUD, stock reservations |
| **Payment Service** | Node.js 20 / Express 4 | In-memory / PostgreSQL | 8003 | Payments, refunds |
| **Notification Service** | Python 3.12 / Flask 3 | SQLite | 8004 | Email notifications |

---

## Quick Start

### Prerequisites
- Docker ≥ 24 and Docker Compose ≥ 2.20

### 1. Start all services

```bash
docker compose up --build -d
```

### 2. Run migrations (Order Service)

```bash
docker compose exec order-service php artisan migrate --seed
```

### 3. Seed inventory products

```bash
docker compose exec inventory-service php artisan migrate
docker compose exec inventory-service php artisan db:seed --class=ProductSeeder
```

### 4. Verify all services are up

```bash
curl http://localhost:8080/health
# {"status":"api-gateway-ok"}

curl http://localhost:8001/api/orders
curl http://localhost:8002/api/products
curl http://localhost:8003/health
curl http://localhost:8004/health
```

### 5. Horizontal scaling

```bash
# Scale Order Service to 3 replicas
docker compose up --scale order-service=3 -d
```

---

## API Reference

### Order Service (via Gateway: `localhost:8080/api/orders`)

#### List orders (with filtering)

```http
GET /api/orders?status=confirmed&customer_email=alice@example.com&min_amount=50&per_page=10
```

Query parameters:

| Parameter | Description |
|---|---|
| `status` | `pending` / `confirmed` / `failed` / `cancelled` |
| `customer_email` | Filter by customer |
| `from_date` / `to_date` | Date range (Y-m-d) |
| `min_amount` / `max_amount` | Amount range |
| `search` | Search order_number or customer_name |
| `per_page` | Items per page (default 15) |

#### Create order (basic, no Saga)

```http
POST /api/orders
Content-Type: application/json

{
  "customer_email": "alice@example.com",
  "customer_name":  "Alice Smith",
  "items": [
    { "product_id": 1, "product_name": "Widget A", "quantity": 2, "unit_price": 29.99 },
    { "product_id": 3, "product_name": "Gadget C", "quantity": 1, "unit_price": 99.00 }
  ]
}
```

---

### Inventory Service (`localhost:8080/api/products`)

#### List products (with filtering)

```http
GET /api/products?category=widgets&in_stock=1&min_price=20&max_price=100
```

#### Create product

```http
POST /api/products
Content-Type: application/json

{
  "sku": "WGT-X001",
  "name": "Widget X",
  "category": "widgets",
  "description": "A premium widget",
  "price": 49.99,
  "stock_quantity": 100
}
```

#### Restock a product

```http
POST /api/products/{id}/restock
Content-Type: application/json

{ "quantity": 50 }
```

---

### Payment Service (`localhost:8080/api/payments`)

#### List payments

```http
GET /api/payments?order_id=42&status=completed
```

#### Refund a payment (compensating action)

```http
POST /api/payments/{payment_id}/refund
Content-Type: application/json

{ "reason": "customer_request", "order_id": 42 }
```

> **Demo tip**: To trigger a payment failure, set `unit_price` so that total > 9999.
> The payment service simulates a card decline for amounts exceeding this threshold.

---

### Notification Service (`localhost:8080/api/notifications`)

#### List notifications

```http
GET /api/notifications?type=order_confirmed&recipient=alice@example.com
```

---

## Saga Pattern: Distributed Transaction Management

The **Place Order** workflow is managed by the `SagaOrchestrator` in the Order Service.
It uses the **Orchestration** pattern: a central coordinator drives all steps and
compensates on failure.

### Successful flow

```
Client           Order Service      Inventory Service  Payment Service  Notification
  │                   │                    │                 │               │
  │  POST /saga/      │                    │                 │               │
  │  place-order      │                    │                 │               │
  │──────────────────>│                    │                 │               │
  │                   │ 1. create_order    │                 │               │
  │                   │ (save to MySQL)    │                 │               │
  │                   │                    │                 │               │
  │                   │ 2. POST /reservations                │               │
  │                   │───────────────────>│                 │               │
  │                   │ {reservation_ids}  │                 │               │
  │                   │<───────────────────│                 │               │
  │                   │                    │                 │               │
  │                   │ 3. POST /payments  │                 │               │
  │                   │────────────────────────────────────>│               │
  │                   │ {payment_id}       │                 │               │
  │                   │<────────────────────────────────────│               │
  │                   │                    │                 │               │
  │                   │ 4. POST /notifications               │               │
  │                   │────────────────────────────────────────────────────>│
  │                   │                    │                 │               │
  │  201 {order, log} │                    │                 │               │
  │<──────────────────│                    │                 │               │
```

### API Example – Successful Saga

```bash
curl -X POST http://localhost:8080/api/saga/place-order \
  -H "Content-Type: application/json" \
  -d '{
    "customer_email": "alice@example.com",
    "customer_name":  "Alice Smith",
    "items": [
      { "product_id": 1, "product_name": "Widget A", "quantity": 2, "unit_price": 29.99 }
    ]
  }'
```

**Response (HTTP 201):**

```json
{
  "saga_id": "550e8400-e29b-41d4-a716-446655440000",
  "status":  "completed",
  "order": {
    "id": 1,
    "order_number":   "ORD-A1B2C3D4",
    "customer_email": "alice@example.com",
    "status":         "confirmed",
    "total_amount":   59.98,
    "items": [
      {
        "product_id":   1,
        "product_name": "Widget A",
        "quantity":     2,
        "unit_price":   29.99,
        "subtotal":     59.98
      }
    ]
  },
  "log": [
    { "step": "create_order",       "result": "success", "timestamp": "2026-01-01T10:00:00Z" },
    { "step": "reserve_inventory",  "result": "success", "timestamp": "2026-01-01T10:00:01Z",
      "context": { "reservation_ids": [1] } },
    { "step": "process_payment",    "result": "success", "timestamp": "2026-01-01T10:00:02Z",
      "context": { "payment_id": "pay_000001", "amount": 59.98, "status": "completed" } },
    { "step": "send_notification",  "result": "success", "timestamp": "2026-01-01T10:00:03Z",
      "context": { "recipient": "alice@example.com" } },
    { "step": "saga_completed",     "result": "success", "timestamp": "2026-01-01T10:00:03Z" }
  ]
}
```

---

### Rollback flow (payment failure demo)

When `amount > 9999`, the Payment Service simulates a card decline, triggering rollback:

```
Completed steps:    create_order → reserve_inventory → ✗ process_payment (FAILED)

Compensating steps: compensate_inventory  ← releases stock reservation
                    compensate_order       ← sets order status = "failed"
                    (in reverse order of completed steps)
```

```bash
# Trigger payment failure: total amount > 9999
curl -X POST http://localhost:8080/api/saga/place-order \
  -H "Content-Type: application/json" \
  -d '{
    "customer_email": "bob@example.com",
    "customer_name":  "Bob Jones",
    "items": [
      { "product_id": 1, "product_name": "Widget A", "quantity": 2, "unit_price": 5000.00 }
    ]
  }'
```

**Response (HTTP 402):**

```json
{
  "saga_id": "660e9400-e29b-41d4-a716-446655440001",
  "status":  "rolled_back",
  "error":   "Payment processing failed: Payment declined: amount exceeds limit",
  "order": {
    "id":           2,
    "status":       "failed",
    "total_amount": 10000.00
  },
  "log": [
    { "step": "create_order",         "result": "success" },
    { "step": "reserve_inventory",    "result": "success",
      "context": { "reservation_ids": [2] } },
    { "step": "saga_failed",          "result": "error",
      "context": { "message": "Payment declined...", "failed_at_step": "reserve_inventory" } },
    { "step": "compensate_inventory", "result": "success",
      "context": { "released": [2] } },
    { "step": "compensate_order",     "result": "success",
      "context": { "new_status": "failed" } }
  ]
}
```

---

### Inventory failure rollback

```bash
# Try to order more than available stock (product 1 has 100 units)
curl -X POST http://localhost:8080/api/saga/place-order \
  -H "Content-Type: application/json" \
  -d '{
    "customer_email": "carol@example.com",
    "customer_name":  "Carol Davis",
    "items": [
      { "product_id": 1, "product_name": "Widget A", "quantity": 999, "unit_price": 29.99 }
    ]
  }'
```

**Response (HTTP 422):**

```json
{
  "saga_id": "770e0500-...",
  "status":  "rolled_back",
  "error":   "Inventory reservation failed: Insufficient stock for product 'Widget A' (requested 999, available 100)",
  "order": {
    "status": "failed"
  },
  "log": [
    { "step": "create_order",    "result": "success" },
    { "step": "saga_failed",     "result": "error" },
    { "step": "compensate_order","result": "success" }
  ]
}
```

---

### Check Saga status

```bash
curl http://localhost:8080/api/saga/{saga_id}/status
```

### Manual rollback (admin / recovery tool)

```bash
curl -X POST http://localhost:8080/api/saga/{saga_id}/rollback \
  -H "Content-Type: application/json" \
  -d '{
    "completed_steps": ["create_order", "reserve_inventory", "process_payment"],
    "payment_id": "pay_000001",
    "reservation_ids": [1, 2]
  }'
```

---

## Cross-Service Relational Queries

Although each service owns its own database, cross-service joins are performed at the
application layer via HTTP:

```bash
# 1. Get confirmed orders from Order Service (MySQL)
curl "http://localhost:8080/api/orders?status=confirmed"

# 2. For each order, fetch its payment details (Payment Service)
curl "http://localhost:8080/api/payments?order_id=42"

# 3. Check stock reservation status (Inventory Service / PostgreSQL)
curl "http://localhost:8080/api/reservations?order_id=42&status=active"

# 4. Verify notification was sent (Notification Service / SQLite)
curl "http://localhost:8080/api/notifications?recipient=alice@example.com&type=order_confirmed"
```

The `order.saga_log` JSON column in the Order Service DB records every cross-service
interaction, providing a complete audit trail for each distributed transaction.

---

## Testing

### Run all tests

```bash
# Order Service (PHP/Laravel) – 14 tests
cd order-service && ./vendor/bin/phpunit --testdox

# Inventory Service (PHP/Laravel) – 5 tests
cd inventory-service && ./vendor/bin/phpunit --testdox

# Payment Service (Node.js/Jest) – 9 tests
cd payment-service && npm test

# Notification Service (Python/pytest) – 10 tests
cd notification-service && python -m pytest tests/ -v
```

### Test Coverage

| Service | Tests | Description |
|---|---|---|
| Order Service – Unit | 4 | SagaOrchestrator: happy path + 2 rollback scenarios + log completeness |
| Order Service – Feature | 10 | Full CRUD API including filtering and pagination |
| Inventory Service | 5 | Stock reservation, insufficient-stock 422, compensating release, product filtering |
| Payment Service | 9 | Charge, decline, listing, filtering, refund, double-refund prevention |
| Notification Service | 10 | CRUD, filtering, email validation, type filtering |
| **Total** | **38** | — |

---

## Scalability Design

### Vertical Scalability

- **PostgreSQL indices** on all frequently-queried columns (`status`, `category`, `price`, `created_at`)
- **MySQL indices** on `orders.saga_id`, `orders.status`, `orders.customer_email`
- **Row-level locking** (`lockForUpdate()`) in inventory reservation prevents race conditions under concurrent load

### Horizontal Scalability

```yaml
# docker-compose.yml – scale Order Service to N replicas
docker compose up --scale order-service=3 -d

# Nginx nginx.conf – add replica servers to upstream block
upstream order_service {
    server order-service:8000;
    # server order-service-2:8000;
    # server order-service-3:8000;
}
```

- **Stateless HTTP services**: All state in databases/Redis
- **Redis-backed queue**: `order-worker` can be scaled independently
- **Shared-nothing architecture**: Services communicate only via APIs

---

## Directory Structure

```
.
├── docker-compose.yml              # Full system orchestration
├── api-gateway/
│   ├── Dockerfile
│   └── nginx.conf                  # Reverse proxy + load balancing
├── order-service/                  # Laravel 10 / PHP 8.2 / MySQL
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── OrderController.php # CRUD with filtering & pagination
│   │   │   └── SagaController.php  # Saga orchestration endpoints
│   │   ├── Models/
│   │   │   ├── Order.php           # saga_log JSON column for audit trail
│   │   │   └── OrderItem.php
│   │   └── Services/
│   │       ├── SagaOrchestrator.php # Distributed transaction + rollback
│   │       ├── InventoryClient.php  # HTTP client for Inventory Service
│   │       ├── PaymentClient.php    # HTTP client for Payment Service
│   │       └── NotificationClient.php
│   ├── database/migrations/
│   ├── routes/api.php
│   └── tests/
│       ├── Feature/OrderApiTest.php         # 10 CRUD feature tests
│       └── Unit/SagaOrchestratorTest.php    # 4 Saga unit tests
├── inventory-service/              # Laravel 10 / PHP 8.2 / PostgreSQL
│   ├── app/Http/Controllers/
│   │   ├── ProductController.php   # CRUD with category/price/stock filtering
│   │   └── ReservationController.php # Atomic reservations + compensating release
│   ├── app/Models/
│   │   ├── Product.php             # available_quantity virtual attribute
│   │   └── StockReservation.php    # active|released|fulfilled lifecycle
│   ├── database/seeders/ProductSeeder.php
│   └── tests/Feature/ReservationApiTest.php
├── payment-service/                # Node.js 20 / Express 4
│   ├── src/
│   │   ├── app.js                  # Express app entry point
│   │   ├── routes/payments.js      # Charge + refund endpoints
│   │   └── models/payment.js       # In-memory store (swap for PostgreSQL)
│   └── tests/payment.test.js       # 9 Jest tests
└── notification-service/           # Python 3.12 / Flask 3 / SQLite
    ├── app.py                      # Full CRUD + type/recipient/status filtering
    ├── requirements.txt
    └── tests/test_notifications.py # 10 pytest tests
```
