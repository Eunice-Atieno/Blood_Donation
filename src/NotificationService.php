<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/email.php";
$autoloader = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoloader)) require_once $autoloader;

class NotificationService
{
    private function htmlTemplate(string $title, string $bodyHtml): string
    {
        $css = "body{margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;}.wrapper{max-width:600px;margin:30px auto;background:#fff;border-radius:10px;overflow:hidden;}.header{background:linear-gradient(135deg,#8b0000,#c0392b);padding:30px 40px;text-align:center;}.header h1{color:#fff;margin:0;font-size:22px;}.header p{color:rgba(255,255,255,.8);margin:6px 0 0;font-size:13px;}.body{padding:32px 40px;color:#333;line-height:1.7;font-size:15px;}.body h2{color:#c0392b;margin-top:0;}.info-box{background:#fdf2f2;border-left:4px solid #c0392b;padding:14px 18px;border-radius:0 6px 6px 0;margin:20px 0;}.info-box p{margin:4px 0;font-size:14px;}.footer{background:#f9f9f9;border-top:1px solid #eee;padding:18px 40px;text-align:center;font-size:12px;color:#999;}";
        return "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"/><style>{$css}</style></head><body><div class=\"wrapper\"><div class=\"header\"><h1>KNH Blood Donation Management System</h1><p>Kenyatta National Hospital</p></div><div class=\"body\">{$bodyHtml}</div><div class=\"footer\"><p>KNH BDMS | Donate Blood. Save Lives.</p></div></div></body></html>";
    }

