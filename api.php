<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/auth.php';
requireAuthenticatedUser(true);

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function getFilters(PDO $pdo): array
{
    $semesters = ['1st Semester', '2nd Semester', 'Summer'];
    $semester = $_GET['semester'] ?? '';
    $academicYear = $_GET['academic_year'] ?? '';

    if ($semester !== '' && !in_array($semester, $semesters, true)) {
        respond(['error' => 'Invalid semester filter.'], 400);
    }
    if ($academicYear !== '' && !preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
        respond(['error' => 'Invalid academic year filter.'], 400);
    }

    $periods = $pdo->query(
        "SELECT semester, academic_year FROM grades
         UNION
         SELECT semester, academic_year FROM sete_evaluations
         UNION
         SELECT semester, academic_year FROM grade_submissions"
    )->fetchAll();

    $years = array_values(array_unique(array_column($periods, 'academic_year')));
    sort($years);

    return [
        'semester' => $semester,
        'academic_year' => $academicYear,
        'semesters' => $semesters,
        'academic_years' => $years,
    ];
}

function addPeriodFilters(string $alias, array $filters, array &$params, bool $includeSemester = true): string
{
    $where = '';
    if ($includeSemester && $filters['semester'] !== '') {
        $where .= " AND {$alias}.semester = :{$alias}_semester";
        $params[":{$alias}_semester"] = $filters['semester'];
    }
    if ($filters['academic_year'] !== '') {
        $where .= " AND {$alias}.academic_year = :{$alias}_year";
        $params[":{$alias}_year"] = $filters['academic_year'];
    }
    return $where;
}

