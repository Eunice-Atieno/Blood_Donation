<?php
/**
 * NotificationService — Sends email notifications to donors and staff.
 *
 * Supports two delivery drivers configured in config/email.php:
 *   - 'smtp'  → uses PHPMailer (requires Composer vendor/autoload.php)
 *   - 'mail'  → uses PHP's built-in mail() function (works on XAMPP)
 *
 * SMS has been removed; all notifications are delivered by email only.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email.php';

// Load Composer autoloader if available (needed for PHPMailer SMTP support)
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

class NotificationService
{
    /**
     * Pre-defined email templates for donor notifications.
     * Placeholders {name} and {blood_type} are replaced at send time.
     */
    private const DONOR_TEMPLATES = [
        'eligibility_reminder' => [
            'subject' => 'You are eligible to donate blood again — KNH',
            'body'    => "Dear {name},\n\nGreat news! You are now eligible to donate blood again.\nPlease visit the KNH Blood Bank at your earliest convenience.\n\nThank you for saving lives.\n\nKNH Blood Donation Management System",
        ],
        'low_stock_alert' => [
            'subject' => 'Urgent: KNH needs your blood type ({blood_type})',
            'body'    => "Dear {name},\n\nKNH Blood Bank urgently needs blood type {blood_type}.\nYour donation could save a life today. Please visit us as soon as possible.\n\nThank you,\nKNH Blood Donation Management System",
        ],
    ];

    // -------------------------------------------------------------------------
    // Private: HTML email template wrapper
    // -------------------------------------------------------------------------

    /**
     * Wraps plain content in a styled HTML email template.
     */
    private function htmlTemplate(string $title, string $bodyHtml): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8"/>
  <style>
    body { margin:0; padding:0; background:#f4f4f4; font-family:'Segoe UI',Arial,sans-serif; }
    .wrapper { max-width:600px; margin:30px auto; background:#fff; border-radius:10px;
               overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,.1); }
    .header  { background:linear-gradient(135deg,#8b0000,#c0392b); padding:30px 40px; text-align:center; }
    .header h1 { color:#fff; margin:0; font-size:22px; letter-spacing:.05em; }
    .header p  { color:rgba(255,255,255,.8); margin:6px 0 0; font-size:13px; }
    .body    { padding:32px 40px; color:#333; line-height:1.7; font-size:15px; }
    .body h2 { color:#c0392b; margin-top:0; }
    .info-box { background:#fdf2f2; border-left:4px solid #c0392b; padding:14px 18px;
                border-radius:0 6px 6px 0; margin:20px 0; }
    .info-box p { margin:4px 0; font-size:14px; }
    .info-box strong { color:#8b0000; }
    .btn { display:inline-block; background:#c0392b; color:#fff; padding:12px 28px;
           border-radius:50px; text-decoration:none; font-weight:700; font-size:14px;
           margin:20px 0; }
    .footer { background:#f9f9f9; border-top:1px solid #eee; padding:18px 40px;
              text-align:center; font-size:12px; color:#999; }
    .footer strong { color:#c0392b; }
    .drop { font-size:28px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <div class="drop">🩸</div>
      <h1>KNH Blood Donation Management System</h1>
      <p>Kenyatta National Hospital — Blood Bank</p>
    </div>
    <div class="body">
      {$bodyHtml}
    </div>
    <div class="footer">
      <p>© KNH Blood Donation Management System &nbsp;|&nbsp; <strong>Donate Blood. Save Lives.</strong></p>
      <p>This is an automated message. Please do not reply to this email.</p>
    </div>
  </div>
</body>
</html>
HTML;
    }

    // -------------------------------------------------------------------------
    // Public: Welcome email on registration
    // -------------------------------------------------------------------------

    public function sendWelcomeEmail(string $toEmail, string $toName, string $bloodType): array
    {
        $subject = 'Welcome to KNH BDMS. Thank You for Registering!';

        $bodyHtml = "
            <h2>Welcome, " . htmlspecialchars($toName) . "! 🎉</h2>
            <p>Thank you for registering with the <strong>KNH Blood Donation Management System</strong>.
               Your decision to donate blood is a powerful act of generosity that saves lives.</p>
            <div class='info-box'>
                <p><strong>Name:</strong> " . htmlspecialchars($toName) . "</p>
                <p><strong>Blood Type:</strong> " . htmlspecialchars($bloodType) . "</p>
                <p><strong>Status:</strong> Registered ✅</p>
            </div>
            <p>You can now log in to your donor portal to:</p>
            <ul>
                <li>📅 Schedule donation appointments</li>
                <li>🩸 Track your donation history</li>
                <li>🔔 Receive important notifications</li>
                <li>🚨 Respond to emergency blood shortage alerts</li>
            </ul>
            <p>Every drop counts. Thank you for being a hero!</p>
        ";

        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $bodyHtml), true);
    }

    // -------------------------------------------------------------------------
    // Public: Appointment confirmation to donor
    // -------------------------------------------------------------------------

    public function sendAppointmentConfirmation(string $toEmail, string $toName, string $date, string $time, string $notes = ''): array
    {
        $subject  = 'Appointment Confirmed — KNH Blood Bank';
        $notesRow = $notes ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : '';

        $bodyHtml = "
            <h2>Appointment Confirmed ✅</h2>
            <p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p>
            <p>Your blood donation appointment has been successfully scheduled. We look forward to seeing you!</p>
            <div class='info-box'>
                <p><strong>📅 Date:</strong> " . htmlspecialchars($date) . "</p>
                <p><strong>🕐 Time:</strong> " . htmlspecialchars($time) . "</p>
                {$notesRow}
            </div>
            <p><strong>Before your appointment, please:</strong></p>
            <ul>
                <li>Eat a healthy meal and drink plenty of water</li>
                <li>Avoid fatty foods and alcohol</li>
                <li>Get a good night's sleep</li>
                <li>Arrive 10 minutes early</li>
                <li>Bring a valid ID</li>
            </ul>
            <p>Thank you for saving lives! 🩸</p>
        ";

        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $bodyHtml), true);
    }

    // -------------------------------------------------------------------------
    // Public: Notify all Lab Technicians of a new appointment
    // -------------------------------------------------------------------------

    public function notifyLabTechsOfAppointment(string $donorName, string $date, string $time, string $notes = ''): void
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT id, username, email FROM staff WHERE role = 'Lab_Technician' AND email IS NOT NULL AND email != ''"
        );
        $stmt->execute();
        $techs = $stmt->fetchAll();

        $subject  = "New Donation Appointment — {$date} at {$time}";
        $notesRow = $notes ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : '';

        foreach ($techs as $tech) {
            $bodyHtml = "
                <h2>New Appointment Scheduled 📅</h2>
                <p>Dear <strong>" . htmlspecialchars($tech['username']) . "</strong>,</p>
                <p>A new blood donation appointment has been booked. Please prepare accordingly.</p>
                <div class='info-box'>
                    <p><strong>👤 Donor:</strong> " . htmlspecialchars($donorName) . "</p>
                    <p><strong>📅 Date:</strong> " . htmlspecialchars($date) . "</p>
                    <p><strong>🕐 Time:</strong> " . htmlspecialchars($time) . "</p>
                    {$notesRow}
                </div>
                <p>Please log in to the Lab Dashboard to view the full schedule.</p>
            ";

            $this->sendEmail($tech['email'], $tech['username'], $subject, $this->htmlTemplate($subject, $bodyHtml), true);

            try {
                $pdo->prepare(
                    "INSERT INTO staff_notifications (staff_id, message, delivery_status) VALUES (:sid, :msg, 'sent')"
                )->execute([
                    ':sid' => $tech['id'],
                    ':msg' => "New appointment: {$donorName} on {$date} at {$time}" . ($notes ? " — {$notes}" : ''),
                ]);
            } catch (\PDOException $ignored) {}
        }
    }

    // -------------------------------------------------------------------------
    // Public: Daily schedule summary to Lab Technicians
    // -------------------------------------------------------------------------

    public function sendDailyScheduleSummary(): array
    {
        $pdo   = getDbConnection();
        $today = date('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT a.appointment_date, a.appointment_time, a.notes, d.name AS donor_name, d.blood_type
               FROM appointments a JOIN donors d ON d.id = a.donor_id
              WHERE a.appointment_date = :today AND a.status = 'scheduled'
              ORDER BY a.appointment_time ASC"
        );
        $stmt->execute([':today' => $today]);
        $appointments = $stmt->fetchAll();

        $techs = $pdo->prepare(
            "SELECT id, username, email FROM staff WHERE role = 'Lab_Technician' AND email IS NOT NULL AND email != ''"
        );
        $techs->execute();
        $labTechs = $techs->fetchAll();

        if (empty($labTechs)) return ['sent' => 0, 'errors' => 0];

        $subject = "Daily Appointment Summary — {$today}";

        if (empty($appointments)) {
            $tableHtml = "<p style='color:#888;'>No appointments scheduled for today.</p>";
        } else {
            $rows = '';
            foreach ($appointments as $i => $a) {
                $bg = $i % 2 === 0 ? '#fff' : '#fdf2f2';
                $rows .= "<tr style='background:{$bg};'>
                    <td style='padding:10px 14px;border-bottom:1px solid #eee;'>" . htmlspecialchars($a['appointment_time']) . "</td>
                    <td style='padding:10px 14px;border-bottom:1px solid #eee;'>" . htmlspecialchars($a['donor_name']) . "</td>
                    <td style='padding:10px 14px;border-bottom:1px solid #eee;text-align:center;'><strong>" . htmlspecialchars($a['blood_type']) . "</strong></td>
                    <td style='padding:10px 14px;border-bottom:1px solid #eee;color:#888;'>" . htmlspecialchars($a['notes'] ?? '—') . "</td>
                </tr>";
            }
            $tableHtml = "
                <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                    <thead>
                        <tr style='background:#c0392b;color:#fff;'>
                            <th style='padding:10px 14px;text-align:left;'>Time</th>
                            <th style='padding:10px 14px;text-align:left;'>Donor</th>
                            <th style='padding:10px 14px;text-align:center;'>Blood Type</th>
                            <th style='padding:10px 14px;text-align:left;'>Notes</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>";
        }

        $sent = 0; $errors = 0;
        foreach ($labTechs as $tech) {
            $bodyHtml = "
                <h2>Daily Schedule Summary 📋</h2>
                <p>Dear <strong>" . htmlspecialchars($tech['username']) . "</strong>,</p>
                <p>Here is the appointment schedule for today, <strong>{$today}</strong>:</p>
                {$tableHtml}
                <p style='margin-top:20px;'>Please log in to the Lab Dashboard for full details and to manage appointments.</p>
            ";
            $result = $this->sendEmail($tech['email'], $tech['username'], $subject, $this->htmlTemplate($subject, $bodyHtml), true);
            $result['success'] ? $sent++ : $errors++;
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Public: Day-of appointment reminder to donors
    // -------------------------------------------------------------------------

    /**
     * Send a reminder email to every donor who has an appointment today.
     * Call this once per day (manually from lab dashboard or via Task Scheduler).
     *
     * @return array ['sent' => int, 'errors' => int]
     */
    public function sendTodayAppointmentReminders(): array
    {
        $pdo   = getDbConnection();
        $today = date('Y-m-d');

        // Fetch all scheduled appointments for today with donor contact details
        $stmt = $pdo->prepare(
            "SELECT a.appointment_date, a.appointment_time, a.notes,
                    d.name AS donor_name, d.email AS donor_email, d.blood_type
               FROM appointments a
               JOIN donors d ON d.id = a.donor_id
              WHERE a.appointment_date = :today AND a.status = 'scheduled'
              ORDER BY a.appointment_time ASC"
        );
        $stmt->execute([':today' => $today]);
        $appointments = $stmt->fetchAll();

        $sent = 0; $errors = 0;

        foreach ($appointments as $appt) {
            if (empty($appt['donor_email'])) { $errors++; continue; }

            $subject  = 'Reminder: Your Blood Donation Appointment is Today — KNH';
            $notesRow = $appt['notes']
                ? "<p><strong>Notes:</strong> " . htmlspecialchars($appt['notes']) . "</p>"
                : '';

            $bodyHtml = "
                <h2>Appointment Reminder 🩸</h2>
                <p>Dear <strong>" . htmlspecialchars($appt['donor_name']) . "</strong>,</p>
                <p>This is a friendly reminder that you have a blood donation appointment <strong>today</strong>!</p>
                <div class='info-box'>
                    <p><strong>📅 Date:</strong> " . htmlspecialchars($appt['appointment_date']) . "</p>
                    <p><strong>🕐 Time:</strong> " . htmlspecialchars($appt['appointment_time']) . "</p>
                    <p><strong>🩸 Blood Type:</strong> " . htmlspecialchars($appt['blood_type']) . "</p>
                    {$notesRow}
                </div>
                <p><strong>Before you come, please remember to:</strong></p>
                <ul>
                    <li>Eat a healthy meal and drink plenty of water</li>
                    <li>Avoid alcohol and fatty foods</li>
                    <li>Bring a valid ID</li>
                    <li>Arrive 10 minutes early</li>
                </ul>
                <p>Your donation saves lives. Thank you for being a hero! 💪</p>
            ";

            $result = $this->sendEmail(
                $appt['donor_email'],
                $appt['donor_name'],
                $subject,
                $this->htmlTemplate($subject, $bodyHtml),
                true
            );

            $result['success'] ? $sent++ : $errors++;
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Public: Emergency blood appeal — broadcast to all eligible donors
    // -------------------------------------------------------------------------

    /**
     * Send an emergency blood appeal email to all donors who can donate
     * the needed blood type (based on compatibility) and are currently eligible.
     *
     * @param string $bloodType  The blood type urgently needed (e.g. 'O-')
     * @param string $message    Optional custom message from the admin
     * @return array ['sent' => int, 'errors' => int]
     */
    public function sendEmergencyAppeal(string $bloodType, string $message = ''): array
    {
        $pdo = getDbConnection();

        // Compatible donor blood types for each recipient type
        $compatibleDonors = [
            'O-'  => ['O-'],
            'O+'  => ['O-', 'O+'],
            'A-'  => ['O-', 'A-'],
            'A+'  => ['O-', 'O+', 'A-', 'A+'],
            'B-'  => ['O-', 'B-'],
            'B+'  => ['O-', 'O+', 'B-', 'B+'],
            'AB-' => ['O-', 'A-', 'B-', 'AB-'],
            'AB+' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'],
        ];

        // Donors whose blood type can donate to the needed type
        $eligible = $compatibleDonors[$bloodType] ?? [$bloodType];
        $placeholders = implode(',', array_fill(0, count($eligible), '?'));

        $today = date('Y-m-d');

        // Fetch eligible donors: correct blood type, not medically flagged,
        // last donation was > 56 days ago (or never donated), and email opt-in
        $stmt = $pdo->prepare(
            "SELECT id, name, email, blood_type, last_donation_date
               FROM donors
              WHERE blood_type IN ({$placeholders})
                AND medical_history_flag = 0
                AND email_opt_in = 1
                AND email IS NOT NULL AND email != ''
                AND (last_donation_date IS NULL
                     OR DATEDIFF(:today, last_donation_date) >= 56)"
        );
        $params = array_merge($eligible, [':today' => $today]);
        $stmt->execute($params);
        $donors = $stmt->fetchAll();

        $sent = 0; $errors = 0;
        $subject = "🚨 URGENT: KNH Blood Bank Needs {$bloodType} Blood — Please Donate Today";

        foreach ($donors as $donor) {
            $customMsg = $message
                ? "<div class='info-box'><p>" . nl2br(htmlspecialchars($message)) . "</p></div>"
                : '';

            $bodyHtml = "
                <h2 style='color:#c0392b;'>🚨 Emergency Blood Appeal</h2>
                <p>Dear <strong>" . htmlspecialchars($donor['name']) . "</strong>,</p>
                <p>KNH Blood Bank is facing a <strong>critical shortage</strong> of
                   <strong style='color:#c0392b;font-size:1.1em;'>{$bloodType}</strong> blood.
                   Your blood type <strong>" . htmlspecialchars($donor['blood_type']) . "</strong>
                   is compatible and urgently needed.</p>
                {$customMsg}
                <div class='info-box'>
                    <p><strong>🏥 Location:</strong> Kenyatta National Hospital Blood Bank</p>
                    <p><strong>⏰ Hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM</p>
                    <p><strong>📞 Contact:</strong> KNH Blood Bank Reception</p>
                </div>
                <p>Every unit of blood can save up to <strong>3 lives</strong>.
                   Please visit us as soon as possible.</p>
                <p style='color:#888;font-size:.85em;margin-top:1.5rem;'>
                    You are receiving this because you are a registered donor with email notifications enabled.
                    You can update your preferences in your donor portal.
                </p>
            ";

            $result = $this->sendEmail(
                $donor['email'], $donor['name'], $subject,
                $this->htmlTemplate($subject, $bodyHtml), true
            );
            $result['success'] ? $sent++ : $errors++;
        }

        return ['sent' => $sent, 'errors' => $errors, 'total_eligible' => count($donors)];
    }

    // -------------------------------------------------------------------------
    // Public: Eligibility update — notify donors who are now eligible to donate
    // -------------------------------------------------------------------------

    /**
     * Send an eligibility update email to all donors who became eligible today
     * (last donation was exactly 56 days ago) and have email opt-in enabled.
     *
     * @return array ['sent' => int, 'errors' => int]
     */
    public function sendEligibilityUpdates(): array
    {
        $pdo   = getDbConnection();
        $today = date('Y-m-d');

        // Donors whose 56-day window expires today (last_donation_date + 56 = today)
        // Also include donors who have never donated (always eligible)
        $stmt = $pdo->prepare(
            "SELECT id, name, email, blood_type, last_donation_date
               FROM donors
              WHERE medical_history_flag = 0
                AND email_opt_in = 1
                AND email IS NOT NULL AND email != ''
                AND (
                    last_donation_date IS NULL
                    OR DATEDIFF(:today, last_donation_date) = 56
                )"
        );
        $stmt->execute([':today' => $today]);
        $donors = $stmt->fetchAll();

        $sent = 0; $errors = 0;
        $subject = 'You Are Now Eligible to Donate Blood Again — KNH';

        foreach ($donors as $donor) {
            $lastDonation = $donor['last_donation_date']
                ? "<p><strong>Last Donation:</strong> " . htmlspecialchars($donor['last_donation_date']) . "</p>"
                : "<p><strong>Status:</strong> First-time donor — eligible now!</p>";

            $bodyHtml = "
                <h2>You're Eligible to Donate Again! 🎉</h2>
                <p>Dear <strong>" . htmlspecialchars($donor['name']) . "</strong>,</p>
                <p>Great news! You are now eligible to donate blood again.
                   Your contribution makes a real difference in saving lives.</p>
                <div class='info-box'>
                    <p><strong>🩸 Your Blood Type:</strong> " . htmlspecialchars($donor['blood_type']) . "</p>
                    {$lastDonation}
                    <p><strong>✅ Status:</strong> Eligible to donate</p>
                </div>
                <p>Log in to your donor portal to schedule an appointment at your convenience.</p>
                <p style='color:#888;font-size:.85em;margin-top:1.5rem;'>
                    You are receiving this because you are a registered donor with email notifications enabled.
                </p>
            ";

            $result = $this->sendEmail(
                $donor['email'], $donor['name'], $subject,
                $this->htmlTemplate($subject, $bodyHtml), true
            );
            $result['success'] ? $sent++ : $errors++;
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Public: Donor notification
    // -------------------------------------------------------------------------

    /**
     * Send an email notification to a donor.
     *
     * Looks up the donor's name, email, and blood type from the database,
     * fills in the appropriate template, and dispatches the email.
     *
     * @param int    $donorId      The donor's database ID
     * @param string $messageType  'eligibility_reminder' or 'low_stock_alert'
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function sendDonorNotification(int $donorId, string $messageType): array
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('SELECT name, email, blood_type FROM donors WHERE id = :id');
        $stmt->execute([':id' => $donorId]);
        $donor = $stmt->fetch();

        if ($donor === false) return ['success' => false, 'error' => 'donor not found'];
        if (empty($donor['email'])) return ['success' => false, 'error' => 'donor has no email address'];

        $tpl = self::DONOR_TEMPLATES[$messageType] ?? [
            'subject' => 'Notification from KNH Blood Bank',
            'body'    => "Dear {name},\n\nYou have a notification from KNH Blood Bank.",
        ];

        $subject = str_replace(['{name}', '{blood_type}'], [$donor['name'], $donor['blood_type']], $tpl['subject']);
        $plain   = str_replace(['{name}', '{blood_type}'], [$donor['name'], $donor['blood_type']], $tpl['body']);

        // Wrap in HTML template
        $bodyHtml = "<h2>" . htmlspecialchars($subject) . "</h2>"
                  . "<p>" . nl2br(htmlspecialchars($plain)) . "</p>";

        return $this->sendEmail($donor['email'], $donor['name'], $subject, $this->htmlTemplate($subject, $bodyHtml), true);
    }

    // -------------------------------------------------------------------------
    // Public: Staff notification
    // -------------------------------------------------------------------------

    /**
     * Send a custom plain-text email notification to a staff member.
     *
     * @param string $toEmail  Staff member's email address
     * @param string $toName   Staff member's username (used in greeting)
     * @param string $message  The message body to send
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function sendStaffNotification(string $toEmail, string $toName, string $message): array
    {
        if (empty($toEmail)) return ['success' => false, 'error' => 'staff member has no email address'];

        $subject  = 'Notification from KNH BDMS';
        $bodyHtml = "
            <h2>You have a new notification</h2>
            <p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p>
            <div class='info-box'><p>" . nl2br(htmlspecialchars($message)) . "</p></div>
        ";

        return $this->sendEmail($toEmail, $toName, $subject, $this->htmlTemplate($subject, $bodyHtml), true);
    }

    // -------------------------------------------------------------------------
    // Private: Core email dispatch
    // -------------------------------------------------------------------------

    /**
     * Send an email using either PHPMailer (SMTP) or PHP's mail() function.
     *
     * The driver is chosen based on the MAIL_DRIVER constant in config/email.php.
     * Falls back to mail() if PHPMailer is not installed.
     *
     * @param string $toEmail   Recipient email address
     * @param string $toName    Recipient display name
     * @param string $subject   Email subject line
     * @param string $body      Plain-text email body
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function sendEmail(string $toEmail, string $toName, string $subject, string $body, bool $isHtml = false): array
    {
        $fromEmail = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@knh-bdms.local';
        $fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'KNH BDMS';
        $driver    = defined('MAIL_DRIVER')    ? MAIL_DRIVER    : 'mail';

        if ($driver === 'smtp' && class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = defined('MAIL_HOST')       ? MAIL_HOST       : 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = defined('MAIL_USERNAME')   ? MAIL_USERNAME   : '';
                $mail->Password   = defined('MAIL_PASSWORD')   ? MAIL_PASSWORD   : '';
                $mail->SMTPSecure = defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls';
                $mail->Port       = defined('MAIL_PORT')       ? MAIL_PORT       : 587;

                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $toName);
                $mail->Subject = $subject;

                if ($isHtml) {
                    $mail->isHTML(true);
                    $mail->Body    = $body;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>', '</li>'], "\n", $body));
                } else {
                    $mail->isHTML(false);
                    $mail->Body = $body;
                }

                $mail->send();
                return ['success' => true];
            } catch (\Exception $e) {
                error_log('NotificationService SMTP error: ' . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Fallback: PHP mail()
        $headers  = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= $isHtml
            ? "Content-Type: text/html; charset=UTF-8\r\n"
            : "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $sent = mail($toEmail, $subject, $body, $headers);
        if ($sent) return ['success' => true];

        error_log("NotificationService mail() failed sending to {$toEmail}");
        return ['success' => false, 'error' => 'mail() returned false'];
    }
}
