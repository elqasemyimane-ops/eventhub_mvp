<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

function generateTicketPDF(
    PDO $pdo,
    int $registrationId,
    string $token,
    string $output = 'D',
    string $filePath = ''
) {
    $stmt = $pdo->prepare(
        'SELECT r.id AS registration_id,
                r.event_id,
                r.name,
                r.email,
                r.registered_at,
                e.title,
                e.event_date,
                e.location,
                e.category,
                e.capacity,
                COUNT(reg2.id) AS registered_count
         FROM registrations r
         JOIN events e ON e.id = r.event_id
         LEFT JOIN registrations reg2 ON reg2.event_id = e.id
         WHERE r.id = :rid AND r.token = :token
         GROUP BY r.id, e.id'
    );
    $stmt->execute([':rid' => $registrationId, ':token' => $token]);
    $data = $stmt->fetch();

    if (!$data) {
        if (php_sapi_name() !== 'cli') {
            http_response_code(404);
        }
        throw new RuntimeException('Inscription introuvable ou token invalide.');
    }

    $colors = categoryColors((string)$data['category']);
    $qrData = $data['event_id'] . '|' . $registrationId . '|' . $token;
    $qrSvg = (new QRCode(new QROptions(['outputType' => QRCode::OUTPUT_MARKUP_SVG])))->render($qrData);
    $qrUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);
    $unsubscribe = appBaseUrl() . '/events/unsubscribe.php?token=' . rawurlencode($token);

    $html = renderTicketHTML($data, $colors, $qrUri, $unsubscribe);
    $dompdf = createDompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A5', 'landscape');
    $dompdf->render();

    return outputPdf($dompdf, 'ticket_' . $registrationId . '.pdf', $output, $filePath);
}

function renderTicketHTML(array $data, array $colors, string $qrUri, string $unsubscribe): string
{
    $date = formatDateFr((string)$data['event_date']);
    $registered = formatDateFr((string)$data['registered_at']);
    $ticketNo = str_pad((string)$data['registration_id'], 5, '0', STR_PAD_LEFT);

    return '<!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 0; }
body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #0f172a; background: #f8fafc; }
.ticket { width: 100%; height: 100%; border-top: 14px solid ' . h($colors['primary']) . '; }
.wrap { padding: 22px 28px; }
.top { display: table; width: 100%; margin-bottom: 18px; }
.brand, .number { display: table-cell; vertical-align: middle; }
.brand h1 { margin: 0; font-size: 25px; color: #0f1f3d; }
.brand p { margin: 2px 0 0; font-size: 11px; color: #64748b; }
.number { text-align: right; font-size: 18px; font-weight: bold; color: ' . h($colors['primary']) . '; }
.main { display: table; width: 100%; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
.left, .right { display: table-cell; vertical-align: top; padding: 18px; }
.left { width: 68%; }
.right { width: 32%; text-align: center; background: ' . h($colors['light']) . '; }
h2 { margin: 0 0 12px; font-size: 22px; color: #0f172a; }
.meta { margin: 8px 0; font-size: 13px; color: #475569; }
.person { margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: 8px; font-size: 13px; }
.qr { width: 110px; height: 110px; margin-top: 8px; }
.chip { display: inline-block; margin-top: 13px; padding: 6px 12px; border-radius: 999px; background: ' . h($colors['primary']) . '; color: #fff; font-size: 11px; font-weight: bold; text-transform: uppercase; }
.creative { margin-top: 14px; padding: 10px 12px; border-left: 5px solid ' . h($colors['primary']) . '; background: #fff; font-size: 12px; color: #334155; }
.footer { margin-top: 12px; font-size: 9px; color: #64748b; word-break: break-all; }
</style></head><body>
<div class="ticket"><div class="wrap">
  <div class="top">
    <div class="brand"><h1>EventHub Pro</h1><p>ENSA Marrakech - Ticket officiel</p></div>
    <div class="number">TICKET N ' . h($ticketNo) . '</div>
  </div>
  <div class="main">
    <div class="left">
      <h2>' . h((string)$data['title']) . '</h2>
      <div class="meta"><strong>Date:</strong> ' . h($date) . '</div>
      <div class="meta"><strong>Lieu:</strong> ' . h((string)$data['location']) . '</div>
      <div class="person">
        <strong>Participant:</strong> ' . h((string)$data['name']) . '<br>
        <strong>Email:</strong> ' . h((string)$data['email']) . '<br>
        <strong>Inscrit le:</strong> ' . h($registered) . '
      </div>
      <div class="creative">Pass numerique verifiable: presentez ce QR code a l entree pour valider rapidement votre inscription.</div>
      <div class="footer">Desinscription: ' . h($unsubscribe) . '</div>
    </div>
   <div class="right">
  <div class="chip">' . h((string)$data['category']) . '</div><br>
  <img class="qr" src="' . h($qrUri) . '" alt="QR Code">
  <p style="font-size:10px;color:#475569;">' . h($data['event_id'] . '|' . $data['registration_id']) . '</p>
</div>
  </div>
</div></div></body></html>';
}

function createDompdf(): Dompdf
{
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    return new Dompdf($options);
}

function outputPdf(Dompdf $dompdf, string $filename, string $output, string $filePath = '')
{
    $pdf = $dompdf->output();
    if ($output === 'F') {
        if ($filePath === '') {
            $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        }
        file_put_contents($filePath, $pdf);
        return $filePath;
    }
    if ($output === 'S') {
        return $pdf;
    }
    $dompdf->stream($filename, ['Attachment' => true]);
    return null;
}

function categoryColors(string $category): array
{
    return [
        'tech' => ['primary' => '#2563EB', 'light' => '#DBEAFE'],
        'design' => ['primary' => '#7C3AED', 'light' => '#EDE9FE'],
        'business' => ['primary' => '#EA580C', 'light' => '#FEF3C7'],
        'science' => ['primary' => '#16A34A', 'light' => '#DCFCE7'],
    ][$category] ?? ['primary' => '#0F1F3D', 'light' => '#F8FAFC'];
}

function formatDateFr(string $date): string
{
    return date('d/m/Y H:i', strtotime($date));
}

function appBaseUrl(): string
{
    if (defined('APP_BASE_URL')) {
        return APP_BASE_URL;
    }
    return rtrim(getenv('APP_BASE_URL') ?: 'http://localhost/eventhub_mvp', '/');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (php_sapi_name() !== 'cli' && isset($_GET['registration_id'], $_GET['token'])) {
    try {
        generateTicketPDF(getDB(), (int)$_GET['registration_id'], (string)$_GET['token'], 'D');
    } catch (Throwable $e) {
        echo h($e->getMessage());
    }
}