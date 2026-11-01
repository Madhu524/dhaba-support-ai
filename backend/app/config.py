import os
from pathlib import Path
from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    APP_NAME: str = "Dhaba Support AI Triage"
    APP_VERSION: str = "1.0.0"
    ENV: str = "development"
    DEBUG: bool = True
    
    # Database
    DATABASE_URL: str = "sqlite:///./dhaba_support.db"
    
    # AI Service Configuration
    AI_PROVIDER: str = "fixture"  # Options: fixture, mock, gemini, openai, anthropic
    AI_API_KEY: str = ""
    AI_MODEL: str = "gemini-1.5-flash"
    AI_TIMEOUT_SECONDS: float = 4.0
    AI_MAX_RETRIES: int = 2
    
    # Circuit Breaker Configuration
    CB_FAILURE_THRESHOLD: int = 3
    CB_RECOVERY_TIME_SECONDS: float = 30.0
    
    # Path to reference tickets dataset
    FIXTURE_DATA_PATH: str = str(Path(__file__).resolve().parent.parent.parent / "data" / "tickets.json")

settings = Settings()
