<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuthenticatedUser(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

try {
    require __DIR__ . '/risk_db_connect.php';
    $sourceDataset = getenv('RISK_TRAINING_SOURCE') ?: 'SYNTHETIC_CICS_DB';
    $statement = $riskPdo->prepare(
        "SELECT section_code, academic_year, semester,
                COUNT(DISTINCT student_key) AS student_count,
                AVG(midtermGrade) AS midtermGrade,
                AVG(attendanceRate) AS attendanceRate,
                AVG(missingTasks) AS missingTasks,
                AVG(capacityUtilization) AS capacityUtilization,
                AVG(seteEngagement) AS seteEngagement
         FROM academic_risk_training_records
         WHERE source_dataset = :source_dataset
         GROUP BY section_code, academic_year, semester
         HAVING COUNT(DISTINCT student_key) >= 5
            AND AVG(midtermGrade) BETWEEN 60 AND 100
            AND AVG(attendanceRate) BETWEEN 50 AND 100
            AND AVG(missingTasks) BETWEEN 0 AND 10
            AND AVG(capacityUtilization) BETWEEN 70 AND 110
            AND AVG(seteEngagement) BETWEEN 40 AND 100
         ORDER BY academic_year DESC,
                  FIELD(semester, '1st Semester', '2nd Semester', 'Summer') DESC,
                  section_code ASC"
    );
    $statement->execute(['source_dataset' => $sourceDataset]);
    $rows = $statement->fetchAll();

    $latestBySection = [];
    foreach ($rows as $row) {
        $sectionCode = (string) $row['section_code'];
        if (isset($latestBySection[$sectionCode])) {
            continue;
        }
        $latestBySection[$sectionCode] = [
            'section_code' => $sectionCode,
            'academic_year' => (string) $row['academic_year'],
            'semester' => (string) $row['semester'],
            'student_count' => (int) $row['student_count'],
            'inputs' => [
                'midtermGrade' => (int) round((float) $row['midtermGrade']),
                'attendanceRate' => (int) round((float) $row['attendanceRate']),
                'missingTasks' => max(0, min(10, (int) round((float) $row['missingTasks']))),
                'capacityUtilization' => (int) round((float) $row['capacityUtilization']),
                'seteEngagement' => (int) round((float) $row['seteEngagement']),
            ],
        ];
    }

    echo json_encode(['sections' => array_values($latestBySection)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('SpartanInsight360 section preset error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load section presets. Check spartan_insight and migration 002.']);
}
