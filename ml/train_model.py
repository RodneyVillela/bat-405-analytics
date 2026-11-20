"""Train and evaluate the CICS academic difficulty Logistic Regression model."""

from __future__ import annotations

import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import joblib
import pandas as pd
import pymysql
from sklearn.metrics import accuracy_score, precision_score, recall_score, roc_auc_score
from sklearn.model_selection import StratifiedGroupKFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.linear_model import LogisticRegression


ROOT = Path(__file__).resolve().parents[1]
ARTIFACT_DIR = ROOT / "ml" / "artifacts"
MODEL_PATH = ARTIFACT_DIR / "academic_risk_model.joblib"
METRICS_PATH = ARTIFACT_DIR / "evaluation.json"
MODEL_VERSION = "cics-risk-logreg-v1"
LABEL = "academic_difficulty_outcome"
FEATURES = [
    "midterm_grade_average",
    "attendance_rate",
    "missing_portal_submissions",
    "section_capacity_utilization",
    "sete_response_rate",
]
REQUIRED_COLUMNS = [
    "student_key",
    "section_code",
    "academic_year",
    "semester",
    "midterm_grade_average",
    "attendance_rate",
    "missing_portal_submissions",
    "section_enrollment",
    "section_capacity",
    "sete_response_rate",
    LABEL,
    "outcome_definition",
]


class DatasetError(ValueError):
    """Raised when source data cannot support a defensible model run."""


def load_dataset() -> tuple[pd.DataFrame, pd.Series, pd.Series]:
    host = os.environ.get("BAT405_DB_HOST", "127.0.0.1")
    database = os.environ.get("BAT405_DB_NAME", "bat405_analytics")
    username = os.environ.get("BAT405_DB_USER", "root")
    password = os.environ.get("BAT405_DB_PASSWORD", "")
    port = int(os.environ.get("BAT405_DB_PORT", "3306"))
    try:
        connection = pymysql.connect(
            host=host,
            port=port,
            database=database,
            user=username,
            password=password,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=5,
        )
        with connection.cursor() as cursor:
            cursor.execute(
                """SELECT student_key, section_code, academic_year, semester,
                          midterm_grade_average, attendance_rate,
                          missing_portal_submissions, section_enrollment,
                          section_capacity, sete_response_rate,
                          academic_difficulty_outcome, outcome_definition,
                          100.0 * section_enrollment / section_capacity AS section_capacity_utilization
                   FROM academic_risk_training_records
                   ORDER BY student_key, academic_year, semester"""
            )
            rows = cursor.fetchall()
    except pymysql.MySQLError as error:
        raise DatasetError(
            "Could not read academic_risk_training_records from MySQL. Start the local "
            "database, apply migrations/001_academic_risk_training.sql, and import the "
            "de-identified CICS CSV."
        ) from error
    finally:
        if "connection" in locals():
            connection.close()

    frame = pd.DataFrame(rows)
    if frame.empty:
        raise DatasetError(
            "No labeled records in academic_risk_training_records. Import the "
            "de-identified CICS CSV before training."
        )
    frame["student_key"] = frame["student_key"].astype("string")
    missing_columns = sorted(set(REQUIRED_COLUMNS) - set(frame.columns))
    if missing_columns:
        raise DatasetError("Missing required columns: " + ", ".join(missing_columns))
    if frame[REQUIRED_COLUMNS].isna().any().any():
        raise DatasetError("Missing values found in required fields; resolve them before training.")
    if frame["student_key"].astype(str).str.strip().eq("").any():
        raise DatasetError("student_key values must be non-empty pseudonymous identifiers.")
    if frame.duplicated(["student_key", "section_code", "academic_year", "semester"]).any():
        raise DatasetError("Duplicate student/section/term records found.")
    if frame["outcome_definition"].astype(str).str.strip().nunique() != 1:
        raise DatasetError("Use one consistent, approved outcome_definition for all rows.")

    numeric_columns = [
        "midterm_grade_average",
        "attendance_rate",
        "missing_portal_submissions",
        "section_enrollment",
        "section_capacity",
        "sete_response_rate",
        LABEL,
    ]
    for column in numeric_columns:
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    if frame[numeric_columns].isna().any().any():
        raise DatasetError("Numeric fields contain blank or non-numeric values.")

    for column in ("midterm_grade_average", "attendance_rate", "sete_response_rate"):
        if not frame[column].between(0, 100).all():
            raise DatasetError(f"{column} must be between 0 and 100.")
    if not frame["missing_portal_submissions"].between(0, 10).all() or not (
        frame["missing_portal_submissions"] % 1 == 0
    ).all():
        raise DatasetError("missing_portal_submissions must be an integer from 0 to 10.")
    if (frame["section_enrollment"] < 0).any() or (frame["section_capacity"] <= 0).any():
        raise DatasetError("Section enrollment must be >= 0 and section capacity must be > 0.")
    if not frame["academic_difficulty_outcome"].isin([0, 1]).all():
        raise DatasetError("academic_difficulty_outcome must use 0 or 1 only.")

    frame["section_capacity_utilization"] = (
        100 * frame["section_enrollment"] / frame["section_capacity"]
    )
    target = frame[LABEL].astype(int)
    groups = frame["student_key"].astype(str)
    for class_value in (0, 1):
        class_groups = groups[target == class_value].nunique()
        if class_groups < 5:
            raise DatasetError(
                "At least five distinct student_key values are required for each outcome "
                "class to create a grouped five-fold holdout."
            )
    if target.nunique() != 2:
        raise DatasetError("The outcome must contain both 0 and 1 labels.")

    return frame, target, groups


