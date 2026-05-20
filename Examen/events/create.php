<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/connection.php';

function createEvent(PDO $pdo, array $data): array
{
    $required = ['title', 'description', 'date', 'location',
                 'capacity', 'category_id', 'organizer_email'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'error' => "Champ manquant : $field"];
        }
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO events
                 (title, description, date, location,
                  capacity, category_id, organizer_email)
             VALUES
                 (:title, :description, :date, :location,
                  :capacity, :category_id, :organizer_email)"
        );
        $stmt->bindValue(':title',           htmlspecialchars($data['title']),       PDO::PARAM_STR);
        $stmt->bindValue(':description',     htmlspecialchars($data['description']), PDO::PARAM_STR);
        $stmt->bindValue(':date',            $data['date'],                          PDO::PARAM_STR);
        $stmt->bindValue(':location',        htmlspecialchars($data['location']),    PDO::PARAM_STR);
        $stmt->bindValue(':capacity',        (int)$data['capacity'],                PDO::PARAM_INT);
        $stmt->bindValue(':category_id',     (int)$data['category_id'],             PDO::PARAM_INT);
        $stmt->bindValue(':organizer_email', filter_var($data['organizer_email'],
                                             FILTER_SANITIZE_EMAIL),                PDO::PARAM_STR);
        $stmt->execute();

        return ['success' => true, 'id' => (int)$pdo->lastInsertId()];

    } catch (PDOException $e) {
        error_log('[createEvent] ' . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur base de données.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'error' => 'JSON invalide']));
    }

    // Mapper catégorie texte → id
    $catMap = ['tech' => 1, 'design' => 2, 'business' => 3, 'science' => 4];
    $data['category_id'] = $catMap[$data['cat'] ?? ''] ?? 1;

    $result = createEvent($pdo, $data);
    http_response_code($result['success'] ? 201 : 422);
    echo json_encode($result);
}