"""Train and evaluate the local CICS academic difficulty Logistic Regression."""

from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import joblib
import pandas as pd
import pymysql
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, precision_score, recall_score, roc_auc_score
from sklearn.model_selection import StratifiedGroupKFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler


ROOT = Path(__file__).resolve().parents[1]
ARTIFACT_DIR = ROOT / "ml" / "artifacts"
MODEL_PATH = ARTIFACT_DIR / "academic_risk_model.joblib"
METRICS_PATH = ARTIFACT_DIR / "evaluation.json"
MODEL_VERSION = "cics-risk-logreg-v2"
LABEL = "academic_difficulty_outcome"
FEATURES = ["midtermGrade", "attendanceRate", "missingTasks", "capacityUtilization", "seteEngagement"]
REQUIRED_COLUMNS = [
    "student_key", "section_code", "academic_year", "semester", *FEATURES,
    LABEL, "outcome_definition", "source_dataset",
]


class DatasetError(ValueError):
    """Raised when the selected local dataset cannot support training."""


def load_dataset(source_dataset: str) -> tuple[pd.DataFrame, pd.Series, pd.Series]:
    host = os.environ.get("SPARTAN_DB_HOST", "127.0.0.1")
    database = os.environ.get("SPARTAN_DB_NAME", "spartan_insight")
    username = os.environ.get("SPARTAN_DB_USER", "root")
    password = os.environ.get("SPARTAN_DB_PASSWORD", "")
    port = int(os.environ.get("SPARTAN_DB_PORT", "3306"))
    try:
        connection = pymysql.connect(
            host=host, port=port, database=database, user=username, password=password,
            charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor, connect_timeout=5,
        )
        with connection.cursor() as cursor:
            cursor.execute(
                """SELECT student_key, section_code, academic_year, semester,
                          midtermGrade, attendanceRate, missingTasks,
                          capacityUtilization, seteEngagement,
                          academic_difficulty_outcome, outcome_definition, source_dataset
                   FROM academic_risk_training_records
                   WHERE source_dataset = %s
                   ORDER BY student_key, academic_year, semester""",
                (source_dataset,),
            )
            rows = cursor.fetchall()
    except pymysql.MySQLError as error:
        raise DatasetError(
            "Could not read spartan_insight.academic_risk_training_records. Start local MySQL, "
            "apply migrations/002_spartan_insight_risk_training.sql, and import the CSV."
        ) from error
    finally:
        if "connection" in locals():
            connection.close()

    frame = pd.DataFrame(rows)
    if frame.empty:
        raise DatasetError(
            f"No records for source_dataset={source_dataset!r}. Generate/import the selected data first."
        )
    missing_columns = sorted(set(REQUIRED_COLUMNS) - set(frame.columns))
    if missing_columns:
        raise DatasetError("Missing required columns: " + ", ".join(missing_columns))
    if frame[REQUIRED_COLUMNS].isna().any().any():
        raise DatasetError("Missing values found in required fields.")
    frame["student_key"] = frame["student_key"].astype("string")
    if frame["student_key"].astype(str).str.strip().eq("").any():
        raise DatasetError("student_key values must be non-empty pseudonymous identifiers.")
    if frame["source_dataset"].astype(str).nunique() != 1:
        raise DatasetError("The query returned more than one dataset source.")
    if frame["outcome_definition"].astype(str).str.strip().nunique() != 1:
        raise DatasetError("Use one consistent outcome_definition for all rows.")
    if frame.duplicated(["student_key", "section_code", "academic_year", "semester"]).any():
        raise DatasetError("Duplicate student/section/term records found.")

    for column in [*FEATURES, LABEL]:
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    if frame[[*FEATURES, LABEL]].isna().any().any():
        raise DatasetError("Model features and target must be numeric.")
    for column in ("midtermGrade", "attendanceRate", "seteEngagement"):
        if not frame[column].between(0, 100).all():
            raise DatasetError(f"{column} must be between 0 and 100.")
    if not frame["missingTasks"].between(0, 10).all() or not (frame["missingTasks"] % 1 == 0).all():
        raise DatasetError("missingTasks must be an integer from 0 to 10.")
    if not frame["capacityUtilization"].between(70, 110).all():
        raise DatasetError("capacityUtilization must be between 70 and 110.")

    target = frame[LABEL].astype(int)
    groups = frame["student_key"].astype(str)
    if not target.isin([0, 1]).all() or target.nunique() != 2:
        raise DatasetError("The outcome must contain both 0 and 1 labels only.")
    for class_value in (0, 1):
        if groups[target == class_value].nunique() < 5:
            raise DatasetError("At least five distinct student keys are required for each outcome class.")
    return frame, target, groups