def train_and_evaluate() -> dict[str, object]:
    frame, target, groups = load_dataset()
    x = frame[FEATURES].astype(float)

    splitter = StratifiedGroupKFold(n_splits=5, shuffle=True, random_state=42)
    train_indices = test_indices = None
    for candidate_train, candidate_test in splitter.split(x, target, groups):
        if target.iloc[candidate_train].nunique() == 2 and target.iloc[candidate_test].nunique() == 2:
            train_indices, test_indices = candidate_train, candidate_test
            break
    if train_indices is None or test_indices is None:
        raise DatasetError(
            "Could not form a student-grouped train/test split containing both outcomes. "
            "Add more distinct students with each outcome."
        )

    pipeline = Pipeline(
        [
            ("scale", StandardScaler()),
            (
                "logistic_regression",
                LogisticRegression(max_iter=2000, random_state=42, solver="lbfgs"),
            ),
        ]
    )
    pipeline.fit(x.iloc[train_indices].to_numpy(), target.iloc[train_indices])

    x_test = x.iloc[test_indices]
    y_test = target.iloc[test_indices]
    probabilities = pipeline.predict_proba(x_test.to_numpy())[:, 1]
    predicted = (probabilities >= 0.5).astype(int)
    evaluation = {
        "model": "Logistic Regression",
        "model_version": MODEL_VERSION,
        "training_source": "academic_risk_training_records (MySQL)",
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
        "validation_method": "One stratified five-fold holdout, grouped by student_key",
        "trained_at_utc": datetime.now(timezone.utc).isoformat(),
        "features": FEATURES,
        "outcome_definition": str(frame["outcome_definition"].iloc[0]),
        "risk_bands": {"low_below": 0.35, "moderate_below": 0.65, "high_from": 0.65},
    }

    ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)
    joblib.dump(
        {
            "pipeline": pipeline,
            "features": FEATURES,
            "model_version": MODEL_VERSION,
            "trained_at_utc": evaluation["trained_at_utc"],
        },
        MODEL_PATH,
    )
    METRICS_PATH.write_text(json.dumps(evaluation, indent=2) + "\n", encoding="utf-8")
    return evaluation


if __name__ == "__main__":
    try:
        results = train_and_evaluate()
    except DatasetError as error:
        print(f"Training stopped: {error}", file=sys.stderr)
        raise SystemExit(2)
    print(json.dumps(results, indent=2))
