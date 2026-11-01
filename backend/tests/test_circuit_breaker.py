import time
import pytest
from app.utils.circuit_breaker import CircuitBreaker, CircuitState

def test_circuit_breaker_transitions():
    cb = CircuitBreaker(failure_threshold=3, recovery_time_seconds=0.2)
    assert cb.get_state() == CircuitState.CLOSED.value
    assert cb.can_execute() is True

    # 1 failure
    cb.record_failure()
    assert cb.get_state() == CircuitState.CLOSED.value
    assert cb.can_execute() is True

    # 2 failures
    cb.record_failure()
    assert cb.get_state() == CircuitState.CLOSED.value

    # 3 failures -> Tripped to OPEN
    cb.record_failure()
    assert cb.get_state() == CircuitState.OPEN.value
    assert cb.can_execute() is False

    # Wait for recovery window (0.2s)
    time.sleep(0.25)
    assert cb.get_state() == CircuitState.HALF_OPEN.value
    assert cb.can_execute() is True

    # Successful call in HALF_OPEN recovers to CLOSED
    cb.record_success()
    assert cb.get_state() == CircuitState.CLOSED.value
    assert cb.failure_count == 0

def test_circuit_breaker_trip_and_reset():
    cb = CircuitBreaker(failure_threshold=2, recovery_time_seconds=10.0)
    cb.trip()
    assert cb.get_state() == CircuitState.OPEN.value
    assert cb.can_execute() is False

    cb.reset()
    assert cb.get_state() == CircuitState.CLOSED.value
    assert cb.can_execute() is True
