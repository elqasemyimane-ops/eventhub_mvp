<?php

header('Content-Type: application/json');

require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// CONNEXION DB
$pdo = new PDO(
    "mysql:host=localhost;dbname=eventhub;charset=utf8",
    "root",
    ""
);

$data = json_decode(file_get_contents("php://input"), true);

$name     = $data['name'] ?? '';
$email    = $data['email'] ?? '';
$eventId  = $data['eventId'] ?? 0;

if (!$name || !$email || !$eventId) {

    echo json_encode([
        "success" => false,
        "message" => "Champs manquants"
    ]);

    exit;
}

// EVENT
$stmt = $pdo->prepare("SELECT * FROM events WHERE id=?");
$stmt->execute([$eventId]);

$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {

    echo json_encode([
        "success" => false,
        "message" => "Event introuvable"
    ]);

    exit;
}

// INSERT INSCRIPTION
$stmt = $pdo->prepare("
    INSERT INTO registrations(name,email,event_id)
    VALUES(?,?,?)
");

$stmt->execute([$name, $email, $eventId]);

// ENVOI EMAIL
$mail = new PHPMailer(true);

try {

    $mail->isSMTP();

    $mail->Host       = 'smtp.gmail.com';

    $mail->SMTPAuth   = true;

    $mail->Username   = 'TON_EMAIL@gmail.com';

    $mail->Password   = 'MOT_DE_PASSE_APPLICATION';

    $mail->SMTPSecure = 'tls';

    $mail->Port       = 587;

    $mail->setFrom('TON_EMAIL@gmail.com', 'EventHub');

    $mail->addAddress($email, $name);

    $mail->isHTML(true);

    $mail->Subject = 'Confirmation inscription';

    $mail->Body = "
        <h2>Bonjour $name 👋</h2>

        <p>
            Votre inscription à l'événement
            <b>{$event['title']}</b>
            est confirmée.
        </p>

        <p>Date : {$event['event_date']}</p>
        <p>Lieu : {$event['location']}</p>

        <hr>

        <p>Merci d'utiliser EventHub 🚀</p>
    ";

    $mail->send();

    echo json_encode([
        "success" => true,
        "message" => "Inscription réussie + email envoyé"
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => $mail->ErrorInfo
    ]);
}
?>