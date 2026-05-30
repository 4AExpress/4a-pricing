"""
COD integration tests — SPEC_COD.md Section 9 (8 cases).

Required env vars:
    TEST_BASE_URL       base URL of the API, e.g. https://example.com/api
    TEST_TOKEN          Bearer token for a regular user session
    ADMIN_TOKEN         Bearer token for an administrator session
    TEST_SETUP_SECRET   Secret that enables api/test_setup.php
"""
import os
import pytest
import requests

BASE_URL     = os.getenv("TEST_BASE_URL",  "http://localhost/api")
TEST_TOKEN   = os.getenv("TEST_TOKEN",     "")
ADMIN_TOKEN  = os.getenv("ADMIN_TOKEN",    "")


def api(path, method="GET", **kwargs):
    return requests.request(method, f"{BASE_URL}/{path}", **kwargs)


def user_auth():
    return {"Authorization": f"Bearer {TEST_TOKEN}"}


def admin_auth():
    return {"Authorization": f"Bearer {ADMIN_TOKEN}"}


# ── Case 1 ── GET /api/cod/services → S1003_GR has cod_capable=true ──────────

def test_case1_services_s1003_gr_cod_capable():
    r = api("cod/services")
    assert r.status_code == 200
    by_code = {s["code"]: s for s in r.json()}
    assert "S1003_GR" in by_code, "S1003_GR missing from services"
    assert by_code["S1003_GR"]["cod_capable"] is True


# ── Case 2 ── cod_amount=500 → fee=1.30 (flat, min_fee applies) ─────────────

def test_case2_calculate_flat_min_fee():
    r = api("cod/calculate", method="POST", json={"cod_amount": 500})
    assert r.status_code == 200
    data = r.json()
    assert data["cod_fee"] == 1.30
    assert data["method"] == "flat"


# ── Case 3 ── cod_amount=2000 → fee=20.00 (1 % of 2000) ─────────────────────

def test_case3_calculate_percentage():
    r = api("cod/calculate", method="POST", json={"cod_amount": 2000})
    assert r.status_code == 200
    data = r.json()
    assert data["cod_fee"] == 20.00
    assert data["method"] == "percentage"


# ── Case 4 ── cod_amount=0.50 → 422 with Greek message mentioning €1.30 ──────

@pytest.mark.parametrize("shipment", [["S1003_GR"]], indirect=True)
def test_case4_amount_below_min_fee_rejected(shipment):
    r = api(
        f"shipments/{shipment}/cod",
        method="POST",
        headers=user_auth(),
        json={"cod_enabled": True, "cod_amount": 0.50, "declared_value": 0.50},
    )
    assert r.status_code == 422
    assert "1.30" in r.json().get("error", ""), \
        f"Expected '1.30' in error message, got: {r.json()}"


# ── Case 5 ── 2 COD services → fee charged once (not twice) ──────────────────

@pytest.mark.parametrize("shipment", [["S1003_GR", "S1039"]], indirect=True)
def test_case5_fee_charged_once_regardless_of_service_count(shipment):
    r = api(
        f"shipments/{shipment}/cod",
        method="POST",
        headers=user_auth(),
        json={"cod_enabled": True, "cod_amount": 500, "declared_value": 500},
    )
    assert r.status_code == 200
    assert r.json()["cod_fee"] == 1.30, \
        f"Expected 1.30 (single charge), got {r.json()['cod_fee']}"


# ── Case 6 ── cod_amount≠declared_value → cod_override=True ─────────────────

@pytest.mark.parametrize("shipment", [["S1003_GR"]], indirect=True)
def test_case6_override_flag_when_amounts_differ(shipment):
    r = api(
        f"shipments/{shipment}/cod",
        method="POST",
        headers=user_auth(),
        json={"cod_enabled": True, "cod_amount": 600, "declared_value": 500},
    )
    assert r.status_code == 200
    data = r.json()
    assert data["cod_override"] is True, \
        "cod_override should be True when cod_amount != declared_value"


# ── Case 7 ── only S1003 (non-COD service) → 422 ────────────────────────────

@pytest.mark.parametrize("shipment", [["S1003"]], indirect=True)
def test_case7_non_cod_service_rejected(shipment):
    r = api(
        f"shipments/{shipment}/cod",
        method="POST",
        headers=user_auth(),
        json={"cod_enabled": True, "cod_amount": 500, "declared_value": 500},
    )
    assert r.status_code == 422
    assert "αντικαταβολή" in r.json().get("error", ""), \
        f"Expected Greek 'αντικαταβολή' in error, got: {r.json()}"


# ── Case 8 ── admin raises min_fee → calculate reflects new value ─────────────

def test_case8_admin_config_change_reflected_in_calculate():
    original = api("admin/cod/config", headers=admin_auth()).json()
    assert "min_fee" in original, "Could not read admin config"

    try:
        r = api(
            "admin/cod/config",
            method="PUT",
            headers=admin_auth(),
            json={
                "min_fee":     5.00,
                "default_fee": float(original["default_fee"]),
                "threshold":   float(original["threshold"]),
                "percentage":  float(original["percentage"]),
            },
        )
        assert r.status_code == 200

        r = api("cod/calculate", method="POST", json={"cod_amount": 500})
        assert r.status_code == 200
        assert r.json()["cod_fee"] == 5.00, \
            f"Expected 5.00 after config change, got {r.json()['cod_fee']}"

    finally:
        api(
            "admin/cod/config",
            method="PUT",
            headers=admin_auth(),
            json={
                "min_fee":     float(original["min_fee"]),
                "default_fee": float(original["default_fee"]),
                "threshold":   float(original["threshold"]),
                "percentage":  float(original["percentage"]),
            },
        )