def train_and_evaluate(source_dataset: str) -> dict[str, object]:
    frame, target, groups = load_dataset(source_dataset)
    x = frame[FEATURES].astype(float)
    splitter = StratifiedGroupKFold(n_splits=5, shuffle=True, random_state=42)
    train_indices = test_indices = None
    for candidate_train, candidate_test in splitter.split(x, target, groups):
        if target.iloc[candidate_train].nunique() == 2 and target.iloc[candidate_test].nunique() == 2:
            train_indices, test_indices = candidate_train, candidate_test
            break
    if train_indices is None or test_indices is None:
        raise DatasetError("Could not form a grouped holdout containing both outcome labels.")

    pipeline = Pipeline([
        ("scale", StandardScaler()),
        ("logistic_regression", LogisticRegression(max_iter=2000, random_state=42, solver="lbfgs")),
    ])
    pipeline.fit(x.iloc[train_indices].to_numpy(), target.iloc[train_indices])
    x_test = x.iloc[test_indices]
    y_test = target.iloc[test_indices]
    probabilities = pipeline.predict_proba(x_test.to_numpy())[:, 1]
    predicted = (probabilities >= 0.5).astype(int)
    is_synthetic = source_dataset.startswith("SYNTHETIC_")
    evaluation = {
        "model": "Logistic Regression",
        "model_version": MODEL_VERSION,
        "training_database": "spartan_insight",
        "training_table": "academic_risk_training_records",
        "training_source": source_dataset,
        "synthetic_prototype_data": is_synthetic,
        "interpretation_notice": (
            "Prototype simulation only: features and target labels are synthetic; metrics do not measure real student outcomes."
            if is_synthetic else "Metrics describe the held-out records and do not guarantee future performance."
        ),
        "training_records": int(len(frame)),
        "unique_students": int(groups.nunique()),
        "holdout_records": int(len(test_indices)),
        "holdout_students": int(groups.iloc[test_indices].nunique()),
        "positive_outcome": "academic_difficulty_outcome = 1",
        "evaluation_threshold": 0.5,
        "accuracy": round(float(accuracy_score(y_test, predicted)), 4),
        "precision": round(float(precision_score(y_test, predicted, zero_division=0)), 4),
        "recall": round(float(recall_score(y_test, predicted, zero_division=0)), 4),
        "roc_auc": round(float(roc_auc_score(y_test, probabilities)), 4),
        "validation_method": "One held-out fold from stratified five-fold splits, grouped by student_key",
        "trained_at_utc": datetime.now(timezone.utc).isoformat(),
        "features": FEATURES,
        "outcome_definition": str(frame["outcome_definition"].iloc[0]),
        "risk_bands": {"low_below": 0.35, "moderate_below": 0.65, "high_from": 0.65},
    }
    ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)
    joblib.dump({
        "pipeline": pipeline, "features": FEATURES, "model_version": MODEL_VERSION,
        "training_source": source_dataset, "trained_at_utc": evaluation["trained_at_utc"],
    }, MODEL_PATH)
    METRICS_PATH.write_text(json.dumps(evaluation, indent=2) + "\n", encoding="utf-8")
    return evaluation


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", default=os.environ.get("RISK_TRAINING_SOURCE", "SYNTHETIC_CICS_DB"))
    args = parser.parse_args()
    try:
        results = train_and_evaluate(args.source)
    except DatasetError as error:
        print(f"Training stopped: {error}", file=sys.stderr)
        raise SystemExit(2)
    print(json.dumps(results, indent=2))
