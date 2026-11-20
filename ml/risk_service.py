"""Loopback-only HTTP service for inference with the locally trained model."""

from __future__ import annotations

import json
import math
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

import joblib
import numpy as np


ROOT = Path(__file__).resolve().parents[1]
ARTIFACT_DIR = ROOT / "ml" / "artifacts"
MODEL_PATH = ARTIFACT_DIR / "academic_risk_model.joblib"
METRICS_PATH = ARTIFACT_DIR / "evaluation.json"
FEATURES = [
    "midtermGrade",
    "attendanceRate",
    "missingTasks",
    "capacityUtilization",
    "seteEngagement",
]
LABELS = {
    "midtermGrade": "Midterm grade average",
    "attendanceRate": "Class attendance rate",
    "missingTasks": "Missing / late portal submissions",
    "capacityUtilization": "Section capacity utilization",
    "seteEngagement": "SETE section response rate",
}
BOUNDS = {
    "midtermGrade": (60, 100),
    "attendanceRate": (50, 100),
    "missingTasks": (0, 10),
    "capacityUtilization": (70, 110),
    "seteEngagement": (40, 100),
}


def load_artifacts() -> tuple[dict | None, dict | None]:
    try:
        bundle = joblib.load(MODEL_PATH)
        metrics = json.loads(METRICS_PATH.read_text(encoding="utf-8"))
        if bundle.get("features") != FEATURES:
            raise ValueError("Model feature order does not match the service contract.")
        return bundle, metrics
    except Exception:
        return None, None


def status_for(probability: float) -> tuple[str, str]:
    if probability < 0.35:
        return "low", "Low Risk (On Target)"
    if probability < 0.65:
        return "moderate", "Moderate Risk (Warning)"
    return "high", "High Risk (Critical Intervention)"


def recommend_action(risk_band: str) -> str:
    if risk_band == "high":
        return "Schedule early academic advising and peer tutoring, then review support needs with the appropriate team."
    if risk_band == "moderate":
        return "Arrange an early check-in and review attendance, progress, and available course supports."
    return "Maintain current supports and review progress at the next academic checkpoint."


def predict(bundle: dict, inputs: dict[str, object]) -> dict[str, object]:
    if set(inputs) != set(FEATURES):
        raise ValueError("Provide all five supported prediction inputs.")
    values: dict[str, float] = {}
    for feature, (minimum, maximum) in BOUNDS.items():
        try:
            value = float(inputs[feature])
        except (TypeError, ValueError):
            raise ValueError(f"{LABELS[feature]} must be numeric.") from None
        if not math.isfinite(value) or not minimum <= value <= maximum:
            raise ValueError(f"{LABELS[feature]} must be from {minimum} to {maximum}.")
        if feature == "missingTasks" and not value.is_integer():
            raise ValueError("Missing / late portal submissions must be a whole number.")
        values[feature] = value

    pipeline = bundle["pipeline"]
    row = np.asarray([[values[name] for name in FEATURES]], dtype=float)
    probability = float(pipeline.predict_proba(row)[0, 1])
    risk_band, status_label = status_for(probability)

    scaler = pipeline.named_steps["scale"]
    classifier = pipeline.named_steps["logistic_regression"]
    standardized = (row[0] - scaler.mean_) / scaler.scale_
    contributions = classifier.coef_[0] * standardized
    drivers = [
        {
            "key": feature,
            "label": LABELS[feature],
            "direction": "risk-increasing" if contribution > 0 else "protective",
            "impact": round(float(contribution), 3),
        }
        for feature, contribution in zip(FEATURES, contributions)
    ]
    drivers.sort(key=lambda item: abs(item["impact"]), reverse=True)

    return {
        "probability": probability,
        "risk_band": risk_band,
        "status_label": status_label,
        "recommended_action": recommend_action(risk_band),
        "top_risk_drivers": drivers,
        "model_version": bundle["model_version"],
    }


class RiskRequestHandler(BaseHTTPRequestHandler):
    server_version = "CICSLocalRiskService/1.0"

    def log_message(self, _format: str, *_args: object) -> None:
        return

    def send_json(self, status: int, payload: dict[str, object]) -> None:
        content = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(content)))
        self.end_headers()
        self.wfile.write(content)

    def do_GET(self) -> None:
        if urlparse(self.path).path != "/health":
            self.send_json(404, {"error": "Not found."})
            return
        bundle, metrics = load_artifacts()
        if bundle is None:
            self.send_json(503, {"loaded": False, "error": "No locally trained model is available."})
            return
        self.send_json(200, {"loaded": True, "model_version": bundle["model_version"], "evaluation": metrics})

    def do_POST(self) -> None:
        if urlparse(self.path).path != "/predict":
            self.send_json(404, {"error": "Not found."})
            return
        bundle, _metrics = load_artifacts()
        if bundle is None:
            self.send_json(503, {"error": "No local model is available. Generate/import a dataset and train the model first."})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length < 1 or length > 16_384:
                raise ValueError("Request body must contain a small JSON input object.")
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            if not isinstance(payload, dict):
                raise ValueError("Prediction input must be a JSON object.")
            self.send_json(200, predict(bundle, payload))
        except (UnicodeDecodeError, json.JSONDecodeError):
            self.send_json(400, {"error": "Request body must be valid JSON."})
        except ValueError as error:
            self.send_json(400, {"error": str(error)})


def main() -> None:
    host = os.environ.get("RISK_MODEL_HOST", "127.0.0.1")
    if host not in {"127.0.0.1", "localhost"}:
        raise SystemExit("The risk model service must bind to loopback only.")
    port = int(os.environ.get("RISK_MODEL_PORT", "8765"))
    server = ThreadingHTTPServer(("127.0.0.1", port), RiskRequestHandler)
    print(f"Local CICS risk model service listening at http://127.0.0.1:{port}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
