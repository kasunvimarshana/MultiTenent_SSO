'use strict';

const express = require('express');
const router  = express.Router();
const { createPayment, findPayment, listPayments, refundPayment } = require('../models/payment');

/**
 * Payment Service Routes
 *
 * GET    /api/payments               List payments (filter by order_id, status, …)
 * POST   /api/payments               Charge (create payment)
 * GET    /api/payments/:id           Get a single payment
 * POST   /api/payments/:id/refund    Refund a payment (Saga compensating action)
 */

// ── GET /api/payments ──────────────────────────────────────────────────────

router.get('/', (req, res) => {
  const payments = listPayments(req.query);
  res.json({ data: payments, total: payments.length });
});

// ── POST /api/payments ─────────────────────────────────────────────────────

/**
 * Charge a customer.
 *
 * Request body:
 * {
 *   "saga_id":        "uuid",
 *   "order_id":       42,
 *   "order_number":   "ORD-ABCDEF01",
 *   "amount":         159.97,
 *   "currency":       "USD",
 *   "customer_email": "alice@example.com"
 * }
 *
 * Simulates a payment gateway. To trigger failure in demos,
 * set amount > 9999 (simulates a declined card).
 *
 * Success (201):
 * { "payment_id": "pay_000001", "amount": 159.97, "status": "completed", "created_at": "..." }
 *
 * Failure (402):
 * { "error": "Payment declined: amount exceeds limit" }
 */
router.post('/', (req, res) => {
  const { saga_id, order_id, order_number, amount, currency, customer_email } = req.body;

  // Validate
  if (!order_id || !amount || !customer_email) {
    return res.status(422).json({ error: 'order_id, amount, and customer_email are required.' });
  }
  if (isNaN(parseFloat(amount)) || parseFloat(amount) <= 0) {
    return res.status(422).json({ error: 'amount must be a positive number.' });
  }

  // ── Simulate payment gateway ──────────────────────────────────────────
  // Amount > 9999 triggers a "card declined" failure (useful for Saga demos)
  if (parseFloat(amount) > 9999) {
    return res.status(402).json({
      error: 'Payment declined: amount exceeds limit (demo: use amount ≤ 9999)',
    });
  }

  const payment = createPayment({
    saga_id,
    order_id,
    order_number,
    amount,
    currency: currency || 'USD',
    customer_email,
    status: 'completed',
  });

  res.status(201).json(payment);
});

// ── GET /api/payments/:id ──────────────────────────────────────────────────

router.get('/:id', (req, res) => {
  const payment = findPayment(req.params.id);
  if (!payment) {
    return res.status(404).json({ error: `Payment '${req.params.id}' not found.` });
  }
  res.json(payment);
});

// ── POST /api/payments/:id/refund ──────────────────────────────────────────

/**
 * Refund a payment – compensating action for Saga rollback.
 *
 * Request body:
 * { "reason": "saga_rollback", "order_id": 42 }
 *
 * Success (200):
 * { "refund_id": "ref_000002", "payment_id": "pay_000001", "amount": 159.97, "status": "refunded" }
 */
router.post('/:id/refund', (req, res) => {
  const payment = findPayment(req.params.id);
  if (!payment) {
    return res.status(404).json({ error: `Payment '${req.params.id}' not found.` });
  }
  if (payment.status === 'refunded') {
    return res.status(409).json({ error: 'Payment has already been refunded.' });
  }

  const updated = refundPayment(req.params.id, req.body.reason || 'manual_refund');
  res.json({
    refund_id:  updated.refund_id,
    payment_id: updated.payment_id,
    amount:     updated.amount,
    status:     updated.status,
    refunded_at: updated.refunded_at,
  });
});

module.exports = router;