    private function sendEmail(string $toEmail, string $toName, string $subject, string $body, bool $isHtml = false): array
    {
        $fromEmail = defined("MAIL_FROM") ? MAIL_FROM : "noreply@knh.local";
        $fromName  = defined("MAIL_FROM_NAME") ? MAIL_FROM_NAME : "KNH BDMS";
        $driver    = defined("MAIL_DRIVER") ? MAIL_DRIVER : "mail";
        if ($driver === "smtp" && class_exists("\PHPMailer\PHPMailer\PHPMailer")) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = defined("MAIL_HOST") ? MAIL_HOST : "smtp.gmail.com";
                $mail->SMTPAuth   = true;
                $mail->Username   = defined("MAIL_USERNAME") ? MAIL_USERNAME : "";
                $mail->Password   = defined("MAIL_PASSWORD") ? MAIL_PASSWORD : "";
                $mail->SMTPSecure = defined("MAIL_ENCRYPTION") ? MAIL_ENCRYPTION : "ssl";
                $mail->Port       = defined("MAIL_PORT") ? MAIL_PORT : 465;
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $toName);
                $mail->Subject = $subject;
                if ($isHtml) { $mail->isHTML(true); $mail->Body = $body; $mail->AltBody = strip_tags($body); }
                else { $mail->isHTML(false); $mail->Body = $body; }
                $mail->send();
                return ["success" => true];
            } catch (\Exception $e) {
                error_log("SMTP: " . $e->getMessage());
                return ["success" => false, "error" => $e->getMessage()];
            }
        }
        $headers = "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\n";
        $headers .= $isHtml ? "Content-Type: text/html; charset=UTF-8\r\n" : "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = mail($toEmail, $subject, $body, $headers);
        return $sent ? ["success" => true] : ["success" => false, "error" => "mail() failed"];
    }


    public function sendStaffAccountCreated(string $toEmail, string $toName, string $role, string $username): array
    {
        $subject  = "Your KNH BDMS Staff Account Has Been Created";
        $roleDisp = str_replace("_", " ", $role);
        $body     = "<h2>Welcome to KNH BDMS, " . htmlspecialchars($toName) . "!</h2>"
                  . "<p>An administrator has created a staff account for you on the <strong>KNH Blood Donation Management System</strong>.</p>"
                  . "<div class=\"info-box\">"
                  . "<p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>"
                  . "<p><strong>Role:</strong> " . htmlspecialchars($roleDisp) . "</p>"
                  . "<p><strong>Email:</strong> " . htmlspecialchars($toEmail) . "</p>"
                  . "</div>"
                  . "<p>Log in at the staff login page using your username and the password set by the administrator.</p>"
                  . "<p>If you did not expect this account, please contact the KNH administrator at <strong><a href=\"mailto:atienoeunice872@gmail.com\">atienoeunice872@gmail.com</a></strong> immediately.</p>";
        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $body), true);
    }
    public function sendPasswordResetConfirmation(string $toEmail, string $toName): array
    {
        $subject = "Your KNH BDMS Password Has Been Reset";
        $time = date("Y-m-d H:i:s");
        $body = "<h2>Password Reset Successful</h2><p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p><p>Your KNH BDMS password was successfully reset.</p><div class=\"info-box\"><p><strong>Time:</strong> {$time}</p><p><strong>Account:</strong> " . htmlspecialchars($toEmail) . "</p></div><p>If you did not request this, contact the KNH administrator at <strong><a href=\"mailto:atienoeunice872@gmail.com\">atienoeunice872@gmail.com</a></strong> immediately.</p>";
        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $body), true);
    }

    public function sendWelcomeEmail(string $toEmail, string $toName, string $bloodType): array
    {
        $subject = "Welcome to KNH BDMS - Thank You for Registering!";
        $body = "<h2>Welcome, " . htmlspecialchars($toName) . "!</h2><p>Thank you for registering with KNH BDMS.</p><div class=\"info-box\"><p><strong>Name:</strong> " . htmlspecialchars($toName) . "</p><p><strong>Blood Type:</strong> " . htmlspecialchars($bloodType) . "</p><p><strong>Status:</strong> Registered</p></div><p>Log in to schedule appointments and track your donations. Thank you for being a hero!</p>";
        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $body), true);
    }

    public function sendAppointmentConfirmation(string $toEmail, string $toName, string $date, string $time, string $notes = ""): array
    {
        $subject  = "Appointment Confirmed - KNH Blood Bank";
        $notesRow = $notes ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : "";
        $body = "<h2>Appointment Confirmed</h2><p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p><p>Your appointment has been scheduled.</p><div class=\"info-box\"><p><strong>Date:</strong> " . htmlspecialchars($date) . "</p><p><strong>Time:</strong> " . htmlspecialchars($time) . "</p>{$notesRow}</div><p>Please arrive 10 minutes early. Thank you!</p>";
        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $body), true);
    }

    public function sendAppointmentRescheduled(string $toEmail, string $toName, string $newDate, string $newTime, string $notes = ""): array
    {
        $subject  = "Appointment Rescheduled - KNH Blood Bank";
        $notesRow = $notes ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : "";
        $body = "<h2>Appointment Rescheduled</h2><p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p><p>Your appointment has been rescheduled.</p><div class=\"info-box\"><p><strong>New Date:</strong> " . htmlspecialchars($newDate) . "</p><p><strong>New Time:</strong> " . htmlspecialchars($newTime) . "</p>{$notesRow}</div><p>Please arrive 10 minutes early. Thank you!</p>";
        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $body), true);
    }

    public function notifyLabTechsOfAppointment(string $donorName, string $date, string $time, string $notes = ""): void
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, username, email FROM staff WHERE role = \"Lab_Technician\" AND email IS NOT NULL AND email != \"\"");
        $stmt->execute();
        $techs = $stmt->fetchAll();
        $subject  = "New Donation Appointment - {$date} at {$time}";
        $notesRow = $notes ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : "";
        foreach ($techs as $tech) {
            $body = "<h2>New Appointment Scheduled</h2><p>Dear <strong>" . htmlspecialchars($tech["username"]) . "</strong>,</p><div class=\"info-box\"><p><strong>Donor:</strong> " . htmlspecialchars($donorName) . "</p><p><strong>Date:</strong> " . htmlspecialchars($date) . "</p><p><strong>Time:</strong> " . htmlspecialchars($time) . "</p>{$notesRow}</div>";
            $this->sendEmail($tech["email"], $tech["username"], $subject, $this->htmlTemplate($subject, $body), true);
            try { $pdo->prepare("INSERT INTO staff_notifications (staff_id, message, delivery_status) VALUES (?, ?, \"sent\")")->execute([$tech["id"], "New appointment: {$donorName} on {$date} at {$time}"]); } catch (\PDOException $e) {}
        }
    }

    public function sendDailyScheduleSummary(): array
    {
        $pdo = getDbConnection();
        $today = date("Y-m-d");
        $stmt = $pdo->prepare("SELECT a.appointment_date, a.appointment_time, a.notes, d.name AS donor_name, d.blood_type FROM appointments a JOIN donors d ON d.id = a.donor_id WHERE a.appointment_date = ? AND a.status = \"scheduled\" ORDER BY a.appointment_time ASC");
        $stmt->execute([$today]);
        $appointments = $stmt->fetchAll();
        $techs = $pdo->prepare("SELECT id, username, email FROM staff WHERE role = \"Lab_Technician\" AND email IS NOT NULL AND email != \"\"");
        $techs->execute();
        $labTechs = $techs->fetchAll();
        if (empty($labTechs)) return ["sent" => 0, "errors" => 0];
        $subject = "Daily Appointment Summary - {$today}";
        $rows = "";
        foreach ($appointments as $i => $a) {
            $bg = $i % 2 === 0 ? "#fff" : "#fdf2f2";
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">" . htmlspecialchars($a["appointment_time"]) . "</td><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">" . htmlspecialchars($a["donor_name"]) . "</td><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">" . htmlspecialchars($a["blood_type"]) . "</td><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">" . htmlspecialchars($a["notes"] ?? "-") . "</td></tr>";
        }
        $tableHtml = empty($appointments) ? "<p>No appointments today.</p>" : "<table style=\"width:100%;border-collapse:collapse;font-size:14px;\"><thead><tr style=\"background:#c0392b;color:#fff;\"><th style=\"padding:8px 12px;text-align:left;\">Time</th><th style=\"padding:8px 12px;text-align:left;\">Donor</th><th style=\"padding:8px 12px;text-align:left;\">Blood Type</th><th style=\"padding:8px 12px;text-align:left;\">Notes</th></tr></thead><tbody>{$rows}</tbody></table>";
        $sent = 0; $errors = 0;
        foreach ($labTechs as $tech) {
            $body = "<h2>Daily Schedule Summary</h2><p>Dear <strong>" . htmlspecialchars($tech["username"]) . "</strong>,</p><p>Schedule for <strong>{$today}</strong>:</p>{$tableHtml}";
            $result = $this->sendEmail($tech["email"], $tech["username"], $subject, $this->htmlTemplate($subject, $body), true);
            $result["success"] ? $sent++ : $errors++;
        }
        return ["sent" => $sent, "errors" => $errors];
    }

    public function sendTodayAppointmentReminders(): array
    {
        $pdo = getDbConnection();
        $today = date("Y-m-d");
        $stmt = $pdo->prepare("SELECT a.appointment_date, a.appointment_time, a.notes, d.name AS donor_name, d.email AS donor_email, d.blood_type FROM appointments a JOIN donors d ON d.id = a.donor_id WHERE a.appointment_date = ? AND a.status = \"scheduled\" ORDER BY a.appointment_time ASC");
        $stmt->execute([$today]);
        $appointments = $stmt->fetchAll();
        $sent = 0; $errors = 0;
        foreach ($appointments as $appt) {
            if (empty($appt["donor_email"])) { $errors++; continue; }
            $subject  = "Reminder: Your Blood Donation Appointment is Today - KNH";
            $notesRow = $appt["notes"] ? "<p><strong>Notes:</strong> " . htmlspecialchars($appt["notes"]) . "</p>" : "";
            $body = "<h2>Appointment Reminder</h2><p>Dear <strong>" . htmlspecialchars($appt["donor_name"]) . "</strong>,</p><p>You have a blood donation appointment <strong>today</strong>!</p><div class=\"info-box\"><p><strong>Date:</strong> " . htmlspecialchars($appt["appointment_date"]) . "</p><p><strong>Time:</strong> " . htmlspecialchars($appt["appointment_time"]) . "</p><p><strong>Blood Type:</strong> " . htmlspecialchars($appt["blood_type"]) . "</p>{$notesRow}</div><p>Please eat well and stay hydrated. Thank you!</p>";
            $result = $this->sendEmail($appt["donor_email"], $appt["donor_name"], $subject, $this->htmlTemplate($subject, $body), true);
            $result["success"] ? $sent++ : $errors++;
        }
        return ["sent" => $sent, "errors" => $errors];
    }

    public function sendEmergencyAppeal(string $bloodType, string $message = ""): array
    {
        $pdo = getDbConnection();
        $compat = ["O-"=>["O-"],"O+"=>["O-","O+"],"A-"=>["O-","A-"],"A+"=>["O-","O+","A-","A+"],"B-"=>["O-","B-"],"B+"=>["O-","O+","B-","B+"],"AB-"=>["O-","A-","B-","AB-"],"AB+"=>["O-","O+","A-","A+","B-","B+","AB-","AB+"]];
        $eligible = $compat[$bloodType] ?? [$bloodType];
        $ph = implode(",", array_fill(0, count($eligible), "?"));
        $today = date("Y-m-d");
        $stmt = $pdo->prepare("SELECT id, name, email, blood_type FROM donors WHERE blood_type IN ({$ph}) AND medical_history_flag = 0 AND email_opt_in = 1 AND email IS NOT NULL AND email != \"\" AND (last_donation_date IS NULL OR DATEDIFF(?, last_donation_date) >= 56)");
        $stmt->execute(array_merge($eligible, [$today]));
        $donors = $stmt->fetchAll();
        $sent = 0; $errors = 0;
        $subject = "URGENT: KNH Blood Bank Needs {$bloodType} Blood";
        $customMsg = $message ? "<div class=\"info-box\"><p>" . nl2br(htmlspecialchars($message)) . "</p></div>" : "";
        foreach ($donors as $donor) {
            $body = "<h2>Emergency Blood Appeal</h2><p>Dear <strong>" . htmlspecialchars($donor["name"]) . "</strong>,</p><p>KNH urgently needs <strong>" . htmlspecialchars($bloodType) . "</strong> blood. Your blood type <strong>" . htmlspecialchars($donor["blood_type"]) . "</strong> is compatible.</p>{$customMsg}<div class=\"info-box\"><p><strong>Location:</strong> KNH Blood Bank</p><p><strong>Hours:</strong> Mon-Fri, 8AM-5PM</p></div><p>Please visit us as soon as possible.</p>";
            $result = $this->sendEmail($donor["email"], $donor["name"], $subject, $this->htmlTemplate($subject, $body), true);
            $result["success"] ? $sent++ : $errors++;
        }
        return ["sent" => $sent, "errors" => $errors, "total_eligible" => count($donors)];
    }

    public function sendEligibilityUpdates(): array
    {
        $pdo = getDbConnection();
        $today = date("Y-m-d");
        $stmt = $pdo->prepare("SELECT id, name, email, blood_type, last_donation_date FROM donors WHERE medical_history_flag = 0 AND email_opt_in = 1 AND email IS NOT NULL AND email != \"\" AND (last_donation_date IS NULL OR DATEDIFF(?, last_donation_date) = 56)");
        $stmt->execute([$today]);
        $donors = $stmt->fetchAll();
        $sent = 0; $errors = 0;
        $subject = "You Are Now Eligible to Donate Blood Again - KNH";
        foreach ($donors as $donor) {
            $lastDon = $donor["last_donation_date"] ? "<p><strong>Last Donation:</strong> " . htmlspecialchars($donor["last_donation_date"]) . "</p>" : "<p><strong>Status:</strong> First-time donor</p>";
            $body = "<h2>You Are Eligible to Donate Again!</h2><p>Dear <strong>" . htmlspecialchars($donor["name"]) . "</strong>,</p><div class=\"info-box\"><p><strong>Blood Type:</strong> " . htmlspecialchars($donor["blood_type"]) . "</p>{$lastDon}<p><strong>Status:</strong> Eligible</p></div><p>Log in to schedule an appointment.</p>";
            $result = $this->sendEmail($donor["email"], $donor["name"], $subject, $this->htmlTemplate($subject, $body), true);
            $result["success"] ? $sent++ : $errors++;
        }
        return ["sent" => $sent, "errors" => $errors];
    }

    public function sendDonorNotification(int $donorId, string $messageType): array
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT name, email, blood_type FROM donors WHERE id = ?");
        $stmt->execute([$donorId]);
        $donor = $stmt->fetch();
        if (!$donor) return ["success" => false, "error" => "donor not found"];
        if (empty($donor["email"])) return ["success" => false, "error" => "no email"];
        $templates = ["eligibility_reminder" => ["subject" => "You are eligible to donate blood again - KNH", "body" => "Dear {name}, you are now eligible to donate blood again."], "low_stock_alert" => ["subject" => "Urgent: KNH needs your blood type ({blood_type})", "body" => "Dear {name}, KNH urgently needs blood type {blood_type}."]];
        $tpl = $templates[$messageType] ?? ["subject" => "Notification from KNH", "body" => "Dear {name}, you have a notification."];
        $subject = str_replace(["{name}", "{blood_type}"], [$donor["name"], $donor["blood_type"]], $tpl["subject"]);
        $plain   = str_replace(["{name}", "{blood_type}"], [$donor["name"], $donor["blood_type"]], $tpl["body"]);
        $body = "<h2>" . htmlspecialchars($subject) . "</h2><p>" . nl2br(htmlspecialchars($plain)) . "</p>";
        return $this->sendEmail($donor["email"], $donor["name"], $subject, $this->htmlTemplate($subject, $body), true);
    }

    public function sendStaffNotification(string $toEmail, string $toName, string $message): array
    {
        if (empty($toEmail)) return ["success" => false, "error" => "no email"];
        $subject = "Notification from KNH BDMS";
        $body = "<h2>New Notification</h2><p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p><div class=\"info-box\"><p>" . nl2br(htmlspecialchars($message)) . "</p></div>";
        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $body), true);
    }

    public function notifyLabTechsOfRescheduledAppointment(string $donorName, string $date, string $time, string $notes = ""): void
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, username, email FROM staff WHERE role = 'Lab_Technician' AND email IS NOT NULL AND email != ''");
        $stmt->execute();
        $techs = $stmt->fetchAll();
        $subject  = "Appointment Rescheduled - {$donorName} on {$date} at {$time}";
        $notesRow = $notes ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : "";
        foreach ($techs as $tech) {
            $body = "<h2>Appointment Rescheduled</h2><p>Dear <strong>" . htmlspecialchars($tech["username"]) . "</strong>,</p><p>A donor has rescheduled their blood donation appointment.</p><div class=\"info-box\"><p><strong>Donor:</strong> " . htmlspecialchars($donorName) . "</p><p><strong>New Date:</strong> " . htmlspecialchars($date) . "</p><p><strong>New Time:</strong> " . htmlspecialchars($time) . "</p>{$notesRow}</div><p>Please update your schedule accordingly.</p>";
            $this->sendEmail($tech["email"], $tech["username"], $subject, $this->htmlTemplate($subject, $body), true);
            try {
                $pdo->prepare("INSERT INTO staff_notifications (staff_id, message, delivery_status) VALUES (?, ?, 'sent')")->execute([$tech["id"], "Appointment rescheduled: {$donorName} on {$date} at {$time}"]);
            } catch (\PDOException $e) {}
        }
    }
}
