# Local SpartanInsight360 setup

The dashboard login and application permissions use `bat405_analytics`. The
Academic Difficulty Risk Predictor uses the supplied `spartan_insight` CICS
database for section and SETE aggregates, synthetic training rows, and section
presets. Open the dashboard through the PHP sign-in flow; do not open
`index.html` directly.

## Database setup

1. Start Apache and MySQL from XAMPP.
2. Keep your existing `spartan_insight` tables and apply
   `migrations/002_spartan_insight_risk_training.sql` to add the predictor
   training table. The migration does not replace your CICS tables.
3. Keep the existing `bat405_analytics` setup for logins and the dashboard API.
   For a fresh prototype database, create the shared Program Chair demo account
   with `C:\\xampp\\php\\php.exe create_user.php admin@gmail.com "Program Chair"`.
   Enter the shared prototype password at the secure prompt. For local demo
   autofill, set `SPARTAN_DEMO_EMAIL` and `SPARTAN_DEMO_PASSWORD`, or create
   the Git-ignored `runtime/demo_credentials.php`; without a local password
   setting, the form pre-fills the email and asks the reviewer to enter it.
4. For a non-default MySQL account or port, configure `SPARTAN_DB_HOST`,
   `SPARTAN_DB_PORT`, `SPARTAN_DB_NAME`, `SPARTAN_DB_USER`, and
   `SPARTAN_DB_PASSWORD` for PHP and Python. The defaults target local XAMPP
   MySQL as `root` with no password and database `spartan_insight`.

## Generate, import, and train the prototype

The supplied SQL includes section enrollment/capacity and SETE participation
aggregates, but it has no student-level midterm grades, attendance, missing
student submissions, or verified academic difficulty labels. The following
pipeline uses the real section codes and capacity/SETE aggregates as context,
then simulates 500 row-level feature sets and labels. Those labels are not
documented student outcomes. This is a software prototype and its metrics must
not be presented as validated performance on actual students.

From the project root in PowerShell, run:

```powershell
.venv\\Scripts\\python.exe ml\\generate_synthetic_cics_data.py
php import_risk_data.php
.venv\\Scripts\\python.exe ml\\train_model.py
```

The generated `data/academic_risk_training.csv` has the five model features
`midtermGrade`, `attendanceRate`, `missingTasks`, `capacityUtilization`, and
`seteEngagement`, plus the binary `academic_difficulty_outcome` and metadata.
The capacity and SETE values are based on the `spartan_insight` section-term
aggregates. The remaining student-level inputs and target are simulated.
Training writes the local model and held-out synthetic evaluation to
`ml/artifacts/`; those machine-generated files are ignored by Git. To use another
dataset key later, set `RISK_TRAINING_SOURCE` for PHP and use
`--source DATASET_KEY` with the trainer.

## Run the dashboard and local model

Open two terminals in the repository root:

```powershell
.venv\\Scripts\\python.exe ml\\risk_service.py
```

```powershell
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080/welcome.php`, sign in, and use the dashboard. The
PHP prediction endpoint proxies authenticated requests to the Python service
on `127.0.0.1:8765`. The Python service binds to loopback only. The Risk
Predictor UI labels its data and holdout metrics as synthetic prototype results.

To replace the prototype with real CICS training, provide approved,
de-identified rows with the same five features and a verified academic outcome
label, import them into this table with a distinct `source_dataset`, and retrain
against that dataset key. Do not mix real outcomes with simulated labels.
