import time
from contextlib import asynccontextmanager
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from app.config import settings
from app.database import init_db
from app.routes import triage, tickets, metrics
from app.utils.logger import logger

@asynccontextmanager
async def lifespan(app: FastAPI):
    init_db()
    logger.info(
        f"Dhaba Support AI started in {settings.ENV} mode. AI Provider: {settings.AI_PROVIDER}",
        extra={"event": "app_startup"}
    )
    yield

app = FastAPI(
    title=settings.APP_NAME,
    version=settings.APP_VERSION,
    description="Intelligent Support Triage System for Dhaba Food App - Propel Assessment",
    lifespan=lifespan
)

# Enable CORS for React Vite dashboard
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.middleware("http")
async def safe_logging_middleware(request: Request, call_next):
    start_time = time.time()
    response = await call_next(request)
    duration_ms = int((time.time() - start_time) * 1000)
    
    # Exclude high-frequency health checks from verbose logging
    if request.url.path not in ["/health"]:
        logger.info(
            f"{request.method} {request.url.path} responded with {response.status_code} in {duration_ms}ms",
            extra={
                "status_code": response.status_code,
                "latency_ms": duration_ms,
                "event": "http_request"
            }
        )
    return response

# Include route modules
app.include_router(triage.router)
app.include_router(tickets.router)
app.include_router(metrics.router)
