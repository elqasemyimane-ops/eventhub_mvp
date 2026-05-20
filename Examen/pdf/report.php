<?php
/*
 * CHOIX : Dompdf pour la cohérence avec ticket.php.
 * Le graphique en barres (Partie 3.2 page 3) est généré en PHP pur
 * via SVG inline dans le HTML — Dompdf supporte SVG nativement,
 * ce qui évite de manipuler des primitives bas-niveau TCPDF tout en
 * restant côté PHP sans JavaScript.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../database/connection.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Génère le rapport PDF multi-pages pour l'organisateur.
 */
function generateReport(PDO $pdo, int $eventId): void
{
    // ── Données événement ────────────────────────────────────────────────────
    $stmtEvent = $pdo->prepare(
        "SELECT e.*, c.name AS cat, c.color,
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id) AS reg_count
         FROM events e
         JOIN categories c ON c.id = e.category_id
         WHERE e.id = :id"
    );
    $stmtEvent->execute([':id' => $eventId]);
    $event = $stmtEvent->fetch();

    if (!$event) {
        http_response_code(404);
        exit(json_encode(['error' => 'Événement introuvable']));
    }

    $fillRate      = round($event['reg_count'] / $event['capacity'] * 100, 1);
    $dateFormatted = date('d/m/Y à H:i', strtotime($event['date']));
    $generatedAt   = date('d/m/Y H:i:s');

    // ── Liste des inscrits (triée par nom) ───────────────────────────────────
    $stmtRegs = $pdo->prepare(
        "SELECT full_name, email, registered_at
         FROM registrations
         WHERE event_id = :id
         ORDER BY full_name ASC"
    );
    $stmtRegs->execute([':id' => $eventId]);
    $registrations = $stmtRegs->fetchAll();

    // ── Inscriptions par jour (7 derniers jours) ─────────────────────────────
    $stmtStats = $pdo->prepare(
        "SELECT DATE(registered_at) AS jour,
                COUNT(*) AS total
         FROM registrations
         WHERE event_id = :id
           AND registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(registered_at)
         ORDER BY jour ASC"
    );
    $stmtStats->execute([':id' => $eventId]);
    $dailyStats = $stmtStats->fetchAll();

    // ── Graphique en barres SVG (PHP pur, pas de JS) ─────────────────────────
    $chartWidth  = 480;
    $chartHeight = 200;
    $barColor    = $event['color'] ?? '#2563eb';
    $maxVal      = max(array_column($dailyStats, 'total') ?: [1]);
    $barCount    = count($dailyStats);
    $barWidth    = $barCount > 0 ? min(50, (int)(($chartWidth - 60) / $barCount) - 8) : 40;
    $gap         = $barCount > 0 ? (int)(($chartWidth - 60) / $barCount) : 50;

    $svgBars = '';
    foreach ($dailyStats as $i => $day) {
        $barH  = (int)(($day['total'] / $maxVal) * ($chartHeight - 40));
        $x     = 40 + $i * $gap + ($gap - $barWidth) / 2;
        $y     = $chartHeight - 30 - $barH;
        $label = date('d/m', strtotime($day['jour']));

        $svgBars .= "
            <!-- Barre -->
            <rect x='{$x}' y='{$y}' width='{$barWidth}' height='{$barH}'
                  fill='{$barColor}' rx='4'/>
            <!-- Valeur au-dessus -->
            <text x='" . ($x + $barWidth / 2) . "' y='" . ($y - 5) . "'
                  text-anchor='middle' font-size='11' fill='#1e293b' font-weight='bold'>
                {$day['total']}
            </text>
            <!-- Label date -->
            <text x='" . ($x + $barWidth / 2) . "' y='" . ($chartHeight - 10) . "'
                  text-anchor='middle' font-size='10' fill='#64748b'>
                $label
            </text>";
    }

    // Axes SVG
    $svgChart = "
    <svg width='{$chartWidth}' height='{$chartHeight}'
         xmlns='http://www.w3.org/2000/svg'>
        <!-- Axe Y -->
        <line x1='38' y1='10' x2='38' y2='" . ($chartHeight - 25) . "'
              stroke='#e2e8f0' stroke-width='1'/>
        <!-- Axe X -->
        <line x1='38' y1='" . ($chartHeight - 25) . "'
              x2='{$chartWidth}' y2='" . ($chartHeight - 25) . "'
              stroke='#e2e8f0' stroke-width='1'/>
        <!-- Graduations Y -->
        <text x='5' y='" . ($chartHeight - 25) . "'
              font-size='9' fill='#94a3b8'>0</text>
        <text x='5' y='" . (($chartHeight - 25) / 2) . "'
              font-size='9' fill='#94a3b8'>" . (int)($maxVal / 2) . "</text>
        <text x='5' y='15' font-size='9' fill='#94a3b8'>{$maxVal}</text>
        {$svgBars}
    </svg>";

    // ── Tableau des inscrits HTML ─────────────────────────────────────────────
    $tableRows = '';
    foreach ($registrations as $i => $reg) {
        $bg   = $i % 2 === 0 ? '#f8fafc' : '#fff';
        $date = date('d/m/Y H:i', strtotime($reg['registered_at']));
        $tableRows .= "
        <tr style='background:{$bg}'>
            <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0'>
                " . ($i + 1) . "
            </td>
            <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;font-weight:bold'>
                {$reg['full_name']}
            </td>
            <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;color:#64748b'>
                {$reg['email']}
            </td>
            <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;color:#64748b'>
                $date
            </td>
        </tr>";
    }

    // ── Couleur selon taux de remplissage ─────────────────────────────────────
    $fillColor = $fillRate >= 100 ? '#dc2626'
               : ($fillRate >= 80 ? '#f59e0b' : '#16a34a');

    // ── HTML COMPLET 3 pages ──────────────────────────────────────────────────
    $html = "
    <!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: DejaVu Sans, sans-serif;
                color: #1e293b;
                font-size: 13px;
                line-height: 1.5;
            }
            /* PAGE BREAK */
            .page-break { page-break-after: always; }

            /* EN-TÊTE commun */
            .page-header {
                background: {$event['color']};
                color: #fff;
                padding: 18px 28px;
                margin-bottom: 24px;
            }
            .page-header .brand {
                font-size: 10px;
                letter-spacing: 2px;
                text-transform: uppercase;
                opacity: 0.7;
            }
            .page-header h2 { font-size: 18px; font-weight: bold; }

            /* PIED DE PAGE */
            .page-footer {
                position: fixed;
                bottom: 0; left: 0; right: 0;
                padding: 8px 28px;
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
                font-size: 9px;
                color: #94a3b8;
                text-align: center;
            }

            /* KPI CARDS page 1 */
            .kpi-grid {
                display: table;
                width: 100%;
                margin-bottom: 20px;
            }
            .kpi-card {
                display: table-cell;
                width: 25%;
                padding: 16px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                text-align: center;
                vertical-align: middle;
            }
            .kpi-value {
                font-size: 28px;
                font-weight: bold;
                line-height: 1;
                margin-bottom: 4px;
            }
            .kpi-label {
                font-size: 10px;
                color: #64748b;
                letter-spacing: 1px;
                text-transform: uppercase;
            }

            /* TABLEAU page 2 */
            table { width: 100%; border-collapse: collapse; }
            th {
                background: {$event['color']};
                color: #fff;
                padding: 10px 12px;
                text-align: left;
                font-size: 11px;
                letter-spacing: 1px;
                text-transform: uppercase;
            }

            /* Numérotation pages */
            @page {
                margin: 60px 28px 50px 28px;
                @bottom-center {
                    content: 'Page ' counter(page) ' / ' counter(pages)
                             ' · Généré le $generatedAt · EventHub Pro';
                    font-size: 9px;
                    color: #94a3b8;
                }
            }
        </style>
    </head>
    <body>

    <!-- ═══════════════════════════════════════════════
         PAGE 1 — RÉSUMÉ EXÉCUTIF
    ═══════════════════════════════════════════════ -->
    <div class='page-header'>
        <div class='brand'>EventHub Pro · Rapport de gestion</div>
        <h2>{$event['title']}</h2>
    </div>

    <div style='padding:0 28px'>
        <h3 style='font-size:14px;margin-bottom:16px;color:#0f1f3d'>
            📊 Résumé exécutif
        </h3>

        <!-- KPI -->
        <table style='margin-bottom:20px'>
            <tr>
                <td style='padding:16px;background:#f8fafc;border:1px solid #e2e8f0;
                           border-radius:8px;text-align:center;width:25%'>
                    <div style='font-size:28px;font-weight:bold;color:{$event['color']}'>
                        {$event['reg_count']}
                    </div>
                    <div style='font-size:10px;color:#64748b;text-transform:uppercase'>
                        Inscrits
                    </div>
                </td>
                <td style='width:4px'></td>
                <td style='padding:16px;background:#f8fafc;border:1px solid #e2e8f0;
                           border-radius:8px;text-align:center;width:25%'>
                    <div style='font-size:28px;font-weight:bold;color:#64748b'>
                        {$event['capacity']}
                    </div>
                    <div style='font-size:10px;color:#64748b;text-transform:uppercase'>
                        Capacité
                    </div>
                </td>
                <td style='width:4px'></td>
                <td style='padding:16px;background:#f8fafc;border:1px solid #e2e8f0;
                           border-radius:8px;text-align:center;width:25%'>
                    <div style='font-size:28px;font-weight:bold;color:{$fillColor}'>
                        {$fillRate}%
                    </div>
                    <div style='font-size:10px;color:#64748b;text-transform:uppercase'>
                        Taux remplissage
                    </div>
                </td>
                <td style='width:4px'></td>
                <td style='padding:16px;background:#f8fafc;border:1px solid #e2e8f0;
                           border-radius:8px;text-align:center;width:25%'>
                    <div style='font-size:28px;font-weight:bold;color:#0d9488'>
                        " . ($event['capacity'] - $event['reg_count']) . "
                    </div>
                    <div style='font-size:10px;color:#64748b;text-transform:uppercase'>
                        Places libres
                    </div>
                </td>
            </tr>
        </table>

        <!-- Barre de progression -->
        <div style='margin-bottom:24px'>
            <div style='display:flex;justify-content:space-between;
                        font-size:11px;color:#64748b;margin-bottom:6px'>
                <span>Taux de remplissage</span>
                <span>{$fillRate}%</span>
            </div>
            <div style='background:#e2e8f0;border-radius:99px;height:12px;overflow:hidden'>
                <div style='width:{$fillRate}%;background:{$fillColor};
                            height:100%;border-radius:99px'></div>
            </div>
        </div>

        <!-- Infos événement -->
        <div style='background:#f8fafc;border-left:4px solid {$event['color']};
                    padding:16px;border-radius:0 8px 8px 0;margin-bottom:16px'>
            <div style='margin-bottom:8px'>
                <span style='font-size:10px;color:#94a3b8;
                             text-transform:uppercase;letter-spacing:1px'>
                    Date
                </span><br>
                <span style='font-weight:bold'>{$dateFormatted}</span>
            </div>
            <div style='margin-bottom:8px'>
                <span style='font-size:10px;color:#94a3b8;
                             text-transform:uppercase;letter-spacing:1px'>
                    Lieu
                </span><br>
                <span style='font-weight:bold'>{$event['location']}</span>
            </div>
            <div>
                <span style='font-size:10px;color:#94a3b8;
                             text-transform:uppercase;letter-spacing:1px'>
                    Catégorie
                </span><br>
                <span style='font-weight:bold'>{$event['cat']}</span>
            </div>
        </div>
    </div>

    <div class='page-break'></div>

    <!-- ═══════════════════════════════════════════════
         PAGE 2 — LISTE DES INSCRITS
    ═══════════════════════════════════════════════ -->
    <div class='page-header'>
        <div class='brand'>EventHub Pro · Rapport de gestion</div>
        <h2>Liste des inscrits — {$event['title']}</h2>
    </div>

    <div style='padding:0 28px'>
        <p style='color:#64748b;font-size:12px;margin-bottom:16px'>
            {$event['reg_count']} inscrit(s) · Triés par nom alphabétique
        </p>
        <table>
            <thead>
                <tr>
                    <th style='width:5%'>#</th>
                    <th style='width:35%'>Nom complet</th>
                    <th style='width:35%'>Email</th>
                    <th style='width:25%'>Date inscription</th>
                </tr>
            </thead>
            <tbody>
                {$tableRows}
            </tbody>
        </table>
    </div>

    <div class='page-break'></div>

    <!-- ═══════════════════════════════════════════════
         PAGE 3 — STATISTIQUES VISUELLES
    ═══════════════════════════════════════════════ -->
    <div class='page-header'>
        <div class='brand'>EventHub Pro · Rapport de gestion</div>
        <h2>Statistiques — Inscriptions par jour</h2>
    </div>

    <div style='padding:0 28px'>
        <p style='color:#64748b;font-size:12px;margin-bottom:20px'>
            Évolution des inscriptions sur les 7 derniers jours
        </p>

        <!-- Graphique SVG généré en PHP pur -->
        <div style='background:#f8fafc;border:1px solid #e2e8f0;
                    border-radius:8px;padding:20px;margin-bottom:24px'>
            {$svgChart}
        </div>

        <!-- Légende -->
        <div style='display:flex;gap:16px;font-size:11px;color:#64748b'>
            <span>
                <span style='display:inline-block;width:12px;height:12px;
                             background:{$event['color']};border-radius:2px;
                             margin-right:4px'></span>
                Inscriptions journalières
            </span>
        </div>

        <!-- Tableau récap stats -->
        <div style='margin-top:24px'>
            <h3 style='font-size:13px;margin-bottom:12px;color:#0f1f3d'>
                Récapitulatif
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Nouveaux inscrits</th>
                        <th>Total cumulé</th>
                    </tr>
                </thead>
                <tbody>";

    $cumul = 0;
    foreach ($dailyStats as $i => $day) {
        $cumul += $day['total'];
        $bg = $i % 2 === 0 ? '#f8fafc' : '#fff';
        $html .= "
                    <tr style='background:{$bg}'>
                        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0'>
                            " . date('d/m/Y', strtotime($day['jour'])) . "
                        </td>
                        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;
                                   font-weight:bold;color:{$event['color']}'>
                            +{$day['total']}
                        </td>
                        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0'>
                            {$cumul}
                        </td>
                    </tr>";
    }

    $html .= "
                </tbody>
            </table>
        </div>
    </div>

    </body>
    </html>";

    // ── Génération PDF ───────────────────────────────────────────────────────
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(
        'rapport_' . $eventId . '_' . date('Ymd') . '.pdf',
        ['Attachment' => true]
    );
}

// ── Point d'entrée ───────────────────────────────────────────────────────────
$eventId = (int)($_GET['event_id'] ?? 0);
if (!$eventId) {
    http_response_code(400);
    exit('event_id manquant');
}
generateReport($pdo, $eventId);