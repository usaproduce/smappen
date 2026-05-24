"""
FastAPI ML sidecar — recommendation #6.

v1 skeleton: serves a baseline weighted-mean forecast until a real
XGBoost model is trained. Same response contract as the future
trained-model version so the PHP caller doesn't need to change.

Run:
    uvicorn app:app --host 127.0.0.1 --port 8088 --workers 4
"""
from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import List, Optional

from fastapi import FastAPI
from pydantic import BaseModel

MODEL_VERSION = "baseline-0.1"
MODELS_DIR = Path(__file__).parent / "models"
LOADED_AT = time.time()

app = FastAPI(title="Smappen ML sidecar", version=MODEL_VERSION)


class FeatureBundle(BaseModel):
    features: List[Optional[float]]


class TrainingPoint(BaseModel):
    features: List[Optional[float]]
    revenue: float


class ForecastRequest(BaseModel):
    candidate: FeatureBundle
    training: List[TrainingPoint]


class ForecastResponse(BaseModel):
    predicted_revenue: float
    ci_low: float
    ci_high: float
    feature_importance: dict
    model_version: str


def cosine(a: List[Optional[float]], b: List[Optional[float]]) -> float:
    """Cosine similarity with null-pair skipping (matches AnalogService)."""
    dot = 0.0
    ma = 0.0
    mb = 0.0
    for x, y in zip(a, b):
        if x is None or y is None:
            continue
        dot += x * y
        ma += x * x
        mb += y * y
    denom = (ma ** 0.5) * (mb ** 0.5)
    return dot / denom if denom > 0 else 0.0


@app.post("/forecast", response_model=ForecastResponse)
def forecast(req: ForecastRequest):
    # Baseline: similarity-weighted mean. Replace with model.predict()
    # once we train one.
    sims = [cosine(req.candidate.features, t.features) for t in req.training]
    weights = [max(0.001, s) for s in sims]
    total_w = sum(weights)
    pred = sum(w * t.revenue for w, t in zip(weights, req.training)) / total_w if total_w else 0.0
    var = sum(w * (t.revenue - pred) ** 2 for w, t in zip(weights, req.training)) / total_w if total_w else 0.0
    std = var ** 0.5
    return ForecastResponse(
        predicted_revenue=round(pred, 2),
        ci_low=round(pred - 1.96 * std, 2),
        ci_high=round(pred + 1.96 * std, 2),
        feature_importance={f"f{i}": 1.0 / len(req.candidate.features) for i in range(len(req.candidate.features))},
        model_version=MODEL_VERSION,
    )


@app.get("/health")
def health():
    return {
        "ok": True,
        "model_version": MODEL_VERSION,
        "loaded_at": LOADED_AT,
        "uptime_sec": int(time.time() - LOADED_AT),
    }
