"""
Notification Service – Python / Flask microservice.

Responsibilities:
  - Receive notification requests from other services (Order Service, etc.)
  - Store notification history in SQLite (dev) / PostgreSQL (prod)
  - Support CRUD on notifications with filtering

Routes:
  POST   /api/notifications            Send a notification
  GET    /api/notifications            List notifications (filter by type, recipient, status)
  GET    /api/notifications/<id>       Get a single notification
  DELETE /api/notifications/<id>       Delete a notification record
  GET    /health                       Health check
"""

import os
from datetime import datetime, timezone

from flask import Flask, jsonify, request
from flask_sqlalchemy import SQLAlchemy
from marshmallow import Schema, ValidationError, fields, validate

# ── App setup ──────────────────────────────────────────────────────────────

app = Flask(__name__)

DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///notifications.db")
app.config["SQLALCHEMY_DATABASE_URI"] = DATABASE_URL
app.config["SQLALCHEMY_TRACK_MODIFICATIONS"] = False

db = SQLAlchemy(app)

# ── Model ──────────────────────────────────────────────────────────────────


class Notification(db.Model):
    """
    Persisted notification record.

    status values:
      sent      – successfully dispatched (simulated here)
      failed    – dispatch failed
      cancelled – suppressed / compensated
    """

    __tablename__ = "notifications"

    id             = db.Column(db.Integer, primary_key=True)
    type           = db.Column(db.String(100), nullable=False, index=True)
    recipient      = db.Column(db.String(255), nullable=False, index=True)
    customer_name  = db.Column(db.String(255))
    subject        = db.Column(db.String(500))
    body           = db.Column(db.Text)
    status         = db.Column(db.String(50), default="sent", index=True)
    metadata_json  = db.Column(db.Text)          # JSON blob for extra fields
    created_at     = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc))

    def to_dict(self):
        return {
            "id":            self.id,
            "type":          self.type,
            "recipient":     self.recipient,
            "customer_name": self.customer_name,
            "subject":       self.subject,
            "body":          self.body,
            "status":        self.status,
            "metadata":      self.metadata_json,
            "created_at":    self.created_at.isoformat() if self.created_at else None,
        }


# ── Schema (validation) ────────────────────────────────────────────────────


class NotificationSchema(Schema):
    type           = fields.Str(required=True, validate=validate.Length(min=1, max=100))
    recipient      = fields.Email(required=True)
    customer_name  = fields.Str(load_default=None)
    order_number   = fields.Str(load_default=None)
    total_amount   = fields.Float(load_default=None)
    payment_id     = fields.Str(load_default=None)
    message        = fields.Str(load_default=None)


notification_schema = NotificationSchema()


# ── Helpers ────────────────────────────────────────────────────────────────

def _build_subject_and_body(data: dict) -> tuple[str, str]:
    """Generate human-readable subject and body from notification data."""
    ntype = data.get("type", "notification")

    if ntype == "order_confirmed":
        subject = f"Order Confirmed – {data.get('order_number', '')}"
        total = data.get('total_amount') or 0
        body = (
            f"Hi {data.get('customer_name', 'Customer')},\n\n"
            f"Your order {data.get('order_number', 'N/A')} has been confirmed.\n"
            f"Total: ${float(total):.2f}\n"
            f"Payment ID: {data.get('payment_id', 'N/A')}\n\n"
            "Thank you for your purchase!"
        )
    elif ntype == "order_failed":
        subject = f"Order Failed – {data.get('order_number', '')}"
        body = (
            f"Hi {data.get('customer_name', 'Customer')},\n\n"
            f"Unfortunately your order {data.get('order_number')} could not be processed.\n"
            f"Reason: {data.get('message', 'Unknown error')}"
        )
    else:
        subject = f"Notification: {ntype}"
        body = data.get("message", "No message body provided.")

    return subject, body


import json


# ── Routes ─────────────────────────────────────────────────────────────────

@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "notification-service-ok", "timestamp": datetime.now(timezone.utc).isoformat()})


@app.route("/api/notifications", methods=["POST"])
def send_notification():
    """
    Send a notification.

    Request:
    {
      "type":          "order_confirmed",
      "recipient":     "alice@example.com",
      "customer_name": "Alice Smith",
      "order_number":  "ORD-ABCD1234",
      "total_amount":  159.97,
      "payment_id":    "pay_000001"
    }

    Response (201):
    {
      "id": 1,
      "type": "order_confirmed",
      "recipient": "alice@example.com",
      "status": "sent",
      ...
    }
    """
    try:
        data = notification_schema.load(request.get_json(force=True) or {})
    except ValidationError as err:
        return jsonify({"errors": err.messages}), 422

    subject, body = _build_subject_and_body(data)

    # In production, call an SMTP/SMS/Push gateway here.
    # For this demo we persist the record and consider it "sent".
    notification = Notification(
        type          = data["type"],
        recipient     = data["recipient"],
        customer_name = data.get("customer_name"),
        subject       = subject,
        body          = body,
        status        = "sent",
        metadata_json = json.dumps({
            k: v for k, v in data.items()
            if k not in ("type", "recipient", "customer_name")
        }),
    )
    db.session.add(notification)
    db.session.commit()

    return jsonify(notification.to_dict()), 201


@app.route("/api/notifications", methods=["GET"])
def list_notifications():
    """
    List notifications with optional filters.

    Query params:
      type       (e.g. order_confirmed)
      recipient  (email)
      status     (sent|failed|cancelled)
      page       (default 1)
      per_page   (default 20)
    """
    query = Notification.query

    if ntype := request.args.get("type"):
        query = query.filter(Notification.type == ntype)
    if recipient := request.args.get("recipient"):
        query = query.filter(Notification.recipient == recipient)
    if status := request.args.get("status"):
        query = query.filter(Notification.status == status)

    page     = int(request.args.get("page", 1))
    per_page = int(request.args.get("per_page", 20))

    pagination = query.order_by(Notification.created_at.desc()).paginate(
        page=page, per_page=per_page, error_out=False
    )

    return jsonify({
        "data":       [n.to_dict() for n in pagination.items],
        "total":      pagination.total,
        "page":       pagination.page,
        "per_page":   pagination.per_page,
        "pages":      pagination.pages,
    })


@app.route("/api/notifications/<int:notification_id>", methods=["GET"])
def get_notification(notification_id):
    notification = db.get_or_404(Notification, notification_id)
    return jsonify(notification.to_dict())


@app.route("/api/notifications/<int:notification_id>", methods=["DELETE"])
def delete_notification(notification_id):
    notification = db.get_or_404(Notification, notification_id)
    db.session.delete(notification)
    db.session.commit()
    return jsonify({"message": "Notification deleted."})


# ── Startup ────────────────────────────────────────────────────────────────

with app.app_context():
    db.create_all()

if __name__ == "__main__":
    port = int(os.getenv("PORT", 5000))
    app.run(host="0.0.0.0", port=port, debug=os.getenv("FLASK_ENV") == "development")
