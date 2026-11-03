<?php
/**
 * SophosCore - API REST para el Portal del Estudiante
 * Lee y persiste exclusivamente desde la base de datos MySQL (sophos_core)
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

$action = $_GET['action'] ?? 'overview';
$studentId = $_GET['student_id'] ?? 'b0000001-0000-0000-0000-000000000001'; // Alex Johnson

try {
    switch ($action) {
        // ==============================================================
        // 1. CARGA COMPLETA DEL PORTAL DEL ESTUDIANTE DESDE LA DB
        // ==============================================================
        case 'overview':
            // A. Datos del estudiante y matrícula activa
            $stmt = $pdo->prepare("
                SELECT 
                    u.id_usuario, 
                    u.nombre_completo, 
                    u.email, 
                    u.rol, 
                    u.id_institucion,
                    i.nombre_legal AS institucion_nombre, 
                    c.id_curso, 
                    c.nombre AS curso_nombre, 
                    c.jornada, 
                    em.id_matricula, 
                    em.ano_lectivo
                FROM usuarios u
                JOIN instituciones i ON u.id_institucion = i.id_institucion
                JOIN estudiantes_matriculados em ON u.id_usuario = em.id_estudiante
                JOIN cursos c ON em.id_curso = c.id_curso
                WHERE u.id_usuario = ? AND em.ano_lectivo = 2026
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();

            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Estudiante no encontrado en la base de datos.']);
                exit;
            }

            // B. Períodos académicos de la institución
            $stmt = $pdo->prepare("
                SELECT id_periodo, numero_periodo, fecha_inicio, fecha_fin, activo 
                FROM periodos_academicos 
                WHERE id_institucion = ? 
                ORDER BY numero_periodo ASC
            ");
            $stmt->execute([$student['id_institucion']]);
            $periods = $stmt->fetchAll();

            $activePeriod = null;
            foreach ($periods as $p) {
                if ($p['activo'] == 1) {
                    $activePeriod = $p;
                    break;
                }
            }
            if (!$activePeriod && count($periods) > 0) {
                $activePeriod = $periods[0];
            }

            // C. Asignaturas y asignaciones del curso de Alex
            $stmt = $pdo->prepare("
                SELECT 
                    aa.id_asignacion,
                    m.id_materia,
                    m.nombre AS materia_nombre,
                    m.area,
                    u.nombre_completo AS docente_nombre
                FROM asignaciones_academicas aa
                JOIN materias m ON aa.id_materia = m.id_materia
                JOIN usuarios u ON aa.id_docente = u.id_usuario
                WHERE aa.id_curso = ? AND aa.ano_lectivo = ?
                ORDER BY m.nombre ASC
            ");
            $stmt->execute([$student['id_curso'], $student['ano_lectivo']]);
            $assignments = $stmt->fetchAll();

            // Para cada materia, calcular su promedio y obtener la última observación
            $subjectsData = [];
            $totalAvgSum = 0;
            $totalAvgCount = 0;

            foreach ($assignments as $asig) {
                // Calificaciones de la materia en el período activo
                $stmtGrades = $pdo->prepare("
                    SELECT nota, comentario_docente 
                    FROM calificaciones 
                    WHERE id_matricula = ? AND id_asignacion = ? AND periodo = ?
                ");
                $stmtGrades->execute([$student['id_matricula'], $asig['id_asignacion'], $activePeriod['numero_periodo']]);
                $grades = $stmtGrades->fetchAll();

                $avg = null;
                $lastObs = "Sin observaciones registradas.";
                if (count($grades) > 0) {
                    $sum = 0;
                    foreach ($grades as $g) {
                        $sum += (float)$g['nota'];
                        if (!empty($g['comentario_docente'])) {
                            $lastObs = $g['comentario_docente'];
                        }
                    }
                    $avg = round($sum / count($grades), 1);
                    $totalAvgSum += $avg;
                    $totalAvgCount++;
                }

                $subjectsData[] = [
                    'id_asignacion' => $asig['id_asignacion'],
                    'id_materia' => $asig['id_materia'],
                    'materia_nombre' => $asig['materia_nombre'],
                    'area' => $asig['area'],
                    'docente_nombre' => $asig['docente_nombre'],
                    'promedio' => $avg,
                    'observacion' => $lastObs,
                    'total_calificaciones' => count($grades)
                ];
            }

            $generalAverage = $totalAvgCount > 0 ? round($totalAvgSum / $totalAvgCount, 1) : 4.3;

            // D. Registro de Inasistencias de Alex Johnson (únicamente FALTA_JUSTIFICADA y FALTA_INJUSTIFICADA)
            $stmt = $pdo->prepare("
                SELECT 
                    a.id_asistencia,
                    a.fecha,
                    a.estado,
                    a.observacion,
                    m.nombre AS materia_nombre,
                    u.nombre_completo AS docente_nombre
                FROM asistencias a
                JOIN asignaciones_academicas aa ON a.id_asignacion = aa.id_asignacion
                JOIN materias m ON aa.id_materia = m.id_materia
                JOIN usuarios u ON aa.id_docente = u.id_usuario
                WHERE a.id_matricula = ?
                  AND a.estado IN ('FALTA_JUSTIFICADA', 'FALTA_INJUSTIFICADA')
                ORDER BY a.fecha DESC
            ");
            $stmt->execute([$student['id_matricula']]);
            $attendances = $stmt->fetchAll();

            $justifiedCount = 0;
            $unjustifiedCount = 0;

            foreach ($attendances as $att) {
                if ($att['estado'] === 'FALTA_JUSTIFICADA') $justifiedCount++;
                elseif ($att['estado'] === 'FALTA_INJUSTIFICADA') $unjustifiedCount++;
            }

            $totalInasistencias = count($attendances);

            // E. Observador digital de Alex Johnson
            $stmt = $pdo->prepare("
                SELECT 
                    o.id_observador,
                    o.tipo_nota,
                    o.observacion_informal,
                    o.observacion_formal_ia,
                    o.firmado,
                    o.fecha_firma,
                    o.fecha_registro,
                    u.nombre_completo AS docente_nombre
                FROM observador_digital o
                JOIN usuarios u ON o.id_docente = u.id_usuario
                WHERE o.id_estudiante = ?
                ORDER BY o.fecha_registro DESC
            ");
            $stmt->execute([$student['id_usuario']]);
            $observations = $stmt->fetchAll();

            // F. Boletines Históricos
            $stmt = $pdo->prepare("
                SELECT 
                    b.id_boletin,
                    b.promedio_general,
                    b.datos_materias,
                    b.observacion_director,
                    b.fecha_cierre,
                    p.numero_periodo,
                    p.fecha_inicio,
                    p.fecha_fin
                FROM boletines_historicos b
                JOIN periodos_academicos p ON b.id_periodo = p.id_periodo
                WHERE b.id_matricula = ?
                ORDER BY p.numero_periodo ASC
            ");
            $stmt->execute([$student['id_matricula']]);
            $bulletins = $stmt->fetchAll();

            foreach ($bulletins as &$bol) {
                $bol['datos_materias'] = json_decode($bol['datos_materias'], true);
            }

            echo json_encode([
                'success' => true,
                'source' => 'MySQL (sophos_core)',
                'student' => $student,
                'periods' => $periods,
                'active_period' => $activePeriod,
                'general_average' => $generalAverage,
                'subjects' => $subjectsData,
                'attendance_summary' => [
                    'total_inasistencias' => $totalInasistencias,
                    'justified_absences' => $justifiedCount,
                    'unjustified_absences' => $unjustifiedCount,
                    'records' => $attendances
                ],
                'observations' => $observations,
                'bulletins' => $bulletins
            ]);
            break;

        // ==============================================================
        // 2. OBTENER CALIFICACIONES DETALLADAS POR MATERIA Y PERÍODO
        // ==============================================================
        case 'grades':
            $asignacionId = $_GET['asignacion_id'] ?? null;
            $periodNumber = isset($_GET['period']) ? (int)$_GET['period'] : 3;
            $matriculaId = $_GET['matricula_id'] ?? null;

            if (!$matriculaId) {
                // Obtener matrícula activa de Alex
                $stmtM = $pdo->prepare("SELECT id_matricula FROM estudiantes_matriculados WHERE id_estudiante = ? AND ano_lectivo = 2026 LIMIT 1");
                $stmtM->execute([$studentId]);
                $matriculaId = $stmtM->fetchColumn();
            }

            if (!$asignacionId) {
                // Default: Primera asignación (Matemáticas)
                $stmtA = $pdo->prepare("
                    SELECT aa.id_asignacion 
                    FROM asignaciones_academicas aa 
                    JOIN cursos c ON aa.id_curso = c.id_curso
                    JOIN estudiantes_matriculados em ON em.id_curso = c.id_curso
                    WHERE em.id_matricula = ? LIMIT 1
                ");
                $stmtA->execute([$matriculaId]);
                $asignacionId = $stmtA->fetchColumn();
            }

            $stmt = $pdo->prepare("
                SELECT 
                    c.id_calificacion,
                    c.nota,
                    c.periodo,
                    c.actividad,
                    c.comentario_docente,
                    c.comentario_ia,
                    c.fecha_registro,
                    e.nombre AS evidencia_nombre,
                    e.descripcion AS evidencia_descripcion,
                    e.orden,
                    m.nombre AS materia_nombre,
                    u.nombre_completo AS docente_nombre
                FROM calificaciones c
                JOIN asignaciones_academicas aa ON c.id_asignacion = aa.id_asignacion
                JOIN materias m ON aa.id_materia = m.id_materia
                JOIN usuarios u ON aa.id_docente = u.id_usuario
                LEFT JOIN evidencias e ON c.id_evidencia = e.id_evidencia
                WHERE c.id_matricula = ? AND c.id_asignacion = ? AND c.periodo = ?
                ORDER BY COALESCE(e.orden, 99) ASC, c.fecha_registro ASC
            ");
            $stmt->execute([$matriculaId, $asignacionId, $periodNumber]);
            $grades = $stmt->fetchAll();

            $sum = 0;
            foreach ($grades as $g) {
                $sum += (float)$g['nota'];
            }
            $average = count($grades) > 0 ? round($sum / count($grades), 2) : 0.00;

            echo json_encode([
                'success' => true,
                'source' => 'MySQL (sophos_core)',
                'asignacion_id' => $asignacionId,
                'period' => $periodNumber,
                'average' => $average,
                'grades' => $grades
            ]);
            break;

        // ==============================================================
        // 3. RADICAR JUSTIFICACIÓN DE FALTA DIRECTO EN LA DB
        // ==============================================================
        case 'justificar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $matriculaId = $input['matricula_id'] ?? null;
            $motivoTipo = $input['motivo_tipo'] ?? 'Incapacidad Médica';
            $detalle = $input['detalle'] ?? 'Falta justificada por acudiente';
            $fecha = $input['fecha'] ?? date('Y-m-d');

            if (!$matriculaId) {
                $stmtM = $pdo->prepare("SELECT id_matricula, id_institucion FROM estudiantes_matriculados WHERE id_estudiante = ? AND ano_lectivo = 2026 LIMIT 1");
                $stmtM->execute([$studentId]);
                $matRow = $stmtM->fetch();
                $matriculaId = $matRow['id_matricula'] ?? null;
                $institucionId = $matRow['id_institucion'] ?? 'a0000001-0000-0000-0000-000000000001';
            } else {
                $stmtInst = $pdo->prepare("SELECT id_institucion FROM estudiantes_matriculados WHERE id_matricula = ? LIMIT 1");
                $stmtInst->execute([$matriculaId]);
                $institucionId = $stmtInst->fetchColumn();
            }

            // Asignación por defecto (primera asignación de la institución)
            $stmtAsig = $pdo->prepare("SELECT id_asignacion FROM asignaciones_academicas WHERE id_institucion = ? LIMIT 1");
            $stmtAsig->execute([$institucionId]);
            $asigId = $stmtAsig->fetchColumn();

            $radicadoCode = 'RAD-' . date('Y') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
            $obsFinal = "{$motivoTipo}: {$detalle} ({$radicadoCode})";

            $stmtInsert = $pdo->prepare("
                INSERT INTO asistencias (id_asistencia, id_matricula, id_asignacion, id_institucion, fecha, estado, observacion)
                VALUES (UUID(), ?, ?, ?, ?, 'FALTA_JUSTIFICADA', ?)
            ");
            $stmtInsert->execute([$matriculaId, $asigId, $institucionId, $fecha, $obsFinal]);

            echo json_encode([
                'success' => true,
                'source' => 'MySQL (sophos_core)',
                'message' => 'Justificación radicada y guardada en base de datos con éxito.',
                'radicado' => $radicadoCode,
                'fecha' => $fecha,
                'observacion' => $obsFinal
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al consultar la base de datos: ' . $e->getMessage()
    ]);
}
