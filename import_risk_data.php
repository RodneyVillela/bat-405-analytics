<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dataRoot = realpath(__DIR__ . '/data');
$requestedPath = $argv[1] ?? (__DIR__ . '/data/academic_risk_training.csv');
$sourcePath = realpath($requestedPath);
if ($dataRoot === false || $sourcePath === false || !str_starts_with($sourcePath, $dataRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "CSV must be located inside the project's data directory.\n");
    exit(2);
}
$handle = fopen($sourcePath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open the de-identified CSV.\n");
    exit(2);
}

$required = [
    'student_key', 'section_code', 'academic_year', 'semester',
    'midterm_grade_average', 'attendance_rate', 'missing_portal_submissions',
    'section_enrollment', 'section_capacity', 'sete_response_rate',
    'academic_difficulty_outcome', 'outcome_definition',
];
$header = fgetcsv($handle, null, ',', '"', '\\');
if (!is_array($header)) {
    fwrite(STDERR, "CSV is empty.\n");
    exit(2);
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
if ($header !== $required) {
    fwrite(STDERR, "CSV header does not match data/academic_risk_training_template.csv.\n");
    exit(2);
}

require __DIR__ . '/db_connect.php';

$insert = $pdo->prepare(
    "INSERT INTO academic_risk_training_records
        (student_key, section_code, academic_year, semester, midterm_grade_average,
         attendance_rate, missing_portal_submissions, section_enrollment,
         section_capacity, sete_response_rate, academic_difficulty_outcome,
         outcome_definition)
     VALUES
        (:student_key, :section_code, :academic_year, :semester, :midterm_grade_average,
         :attendance_rate, :missing_portal_submissions, :section_enrollment,
         :section_capacity, :sete_response_rate, :academic_difficulty_outcome,
         :outcome_definition)
     ON DUPLICATE KEY UPDATE
        midterm_grade_average = VALUES(midterm_grade_average),
        attendance_rate = VALUES(attendance_rate),
        missing_portal_submissions = VALUES(missing_portal_submissions),
        section_enrollment = VALUES(section_enrollment),
        section_capacity = VALUES(section_capacity),
        sete_response_rate = VALUES(sete_response_rate),
        academic_difficulty_outcome = VALUES(academic_difficulty_outcome),
        outcome_definition = VALUES(outcome_definition)"
);

$pdo->beginTransaction();
$count = 0;
$outcomeDefinition = null;
$seenRecords = [];
try {
    while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        if (count($row) !== count($required)) {
            throw new RuntimeException('Invalid CSV field count at record ' . ($count + 2) . '.');
        }
        $record = array_combine($required, $row);
        $record['student_key'] = trim((string) $record['student_key']);
        $record['section_code'] = trim((string) $record['section_code']);
        $record['academic_year'] = trim((string) $record['academic_year']);
        $record['semester'] = trim((string) $record['semester']);
        $record['outcome_definition'] = trim((string) $record['outcome_definition']);
        foreach (['midterm_grade_average', 'attendance_rate', 'missing_portal_submissions', 'section_enrollment', 'section_capacity', 'sete_response_rate', 'academic_difficulty_outcome'] as $field) {
            if (!is_numeric($record[$field])) {
                throw new RuntimeException('Invalid numeric field at record ' . ($count + 2) . '.');
            }
            $record[$field] = (float) $record[$field];
        }
        if ($record['student_key'] === '' || strlen($record['student_key']) > 64 || $record['section_code'] === '' || $record['outcome_definition'] === '') {
            throw new RuntimeException('A required text field is empty or too long at record ' . ($count + 2) . '.');
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $record['academic_year'])) {
            throw new RuntimeException('Invalid academic year format at record ' . ($count + 2) . '.');
        }
        if (!in_array($record['semester'], ['1st Semester', '2nd Semester', 'Summer'], true)) {
            throw new RuntimeException('Invalid semester at record ' . ($count + 2) . '.');
        }
        if ($record['midterm_grade_average'] < 0 || $record['midterm_grade_average'] > 100 || $record['attendance_rate'] < 0 || $record['attendance_rate'] > 100 || $record['sete_response_rate'] < 0 || $record['sete_response_rate'] > 100) {
            throw new RuntimeException('A percentage/grade is outside 0 to 100 at record ' . ($count + 2) . '.');
        }
        if ($record['missing_portal_submissions'] < 0 || $record['missing_portal_submissions'] > 10 || floor($record['missing_portal_submissions']) !== $record['missing_portal_submissions'] || $record['section_enrollment'] < 0 || floor($record['section_enrollment']) !== $record['section_enrollment'] || $record['section_capacity'] <= 0 || floor($record['section_capacity']) !== $record['section_capacity'] || !in_array($record['academic_difficulty_outcome'], [0.0, 1.0], true)) {
            throw new RuntimeException('A count or outcome label is invalid at record ' . ($count + 2) . '.');
        }
        if ($outcomeDefinition !== null && $record['outcome_definition'] !== $outcomeDefinition) {
            throw new RuntimeException('Use a single outcome_definition for the entire CSV.');
        }
        $recordKey = implode("\x1f", [$record['student_key'], $record['section_code'], $record['academic_year'], $record['semester']]);
        if (isset($seenRecords[$recordKey])) {
            throw new RuntimeException('Duplicate student/section/term record at record ' . ($count + 2) . '.');
        }
        $seenRecords[$recordKey] = true;
        $outcomeDefinition = $record['outcome_definition'];
        $insert->execute($record);
        $count++;
    }
    if ($count === 0) {
        throw new RuntimeException('The CSV contains no data records.');
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Import stopped: " . $exception->getMessage() . "\n");
    exit(2);
} finally {
    fclose($handle);
}

fwrite(STDOUT, "Imported {$count} de-identified records into academic_risk_training_records.\n");
