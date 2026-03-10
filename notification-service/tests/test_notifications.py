"""
pytest tests for the Notification Service.
"""

import json
import pytest

# Make sure we're using an in-memory SQLite DB for tests
import os
os.environ["DATABASE_URL"] = "sqlite:///:memory:"

from app import app as flask_app, db


@pytest.fixture()
def app():
    flask_app.config["TESTING"] = True
    flask_app.config["SQLALCHEMY_DATABASE_URI"] = "sqlite:///:memory:"
    with flask_app.app_context():
        db.create_all()
        yield flask_app
        db.session.remove()
        db.drop_all()


@pytest.fixture()
def client(app):
    return app.test_client()


# ── Health check ──────────────────────────────────────────────────────────

def test_health(client):
    res = client.get("/health")
    assert res.status_code == 200
    data = res.get_json()
    assert data["status"] == "notification-service-ok"


# ── POST /api/notifications ───────────────────────────────────────────────

def test_send_order_confirmed_notification(client):
    payload = {
        "type":          "order_confirmed",
        "recipient":     "alice@example.com",
        "customer_name": "Alice Smith",
        "order_number":  "ORD-TEST001",
        "total_amount":  159.97,
        "payment_id":    "pay_000001",
    }
    res = client.post(
        "/api/notifications",
        data=json.dumps(payload),
        content_type="application/json",
    )
    assert res.status_code == 201
    data = res.get_json()
    assert data["type"] == "order_confirmed"
    assert data["recipient"] == "alice@example.com"
    assert data["status"] == "sent"
    assert "Order Confirmed" in data["subject"]


def test_send_notification_validates_email(client):
    res = client.post(
        "/api/notifications",
        data=json.dumps({"type": "test", "recipient": "not-an-email"}),
        content_type="application/json",
    )
    assert res.status_code == 422
    assert "errors" in res.get_json()


def test_send_notification_requires_type(client):
    res = client.post(
        "/api/notifications",
        data=json.dumps({"recipient": "x@test.com"}),
        content_type="application/json",
    )
    assert res.status_code == 422


# ── GET /api/notifications ────────────────────────────────────────────────

def test_list_notifications(client):
    # Create two notifications
    for i in range(2):
        client.post(
            "/api/notifications",
            data=json.dumps({
                "type":      "order_confirmed",
                "recipient": f"user{i}@example.com",
            }),
            content_type="application/json",
        )

    res = client.get("/api/notifications")
    assert res.status_code == 200
    data = res.get_json()
    assert data["total"] == 2
    assert len(data["data"]) == 2


def test_filter_notifications_by_recipient(client):
    client.post(
        "/api/notifications",
        data=json.dumps({"type": "t1", "recipient": "alice@example.com"}),
        content_type="application/json",
    )
    client.post(
        "/api/notifications",
        data=json.dumps({"type": "t2", "recipient": "bob@example.com"}),
        content_type="application/json",
    )

    res = client.get("/api/notifications?recipient=alice@example.com")
    assert res.status_code == 200
    data = res.get_json()
    assert all(n["recipient"] == "alice@example.com" for n in data["data"])


def test_filter_notifications_by_type(client):
    client.post(
        "/api/notifications",
        data=json.dumps({"type": "order_confirmed", "recipient": "a@test.com"}),
        content_type="application/json",
    )
    client.post(
        "/api/notifications",
        data=json.dumps({"type": "order_failed", "recipient": "b@test.com"}),
        content_type="application/json",
    )

    res = client.get("/api/notifications?type=order_failed")
    assert res.status_code == 200
    data = res.get_json()
    assert all(n["type"] == "order_failed" for n in data["data"])


# ── GET /api/notifications/<id> ───────────────────────────────────────────

def test_get_notification_by_id(client):
    create_res = client.post(
        "/api/notifications",
        data=json.dumps({"type": "test", "recipient": "z@test.com"}),
        content_type="application/json",
    )
    nid = create_res.get_json()["id"]

    res = client.get(f"/api/notifications/{nid}")
    assert res.status_code == 200
    assert res.get_json()["id"] == nid


def test_get_notification_404(client):
    res = client.get("/api/notifications/99999")
    assert res.status_code == 404


# ── DELETE /api/notifications/<id> ────────────────────────────────────────

def test_delete_notification(client):
    create_res = client.post(
        "/api/notifications",
        data=json.dumps({"type": "test", "recipient": "del@test.com"}),
        content_type="application/json",
    )
    nid = create_res.get_json()["id"]

    del_res = client.delete(f"/api/notifications/{nid}")
    assert del_res.status_code == 200

    get_res = client.get(f"/api/notifications/{nid}")
    assert get_res.status_code == 404
