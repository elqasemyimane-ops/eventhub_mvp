<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendCapacityAlert(PDO $pdo, array $event, string $pdfPath): bool
{
    // Anti-doublon : UNIQUE sur event_id → INSERT IGNORE
    $lock = $pdo->prepare(
        "INSERT IGNORE INTO capacity_alerts (event_id) VALUES (:event_id)"
    );
    $lock->execute([':event_id' => $event['id']]);

    if ($lock->rowCount() === 0) {
        return false; // Déjà envoyé pour cet événement
    }

    // Statistiques
    $stmtStats = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM registrations WHERE event_id = :id"
    );
    $stmtStats->execute([':id' => $event['id']]);
    $stats    = $stmtStats->fetch();
    $fillRate = round($stats['total'] / $event['capacity'] * 100, 1);

    $html = "
    <!DOCTYPE html>
    <html lang='fr'>
    <head><meta charset='UTF-8'></head>
    <body style='font-family:sans-serif;background:#f1f5f9;padding:20px'>
        <div style='max-width:520px;margin:auto;background:#fff;
                    border-radius:12px;padding:32px;box-shadow:0 4px 12px rgba(0,0,0,.08)'>
            <div style='background:#0f1f3d;padding:20px;border-radius:8px;
                        text-align:center;margin-bottom:24px'>
                <h1 style='color:#f59e0b;margin:0;font-size:22px'>EventHub Pro</h1>
            </div>
            <h2 style='color:#dc2626'>⚠️ Alerte capacité</h2>
            <p style='color:#475569'>
                L'événement <b>{$event['title']}</b> a atteint
                <b style='color:#dc2626'>{$fillRate}%</b> de sa capacité.
            </p>
            <div style='background:#fef2f2;border-left:4px solid #dc2626;
                        padding:16px;border-radius:0 8px 8px 0;margin:20px 0'>
                <p style='margin:4px 0;color:#1e293b'>
                    <b>👥 Inscrits :</b> {$stats['total']} / {$event['capacity']}
                </p>
                <p style='margin:4px 0;color:#1e293b'>
                    <b>📊 Taux de remplissage :</b> {$fillRate}%
                </p>
                <p style='margin:4px 0;color:#1e293b'>
                    <b>📍 Lieu :</b> {$event['location']}
                </p>
            </div>
            <p style='color:#475569'>
                Le rapport détaillé est joint à cet email en pièce jointe PDF.
            </p>
        </div>
    </body>
    </html>";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'VOTRE_USERNAME_MAILTRAP';
        $mail->Password   = 'VOTRE_PASSWORD_MAILTRAP';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('noreply@eventhub.ma', 'EventHub Pro — Alertes');
        $mail->addAddress($event['organizer_email']);
        $mail->isHTML(true);
        $mail->Subject = "⚠️ Alerte capacité {$fillRate}% — " . $event['title'];
        $mail->Body    = $html;
        $mail->AltBody = "Alerte : {$event['title']} est à {$fillRate}% "
                       . "({$stats['total']}/{$event['capacity']} inscrits)";

        // Pièce jointe PDF si disponible
        if ($pdfPath && file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, 'rapport_capacite.pdf');
        }

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Rollback pour permettre une nouvelle tentative
        $pdo->prepare("DELETE FROM capacity_alerts WHERE event_id = :id")
            ->execute([':id' => $event['id']]);
        error_log('[sendCapacityAlert] ' . $mail->ErrorInfo);
        return false;
    }
}