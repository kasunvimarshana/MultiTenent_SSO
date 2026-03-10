'use strict';

/**
 * In-memory payment store (replaces PostgreSQL for test simplicity).
 * In production this module is replaced by the real DB model in db.js.
 */

const payments = new Map();

let idCounter = 1;

/**
 * Create a new payment record.
 * @param {object} data
 * @returns {object} saved payment
 */
function createPayment(data) {
  const id = `pay_${String(idCounter++).padStart(6, '0')}`;
  const payment = {
    payment_id:      id,
    saga_id:         data.saga_id || null,
    order_id:        data.order_id,
    order_number:    data.order_number,
    amount:          parseFloat(data.amount),
    currency:        data.currency || 'USD',
    customer_email:  data.customer_email,
    status:          data.status || 'completed',
    refund_id:       null,
    refunded_at:     null,
    created_at:      new Date().toISOString(),
  };
  payments.set(id, payment);
  return { ...payment };
}

/**
 * Find a payment by its ID.
 */
function findPayment(paymentId) {
  return payments.has(paymentId) ? { ...payments.get(paymentId) } : null;
}

/**
 * List all payments, optionally filtered.
 * @param {object} filters  { order_id, customer_email, status, from_date, to_date }
 */
function listPayments(filters = {}) {
  let results = Array.from(payments.values());

  if (filters.order_id) {
    results = results.filter(p => String(p.order_id) === String(filters.order_id));
  }
  if (filters.customer_email) {
    results = results.filter(p => p.customer_email === filters.customer_email);
  }
  if (filters.status) {
    results = results.filter(p => p.status === filters.status);
  }
  if (filters.saga_id) {
    results = results.filter(p => p.saga_id === filters.saga_id);
  }
  if (filters.min_amount) {
    results = results.filter(p => p.amount >= parseFloat(filters.min_amount));
  }
  if (filters.max_amount) {
    results = results.filter(p => p.amount <= parseFloat(filters.max_amount));
  }

  return results.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
}

/**
 * Refund a payment (compensating transaction).
 */
function refundPayment(paymentId, reason) {
  const payment = payments.get(paymentId);
  if (!payment) return null;

  payment.status      = 'refunded';
  payment.refund_id   = `ref_${String(idCounter++).padStart(6, '0')}`;
  payment.refund_reason = reason;
  payment.refunded_at = new Date().toISOString();
  payments.set(paymentId, payment);

  return { ...payment };
}

/**
 * Reset store (used in tests).
 */
function resetStore() {
  payments.clear();
  idCounter = 1;
}

module.exports = { createPayment, findPayment, listPayments, refundPayment, resetStore };
