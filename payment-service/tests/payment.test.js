'use strict';

const request  = require('supertest');
const app      = require('../src/app');
const { resetStore } = require('../src/models/payment');

beforeEach(() => {
  resetStore();
});

// ── POST /api/payments (charge) ────────────────────────────────────────────

describe('POST /api/payments', () => {
  it('should create a payment and return 201', async () => {
    const res = await request(app)
      .post('/api/payments')
      .send({
        saga_id:        'test-saga-uuid',
        order_id:       1,
        order_number:   'ORD-TEST001',
        amount:         59.98,
        currency:       'USD',
        customer_email: 'alice@example.com',
      });

    expect(res.status).toBe(201);
    expect(res.body).toHaveProperty('payment_id');
    expect(res.body.status).toBe('completed');
    expect(res.body.amount).toBe(59.98);
  });

  it('should decline payment when amount > 9999 (Saga failure demo)', async () => {
    const res = await request(app)
      .post('/api/payments')
      .send({
        order_id:       99,
        amount:         10000,
        customer_email: 'bob@example.com',
      });

    expect(res.status).toBe(402);
    expect(res.body).toHaveProperty('error');
  });

  it('should return 422 when required fields are missing', async () => {
    const res = await request(app)
      .post('/api/payments')
      .send({ amount: 10 }); // missing order_id and customer_email

    expect(res.status).toBe(422);
  });
});

// ── GET /api/payments ──────────────────────────────────────────────────────

describe('GET /api/payments', () => {
  it('should list all payments', async () => {
    // Create two payments first
    await request(app).post('/api/payments').send({
      order_id: 1, amount: 50, customer_email: 'a@test.com',
    });
    await request(app).post('/api/payments').send({
      order_id: 2, amount: 100, customer_email: 'b@test.com',
    });

    const res = await request(app).get('/api/payments');
    expect(res.status).toBe(200);
    expect(res.body.data.length).toBe(2);
  });

  it('should filter payments by order_id', async () => {
    await request(app).post('/api/payments').send({
      order_id: 10, amount: 25, customer_email: 'x@test.com',
    });
    await request(app).post('/api/payments').send({
      order_id: 20, amount: 75, customer_email: 'y@test.com',
    });

    const res = await request(app).get('/api/payments?order_id=10');
    expect(res.status).toBe(200);
    expect(res.body.data.every(p => p.order_id === 10)).toBe(true);
  });
});

// ── GET /api/payments/:id ──────────────────────────────────────────────────

describe('GET /api/payments/:id', () => {
  it('should return a single payment', async () => {
    const create = await request(app).post('/api/payments').send({
      order_id: 5, amount: 99, customer_email: 'c@test.com',
    });
    const id = create.body.payment_id;

    const res = await request(app).get(`/api/payments/${id}`);
    expect(res.status).toBe(200);
    expect(res.body.payment_id).toBe(id);
  });

  it('should return 404 for unknown payment', async () => {
    const res = await request(app).get('/api/payments/pay_unknown');
    expect(res.status).toBe(404);
  });
});

// ── POST /api/payments/:id/refund (compensating action) ───────────────────

describe('POST /api/payments/:id/refund', () => {
  it('should refund a payment (Saga compensating action)', async () => {
    const create = await request(app).post('/api/payments').send({
      order_id: 7, amount: 200, customer_email: 'd@test.com',
    });
    const id = create.body.payment_id;

    const res = await request(app)
      .post(`/api/payments/${id}/refund`)
      .send({ reason: 'saga_rollback', order_id: 7 });

    expect(res.status).toBe(200);
    expect(res.body).toHaveProperty('refund_id');
    expect(res.body.status).toBe('refunded');
  });

  it('should not refund an already-refunded payment', async () => {
    const create = await request(app).post('/api/payments').send({
      order_id: 8, amount: 50, customer_email: 'e@test.com',
    });
    const id = create.body.payment_id;

    await request(app).post(`/api/payments/${id}/refund`).send({ reason: 'first' });
    const res = await request(app).post(`/api/payments/${id}/refund`).send({ reason: 'second' });

    expect(res.status).toBe(409);
  });
});
