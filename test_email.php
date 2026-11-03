
<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$mail = new PHPMailer(true);

try {
    // SMTP settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'kcommerce@gmail.com'; // Your Gmail address
    $mail->Password   = 'lgjp drlv rvjq smjv';    // App Password from Google
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or 'tls'
    $mail->Port       = 587;

    // Email details
    $mail->setFrom('kcommerce@gmail.com', 'Fusion Quiz');
    $mail->addAddress('sakchart@fusion.co.th');
    $mail->Subject = 'Test Email via Gmail SMTP';
    $mail->Body    = 'This is a test email sent using Gmail SMTP relay and PHP.';

    $mail->send();
    echo 'Message sent successfully!';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}

