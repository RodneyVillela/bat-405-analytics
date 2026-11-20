# Local SpartanInsight360 setup

The dashboard, PHP/MySQL backend, training scripts, and model service are in
this repository. Run the dashboard behind the PHP sign-in flow; do not open
`index.html` directly.

## One-time database setup

1. Start MySQL from XAMPP.
2. Import `database.sql` into MySQL. It contains the application schema but
   intentionally contains no sample student or faculty records.
3. If `bat405_analytics` already exists, apply
   `migrations/001_academic_risk_training.sql` instead of recreating the base
   schema.
4. Configure `BAT405_DB_HOST`, `BAT405_DB_NAME`, `BAT405_DB_USER`,
   `BAT405_DB_PASSWORD`, and optionally `BAT405_DB_PORT` for both PHP and
   Python. The defaults match a local XAMPP MySQL setup.
5. Create the local Program Chair login using the existing CLI helper:
   `php create_user.php chair@example.edu "Program Chair"`.

## Import and train

1. Add the de-identified, labeled file as
   `data/academic_risk_training.csv`, using
   `data/academic_risk_training_template.csv` as the exact header.
2. Confirm the target label uses one consistent, institution-approved
   `outcome_definition`. Do not include names, student numbers, comments, or
   other direct identifiers.
3. Import the CSV into MySQL with `php import_risk_data.php`.
4. Install Python packages in the isolated project environment:
   `.venv\\Scripts\\python.exe -m pip install -r requirements.txt`.
5. Train and evaluate the model:
   `.venv\\Scripts\\python.exe ml\\train_model.py`.

The training script requires at least five distinct student keys in each
outcome class and performs a stratified five-fold holdout grouped by student.
It writes the local model and actual holdout metrics to `ml/artifacts/`.
Training data, model artifacts, and metrics are ignored by Git.

## Run the dashboard and local model

Open two terminals in the repository root:

```powershell
.venv\Scripts\python.exe ml\risk_service.py
```

```powershell
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080/welcome.php`, sign in, and use the dashboard. The
PHP prediction endpoint proxies authenticated requests to the Python service
on `127.0.0.1:8765`. The Python service only binds to loopback. If no trained
model is present, the dashboard reports that state and displays no probability
or unsupported performance metrics.
