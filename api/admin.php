<?php
/**
 * SophosCore - API REST para el Panel de Administrador (Rectoría / Coordinación)
 * Conectado exclusivamente a la base de datos MySQL (sophos_core)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
$pdo = getDBConnection();

$action = $_GET['action'] ?? 'overview';

try {
    switch ($action) {
        // ==============================================================
        // 1. MÉTRICAS GENERALES Y DATOS REALES DE LA BASE DE DATOS
        // ==============================================================
        case 'overview':
            // 1.1 Total Estudiantes Reales Matriculados
            $stmtEst = $pdo->query("SELECT COUNT(*) as total FROM estudiantes_matriculados WHERE ano_lectivo = 2026");
            $totalEstudiantes = (int)$stmtEst->fetch()['total'];

            // 1.2 Total Docentes Reales
            $stmtDoc = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'DOCENTE'");
            $totalDocentes = (int)$stmtDoc->fetch()['total'];

            // 1.3 Periodos Académicos
            $stmtPer = $pdo->query("SELECT id_periodo, numero_periodo, fecha_inicio, fecha_fin, activo FROM periodos_academicos WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001' ORDER BY numero_periodo ASC");
            $periodos = $stmtPer->fetchAll();
            $periodoActivoNum = 3;
            $periodoActivoId = null;
            foreach ($periodos as $p) {
                if ($p['activo'] == 1) {
                    $periodoActivoNum = (int)$p['numero_periodo'];
                    $periodoActivoId = $p['id_periodo'];
                    break;
                }
            }

            // 1.4 Plan de Suscripción e Institución
            $stmtInst = $pdo->query("
                SELECT i.nombre_legal, i.nit, i.estado_suscripcion, i.fecha_expiracion,
                       p.nombre as plan_nombre, p.precio, p.limite_usuarios
                FROM instituciones i
                JOIN planes_suscripcion p ON i.id_suscripcion = p.id_suscripcion
                WHERE i.id_institucion = 'a0000001-0000-0000-0000-000000000001'
                LIMIT 1
            ");
            $institucion = $stmtInst->fetch() ?: [
                'nombre_legal' => 'Colegio Sophos Core',
                'plan_nombre' => 'Plan Institucional Pro (Campus)',
                'limite_usuarios' => 1500,
                'estado_suscripcion' => 'ACTIVO'
            ];

            // 1.5 Lista Real de los 10 Estudiantes con sus Promedios de Calificaciones en BD
            $stmtStudents = $pdo->query("
                SELECT
                    u.id_usuario,
                    u.nombre_completo,
                    u.email,
                    c.nombre as curso_nombre,
                    c.id_curso,
                    em.id_matricula
                FROM estudiantes_matriculados em
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                JOIN cursos c ON em.id_curso = c.id_curso
                WHERE em.ano_lectivo = 2026
                ORDER BY c.nombre ASC, u.nombre_completo ASC
            ");
            $studentsRaw = $stmtStudents->fetchAll();

            $alumnosEnRiesgo = 0;
            $studentsWithGrades = [];

            // Obtener promedio real de cada estudiante para el periodo activo
            foreach ($studentsRaw as $st) {
                $stmtAvg = $pdo->prepare("
                    SELECT AVG(nota) as promedio
                    FROM calificaciones
                    WHERE id_matricula = ? AND id_periodo = ?
                ");
                $stmtAvg->execute([$st['id_matricula'], $periodoActivoId ?: 'f0000001-0000-0000-0000-000000000003']);
                $avgRow = $stmtAvg->fetch();
                $avg = $avgRow['promedio'] ? round((float)$avgRow['promedio'], 2) : 0.0;

                // Si no hay calificaciones por id_periodo, buscar por número de periodo activo
                if ($avg == 0.0) {
                    $stmtAvgNum = $pdo->prepare("
                        SELECT AVG(nota) as promedio
                        FROM calificaciones
                        WHERE id_matricula = ? AND periodo = ?
                    ");
                    $stmtAvgNum->execute([$st['id_matricula'], $periodoActivoNum]);
                    $avgRowNum = $stmtAvgNum->fetch();
                    $avg = $avgRowNum['promedio'] ? round((float)$avgRowNum['promedio'], 2) : 3.5;
                }

                // Determinar estado cualitativo oficial
                $estado = 'Básico';
                $badgeClass = 'badge-soft-warning';
                $barClass = 'bg-warning';
                if ($avg >= 4.6) {
                    $estado = 'Superior';
                    $badgeClass = 'badge-soft-success';
                    $barClass = 'bg-success';
                } elseif ($avg >= 4.0) {
                    $estado = 'Alto';
                    $badgeClass = 'badge-soft-primary';
                    $barClass = 'bg-primary';
                } elseif ($avg >= 3.0) {
                    $estado = 'Básico';
                    $badgeClass = 'badge-soft-warning';
                    $barClass = 'bg-warning';
                } else {
                    $estado = 'Bajo';
                    $badgeClass = 'badge-soft-danger';
                    $barClass = 'bg-danger';
                    $alumnosEnRiesgo++;
                }

                // Generar iniciales para avatar
                $partes = explode(' ', trim($st['nombre_completo']));
                $ini = strtoupper(substr($partes[0], 0, 1) . (isset($partes[1]) ? substr($partes[1], 0, 1) : ''));

                $studentsWithGrades[] = [
                    'id_usuario' => $st['id_usuario'],
                    'nombre' => $st['nombre_completo'],
                    'email' => $st['email'],
                    'curso' => $st['curso_nombre'],
                    'promedio' => number_format($avg, 2),
                    'promedio_porcentaje' => round(($avg / 5.0) * 100),
                    'estado' => $estado,
                    'badge_class' => $badgeClass,
                    'bar_class' => $barClass,
                    'iniciales' => $ini
                ];
            }

            // Ordenar por promedio descendente (Cuadro de Honor: mejores puntajes arriba)
            usort($studentsWithGrades, function ($a, $b) {
                return (float)$b['promedio'] <=> (float)$a['promedio'];
            });

            // Limitar a los 10 mejores puntajes para el Cuadro de Honor
            $cuadroDeHonor = array_slice($studentsWithGrades, 0, 10);

            // 1.6 Registros Reales del Observador Digital y Asistente IA
            $stmtObs = $pdo->query("
                SELECT 
                    o.id_observador,
                    o.tipo_nota,
                    o.observacion_informal,
                    o.observacion_formal_ia,
                    o.fecha_registro,
                    u.nombre_completo as estudiante_nombre,
                    d.nombre_completo as docente_nombre
                FROM observador_digital o
                JOIN usuarios u ON o.id_estudiante = u.id_usuario
                JOIN usuarios d ON o.id_docente = d.id_usuario
                ORDER BY o.fecha_registro DESC
                LIMIT 6
            ");
            $observaciones = $stmtObs->fetchAll();

            // Formatear incidencias para monitoreo
            $aiMonitoring = [];
            foreach ($observaciones as $obs) {
                $icon = 'bi-info-circle-fill';
                $color = '#4f46e5';
                $bg = 'rgba(79, 70, 229, 0.1)';
                $titulo = 'Seguimiento Pedagógico';

                if ($obs['tipo_nota'] === 'LOGRO_DESTACADO') {
                    $icon = 'bi-award-fill';
                    $color = '#059669';
                    $bg = 'rgba(16, 185, 129, 0.12)';
                    $titulo = 'Logro Académico Destacado';
                } elseif ($obs['tipo_nota'] === 'DISCIPLINARIA') {
                    $icon = 'bi-exclamation-triangle-fill';
                    $color = '#d97706';
                    $bg = 'rgba(245, 158, 11, 0.12)';
                    $titulo = 'Reporte de Convivencia';
                } elseif ($obs['tipo_nota'] === 'PEDAGOGICA') {
                    $icon = 'bi-cpu-fill';
                    $color = '#0284c7';
                    $bg = 'rgba(2, 132, 199, 0.12)';
                    $titulo = 'Acompañamiento Pedagógico';
                }

                $descripcion = !empty($obs['observacion_formal_ia']) ? $obs['observacion_formal_ia'] : $obs['observacion_informal'];

                $aiMonitoring[] = [
                    'tipo' => $obs['tipo_nota'],
                    'titulo' => $titulo,
                    'estudiante' => $obs['estudiante_nombre'],
                    'docente' => $obs['docente_nombre'],
                    'fecha' => date('d/m/Y', strtotime($obs['fecha_registro'])),
                    'descripcion' => $descripcion,
                    'icon' => $icon,
                    'color' => $color,
                    'bg' => $bg
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'kpis' => [
                        'total_estudiantes' => $totalEstudiantes,
                        'total_docentes' => $totalDocentes,
                        'alumnos_riesgo' => $alumnosEnRiesgo,
                        'plan_nombre' => $institucion['plan_nombre'],
                        'limite_usuarios' => (int)$institucion['limite_usuarios']
                    ],
                    'institucion' => $institucion,
                    'periodo_activo' => $periodoActivoNum,
                    'periodos' => $periodos,
                    'estudiantes' => $cuadroDeHonor,
                    'monitoreo_ia' => $aiMonitoring
                ]
            ]);
            break;

        // ==============================================================
        // 2. CERRAR PERÍODO ACADÉMICO EN LA BASE DE DATOS
        // ==============================================================
        case 'close_period':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $periodoActual = (int)($input['periodo_actual'] ?? 3);
            $siguientePeriodo = $periodoActual + 1;

            if ($siguientePeriodo > 4) {
                echo json_encode(['success' => false, 'error' => 'Ya se ha alcanzado el último período académico del año lectivo.']);
                exit;
            }

            // Desactivar período actual y activar el siguiente
            $pdo->beginTransaction();

            $stmtClose = $pdo->prepare("UPDATE periodos_academicos SET activo = 0 WHERE numero_periodo = ? AND id_institucion = 'a0000001-0000-0000-0000-000000000001'");
            $stmtClose->execute([$periodoActual]);

            $stmtOpen = $pdo->prepare("UPDATE periodos_academicos SET activo = 1 WHERE numero_periodo = ? AND id_institucion = 'a0000001-0000-0000-0000-000000000001'");
            $stmtOpen->execute([$siguientePeriodo]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "El Período $periodoActual fue cerrado y se activó el Período $siguientePeriodo.",
                'nuevo_periodo_activo' => $siguientePeriodo
            ]);
            break;

        // ==============================================================
        // 3. REINICIAR PERÍODO ACADÉMICO AL PERÍODO 3 (PARA DEMO)
        // ==============================================================
        case 'reset_period':
            $pdo->beginTransaction();
            $pdo->exec("UPDATE periodos_academicos SET activo = 0 WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001'");
            $pdo->exec("UPDATE periodos_academicos SET activo = 1 WHERE numero_periodo = 3 AND id_institucion = 'a0000001-0000-0000-0000-000000000001'");
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Período restablecido al Período 3 Activo.'
            ]);
            break;

        // ==============================================================
        // 4. LIBRETA INSTITUCIONAL DE CALIFICACIONES (ADMIN / RECTORÍA)
        // Permite al Administrador auditar TODOS los cursos, TODAS las materias
        // y filtrar por estudiante específico con calificaciones de la base de datos.
        // ==============================================================
        case 'gradebook':
            // 4.1 Determinar período activo
            $stmtAct = $pdo->query("SELECT numero_periodo FROM periodos_academicos WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001' AND activo = 1 LIMIT 1");
            $activeP = $stmtAct->fetchColumn() ?: 3;
            $period = (int)($_GET['period'] ?? $activeP);
            $selectedCourse = trim($_GET['course'] ?? 'ALL');
            $selectedSubject = trim($_GET['subject'] ?? 'ALL');
            $searchStudent = trim($_GET['student'] ?? '');

            // 4.2 Listas para los filtros
            $stmtCursos = $pdo->query("SELECT id_curso, nombre FROM cursos WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001' ORDER BY nombre ASC");
            $cursosList = $stmtCursos->fetchAll();

            $stmtMaterias = $pdo->query("SELECT id_materia, nombre, area FROM materias WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001' ORDER BY nombre ASC");
            $materiasList = $stmtMaterias->fetchAll();

            $stmtPeriodos = $pdo->query("SELECT numero_periodo, activo FROM periodos_academicos WHERE id_institucion = 'a0000001-0000-0000-0000-000000000001' ORDER BY numero_periodo ASC");
            $periodosList = $stmtPeriodos->fetchAll();

            // 4.3 Construcción de la consulta con filtros dinámicos
            $sql = "
                SELECT 
                    em.id_matricula,
                    u.id_usuario as id_estudiante,
                    u.nombre_completo as estudiante_nombre,
                    u.email as estudiante_email,
                    c.id_curso,
                    c.nombre as curso_nombre,
                    m.id_materia,
                    m.nombre as materia_nombre,
                    m.area as materia_area,
                    d.nombre_completo as docente_nombre,
                    aa.id_asignacion
                FROM estudiantes_matriculados em
                JOIN usuarios u ON em.id_estudiante = u.id_usuario
                JOIN cursos c ON em.id_curso = c.id_curso
                JOIN asignaciones_academicas aa ON aa.id_curso = c.id_curso
                JOIN materias m ON aa.id_materia = m.id_materia
                JOIN usuarios d ON aa.id_docente = d.id_usuario
                WHERE em.ano_lectivo = 2026 AND aa.ano_lectivo = 2026
            ";

            $params = [];

            if ($selectedCourse !== 'ALL' && !empty($selectedCourse)) {
                $sql .= " AND (c.id_curso = :curso OR c.nombre LIKE :cursoName) ";
                $params[':curso'] = $selectedCourse;
                $params[':cursoName'] = "%$selectedCourse%";
            }

            if ($selectedSubject !== 'ALL' && !empty($selectedSubject)) {
                $sql .= " AND (m.id_materia = :materia OR m.nombre LIKE :materiaName) ";
                $params[':materia'] = $selectedSubject;
                $params[':materiaName'] = "%$selectedSubject%";
            }

            if (!empty($searchStudent)) {
                $sql .= " AND (u.nombre_completo LIKE :search OR u.email LIKE :search) ";
                $params[':search'] = "%$searchStudent%";
            }

            $sql .= " ORDER BY c.nombre ASC, u.nombre_completo ASC, m.nombre ASC";

            $stmtR = $pdo->prepare($sql);
            $stmtR->execute($params);
            $rows = $stmtR->fetchAll();

            // 4.4 Adjuntar las calificaciones reales registradas para cada estudiante/materia
            $gradebookRecords = [];
            foreach ($rows as $r) {
                $stmtC = $pdo->prepare("
                    SELECT c.id_calificacion, c.nota, c.actividad, c.comentario_docente
                    FROM calificaciones c
                    WHERE c.id_matricula = ? AND c.id_asignacion = ? AND c.periodo = ?
                    ORDER BY c.fecha_registro ASC
                ");
                $stmtC->execute([$r['id_matricula'], $r['id_asignacion'], $period]);
                $califs = $stmtC->fetchAll();

                $notasList = [];
                $suma = 0;
                $count = 0;
                foreach ($califs as $cl) {
                    if ($cl['nota'] !== null) {
                        $n = (float)$cl['nota'];
                        $notasList[] = [
                            'actividad' => $cl['actividad'] ?: ('Nota ' . ($count + 1)),
                            'nota' => $n
                        ];
                        $suma += $n;
                        $count++;
                    }
                }

                $promedio = $count > 0 ? round($suma / $count, 2) : 0.0;

                // Nivel de desempeño institucional
                $nivel = 'Sin Calificaciones';
                $badgeClass = 'bg-secondary';
                if ($count > 0) {
                    if ($promedio >= 4.6) {
                        $nivel = 'Superior';
                        $badgeClass = 'bg-success';
                    } elseif ($promedio >= 4.0) {
                        $nivel = 'Alto';
                        $badgeClass = 'bg-primary';
                    } elseif ($promedio >= 3.0) {
                        $nivel = 'Básico';
                        $badgeClass = 'bg-warning text-dark';
                    } else {
                        $nivel = 'Bajo';
                        $badgeClass = 'bg-danger';
                    }
                }

                // Generar iniciales del estudiante
                $parts = explode(' ', trim($r['estudiante_nombre']));
                $initials = strtoupper(substr($parts[0] ?? 'E', 0, 1) . substr($parts[1] ?? '', 0, 1));

                $gradebookRecords[] = [
                    'id_matricula' => $r['id_matricula'],
                    'id_estudiante' => $r['id_estudiante'],
                    'estudiante_nombre' => $r['estudiante_nombre'],
                    'estudiante_email' => $r['estudiante_email'],
                    'iniciales' => $initials,
                    'curso_id' => $r['id_curso'],
                    'curso_nombre' => $r['curso_nombre'],
                    'materia_id' => $r['id_materia'],
                    'materia_nombre' => $r['materia_nombre'],
                    'materia_area' => $r['materia_area'],
                    'docente_nombre' => $r['docente_nombre'],
                    'calificaciones' => $notasList,
                    'cantidad_notas' => $count,
                    'promedio' => $promedio,
                    'nivel_desempeno' => $nivel,
                    'badge_class' => $badgeClass
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'periodo_seleccionado' => $period,
                    'periodo_activo' => (int)$activeP,
                    'cursos' => $cursosList,
                    'materias' => $materiasList,
                    'periodos' => $periodosList,
                    'records' => $gradebookRecords,
                    'total_records' => count($gradebookRecords)
                ]
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en el servidor: ' . $e->getMessage()]);
}
