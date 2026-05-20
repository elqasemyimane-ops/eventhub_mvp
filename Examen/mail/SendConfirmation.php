<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendConfirmation(PDO $pdo, array $registration, array $event): bool
{
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) return false;
    require_once $autoloadPath;

    $unsubscribeUrl = 'http://localhost/EventHub_Pro/events/unregister.php'
                    . '?token=' . urlencode($registration['token']);

    $templatePath = __DIR__ . '/templates/confirmation.html';
    if (file_exists($templatePath)) {
        $html = file_get_contents($templatePath);
        $dateFormatted = date('d/m/Y à H:i', strtotime($event['date']));
        $html = str_replace(
            ['{{full_name}}', '{{event_title}}', '{{event_date}}',
             '{{event_location}}', '{{unsubscribe_url}}'],
            [htmlspecialchars($registration['full_name']),
             htmlspecialchars($event['title']),
             $dateFormatted,
             htmlspecialchars($event['location']),
             $unsubscribeUrl],
            $html
        );
    } else {
        $html = "<h2>Inscription confirmée</h2>
                 <p>Bonjour {$registration['full_name']},</p>
                 <p>Votre inscription à <b>{$event['title']}</b> est confirmée.</p>
                 <p>Date : " . date('d/m/Y à H:i', strtotime($event['date'])) . "</p>
                 <p>Lieu : {$event['location']}</p>
                 <p><a href='$unsubscribeUrl'>Se désinscrire</a></p>";
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'VOTRE_USERNAME_MAILTRAP';
        $mail->Password   = 'VOTRE_PASSWORD_MAILTRAP';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('noreply@eventhub.ma', 'EventHub Pro');
        $mail->addAddress($registration['email'], $registration['full_name']);
        $mail->isHTML(true);
        $mail->Subject = "✅ Inscription confirmée — " . $event['title'];
        $mail->Body    = $html;
        $mail->AltBody = "Inscription confirmée pour {$event['title']}";
        $mail->send();
        return true;

    } catch (Exception $e) {
        logMailError($pdo, 'confirmation', $registration['email'], $mail->ErrorInfo);
        return false;
    }
}

function logMailError(PDO $pdo, string $type, string $recipient, string $error): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO mail_logs (type, recipient, error_message)
             VALUES (:type, :recipient, :error)"
        );
        $stmt->execute([':type' => $type, ':recipient' => $recipient, ':error' => $error]);
    } catch (PDOException $e) {
        error_log("[mail_log] $type → $recipient : $error");
    }
}