try {
    require __DIR__ . '/db_connect.php';
    $filters = getFilters($pdo);
    $gradeParams = [];
    $gradeWhere = addPeriodFilters('g', $filters, $gradeParams);

    // Each enrollment is grouped by program track; filters select the grade term.
    $trackQuery = $pdo->prepare(
        "SELECT s.track, AVG(g.grade) AS average_grade
         FROM grades g
         JOIN students s ON s.id = g.student_id
         WHERE 1 = 1{$gradeWhere}
         GROUP BY s.track"
    );
    $trackQuery->execute($gradeParams);
    $averageByTrack = ['BA' => null, 'NT' => null];
    foreach ($trackQuery->fetchAll() as $row) {
        $averageByTrack[$row['track']] = round((float) $row['average_grade'], 2);
    }
    $overallGradeQuery = $pdo->prepare(
        "SELECT AVG(g.grade)
         FROM grades g
         WHERE 1 = 1{$gradeWhere}"
    );
    $overallGradeQuery->execute($gradeParams);
    $overallAverageGrade = $overallGradeQuery->fetchColumn();

    // At-risk KPI: count distinct students with at least one core-subject grade below 75.
    $riskQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT g.student_id)
         FROM grades g
         WHERE g.grade < 75{$gradeWhere}"
    );
    $riskQuery->execute($gradeParams);
    $atRiskStudents = (int) $riskQuery->fetchColumn();

    // Section averages and pass rates use all filtered grade records in that section.
    $sectionQuery = $pdo->prepare(
        "SELECT s.section,
                COUNT(*) AS total,
                SUM(g.status = 'Pass') AS passed,
                SUM(g.status = 'Fail') AS failed,
                AVG(g.grade) AS average_grade
         FROM grades g
         JOIN students s ON s.id = g.student_id
         WHERE 1 = 1{$gradeWhere}
         GROUP BY s.section
         ORDER BY s.section"
    );
    $sectionQuery->execute($gradeParams);
    $sections = array_map(static function (array $row): array {
        $total = (int) $row['total'];
        return [
            'section' => $row['section'],
            'total' => $total,
            'passed' => (int) $row['passed'],
            'failed' => (int) $row['failed'],
            'pass_rate' => $total > 0 ? round((int) $row['passed'] / $total * 100, 1) : 0,
            'fail_rate' => $total > 0 ? round((int) $row['failed'] / $total * 100, 1) : 0,
            'average_grade' => round((float) $row['average_grade'], 2),
        ];
    }, $sectionQuery->fetchAll());

    $distributionQuery = $pdo->prepare(
        "SELECT g.status, COUNT(*) AS total
         FROM grades g
         WHERE 1 = 1{$gradeWhere}
         GROUP BY g.status"
    );
    $distributionQuery->execute($gradeParams);
    $passFail = ['Pass' => 0, 'Fail' => 0];
    foreach ($distributionQuery->fetchAll() as $row) {
        $passFail[$row['status']] = (int) $row['total'];
    }

    $seteParams = [];
    $seteWhere = addPeriodFilters('e', $filters, $seteParams);
    $facultyQuery = $pdo->prepare(
        "SELECT f.id, f.name, AVG(e.score) AS average_score
         FROM faculty f
         LEFT JOIN sete_evaluations e ON e.faculty_id = f.id{$seteWhere}
         GROUP BY f.id, f.name
         ORDER BY f.name"
    );
    $facultyQuery->execute($seteParams);
    $facultyAverages = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'average_score' => $row['average_score'] === null
                ? null
                : round((float) $row['average_score'], 2),
        ];
    }, $facultyQuery->fetchAll());
    $seteValues = array_values(array_filter(
        array_column($facultyAverages, 'average_score'),
        static fn ($value): bool => $value !== null
    ));
    $averageSete = count($seteValues) > 0 ? round(array_sum($seteValues) / count($seteValues), 2) : null;
    $lowSeteFaculty = array_values(array_filter(
        $facultyAverages,
        static fn (array $faculty): bool => $faculty['average_score'] !== null && $faculty['average_score'] < 3.0
    ));

    // A faculty member counts as on-time only if all of their included submissions were on time.
    // The total faculty count remains the denominator, so missing submissions are not omitted.
    $submissionParams = [];
    $submissionWhere = addPeriodFilters('gs', $filters, $submissionParams);
    $submissionQuery = $pdo->prepare(
        "SELECT COUNT(*) AS faculty_total,
                SUM(CASE WHEN COALESCE(submitted.on_time, 0) = 1 THEN 1 ELSE 0 END) AS on_time_total
         FROM faculty f
         LEFT JOIN (
             SELECT faculty_id,
                    MIN(CASE WHEN is_submitted = 1
                                  AND submitted_at IS NOT NULL
                                  AND submitted_at <= deadline
                             THEN 1 ELSE 0 END) AS on_time
             FROM grade_submissions gs
             WHERE 1 = 1{$submissionWhere}
             GROUP BY faculty_id
         ) submitted ON submitted.faculty_id = f.id"
    );
    $submissionQuery->execute($submissionParams);
    $submissionCounts = $submissionQuery->fetch();
    $facultyTotal = (int) $submissionCounts['faculty_total'];
    $onTimeTotal = (int) $submissionCounts['on_time_total'];
    $onTimePercentage = $facultyTotal > 0 ? round($onTimeTotal / $facultyTotal * 100, 1) : 0;

    // Keep the full history for a meaningful trend line, independent of the summary filters.
    $trendRows = $pdo->query(
        "SELECT f.name AS faculty_name, e.semester, e.academic_year, AVG(e.score) AS average_score
         FROM sete_evaluations e
         JOIN faculty f ON f.id = e.faculty_id
         GROUP BY f.id, f.name, e.academic_year, e.semester
         ORDER BY e.academic_year,
                  FIELD(e.semester, '1st Semester', '2nd Semester', 'Summer')"
    )->fetchAll();
    $trendLabels = [];
    $trendMap = [];
    foreach ($trendRows as $row) {
        $label = $row['academic_year'] . ' ' . $row['semester'];
        $trendLabels[$label] = true;
        $trendMap[$row['faculty_name']][$label] = round((float) $row['average_score'], 2);
    }
    $trendLabels = array_keys($trendLabels);
    $trend = [];
    foreach ($trendMap as $name => $scores) {
        $trend[] = [
            'label' => $name,
            'data' => array_map(static fn (string $label): ?float => $scores[$label] ?? null, $trendLabels),
        ];
    }

    $populationQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT g.student_id)
         FROM grades g
         WHERE 1 = 1{$gradeWhere}"
    );
    $populationQuery->execute($gradeParams);
    $totalStudents = (int) $populationQuery->fetchColumn();

    respond([
        'filters' => $filters,
        'kpis' => [
            'average_grade_by_track' => $averageByTrack,
            'average_core_grade' => $overallAverageGrade === null
                ? null
                : round((float) $overallAverageGrade, 2),
            'at_risk_students' => $atRiskStudents,
            'total_students' => $totalStudents,
            'average_sete_score' => $averageSete,
            'faculty_on_time_percentage' => $onTimePercentage,
            'faculty_on_time_count' => $onTimeTotal,
            'faculty_total' => $facultyTotal,
        ],
        'sections' => $sections,
        'pass_fail' => $passFail,
        'faculty_averages' => $facultyAverages,
        'low_sete_faculty' => $lowSeteFaculty,
        'sete_trend' => ['labels' => $trendLabels, 'datasets' => $trend],
    ]);
} catch (Throwable $exception) {
    error_log('BAT405 analytics API error: ' . $exception->getMessage());
    respond(['error' => 'Unable to load analytics. Check the PHP error log and database connection.'], 500);
}
