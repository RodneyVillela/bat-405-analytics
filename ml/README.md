# Local academic risk model

This directory contains the local Logistic Regression trainer and loopback-only
prediction service. The model reads `spartan_insight.academic_risk_training_records`
and accepts five camelCase inputs:

| Field | Meaning | Model range |
| --- | --- | --- |
| `midtermGrade` | Midterm grade average | 60–100 |
| `attendanceRate` | Class attendance rate | 50–100 |
| `missingTasks` | Missing or late student portal tasks | Integer 0–10 |
| `capacityUtilization` | Section enrollment divided by roster capacity, as a percent | 70–110 |
| `seteEngagement` | SETE response rate for the section, as a percent | 40–100 |

`academic_difficulty_outcome` is a binary target. A real training file must use
one consistent, verified, institution-approved definition of academic
difficulty and contain de-identified records only.

## Current synthetic prototype

The supplied `spartan_insight` schema has section/term enrollment and SETE
response aggregates, but does not include student-level grades, attendance,
student task submissions, or verified outcome labels. Run
`python ml/generate_synthetic_cics_data.py` to create 500 simulated records
anchored to those section and SETE aggregates; import them with
`php import_risk_data.php`. Generated grades, attendance, task counts, and
labels are simulated, not observed CICS student data. Holdout metrics measure
only this synthetic simulation and do not establish real-world predictive
validity, causal effects, or future performance.

Train on the default prototype dataset:

```powershell
.venv\\Scripts\\python.exe ml\\train_model.py
```

Or choose another imported data source:

```powershell
.venv\\Scripts\\python.exe ml\\train_model.py --source DATASET_KEY
```

Training validates ranges and binary labels, then uses a stratified holdout
grouped by `student_key`. The model and evaluation JSON are written under
`ml/artifacts/` and ignored by Git because they are local generated files.

Start inference with `.venv\\Scripts\\python.exe ml\\risk_service.py`. It binds
only to `127.0.0.1:8765`, offers `GET /health` and `POST /predict`, and reports
probability bands as Low (<35%), Moderate (35–64%), and High (65%+). The PHP
dashboard proxy validates authentication and CSRF before forwarding predictions.
