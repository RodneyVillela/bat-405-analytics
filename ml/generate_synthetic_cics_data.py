"""Build 500 synthetic prototype records anchored to spartan_insight section metrics.

This script creates no real student data. Midterm grades, attendance, missing
tasks, row-level variation, and target labels are simulated. Capacity and SETE
engagement start from the database's section-term aggregates and receive small
synthetic variation for a useful training range.
"""

from __future__ import annotations

import csv
import hashlib
import math
import os
import random
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "data" / "academic_risk_training.csv"
ROW_COUNT = 500
RANDOM_SEED = 405
SOURCE_DATASET = "SYNTHETIC_CICS_DB"
OUTCOME_DEFINITION = (
    "Synthetic prototype label sampled from a five-feature difficulty-risk rule; "
    "not an observed student outcome."
)
CSV_COLUMNS = [
    "student_key",
    "section_code",
    "academic_year",
    "semester",
    "midtermGrade",
    "attendanceRate",
    "missingTasks",
    "capacityUtilization",
    "seteEngagement",
    "academic_difficulty_outcome",
    "outcome_definition",
    "source_dataset",
]


def connect_to_spartan_insight():
    return pymysql.connect(
        host=os.environ.get("SPARTAN_DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("SPARTAN_DB_PORT", "3306")),
        database=os.environ.get("SPARTAN_DB_NAME", "spartan_insight"),
        user=os.environ.get("SPARTAN_DB_USER", "root"),
        password=os.environ.get("SPARTAN_DB_PASSWORD", ""),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=5,
    )


def clip(value: float, low: float, high: float) -> float:
    return min(high, max(low, value))


def sigmoid(value: float) -> float:
    if value >= 0:
        return 1 / (1 + math.exp(-value))
    exp_value = math.exp(value)
    return exp_value / (1 + exp_value)


def load_section_profiles() -> list[dict[str, object]]:
    connection = connect_to_spartan_insight()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """SELECT s.id AS section_code, s.dropout_count,
                          td.academic_year, td.semester,
                          m.enrolled, m.roster, m.responses, m.invited
                   FROM sections s
                   JOIN section_term_metrics m ON m.section_id = s.id
                   JOIN term_deadlines td ON td.term_key = m.term_key
                   ORDER BY s.id, td.academic_year, td.semester"""
            )
            profiles = cursor.fetchall()
    finally:
        connection.close()

    if len(profiles) < 10:
        raise RuntimeError(
            "spartan_insight must contain section_term_metrics for the CICS sections before generating the prototype CSV."
        )
    return profiles


def build_rows(profiles: list[dict[str, object]]) -> list[dict[str, object]]:
    rng = random.Random(RANDOM_SEED)
    profile_rng = random.Random(RANDOM_SEED ^ 0xC1C5)
    rows: list[dict[str, object]] = []
    for index in range(1, ROW_COUNT + 1):
        profile = profile_rng.choice(profiles)
        dropout_count = int(profile["dropout_count"])
        enrolled = int(profile["enrolled"])
        roster = max(1, int(profile["roster"]))
        invited = max(1, int(profile["invited"]))
        capacity_base = 100 * enrolled / roster
        sete_base = 100 * int(profile["responses"]) / invited

        # Three student-level fields are simulated because the source SQL has
        # no midterm, class attendance, or student portal submission records.
        midterm = round(clip(rng.gauss(78 - 1.0 * dropout_count, 9), 60, 100), 1)
        attendance = round(clip(rng.gauss(84 - 1.5 * dropout_count, 10), 50, 100), 1)
        missing_lambda = 1.5 + 0.45 * dropout_count + max(0, (75 - sete_base) / 30)
        missing_tasks = min(10, max(0, round(rng.gauss(missing_lambda, 1.5))))

        # The 80 real section-term capacity/SETE summaries anchor these values.
        # Some capacity rows get a simulated +7 point load scenario so the
        # prototype can learn the requested over-95% warning behavior.
        capacity_shift = 7 if rng.random() < 0.45 else 0
        capacity_spread = 5.5 if capacity_shift else 4
        capacity = round(clip(rng.gauss(capacity_base + capacity_shift, capacity_spread), 70, 110), 1)
        sete = round(clip(rng.gauss(sete_base, 4), 40, 100), 1)

        logit = (
            -1.7
            + 0.13 * (78 - midterm)
            + 0.08 * (82 - attendance)
            + 0.55 * missing_tasks
            + 0.07 * (capacity - 90)
            + 0.16 * max(0, capacity - 95)
            + 0.08 * (72 - sete)
        )
        outcome = 1 if rng.random() < sigmoid(logit) else 0
        synthetic_key = hashlib.sha256(f"spartan-synthetic-{index:06d}".encode("utf-8")).hexdigest()
        rows.append(
            {
                "student_key": synthetic_key,
                "section_code": str(profile["section_code"]),
                "academic_year": str(profile["academic_year"]),
                "semester": str(profile["semester"]),
                "midtermGrade": midterm,
                "attendanceRate": attendance,
                "missingTasks": missing_tasks,
                "capacityUtilization": capacity,
                "seteEngagement": sete,
                "academic_difficulty_outcome": outcome,
                "outcome_definition": OUTCOME_DEFINITION,
                "source_dataset": SOURCE_DATASET,
            }
        )

    labels = {int(row["academic_difficulty_outcome"]) for row in rows}
    if labels != {0, 1}:
        raise RuntimeError("The synthetic generation seed did not produce both label classes.")
    return rows


def main() -> None:
    profiles = load_section_profiles()
    rows = build_rows(profiles)
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with OUTPUT.open("w", newline="", encoding="utf-8") as csv_file:
        writer = csv.DictWriter(csv_file, fieldnames=CSV_COLUMNS)
        writer.writeheader()
        writer.writerows(rows)
    positive = sum(int(row["academic_difficulty_outcome"]) for row in rows)
    section_count = len({str(row["section_code"]) for row in rows})
    print(
        f"Generated {len(rows)} synthetic prototype records across {section_count} "
        f"database sections; simulated labels: difficulty={positive}, no-difficulty={len(rows) - positive}."
    )
    print(f"CSV: {OUTPUT}")


if __name__ == "__main__":
    main()
