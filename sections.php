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
    require __DIR__ . '/db_connect.php';
    $rows = $pdo->query(
        "SELECT section_code, academic_year, semester,
                COUNT(DISTINCT student_key) AS student_count,
                AVG(midterm_grade_average) AS midterm_grade_average,
                AVG(attendance_rate) AS attendance_rate,
                AVG(missing_portal_submissions) AS missing_portal_submissions,
                AVG(100.0 * section_enrollment / section_capacity) AS section_capacity_utilization,
                AVG(sete_response_rate) AS sete_response_rate
         FROM academic_risk_training_records
         GROUP BY section_code, academic_year, semester
         HAVING COUNT(DISTINCT student_key) >= 5
            AND AVG(midterm_grade_average) BETWEEN 60 AND 100
            AND AVG(attendance_rate) BETWEEN 50 AND 100
            AND AVG(missing_portal_submissions) BETWEEN 0 AND 10
            AND AVG(100.0 * section_enrollment / section_capacity) BETWEEN 70 AND 110
            AND AVG(sete_response_rate) BETWEEN 40 AND 100
         ORDER BY academic_year DESC,
                  FIELD(semester, '1st Semester', '2nd Semester', 'Summer') DESC,
                  section_code ASC"
    )->fetchAll();

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
                'midterm_grade_average' => round((float) $row['midterm_grade_average']),
                'attendance_rate' => round((float) $row['attendance_rate']),
                'missing_portal_submissions' => max(0, min(10, (int) round((float) $row['missing_portal_submissions']))),
                'section_capacity_utilization' => round((float) $row['section_capacity_utilization']),
                'sete_response_rate' => round((float) $row['sete_response_rate']),
            ],
        ];
    }

    echo json_encode(['sections' => array_values($latestBySection)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('BAT405 section preset error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load section presets. Check the local database and migration.']);
}
