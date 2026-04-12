<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Show what credentials are being used (masked)
$user = MAIL_USERNAME;
$pass = MAIL_PASSWORD;
$maskedPass = substr($pass, 0, 4) . str_repeat('*', strlen($pass) - 4);

echo "<p><strong>Username:</strong> " . htmlspecialchars($user) . "</p>";
echo "<p><strong>Password being used:</strong> " . htmlspecialchars($maskedPass) . " (length: " . strlen($pass) . ")</p>";
echo "<p><strong>Port:</strong> " . MAIL_PORT . " | <strong>Encryption:</strong> " . MAIL_ENCRYPTION . "</p>";
echo "<hr>";

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // force SSL for port 465
    $mail->Port       = MAIL_PORT;
    $mail->SMTPDebug  = 2;

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_USERNAME);
    $mail->isHTML(true);
    $mail->Subject = 'KNH BDMS Test';
    $mail->Body    = '<h2>Test email</h2>';

    $mail->send();
    echo '<h2 style="color:green">✅ Email sent successfully!</h2>';
} catch (Exception $e) {
    echo '<h2 style="color:red">❌ Failed: ' . htmlspecialchars($mail->ErrorInfo) . '</h2>';
}
