# ML Sidecar (recommendation #6)

A FastAPI service that runs alongside PHP-FPM and serves an XGBoost
demand-forecasting model. Replaces the simple k-NN regression in
`ForecastController` with a trained model that improves as more orgs
feed it labelled training data.

## Why a sidecar

PHP-side k-NN regression is fine for v1 but caps out:
- No regularization (over-fits with few training points)
- No interaction features (income × density, age × competition, etc.)
- Hard to swap algorithms (LightGBM vs XGBoost vs a small MLP)

A 50-line FastAPI service in Python gets us scikit-learn / XGBoost
without crowbarring NumPy into PHP. PHP makes a single HTTP call to
`http://127.0.0.1:8088/forecast` and gets back a JSON response.

## Architecture

```
PHP (ForecastController)
   │  POST http://127.0.0.1:8088/forecast {candidate_features, training_data}
   ▼
FastAPI (uvicorn, 4 workers)
   │  loads xgboost model on startup
   │  serves prediction in < 50ms
   ▼
   JSON {predicted_revenue, ci_low, ci_high, feature_importance}
```

## File layout (when implemented)

```
ml-sidecar/
├── README.md             this file
├── requirements.txt      fastapi, uvicorn, xgboost, scikit-learn, numpy
├── app.py                FastAPI + endpoints
├── train.py              CLI: nightly retrain from postgres dump
├── models/
│   └── forecast.json     trained XGBoost model (versioned, swappable)
└── systemd/
    └── smappen-ml.service  uvicorn launcher
```

## Endpoints

`POST /forecast`
Body: `{ candidate: { features: [18 floats] }, training: [{ features: [...], revenue: 350000 }, ...] }`
Returns: `{ predicted_revenue, ci_low, ci_high, feature_importance: { name: weight }, model_version }`

`GET /health`
Returns `{ ok, model_version, model_loaded_at }`

`POST /train`
Admin-only. Triggers nightly retraining off the current training set.

## Wire-up plan

1. `pip install -r requirements.txt` on the droplet
2. Drop `app.py` skeleton + a placeholder `forecast.json` (returns
   weighted-mean baseline until trained)
3. Add systemd unit; bind to localhost only
4. Patch `ForecastController` to POST to the sidecar when
   `ML_SIDECAR_URL` env var is set; fall back to existing k-NN otherwise
5. Add a `/admin/ml-training` page that lets ops trigger retrain after
   they label more data

## Cost

- 4 vCPU * 16GB RAM Droplet is $96/mo if separate (probably overkill)
- 1GB carved out of the existing droplet runs fine for 4 uvicorn workers
- Per-request cost: ~0 (model is in-memory)
