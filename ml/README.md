# Academic risk model data contract

The predictor uses a supervised, binary Logistic Regression model. It must be
trained on de-identified, term-level CICS records with a documented outcome
label. The checked-in CSV is a header-only template; it contains no student
records and is not training data. The CSV is imported into MySQL and the
training script reads from `academic_risk_training_records` so the database is
the model's source of truth.

## Required fields

| CSV field | Meaning | Validation |
| --- | --- | --- |
| `student_key` | Stable pseudonymous key (never a name or student number) | Non-empty; one row per student, section, and term |
| `section_code` | Section identifier | Non-empty |
| `academic_year`, `semester` | Source term | Non-empty |
| `midterm_grade_average` | Grade average available at the prediction point | 0–100 |
| `attendance_rate` | Attendance percentage available at that point | 0–100 |
| `missing_portal_submissions` | Count of missing or late student submissions at that point | Integer 0–10 |
| `section_enrollment`, `section_capacity` | Actual roster count and approved room/section capacity | Enrollment >= 0; capacity > 0 |
| `sete_response_rate` | Responses divided by eligible SETE invitations for that section | 0–100; do not substitute mean SETE score |
| `academic_difficulty_outcome` | Historical binary outcome | 1 = documented difficulty, 0 = no documented difficulty |
| `outcome_definition` | Consistent institutional definition used for the target | Same approved definition in every row |

The fifth model feature, `section_capacity_utilization`, is derived as
`100 * section_enrollment / section_capacity`. Training data should not include
information recorded after the outcome or prediction cut-off. Remove direct
identifiers before placing the CSV in this project.

The original PHP schema has no student midterm average, student portal
submission counts, section capacity, or SETE invitations/response counts. Its
`grade_submissions` table describes faculty grade submissions, and its SETE
table stores evaluation scores only. Those are not valid substitutes. The
new `academic_risk_training_records` table provides the aligned fields. The
original ten sample rows are not sufficient training or validation data.

Before training, the Program Chair/data steward must choose and document what
constitutes `academic_difficulty_outcome` (for example, one specific official
final-grade rule or a verified intervention outcome). Do not mix label
definitions. The train/test split must be by student, not by row, to prevent the
same student's terms appearing on both sides. Report evaluation only from a
held-out set and compare against a baseline. These measures do not establish
causal effects or guarantee future performance.

The header-only template is at `data/academic_risk_training_template.csv`.
The matching database migration is `migrations/001_academic_risk_training.sql`.
To import a completed file, run `php import_risk_data.php` from the project
root. Then train with `.venv\\Scripts\\python.exe ml\\train_model.py` and run
the local API with `.venv\\Scripts\\python.exe ml\\risk_service.py`. The
serialized model and held-out metrics are written under `ml/artifacts/` and
ignored by Git because they are local outputs derived from institutional data.

Install the local dependencies with `.venv\\Scripts\\python.exe -m pip
install -r requirements.txt`. Configure the same `BAT405_DB_*` environment
variables for PHP and Python if the local MySQL defaults are not correct.
