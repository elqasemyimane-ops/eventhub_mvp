<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — api/stats.php                               ║
 * ║  Endpoint AJAX — Statistiques temps réel (Dashboard)        ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fallback MVP — auto-login si pas de session active
if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role']  = 'organizer';
    $_SESSION['user_email'] = 'admin@ensa.ma';
}

try {
    $pdo = getDB();

    // ── ÉTAPE 2 : Summary ─────────────────────────────────────────────────
    // COALESCE(cnt.reg, 0) évite la division par NULL quand aucune inscription
    $stmtSummary = $pdo->query(
        "SELECT
            COUNT(DISTINCT e.id)                                                AS total_events,
            COALESCE(SUM(cnt.reg), 0)                                           AS total_registered,
            COALESCE(SUM(r24.cnt24), 0)                                         AS new_last_24h,
            ROUND(AVG(ROUND(COALESCE(cnt.reg, 0) / e.capacity * 100)))          AS avg_fill_pct,
            SUM(ROUND(COALESCE(cnt.reg, 0) / e.capacity * 100) >= 80)           AS alert_count
         FROM events e
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS reg
             FROM registrations
             GROUP BY event_id
         ) cnt ON cnt.event_id = e.id
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS cnt24
             FROM registrations
             WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY event_id
         ) r24 ON r24.event_id = e.id"
    );
    $summary = $stmtSummary->fetch();

    $summary = [
        'total_events'     => (int) ($summary['total_events']     ?? 0),
        'total_registered' => (int) ($summary['total_registered'] ?? 0),
        'new_last_24h'     => (int) ($summary['new_last_24h']     ?? 0),
        'avg_fill_pct'     => (int) ($summary['avg_fill_pct']     ?? 0),
        'alert_count'      => (int) ($summary['alert_count']      ?? 0),
    ];

    // ── ÉTAPE 3 : Top 3 ───────────────────────────────────────────────────
    $stmtTop3 = $pdo->query(
        "SELECT e.id,
                e.title,
                e.capacity,
                COALESCE(COUNT(r.id), 0)                                    AS reg,
                ROUND(COALESCE(COUNT(r.id), 0) / e.capacity * 100)         AS fill_pct
         FROM   events e
         LEFT JOIN registrations r ON r.event_id = e.id
         GROUP  BY e.id
         ORDER  BY fill_pct DESC
         LIMIT  3"
    );
    $top3 = array_map(function($row) {
        return [
            'id'        => (int) $row['id'],
            'title'     => $row['title'],
            'capacity'  => (int) $row['capacity'],
            'reg'       => (int) $row['reg'],
            'available' => (int) ($row['capacity'] - $row['reg']),
            'fill_pct'  => (int) $row['fill_pct'],
        ];
    }, $stmtTop3->fetchAll());

    // ── ÉTAPE 4 : Per event ───────────────────────────────────────────────
    $stmtPerEvent = $pdo->query(
        "SELECT e.id,
                e.title,
                e.capacity,
                COALESCE(COUNT(r.id), 0)                                    AS registered,
                ROUND(COALESCE(COUNT(r.id), 0) / e.capacity * 100)         AS fill_pct,
                (COALESCE(COUNT(r.id), 0) >= e.capacity)                   AS is_full
         FROM   events e
         LEFT JOIN registrations r ON r.event_id = e.id
         GROUP  BY e.id
         ORDER  BY e.event_date ASC"
    );
    $perEvent = array_map(function($row) {
        return [
            'id'         => (int)  $row['id'],
            'title'      => $row['title'],
            'capacity'   => (int)  $row['capacity'],
            'registered' => (int)  $row['registered'],
            'available'  => (int)  ($row['capacity'] - $row['registered']),
            'fill_pct'   => (int)  $row['fill_pct'],
            'is_full'    => (bool) $row['is_full'],
        ];
    }, $stmtPerEvent->fetchAll());

    // ── ÉTAPE 5 : Par jour ────────────────────────────────────────────────
    $stmtByDay = $pdo->query(
        "SELECT DATE(registered_at) AS day,
                COUNT(*)            AS count
         FROM   registrations
         WHERE  registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP  BY DATE(registered_at)
         ORDER  BY DATE(registered_at) ASC"
    );
    $byDay = array_map(function($row) {
        return [
            'day'   => $row['day'],
            'count' => (int) $row['count'],
        ];
    }, $stmtByDay->fetchAll());

    echo json_encode([
        'success'              => true,
        'generated_at'         => date('Y-m-d H:i:s'),
        'summary'              => $summary,
        'top3'                 => $top3,
        'per_event'            => $perEvent,
        'registrations_by_day' => $byDay,
    ]);

} catch (PDOException $e) {
    error_log('[EventHub] api/stats.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}