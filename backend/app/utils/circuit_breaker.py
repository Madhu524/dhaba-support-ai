import time
from enum import Enum
from typing import Optional

class CircuitState(str, Enum):
    CLOSED = "CLOSED"
    OPEN = "OPEN"
    HALF_OPEN = "HALF_OPEN"

class CircuitBreaker:
    def __init__(self, failure_threshold: int = 3, recovery_time_seconds: float = 30.0):
        self.failure_threshold = failure_threshold
        self.recovery_time_seconds = recovery_time_seconds
        self.state = CircuitState.CLOSED
        self.failure_count = 0
        self.last_state_change = time.time()
        self.opened_at: Optional[float] = None

    def can_execute(self) -> bool:
        """Determines whether a call should be attempted or degraded."""
        now = time.time()
        if self.state == CircuitState.OPEN:
            if self.opened_at and (now - self.opened_at >= self.recovery_time_seconds):
                self.state = CircuitState.HALF_OPEN
                self.last_state_change = now
                return True
            return False
        return True

    def record_success(self) -> None:
        """Records a successful execution, resetting circuit breaker to CLOSED."""
        self.failure_count = 0
        if self.state != CircuitState.CLOSED:
            self.state = CircuitState.CLOSED
            self.last_state_change = time.time()
            self.opened_at = None

    def record_failure(self) -> None:
        """Records a failure. Trips circuit to OPEN if threshold is reached."""
        self.failure_count += 1
        now = time.time()
        if self.state == CircuitState.HALF_OPEN or self.failure_count >= self.failure_threshold:
            self.state = CircuitState.OPEN
            self.opened_at = now
            self.last_state_change = now

    def trip(self) -> None:
        """Manually trip the circuit breaker into OPEN state (useful for tests and outages)."""
        self.state = CircuitState.OPEN
        self.opened_at = time.time()
        self.last_state_change = self.opened_at

    def reset(self) -> None:
        """Reset the circuit breaker to clean CLOSED state."""
        self.state = CircuitState.CLOSED
        self.failure_count = 0
        self.opened_at = None
        self.last_state_change = time.time()

    def get_state(self) -> str:
        # Check if OPEN has expired into HALF_OPEN
        if self.state == CircuitState.OPEN and self.opened_at:
            if time.time() - self.opened_at >= self.recovery_time_seconds:
                return CircuitState.HALF_OPEN.value
        return self.state.value

# Global singleton circuit breaker for AI provider
circuit_breaker = CircuitBreaker()
