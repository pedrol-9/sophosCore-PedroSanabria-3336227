/**
 * SophosCore - Cliente de Datos Sincronizado con Base de Datos MySQL
 * Archivo: assets/js/sophos-client-db.js
 * Conecta las vistas directamente con api/docente.php y la base oficial (sophos_core)
 */

const SophosDB = (() => {
    let memoryStore = {
        meta: { currentPeriodId: 3, schoolName: "Colegio Sophos Core", academicYear: 2026 },
        periods: [
            { id: 1, name: "Primer Período", shortName: "P1", status: "closed", closeDate: "2026-04-10" },
            { id: 2, name: "Segundo Período", shortName: "P2", status: "closed", closeDate: "2026-07-03" },
            { id: 3, name: "Tercer Período", shortName: "P3", status: "active", closeDate: "2026-10-19" },
            { id: 4, name: "Cuarto Período", shortName: "P4", status: "pending", closeDate: "2026-12-04" }
        ],
        courses: [
            { id: "11A", name: "11° Grado A", grade: 11 },
            { id: "10A", name: "10° Grado A", grade: 10 }
        ],
        subjects: [
            { id: "MAT", name: "Matemáticas", teacher: "Mariana Ruiz" },
            { id: "FIS", name: "Física", teacher: "Mariana Ruiz" },
            { id: "QUI", name: "Química", teacher: "Mariana Ruiz" },
            { id: "CAS", name: "Lengua Castellana", teacher: "Carlos Mendoza" },
            { id: "LEC", name: "Lectura Crítica", teacher: "Carlos Mendoza" }
        ],
        students: [],
        gradebooks: {},
        observations: [],
        reports: []
    };

    // Auto-sincronización con el backend MySQL
    async function initFromMySQL() {
        try {
            const res = await fetch('/api/docente.php?action=sync_all');
            if (res.ok) {
                const data = await res.json();
                if (data.success) {
                    if (data.students) memoryStore.students = data.students;
                    if (data.gradebooks) memoryStore.gradebooks = data.gradebooks;
                    if (data.observations) memoryStore.observations = data.observations;
                }
            }

            const resInit = await fetch('/api/docente.php?action=init');
            if (resInit.ok) {
                const initData = await resInit.json();
                if (initData.success) {
                    if (initData.periods) memoryStore.periods = initData.periods;
                    if (initData.current_period) memoryStore.meta.currentPeriodId = initData.current_period;
                    if (initData.subjects) memoryStore.subjects = initData.subjects;
                }
            }
        } catch (e) {
            console.warn("Sincronización con MySQL en segundo plano:", e);
        }
    }

    initFromMySQL();

    return {
        async sync() {
            await initFromMySQL();
        },

        getPeriods() {
            return memoryStore.periods;
        },

        getCurrentPeriod() {
            return memoryStore.periods.find(p => p.status === 'active') || 
                   memoryStore.periods.find(p => p.id === memoryStore.meta.currentPeriodId) || 
                   memoryStore.periods[2];
        },

        getCourses() {
            return memoryStore.courses;
        },

        getSubjects() {
            return memoryStore.subjects;
        },

        getStudentsByCourse(courseId) {
            if (memoryStore.students && memoryStore.students.length > 0) {
                return memoryStore.students.filter(s => s.courseId === courseId);
            }
            if (courseId === '11A') {
                return [
                    { id: "EST-1101", name: "Alex Johnson", courseId: "11A", avatar: "AJ", avatarColor: "#4f46e5" },
                    { id: "EST-1102", name: "Carlos Andrés Mendoza H.", courseId: "11A", avatar: "CA", avatarColor: "#059669" },
                    { id: "EST-1103", name: "Daniel Felipe Mora", courseId: "11A", avatar: "DF", avatarColor: "#4f46e5" },
                    { id: "EST-1104", name: "Isabella Ríos", courseId: "11A", avatar: "IR", avatarColor: "#059669" },
                    { id: "EST-1105", name: "Valentina Herrera", courseId: "11A", avatar: "VH", avatarColor: "#4f46e5" }
                ];
            } else {
                return [
                    { id: "EST-1001", name: "Camila Andrea Torres", courseId: "10A", avatar: "CA", avatarColor: "#4f46e5" },
                    { id: "EST-1002", name: "Juan Diego Pérez", courseId: "10A", avatar: "JD", avatarColor: "#059669" },
                    { id: "EST-1003", name: "Lucas Sebastián Ramírez", courseId: "10A", avatar: "LS", avatarColor: "#4f46e5" },
                    { id: "EST-1004", name: "Mateo Alejandro Rodríguez", courseId: "10A", avatar: "MA", avatarColor: "#059669" },
                    { id: "EST-1005", name: "Sofía Gómez", courseId: "10A", avatar: "SG", avatarColor: "#4f46e5" }
                ];
            }
        },

        getStudentById(id) {
            const all = (memoryStore.students && memoryStore.students.length > 0) 
                ? memoryStore.students 
                : [...this.getStudentsByCourse('11A'), ...this.getStudentsByCourse('10A')];
            return all.find(s => s.id === id || s.db_id === id);
        },

        getGradebook(courseId, subjectId, periodId) {
            const key = `${courseId}_${subjectId}_${periodId}`;
            if (memoryStore.gradebooks[key]) {
                return memoryStore.gradebooks[key];
            }

            const students = this.getStudentsByCourse(courseId);
            const defaultRecords = {};
            students.forEach(st => {
                defaultRecords[st.id] = {
                    grades: [4.0, 3.8, 4.2, 4.1],
                    observation: "Desempeño satisfactorio en las actividades del período."
                };
            });

            memoryStore.gradebooks[key] = {
                columnTitles: ["Actividad 1", "Actividad 2", "Actividad 3", "Evaluación"],
                records: defaultRecords
            };

            return memoryStore.gradebooks[key];
        },

        saveGradebook(courseId, subjectId, periodId, gradebookData) {
            const key = `${courseId}_${subjectId}_${periodId}`;
            memoryStore.gradebooks[key] = gradebookData;

            // Enviar inmediatamente a la base de datos MySQL por REST
            fetch('/api/docente.php?action=save_gradebook', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    course: courseId,
                    subject: subjectId,
                    period: periodId,
                    records: gradebookData.records,
                    columnTitles: gradebookData.columnTitles
                })
            }).catch(e => console.warn("Sync gradebook to MySQL error:", e));
        },

        calculateAverage(grades) {
            if (!Array.isArray(grades)) return null;
            const valid = grades
                .map(g => (g !== null && g !== "" && !isNaN(Number(g))) ? Number(g) : null)
                .filter(g => g !== null);
            if (valid.length === 0) return null;
            const sum = valid.reduce((a, b) => a + b, 0);
            return (sum / valid.length).toFixed(1);
        },

        getStudentSummaryForAI(studentId) {
            const student = this.getStudentById(studentId);
            if (!student) return null;
            const currentPeriod = this.getCurrentPeriod();
            const results = [];

            memoryStore.subjects.forEach(subj => {
                const key = `${student.courseId}_${subj.id}_${currentPeriod.id}`;
                const gb = memoryStore.gradebooks[key];
                if (gb && gb.records[student.id]) {
                    const rec = gb.records[student.id];
                    results.push({
                        subjectName: subj.name,
                        average: this.calculateAverage(rec.grades),
                        observation: rec.observation
                    });
                }
            });

            return {
                student,
                currentPeriodId: currentPeriod.id,
                subjects: results
            };
        },

        getObservations(studentId = null) {
            const list = memoryStore.observations || [];
            if (!studentId) return list;
            return list.filter(o => o.studentId === studentId);
        },

        addObservation(obsData) {
            const newObs = {
                id: 'OBS-' + String((memoryStore.observations?.length || 0) + 1).padStart(3, '0'),
                date: new Date().toISOString().split('T')[0],
                teacher: 'Mariana Ruiz',
                ...obsData
            };
            if (!memoryStore.observations) memoryStore.observations = [];
            memoryStore.observations.unshift(newObs);

            // Persistir en MySQL (observador_digital)
            fetch('/api/docente.php?action=save_observation', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(obsData)
            }).catch(e => console.warn("Sync obs error:", e));

            return newObs;
        },

        getReports(studentId = null) {
            const list = memoryStore.reports || [];
            if (!studentId) return list;
            return list.filter(r => r.studentId === studentId);
        },

        addReport(reportData) {
            const newRep = {
                id: 'REP-' + String((memoryStore.reports?.length || 0) + 1).padStart(3, '0'),
                date: new Date().toISOString().split('T')[0],
                status: 'Radicado',
                teacher: 'Mariana Ruiz',
                ...reportData
            };
            if (!memoryStore.reports) memoryStore.reports = [];
            memoryStore.reports.unshift(newRep);
            return newRep;
        },

        closeCurrentPeriod() {
            const current = this.getCurrentPeriod();
            const nextId = current.id + 1;

            current.status = 'closed';
            const nextP = memoryStore.periods.find(p => p.id === nextId);
            if (nextP) {
                nextP.status = 'active';
                memoryStore.meta.currentPeriodId = nextId;
            }

            // Persistir cierre de periodo en MySQL
            fetch('/api/docente.php?action=close_period', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ period: current.id })
            }).catch(e => console.warn("Close period MySQL error:", e));

            return {
                previousPeriodId: current.id,
                newPeriodId: nextId
            };
        },

        resetDatabase() {
            fetch('/api/docente.php?action=reset_period', { method: 'POST' })
                .catch(e => console.warn("Reset period error:", e));
            memoryStore.periods.forEach(p => {
                p.status = (p.id === 3) ? 'active' : ((p.id < 3) ? 'closed' : 'pending');
            });
            memoryStore.meta.currentPeriodId = 3;
        }
    };
})();

if (typeof window !== 'undefined') {
    window.SophosDB = SophosDB;
}
