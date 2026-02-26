<?php
/**
 * API: Treinamentos
 * GET /api/training.php - Listar cursos
 * POST /api/training.php - Marcar progresso
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) { jsonError('Erro de conexão', 500); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $courseId = $_GET['course_id'] ?? null;
    
    try {
        if ($courseId) {
            // Detalhes do curso
            $stmt = $db->prepare("
                SELECT c.*, wp.progress, wp.completed, wp.completed_at
                FROM " . table('training_courses') . " c
                LEFT JOIN " . table('worker_training') . " wp ON wp.course_id = c.id AND wp.worker_id = ?
                WHERE c.id = ? AND c.is_active = 1
            ");
            $stmt->execute([$workerId, $courseId]);
            $course = $stmt->fetch();
            
            if (!$course) { jsonError('Curso não encontrado', 404); }
            
            // Módulos do curso
            $stmt = $db->prepare("
                SELECT m.*, wm.completed as module_completed, wm.completed_at as module_completed_at
                FROM " . table('training_modules') . " m
                LEFT JOIN " . table('worker_training_modules') . " wm ON wm.module_id = m.id AND wm.worker_id = ?
                WHERE m.course_id = ?
                ORDER BY m.sort_order
            ");
            $stmt->execute([$workerId, $courseId]);
            $course['modules'] = $stmt->fetchAll();
            
            jsonSuccess(['course' => $course]);
        }
        
        // Listar cursos
        $stmt = $db->prepare("
            SELECT 
                c.id, c.title, c.description, c.duration_minutes, c.thumbnail, c.is_required,
                COALESCE(wp.progress, 0) as progress,
                COALESCE(wp.completed, 0) as completed
            FROM " . table('training_courses') . " c
            LEFT JOIN " . table('worker_training') . " wp ON wp.course_id = c.id AND wp.worker_id = ?
            WHERE c.is_active = 1
            ORDER BY c.is_required DESC, c.sort_order
        ");
        $stmt->execute([$workerId]);
        $courses = $stmt->fetchAll();
        
        // Estatísticas
        $totalCourses = count($courses);
        $completedCourses = count(array_filter($courses, fn($c) => $c['completed']));
        $requiredPending = count(array_filter($courses, fn($c) => $c['is_required'] && !$c['completed']));
        
        jsonSuccess([
            'courses' => $courses,
            'stats' => [
                'total' => $totalCourses,
                'completed' => $completedCourses,
                'required_pending' => $requiredPending
            ]
        ]);
        
    } catch (Exception $e) {
        jsonError('Erro ao buscar treinamentos', 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $courseId = $input['course_id'] ?? null;
    $moduleId = $input['module_id'] ?? null;
    $action = $input['action'] ?? 'complete_module';
    
    if (!$courseId) { jsonError('ID do curso é obrigatório'); }
    
    try {
        $db->beginTransaction();
        
        if ($action === 'complete_module' && $moduleId) {
            // Marcar módulo como concluído
            $stmt = $db->prepare("
                INSERT INTO " . table('worker_training_modules') . " (worker_id, course_id, module_id, completed, completed_at)
                VALUES (?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE completed = 1, completed_at = NOW()
            ");
            $stmt->execute([$workerId, $courseId, $moduleId]);
            
            // Calcular progresso do curso
            $stmt = $db->prepare("SELECT COUNT(*) FROM " . table('training_modules') . " WHERE course_id = ?");
            $stmt->execute([$courseId]);
            $totalModules = $stmt->fetchColumn();
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM " . table('worker_training_modules') . " WHERE worker_id = ? AND course_id = ? AND completed = 1");
            $stmt->execute([$workerId, $courseId]);
            $completedModules = $stmt->fetchColumn();
            
            $progress = $totalModules > 0 ? round(($completedModules / $totalModules) * 100) : 0;
            $courseCompleted = $progress >= 100;
            
            // Atualizar progresso do curso
            $stmt = $db->prepare("
                INSERT INTO " . table('worker_training') . " (worker_id, course_id, progress, completed, completed_at)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE progress = ?, completed = ?, completed_at = ?
            ");
            $completedAt = $courseCompleted ? date('Y-m-d H:i:s') : null;
            $stmt->execute([$workerId, $courseId, $progress, $courseCompleted ? 1 : 0, $completedAt, $progress, $courseCompleted ? 1 : 0, $completedAt]);
        }
        
        $db->commit();
        jsonSuccess(['progress' => $progress ?? 0, 'completed' => $courseCompleted ?? false], 'Progresso salvo');
        
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Erro ao salvar progresso', 500);
    }
}

jsonError('Método não permitido', 405);
