import os
import pytest
import requests

BASE_URL     = os.getenv("TEST_BASE_URL",     "http://localhost/api")
SETUP_SECRET = os.getenv("TEST_SETUP_SECRET", "")


@pytest.fixture
def shipment(request):
    """
    Creates a test shipment with the given services, yields its DB id,
    then deletes it unconditionally.

    Usage:
        @pytest.mark.parametrize("shipment", [["S1003_GR"]], indirect=True)
        def test_foo(shipment): ...
    """
    services = getattr(request, "param", ["S1003_GR"])

    r = requests.post(
        f"{BASE_URL}/test_setup.php",
        json={"secret": SETUP_SECRET, "action": "create_shipment", "services": services},
    )
    r.raise_for_status()
    ship_id = r.json()["id"]

    yield ship_id

    requests.post(
        f"{BASE_URL}/test_setup.php",
        json={"secret": SETUP_SECRET, "action": "delete_shipment", "id": ship_id},
    )
