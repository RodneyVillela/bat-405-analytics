<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dataRoot = realpath(__DIR__ . '/data');
$requestedPath = $argv[1] ?? (__DIR__ . '/data/academic_risk_training.csv');
$sourcePath = realpath($requestedPath);
if ($dataRoot === false || $sourcePath === false) {
    fwrite(STDERR, "CSV not found. Generate it at data/academic_risk_training.csv first.\n");
    exit(2);
}
if (!str_starts_with($sourcePath, $dataRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "CSV must be located inside the project's data directory.\n");
    exit(2);
}
$handle = fopen($sourcePath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open the CSV.\n");
    exit(2);
}

$required = [
    'student_key', 'section_code', 'academic_year', 'semester',
    'midtermGrade', 'attendanceRate', 'missingTasks', 'capacityUtilization',
    'seteEngagement', 'academic_difficulty_outcome', 'outcome_definition',
    'source_dataset',
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

require __DIR__ . '/risk_db_connect.php';
$insert = $riskPdo->prepare(
    "INSERT INTO academic_risk_training_records
        (student_key, section_code, academic_year, semester, midtermGrade,
         attendanceRate, missingTasks, capacityUtilization, seteEngagement,
         academic_difficulty_outcome, outcome_definition, source_dataset)
     VALUES
        (:student_key, :section_code, :academic_year, :semester, :midtermGrade,
         :attendanceRate, :missingTasks, :capacityUtilization, :seteEngagement,
         :academic_difficulty_outcome, :outcome_definition, :source_dataset)
     ON DUPLICATE KEY UPDATE
        midtermGrade = VALUES(midtermGrade),
        attendanceRate = VALUES(attendanceRate),
        missingTasks = VALUES(missingTasks),
        capacityUtilization = VALUES(capacityUtilization),
        seteEngagement = VALUES(seteEngagement),
        academic_difficulty_outcome = VALUES(academic_difficulty_outcome),
        outcome_definition = VALUES(outcome_definition)"
);

$riskPdo->beginTransaction();
$count = 0;
$outcomeDefinition = null;
$sourceDataset = null;
$sourceDatasetCleared = false;
$seenRecords = [];
try {
    while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        $lineNumber = $count + 2;
        if (count($row) !== count($required)) {
            throw new RuntimeException("Invalid field count at CSV line {$lineNumber}.");
        }
        $record = array_combine($required, $row);
        foreach (['student_key', 'section_code', 'academic_year', 'semester', 'outcome_definition', 'source_dataset'] as $field) {
            $record[$field] = trim((string) $record[$field]);
        }
        foreach (['midtermGrade', 'attendanceRate', 'missingTasks', 'capacityUtilization', 'seteEngagement', 'academic_difficulty_outcome'] as $field) {
            if (!is_numeric($record[$field])) {
                throw new RuntimeException("Invalid numeric field at CSV line {$lineNumber}.");
            }
            $record[$field] = (float) $record[$field];
        }
        if ($record['student_key'] === '' || strlen($record['student_key']) > 64 || $record['section_code'] === '' || $record['outcome_definition'] === '' || $record['source_dataset'] === '') {
            throw new RuntimeException("A required text field is empty or too long at CSV line {$lineNumber}.");
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $record['academic_year'])) {
            throw new RuntimeException("Invalid academic year at CSV line {$lineNumber}.");
        }
        if (!in_array($record['semester'], ['1st Semester', '2nd Semester', 'Summer'], true)) {
            throw new RuntimeException("Invalid semester at CSV line {$lineNumber}.");
        }
        foreach (['midtermGrade', 'attendanceRate', 'seteEngagement'] as $field) {
            if ($record[$field] < 0 || $record[$field] > 100) {
                throw new RuntimeException("{$field} must be from 0 to 100 at CSV line {$lineNumber}.");
            }
        }
        if ($record['missingTasks'] < 0 || $record['missingTasks'] > 10 || floor($record['missingTasks']) !== $record['missingTasks']) {
            throw new RuntimeException("missingTasks must be a whole number from 0 to 10 at CSV line {$lineNumber}.");
        }
        if ($record['capacityUtilization'] < 0 || $record['capacityUtilization'] > 110) {
            throw new RuntimeException("capacityUtilization must be from 0 to 110 at CSV line {$lineNumber}.");
        }
        if (!in_array($record['academic_difficulty_outcome'], [0.0, 1.0], true)) {
            throw new RuntimeException("academic_difficulty_outcome must be 0 or 1 at CSV line {$lineNumber}.");
        }
        if ($outcomeDefinition !== null && $record['outcome_definition'] !== $outcomeDefinition) {
            throw new RuntimeException('Use one consistent outcome_definition for the entire CSV.');
        }
        if ($sourceDataset !== null && $record['source_dataset'] !== $sourceDataset) {
            throw new RuntimeException('Use one source_dataset for the entire CSV.');
        }
        if (!$sourceDatasetCleared) {
            // Importing a dataset key replaces that dataset atomically. This
            // keeps regenerated synthetic CSVs at exactly their declared size.
            $delete = $riskPdo->prepare('DELETE FROM academic_risk_training_records WHERE source_dataset = :source_dataset');
            $delete->execute(['source_dataset' => $record['source_dataset']]);
            $sourceDatasetCleared = true;
        }
        $recordKey = implode("\x1f", [$record['source_dataset'], $record['student_key'], $record['section_code'], $record['academic_year'], $record['semester']]);
        if (isset($seenRecords[$recordKey])) {
            throw new RuntimeException("Duplicate source/student/section/term record at CSV line {$lineNumber}.");
        }
        $seenRecords[$recordKey] = true;
        $outcomeDefinition = $record['outcome_definition'];
        $sourceDataset = $record['source_dataset'];
        $insert->execute($record);
        $count++;
    }
    if ($count === 0) {
        throw new RuntimeException('The CSV has no data rows.');
    }
    $riskPdo->commit();
} catch (Throwable $exception) {
    if ($riskPdo->inTransaction()) {
        $riskPdo->rollBack();
    }
    fwrite(STDERR, "Import stopped: " . $exception->getMessage() . "\n");
    exit(2);
} finally {
    fclose($handle);
}

fwrite(STDOUT, "Imported {$count} records from {$sourceDataset} into spartan_insight.academic_risk_training_records.\n");
