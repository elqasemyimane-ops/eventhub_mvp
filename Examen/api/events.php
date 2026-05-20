<?php

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../database/connection.php';

$pdo = getDB();

function searchEvents(PDO $pdo, array $filters = []): array
{
    $conditions = ["1=1"];
    $params     = [];

    // ─────────────────────────
    // Catégorie
    // ─────────────────────────
    if (!empty($filters['category'])) {

        $conditions[] = "c.name = :category";

        $params[':category'] = $filters['category'];
    }

    // ─────────────────────────
    // Date min
    // ─────────────────────────
    if (!empty($filters['date_from'])) {

        $conditions[] = "e.date >= :date_from";

        $params[':date_from'] = $filters['date_from'];
    }

    // ─────────────────────────
    // Date max
    // ─────────────────────────
    if (!empty($filters['date_to'])) {

        $conditions[] = "e.date <= :date_to";

        $params[':date_to'] = $filters['date_to'];
    }

    // ─────────────────────────
    // Places disponibles
    // ─────────────────────────
    if (!empty($filters['available'])) {

        $conditions[] = "(e.capacity - (
                            SELECT COUNT(*)
                            FROM registrations r
                            WHERE r.event_id = e.id
                         )) > 0";
    }

    // ─────────────────────────
    // Recherche
    // ─────────────────────────
    if (!empty($filters['keyword'])) {

        $conditions[] = "(e.title LIKE :kw OR e.description LIKE :kw)";

        $params[':kw'] = '%' . $filters['keyword'] . '%';
    }

    // ─────────────────────────
    // Tabs
    // ─────────────────────────
    if (!empty($filters['tab'])) {

        if ($filters['tab'] === 'full') {

            $conditions[] = "(SELECT COUNT(*)
                              FROM registrations r
                              WHERE r.event_id = e.id) >= e.capacity";
        }

        elseif ($filters['tab'] === 'upcoming') {

            $conditions[] = "(SELECT COUNT(*)
                              FROM registrations r
                              WHERE r.event_id = e.id) < e.capacity";

            $conditions[] = "e.date > NOW()";
        }
    }

    $where = implode(' AND ', $conditions);

    $sql = "
        SELECT
            e.id,
            e.title,
            e.description,
            e.date,
            e.location AS loc,
            e.capacity AS cap,
            e.organizer_email,

            c.name  AS cat,
            c.color AS color,

            (
                SELECT COUNT(*)
                FROM registrations r
                WHERE r.event_id = e.id
            ) AS reg,

            (
                e.capacity - (
                    SELECT COUNT(*)
                    FROM registrations r
                    WHERE r.event_id = e.id
                )
            ) AS places_left

        FROM events e

        JOIN categories c
        ON c.id = e.category_id

        WHERE $where

        ORDER BY e.date ASC
    ";

    try {

        $stmt = $pdo->prepare($sql);

        $stmt->execute($params);

        return $stmt->fetchAll();

    } catch (PDOException $e) {

        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage()
        ]);

        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $filters = [

        'category'  => $body['cat'] ?? '',

        'date_from' => $body['dateFrom'] ?? '',

        'date_to'   => $body['dateTo'] ?? '',

        'available' => $body['pl'] ?? false,

        'keyword'   => $body['kw'] ?? '',

        'tab'       => $body['tab'] ?? 'all',
    ];

    $events = searchEvents($pdo, $filters);

    echo json_encode($events);
}