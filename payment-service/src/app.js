'use strict';

require('dotenv').config();

const express      = require('express');
const cors         = require('cors');
const morgan       = require('morgan');
const paymentRoutes = require('./routes/payments');

const app  = express();
const PORT = process.env.PORT || 3000;

// ── Middleware ─────────────────────────────────────────────────────────────
app.use(cors());
app.use(express.json());
app.use(morgan('combined'));

// ── Routes ─────────────────────────────────────────────────────────────────
app.use('/api/payments', paymentRoutes);

// ── Health check ───────────────────────────────────────────────────────────
app.get('/health', (_req, res) =>
  res.json({ status: 'payment-service-ok', timestamp: new Date().toISOString() })
);

// ── 404 ────────────────────────────────────────────────────────────────────
app.use((_req, res) => res.status(404).json({ error: 'Not found' }));

// ── Global error handler ───────────────────────────────────────────────────
app.use((err, _req, res, _next) => {
  console.error(err);
  res.status(err.status || 500).json({ error: err.message || 'Internal server error' });
});

// ── Start ──────────────────────────────────────────────────────────────────
if (require.main === module) {
  app.listen(PORT, () =>
    console.log(`Payment Service running on port ${PORT}`)
  );
}

module.exports = app;
