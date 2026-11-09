<?php
/**
 * SophosCore - API REST para el Portal y Gestión Docente
 * Conectado exclusivamente a la base de datos MySQL (sophos_core)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
$pdo = getDBConnection();

$action = $_GET['action'] ?? 'init';

// Mapeos de códigos rápidos <-> UUIDs de materias y cursos
$subjectCodeMap = [
    'MAT' => ['id' => 'd0000001-0000-0000-0000-000000000001', 'name' => 'Matemáticas', 'teacher' => 'Mariana Ruiz'],
    'FIS' => ['id' => 'd0000001-0000-0000-0000-000000000002', 'name' => 'Física', 'teacher' => 'Mariana Ruiz'],
    'QUI' => ['id' => 'd0000001-0000-0000-0000-000000000003', 'name' => 'Química', 'teacher' => 'Mariana Ruiz'],
    'CAS' => ['id' => 'd0000001-0000-0000-0000-000000000004', 'name' => 'Lengua Castellana', 'teacher' => 'Carlos Mendoza'],
    'LEC' => ['id' => 'd0000001-0000-0000-0000-000000000005', 'name' => 'Lectura Crítica', 'teacher' => 'Carlos Mendoza']
];

$courseCodeMap = [
    '11A' => ['id' => 'c0000001-0000-0000-0000-000000000001', 'name' => '11° Grado A', 'grade' => 11],
    '10A' => ['id' => 'c0000001-0000-0000-0000-000000000002', 'name' => '10° Grado A', 'grade' => 10]
];

try {
    switch ($action) {
        // ==============================================================
        // 1. INICIALIZACIÓN Y METADATOS DOCENTE
        // ==============================================================
        case 'init':
            $docenteId = $_GET['docente_id'] ?? 'b0000001-0000-0000-0000-000000000002'; // Mariana Ruiz

            // Docente info
            $stmtD = $pdo->prepare("SELECT id_usuario, nombre_completo, email, rol FROM usuarios WHERE id_usuario = ?");
            $stmtD->execute([$docenteId]);
            $docente = $stmtD->fetch() ?: [
                'id_usuario' => $docenteId,
                'nombre_completo' => 'Mariana Ruiz',
                'email' => 'mariana.ruiz@sophoscore.edu.co',
                'rol' => 'DOCENTE'
            ];

            // Cursos
            $courses = [
                ['id' => '11A', 'name' => '11° Grado A (5 Estudiantes)', 'grade' => 11, 'db_id' => 'c0000001-0000-0000-0000-000000000001'],
                ['id' => '10A', 'name' => '10° Grado A (5 Estudiantes)', 'grade' => 10, 'db_id' => 'c0000001-0000-0000-0000-000000000002']
            ];

            // Materias asignadas
            $stmtM = $pdo->prepare("
                SELECT DISTINCT m.id_materia, m.nombre, m.area, u.nombre_completo as docente
                FROM asignaciones_academicas aa
                JOIN materias m ON aa.id_materia = m.id_materia
                JOIN usuarios u ON aa.id_docente = u.id_usuario
                WHERE aa.ano_lectivo = 2026
                ORDER BY m.nombre ASC
            ");
            $stmtM->execute();
            $materiasRaw = $stmtM->fetchAll();

            $subjects = [];
            foreach ($materiasRaw as $m) {
                $code = 'MAT';
                if (stripos($m['nombre'], 'fís') !== false) $code = 'FIS';
                elseif (stripos($m['nombre'], 'quím') !== false) $code = 'QUI';
                elseif (stripos($m['nombre'], 'lengua') !== false || stripos($m['nombre'], 'castell') !== false) $code = 'CAS';
                elseif (stripos($m['nombre'], 'lect') !== false) $code = 'LEC';

                $subjects[] = [
                    'id' => $code,
                    'db_id' => $m['id_materia'],
                    'name' => $m['nombre'],
                    'area' => $m['area'],
                    'teacher' => $m['docente']
                ];
            }

            // Períodos de la institución oficial
            $stmtP = $pdo->query("SELECT id_periodo, numero_periodo, fecha_inicio, fecha_fin, activo FROM periodos_academicos WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001' ORDER BY numero_periodo ASC");
            $periodsRaw = $stmtP->fetchAll();
            $periods = [];
            $currentPeriodId = 3;
            foreach ($periodsRaw as $p) {
                $status = 'closed';
                if ($p['activo'] == 1) {
                    $status = 'active';
                    $currentPeriodId = (int)$p['numero_periodo'];
                } elseif ((int)$p['numero_periodo'] > 3) {
                    $status = 'pending';
                }
                $periods[] = [
                    'id' => (int)$p['numero_periodo'],
                    'db_id' => $p['id_periodo'],
                    'name' => $p['numero_periodo'] == 1 ? "Primer Período" : ($p['numero_periodo'] == 2 ? "Segundo Período" : ($p['numero_periodo'] == 3 ? "Tercer Período" : "Cuarto Período")),
                    'shortName' => 'P' . $p['numero_periodo'],
                    'status' => $status,
                    'closeDate' => $p['fecha_fin']
                ];
            }

            echo json_encode([
                'success' => true,
                'docente' => $docente,
                'courses' => $courses,
                'subjects' => $subjects,
                'periods' => $periods,
                'current_period' => $currentPeriodId
            ]);
            break;

        // ==============================================================
        // 2. ESTUDIANTES POR CURSO
        // ==============================================================
        case 'class_filters':
            $docenteId = $_GET['docente_id'] ?? 'b0000001-0000-0000-0000-000000000002';

            $stmtFilters = $pdo->prepare("
                SELECT DISTINCT
                    c.id_curso,
                    c.nombre AS curso_nombre,
                    m.id_materia,
                    m.nombre AS materia_nombre
                FROM asignaciones_academicas aa
                JOIN cursos c ON aa.id_curso = c.id_curso
                JOIN materias m ON aa.id_materia = m.id_materia
                WHERE aa.id_docente = ? AND aa.ano_lectivo = 2026
                ORDER BY c.nombre DESC,
                    CASE WHEN m.nombre = 'Matemáticas' THEN 0 ELSE 1 END,
                    m.nombre ASC
            ");
            $stmtFilters->execute([$docenteId]);
            $assignments = $stmtFilters->fetchAll();

            $courses = [];
            $courseIds = [];
            foreach ($assignments as $assignment) {
                if (!isset($courseIds[$assignment['id_curso']])) {
                    $courseIds[$assignment['id_curso']] = true;
                    $courses[] = [
                        'id' => $assignment['id_curso'],
                        'name' => $assignment['curso_nombre']
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'docente_id' => $docenteId,
                'courses' => $courses,
                'assignments' => array_map(static function ($assignment) {
                    return [
                        'course_id' => $assignment['id_curso'],
                        'course_name' => $assignment['curso_nombre'],
                        'subject_id' => $assignment['id_materia'],
                        'subject_name' => $assignment['materia_nombre']
                    ];
                }, $assignments)
            ]);
            break;

        case 'students':
            $courseKey = $_GET['course'] ?? '11A';
            $dbCourseId = $courseCodeMap[$courseKey]['id'] ?? $courseKey;
            $docenteId = $_GET['docente_id'] ?? 'b0000001-0000-0000-0000-000000000002';
            $subjectKey = $_GET['subject'] ?? '';
            $dbSubjectId = $subjectCodeMap[$subjectKey]['id'] ?? $subjectKey;

            $stmtCourse = $pdo->prepare("SELECT nombre FROM cursos WHERE id_curso = ?");
            $stmtCourse->execute([$dbCourseId]);
            $courseName = $stmtCourse->fetchColumn();
            if (!$courseName) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Curso no válido']);
                break;
            }

            $assignmentSql = "
                SELECT 1
                FROM asignaciones_academicas
                WHERE id_docente = ? AND id_curso = ? AND ano_lectivo = 2026
            ";
            $assignmentParams = [$docenteId, $dbCourseId];
            if ($subjectKey !== '') {
                $assignmentSql .= " AND id_materia = ?";
                $assignmentParams[] = $dbSubjectId;
            }
            $assignmentSql .= " LIMIT 1";
            $stmtAssignment = $pdo->prepare($assignmentSql);
            $stmtAssignment->execute($assignmentParams);
            if (!$stmtAssignment->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'No hay una asignación docente para este curso y materia']);
                break;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    u.id_usuario,
                    u.nombre_completo,
                    u.email,
                    em.id_matricula,
                    c.nombre AS curso_nombre
                FROM estudiantes_matriculados em
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                JOIN cursos c ON em.id_curso = c.id_curso
                WHERE em.id_curso = ? AND em.ano_lectivo = 2026
                ORDER BY u.nombre_completo ASC
            ");
            $stmt->execute([$dbCourseId]);
            $rows = $stmt->fetchAll();

            $students = [];
            $counter = 1;
            foreach ($rows as $r) {
                $parts = explode(' ', trim($r['nombre_completo']));
                $initials = '';
                if (count($parts) >= 2) {
                    $initials = mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1);
                } else {
                    $initials = mb_substr($parts[0], 0, 2);
                }

                $stdCode = ($courseKey === '11A' ? 'EST-110' : 'EST-100') . $counter++;

                $students[] = [
                    'id' => $stdCode,
                    'db_id' => $r['id_usuario'],
                    'matricula_id' => $r['id_matricula'],
                    'name' => $r['nombre_completo'],
                    'email' => $r['email'],
                    'courseName' => $r['curso_nombre'],
                    'courseId' => $courseKey,
                    'avatar' => strtoupper($initials),
                    'avatarColor' => ($counter % 2 == 0) ? '#4f46e5' : '#059669'
                ];
            }

            echo json_encode([
                'success' => true,
                'course' => $courseKey,
                'students' => $students
            ]);
            break;

        // ==============================================================
        // 3. OBTENER LIBRETA DE CALIFICACIONES (GRADEBOOK)
        // ==============================================================
        case 'gradebook':
            $courseKey = $_GET['course'] ?? '11A';
            $subjectKey = $_GET['subject'] ?? 'MAT';
            $period = (int)($_GET['period'] ?? 3);

            $dbCourseId = $courseCodeMap[$courseKey]['id'] ?? 'c0000001-0000-0000-0000-000000000001';
            $dbSubjectId = $subjectCodeMap[$subjectKey]['id'] ?? 'd0000001-0000-0000-0000-000000000001';

            // Buscar asignación académica
            $stmtA = $pdo->prepare("SELECT id_asignacion FROM asignaciones_academicas WHERE id_curso = ? AND id_materia = ? AND ano_lectivo = 2026 LIMIT 1");
            $stmtA->execute([$dbCourseId, $dbSubjectId]);
            $asigId = $stmtA->fetchColumn();

            // Obtener estudiantes del curso
            $stmtS = $pdo->prepare("
                SELECT em.id_matricula, u.id_usuario, u.nombre_completo 
                FROM estudiantes_matriculados em
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                WHERE em.id_curso = ? AND em.ano_lectivo = 2026
                ORDER BY u.nombre_completo ASC
            ");
            $stmtS->execute([$dbCourseId]);
            $studentsList = $stmtS->fetchAll();

            // Obtener evidencias para las columnas
            $stmtE = $pdo->prepare("
                SELECT nombre, orden 
                FROM evidencias 
                WHERE id_asignacion = ? AND numero_periodo = ?
                ORDER BY orden ASC
            ");
            $stmtE->execute([$asigId, $period]);
            $evidencias = $stmtE->fetchAll();

            $columnTitles = [];
            foreach ($evidencias as $ev) {
                $columnTitles[] = $ev['nombre'];
            }
            if (empty($columnTitles)) {
                $columnTitles = ["Actividad 1", "Actividad 2", "Actividad 3", "Evaluación"];
            }

            // Mapear registros por estudiante
            $records = [];
            $counter = 1;
            foreach ($studentsList as $st) {
                $stdCode = ($courseKey === '11A' ? 'EST-110' : 'EST-100') . $counter++;

                // Consultar calificaciones del estudiante
                $stmtC = $pdo->prepare("
                    SELECT c.id_calificacion, c.nota, c.actividad, c.comentario_docente
                    FROM calificaciones c
                    WHERE c.id_matricula = ? AND c.id_asignacion = ? AND c.periodo = ?
                    ORDER BY c.fecha_registro ASC
                ");
                $stmtC->execute([$st['id_matricula'], $asigId, $period]);
                $gradesRows = $stmtC->fetchAll();

                $grades = [];
                $obs = "";
                foreach ($gradesRows as $gr) {
                    $grades[] = $gr['nota'] !== null ? (float)$gr['nota'] : null;
                    if (!empty($gr['comentario_docente'])) {
                        $obs = $gr['comentario_docente'];
                    }
                }

                // Asegurar al menos 4 posiciones
                while (count($grades) < 4) {
                    $grades[] = null;
                }

                $records[$stdCode] = [
                    'matricula_id' => $st['id_matricula'],
                    'student_id' => $st['id_usuario'],
                    'student_name' => $st['nombre_completo'],
                    'grades' => array_slice($grades, 0, 4),
                    'observation' => $obs
                ];
            }

            echo json_encode([
                'success' => true,
                'course' => $courseKey,
                'subject' => $subjectKey,
                'period' => $period,
                'columnTitles' => $columnTitles,
                'records' => $records
            ]);
            break;

        // ==============================================================
        // 4. GUARDAR LIBRETA DE CALIFICACIONES (SAVE GRADEBOOK)
        // ==============================================================
        case 'save_gradebook':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);

            $courseKey = $input['course'] ?? '11A';
            $subjectKey = $input['subject'] ?? 'MAT';
            $period = (int)($input['period'] ?? 3);
            $records = $input['records'] ?? [];
            $columnTitles = $input['columnTitles'] ?? ["Actividad 1", "Actividad 2", "Actividad 3", "Evaluación"];

            $dbCourseId = $courseCodeMap[$courseKey]['id'] ?? 'c0000001-0000-0000-0000-000000000001';
            $dbSubjectId = $subjectCodeMap[$subjectKey]['id'] ?? 'd0000001-0000-0000-0000-000000000001';

            $stmtA = $pdo->prepare("SELECT id_asignacion, id_institucion FROM asignaciones_academicas WHERE id_curso = ? AND id_materia = ? AND ano_lectivo = 2026 LIMIT 1");
            $stmtA->execute([$dbCourseId, $dbSubjectId]);
            $asigRow = $stmtA->fetch();
            $asigId = $asigRow['id_asignacion'];
            $instId = $asigRow['id_institucion'];

            // Obtener matriculas
            $stmtS = $pdo->prepare("
                SELECT em.id_matricula, u.id_usuario, u.nombre_completo 
                FROM estudiantes_matriculados em
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                WHERE em.id_curso = ? AND em.ano_lectivo = 2026
                ORDER BY u.nombre_completo ASC
            ");
            $stmtS->execute([$dbCourseId]);
            $studentsList = $stmtS->fetchAll();

            $counter = 1;
            $updatedCount = 0;

            foreach ($studentsList as $st) {
                $stdCode = ($courseKey === '11A' ? 'EST-110' : 'EST-100') . $counter++;
                $rec = $records[$stdCode] ?? null;
                if (!$rec) continue;

                $grades = $rec['grades'] ?? [];
                $obs = $rec['observation'] ?? '';

                // Eliminar calificaciones anteriores de este periodo para este estudiante/asignación
                $stmtDel = $pdo->prepare("DELETE FROM calificaciones WHERE id_matricula = ? AND id_asignacion = ? AND periodo = ?");
                $stmtDel->execute([$st['id_matricula'], $asigId, $period]);

                // Insertar nuevas
                $stmtIns = $pdo->prepare("
                    INSERT INTO calificaciones 
                    (id_calificacion, id_matricula, id_asignacion, id_institucion, nota, periodo, actividad, comentario_docente, fecha_registro)
                    VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                foreach ($grades as $idx => $val) {
                    if ($val === null || $val === '') continue;
                    $actName = $columnTitles[$idx] ?? "Actividad " . ($idx + 1);
                    $comment = ($idx === count($grades) - 1 || $idx === 0) ? $obs : null;
                    $stmtIns->execute([$st['id_matricula'], $asigId, $instId, (float)$val, $period, $actName, $comment]);
                    $updatedCount++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Calificaciones persistidas correctamente en MySQL (sophos_core)',
                'updated_count' => $updatedCount
            ]);
            break;

        // ==============================================================
        // 5. SINCRONIZACIÓN COMPLETA PARA CLIENTE (MOCKBRIDGE)
        // ==============================================================
        case 'sync_all':
            // Cargar estudiantes de ambos cursos
            $allStudents = [];
            foreach (['11A', '10A'] as $cKey) {
                $dbCourseId = $courseCodeMap[$cKey]['id'];
                $stmt = $pdo->prepare("
                    SELECT u.id_usuario, u.nombre_completo, em.id_matricula
                    FROM estudiantes_matriculados em
                    JOIN usuarios u ON em.id_estudiante = u.id_usuario
                    WHERE em.id_curso = ? AND em.ano_lectivo = 2026
                    ORDER BY u.nombre_completo ASC
                ");
                $stmt->execute([$dbCourseId]);
                $cStudents = $stmt->fetchAll();

                $cCount = 1;
                foreach ($cStudents as $cs) {
                    $parts = explode(' ', trim($cs['nombre_completo']));
                    $initials = (count($parts) >= 2) ? (mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1)) : mb_substr($parts[0], 0, 2);
                    $code = ($cKey === '11A' ? 'EST-110' : 'EST-100') . $cCount++;
                    $allStudents[] = [
                        'id' => $code,
                        'db_id' => $cs['id_usuario'],
                        'matricula_id' => $cs['id_matricula'],
                        'name' => $cs['nombre_completo'],
                        'courseId' => $cKey,
                        'avatar' => strtoupper($initials),
                        'avatarColor' => ($cCount % 2 == 0) ? '#4f46e5' : '#059669'
                    ];
                }
            }

            // Construir todos los gradebooks desde calificaciones
            $gradebooks = [];
            foreach (['11A', '10A'] as $cKey) {
                $dbCourseId = $courseCodeMap[$cKey]['id'];
                foreach (array_keys($subjectCodeMap) as $sKey) {
                    $dbSubjId = $subjectCodeMap[$sKey]['id'];
                    $stmtAs = $pdo->prepare("SELECT id_asignacion FROM asignaciones_academicas WHERE id_curso = ? AND id_materia = ? LIMIT 1");
                    $stmtAs->execute([$dbCourseId, $dbSubjId]);
                    $asigId = $stmtAs->fetchColumn();

                    if (!$asigId) continue;

                    for ($p = 1; $p <= 3; $p++) {
                        $key = "{$cKey}_{$sKey}_{$p}";

                        // Buscar columnas de evidencia
                        $stmtE = $pdo->prepare("SELECT nombre FROM evidencias WHERE id_asignacion = ? AND numero_periodo = ? ORDER BY orden ASC");
                        $stmtE->execute([$asigId, $p]);
                        $evNames = $stmtE->fetchAll(PDO::FETCH_COLUMN);
                        if (empty($evNames)) {
                            $evNames = ["Taller 1", "Quiz 1", "Taller 2", "Evaluación"];
                        }

                        // Registros de los estudiantes
                        $studentsInCourse = array_filter($allStudents, function($s) use ($cKey) { return $s['courseId'] === $cKey; });
                        $recMap = [];
                        foreach ($studentsInCourse as $stObj) {
                            $stmtG = $pdo->prepare("SELECT nota, comentario_docente FROM calificaciones WHERE id_matricula = ? AND id_asignacion = ? AND periodo = ? ORDER BY fecha_registro ASC");
                            $stmtG->execute([$stObj['matricula_id'], $asigId, $p]);
                            $gRows = $stmtG->fetchAll();

                            $gArray = [];
                            $obs = "";
                            foreach ($gRows as $gr) {
                                $gArray[] = $gr['nota'] !== null ? (float)$gr['nota'] : null;
                                if (!empty($gr['comentario_docente'])) $obs = $gr['comentario_docente'];
                            }
                            while (count($gArray) < 4) $gArray[] = null;

                            $recMap[$stObj['id']] = [
                                'grades' => array_slice($gArray, 0, 4),
                                'observation' => $obs
                            ];
                        }

                        $gradebooks[$key] = [
                            'columnTitles' => $evNames,
                            'records' => $recMap
                        ];
                    }
                }
            }

            // Observaciones de observador_digital
            $stmtObs = $pdo->query("
                SELECT 
                    o.id_observador as id,
                    o.id_estudiante as db_student_id,
                    u.nombre_completo as studentName,
                    o.tipo_nota as type,
                    o.observacion_informal,
                    o.observacion_formal_ia,
                    o.fecha_registro as date,
                    ud.nombre_completo as teacher
                FROM observador_digital o
                JOIN usuarios u ON o.id_estudiante = u.id_usuario
                LEFT JOIN usuarios ud ON o.id_docente = ud.id_usuario
                ORDER BY o.fecha_registro DESC
            ");
            $obsRows = $stmtObs->fetchAll();
            $observations = [];
            foreach ($obsRows as $ob) {
                // Mapear student code
                $matching = array_filter($allStudents, function($s) use ($ob) { return $s['db_id'] === $ob['db_student_id']; });
                $first = reset($matching);
                $code = $first ? $first['id'] : 'EST-1101';
                $course = $first ? $first['courseId'] : '11A';

                $observations[] = [
                    'id' => $ob['id'],
                    'studentId' => $code,
                    'studentName' => $ob['studentName'],
                    'courseId' => $course,
                    'date' => substr($ob['date'], 0, 10),
                    'type' => strtolower($ob['type']),
                    'category' => $ob['type'] === 'DISCIPLINARIO' ? 'Falta / Seguimiento' : 'Seguimiento Académico',
                    'description' => $ob['observacion_formal_ia'] ?: $ob['observacion_informal'],
                    'agreements' => 'Compromiso firmado y radicado en el observador.',
                    'teacher' => $ob['teacher'] ?: 'Profe Mariana'
                ];
            }

            echo json_encode([
                'success' => true,
                'source' => 'MySQL (sophos_core)',
                'students' => $allStudents,
                'gradebooks' => $gradebooks,
                'observations' => $observations
            ]);
            break;

        // ==============================================================
        // 6. GESTIÓN DE INASISTENCIAS (MÓDULO ACADÉMICO DOCENTE)
        // ==============================================================
        case 'inasistencias':
            $courseKey = $_GET['course'] ?? 'all';
            $subjectKey = $_GET['subject'] ?? 'all';
            $statusKey = $_GET['status'] ?? 'all';

            $sql = "
                SELECT 
                    a.id_asistencia,
                    a.fecha,
                    a.estado,
                    a.observacion,
                    a.id_matricula,
                    a.id_asignacion,
                    u.id_usuario AS id_estudiante,
                    u.nombre_completo AS estudiante_nombre,
                    c.id_curso,
                    c.nombre AS curso_nombre,
                    m.id_materia,
                    m.nombre AS materia_nombre,
                    ud.nombre_completo AS docente_nombre
                FROM asistencias a
                JOIN estudiantes_matriculados em ON a.id_matricula = em.id_matricula
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                JOIN cursos c ON em.id_curso = c.id_curso
                JOIN asignaciones_academicas aa ON a.id_asignacion = aa.id_asignacion
                JOIN materias m ON aa.id_materia = m.id_materia
                JOIN usuarios ud ON aa.id_docente = ud.id_usuario
                WHERE a.estado IN ('FALTA_JUSTIFICADA', 'FALTA_INJUSTIFICADA')
            ";
            $params = [];

            if ($courseKey !== 'all' && isset($courseCodeMap[$courseKey])) {
                $sql .= " AND c.id_curso = ? ";
                $params[] = $courseCodeMap[$courseKey]['id'];
            }

            if ($subjectKey !== 'all' && isset($subjectCodeMap[$subjectKey])) {
                $sql .= " AND m.id_materia = ? ";
                $params[] = $subjectCodeMap[$subjectKey]['id'];
            }

            if ($statusKey === 'JUSTIFICADA' || $statusKey === 'FALTA_JUSTIFICADA') {
                $sql .= " AND a.estado = 'FALTA_JUSTIFICADA' ";
            } elseif ($statusKey === 'INJUSTIFICADA' || $statusKey === 'FALTA_INJUSTIFICADA') {
                $sql .= " AND a.estado = 'FALTA_INJUSTIFICADA' ";
            }

            $sql .= " ORDER BY a.fecha DESC, u.nombre_completo ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $justified = 0;
            $unjustified = 0;
            $studentsAfected = [];

            $formatted = [];
            foreach ($records as $r) {
                if ($r['estado'] === 'FALTA_JUSTIFICADA') $justified++;
                elseif ($r['estado'] === 'FALTA_INJUSTIFICADA') $unjustified++;
                $studentsAfected[$r['id_estudiante']] = true;

                $parts = explode(' ', trim($r['estudiante_nombre']));
                $initials = (count($parts) >= 2) ? (mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1)) : mb_substr($parts[0], 0, 2);

                $formatted[] = [
                    'id' => $r['id_asistencia'],
                    'fecha' => $r['fecha'],
                    'estado' => $r['estado'],
                    'tipo' => $r['estado'] === 'FALTA_JUSTIFICADA' ? 'Justificada' : 'Injustificada',
                    'observacion' => $r['observacion'] ?: 'Sin observaciones registradas',
                    'matricula_id' => $r['id_matricula'],
                    'asignacion_id' => $r['id_asignacion'],
                    'estudiante_id' => $r['id_estudiante'],
                    'estudiante_nombre' => $r['estudiante_nombre'],
                    'avatar' => strtoupper($initials),
                    'curso_nombre' => $r['curso_nombre'],
                    'curso_id' => $r['id_curso'] === 'c0000001-0000-0000-0000-000000000002' ? '10A' : '11A',
                    'materia_nombre' => $r['materia_nombre'],
                    'docente_nombre' => $r['docente_nombre']
                ];
            }

            echo json_encode([
                'success' => true,
                'source' => 'MySQL (sophos_core)',
                'stats' => [
                    'total' => count($formatted),
                    'justificadas' => $justified,
                    'injustificadas' => $unjustified,
                    'estudiantes_afectados' => count($studentsAfected)
                ],
                'records' => $formatted
            ]);
            break;

        case 'save_inasistencia':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?: $_POST;

            $matriculaId = $input['matricula_id'] ?? null;
            $studentId = $input['student_id'] ?? null;
            $asignacionId = $input['asignacion_id'] ?? null;
            $courseKey = $input['course'] ?? '11A';
            $subjectKey = $input['subject'] ?? 'MAT';
            $fecha = $input['fecha'] ?? date('Y-m-d');
            $estado = $input['estado'] ?? 'FALTA_INJUSTIFICADA';
            $observacion = trim($input['observacion'] ?? '');

            if ($estado !== 'FALTA_JUSTIFICADA' && $estado !== 'FALTA_INJUSTIFICADA') {
                $estado = ($estado === 'Justificada' || $estado === 'JUSTIFICADA') ? 'FALTA_JUSTIFICADA' : 'FALTA_INJUSTIFICADA';
            }

            // Resolver matricula si no viene directa
            if (!$matriculaId && $studentId) {
                if (strpos($studentId, '-') !== false && strlen($studentId) > 20) {
                    $stmtM = $pdo->prepare("SELECT id_matricula FROM estudiantes_matriculados WHERE (id_estudiante = ? OR id_matricula = ?) AND ano_lectivo = 2026 LIMIT 1");
                    $stmtM->execute([$studentId, $studentId]);
                    $matriculaId = $stmtM->fetchColumn();
                } else {
                    $courseUUID = ($courseKey === '10A') ? 'c0000001-0000-0000-0000-000000000002' : 'c0000001-0000-0000-0000-000000000001';
                    $index = 0;
                    if (preg_match('/(\d+)$/', $studentId, $matches)) {
                        $num = (int)$matches[1];
                        $index = ($num % 100) - 1;
                        if ($index < 0) $index = 0;
                    }

                    $stmtList = $pdo->prepare("
                        SELECT em.id_matricula
                        FROM estudiantes_matriculados em
                        JOIN usuarios u ON em.id_estudiante = u.id_usuario
                        WHERE em.id_curso = ? AND em.ano_lectivo = 2026
                        ORDER BY u.nombre_completo ASC
                    ");
                    $stmtList->execute([$courseUUID]);
                    $mList = $stmtList->fetchAll(PDO::FETCH_COLUMN);
                    $matriculaId = $mList[$index] ?? ($mList[0] ?? null);
                }
            }

            // Resolver asignacion si no viene directa
            if (!$asignacionId) {
                $dbCourseId = $courseCodeMap[$courseKey]['id'] ?? 'c0000001-0000-0000-0000-000000000001';
                $dbSubjId = $subjectCodeMap[$subjectKey]['id'] ?? 'd0000001-0000-0000-0000-000000000001';
                $stmtAsig = $pdo->prepare("SELECT id_asignacion FROM asignaciones_academicas WHERE id_curso = ? AND id_materia = ? LIMIT 1");
                $stmtAsig->execute([$dbCourseId, $dbSubjId]);
                $asignacionId = $stmtAsig->fetchColumn();
            }

            if (!$matriculaId || !$asignacionId) {
                echo json_encode(['success' => false, 'error' => 'Faltan parámetros requeridos (matrícula o asignación académica no encontrada)']);
                exit;
            }

            $instId = 'a0000001-0000-0000-0000-000000000001';

            $stmtIns = $pdo->prepare("
                INSERT INTO asistencias (id_asistencia, id_matricula, id_asignacion, id_institucion, fecha, estado, observacion)
                VALUES (UUID(), ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$matriculaId, $asignacionId, $instId, $fecha, $estado, $observacion]);

            echo json_encode([
                'success' => true,
                'message' => 'Inasistencia registrada correctamente en la base de datos.',
                'fecha' => $fecha,
                'estado' => $estado
            ]);
            break;

        case 'delete_inasistencia':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?: $_POST;
            $id = $input['id_asistencia'] ?? ($input['id'] ?? null);

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID de inasistencia no proporcionado']);
                exit;
            }

            $stmtDel = $pdo->prepare("DELETE FROM asistencias WHERE id_asistencia = ?");
            $stmtDel->execute([$id]);

            echo json_encode([
                'success' => true,
                'message' => 'Registro de inasistencia eliminado correctamente.'
            ]);
            break;

        // ==============================================================
        // 7. OBSERVADOR DIGITAL (GUARDAR Y CONSULTAR)
        // ==============================================================
        case 'save_observation':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?: $_POST;

            $studentCode = $input['studentId'] ?? '';
            $courseKey = $input['courseId'] ?? '11A';
            $type = strtoupper($input['type'] ?? 'DISCIPLINARIA');
            $category = $input['category'] ?? 'Compromiso Convivencial';
            $description = trim($input['description'] ?? '');
            $agreements = trim($input['agreements'] ?? '');
            $date = $input['date'] ?? date('Y-m-d');
            $teacherId = 'b0000001-0000-0000-0000-000000000002'; // Mariana Ruiz
            $instId = 'a0000001-0000-0000-0000-000000000001';

            if (!in_array($type, ['PEDAGOGICA', 'DISCIPLINARIA', 'LOGRO_DESTACADO'])) {
                $type = 'DISCIPLINARIA';
            }

            // Encontrar id_usuario del estudiante
            $courseUUID = ($courseKey === '10A') ? 'c0000001-0000-0000-0000-000000000002' : 'c0000001-0000-0000-0000-000000000001';
            $index = 0;
            if (preg_match('/(\d+)$/', $studentCode, $matches)) {
                $num = (int)$matches[1];
                $index = ($num % 100) - 1;
                if ($index < 0) $index = 0;
            }

            $stmtSt = $pdo->prepare("
                SELECT u.id_usuario, u.nombre_completo
                FROM estudiantes_matriculados em
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                WHERE em.id_curso = ? AND em.ano_lectivo = 2026
                ORDER BY u.nombre_completo ASC
            ");
            $stmtSt->execute([$courseUUID]);
            $stList = $stmtSt->fetchAll();
            $targetUser = $stList[$index] ?? ($stList[0] ?? null);

            if (!$targetUser) {
                echo json_encode(['success' => false, 'error' => 'Estudiante no encontrado en la base de datos']);
                exit;
            }

            $stmtInsObs = $pdo->prepare("
                INSERT INTO observador_digital 
                (id_observador, id_estudiante, id_docente, id_institucion, tipo_nota, observacion_informal, observacion_formal_ia, fecha_registro)
                VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)
            ");
            $formal = $agreements ? ($description . " Compromiso: " . $agreements) : $description;
            $stmtInsObs->execute([$targetUser['id_usuario'], $teacherId, $instId, $type, $description, $formal, $date . ' ' . date('H:i:s')]);

            echo json_encode([
                'success' => true,
                'message' => 'Observación registrada en la base de datos MySQL (observador_digital).',
                'studentName' => $targetUser['nombre_completo']
            ]);
            break;

        // ==============================================================
        // 8. CIERRE DE PERÍODO ACADÉMICO (ADMIN)
        // ==============================================================
        case 'close_period':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?: $_POST;
            $periodToClose = (int)($input['period'] ?? 3);
            $nextPeriod = $periodToClose + 1;

            // Desactivar periodo a cerrar
            $stmtC = $pdo->prepare("UPDATE periodos_academicos SET activo = 0 WHERE numero_periodo = ?");
            $stmtC->execute([$periodToClose]);

            // Activar siguiente periodo
            $stmtN = $pdo->prepare("UPDATE periodos_academicos SET activo = 1 WHERE numero_periodo = ?");
            $stmtN->execute([$nextPeriod]);

            echo json_encode([
                'success' => true,
                'message' => "Período {$periodToClose} cerrado exitosamente. Período {$nextPeriod} ahora activo.",
                'previousPeriodId' => $periodToClose,
                'newPeriodId' => $nextPeriod
            ]);
            break;

        case 'reset_period':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            // Restaurar Periodo 3 como activo, otros inactivos
            $pdo->query("UPDATE periodos_academicos SET activo = 0");
            $pdo->query("UPDATE periodos_academicos SET activo = 1 WHERE numero_periodo = 3");

            echo json_encode([
                'success' => true,
                'message' => 'Base de datos restaurada al Período 3 activo.'
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no soportada']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en servidor docente: ' . $e->getMessage()]);
}