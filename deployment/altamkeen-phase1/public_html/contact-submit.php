<?php
declare(strict_types=1);

/* Contact-form configuration: update these three values before launch if needed. */
const CONTACT_TO = 'info@altamkeen.ae';
const CONTACT_FROM = 'website@altamkeen.ae';
const COMPANY_NAME = 'AL TAMKEEN CORPORATE SERVICES DWC LLC';
const RATE_LIMIT_SECONDS = 60;

function redirect_with_status(string $status): void
{
    header('Location: /contact.html?status=' . rawurlencode($status) . '#enquiry', true, 303);
    exit;
}

function clean_text(string $value, int $maxLength): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength, 'UTF-8')
        : substr($value, 0, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

/* Honeypot and minimum-completion-time checks deter automated submissions. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect_with_status('spam');
}
$loadedAt = filter_var($_POST['form_loaded'] ?? null, FILTER_VALIDATE_INT);
if (!$loadedAt || time() - (int) $loadedAt < 3 || time() - (int) $loadedAt > 86400) {
    redirect_with_status('spam');
}

$name = clean_text((string) ($_POST['name'] ?? ''), 100);
$phone = clean_text((string) ($_POST['phone'] ?? ''), 30);
$email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_SANITIZE_EMAIL);
$service = clean_text((string) ($_POST['service'] ?? ''), 100);
$message = clean_text((string) ($_POST['message'] ?? ''), 3000);

$allowedServices = [
    'Business Setup', 'PRO Services', 'Visa Services', 'Legal and Attestation',
    'Real Estate and Investment', 'Business Advisory', 'HR Consultancy',
    'Global Immigration', 'Travel and Tourism', 'Insurance Services',
    'Driving and Vehicle Services', 'Other Corporate Service',
];

if ($name === '' || $phone === '' || $message === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !preg_match('/^[0-9+().\-\s]{7,30}$/', $phone) ||
    !in_array($service, $allowedServices, true)) {
    redirect_with_status('invalid');
}

/* Per-IP file-based throttling works without a database and degrades safely. */
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
    'altamkeen-contact-' . hash('sha256', $clientIp) . '.rate';
$rateHandle = @fopen($rateFile, 'c+');
if ($rateHandle !== false) {
    if (flock($rateHandle, LOCK_EX)) {
        $previous = trim((string) stream_get_contents($rateHandle));
        if ($previous !== '' && time() - (int) $previous < RATE_LIMIT_SECONDS) {
            flock($rateHandle, LOCK_UN);
            fclose($rateHandle);
            redirect_with_status('rate');
        }
        ftruncate($rateHandle, 0);
        rewind($rateHandle);
        fwrite($rateHandle, (string) time());
        fflush($rateHandle);
        flock($rateHandle, LOCK_UN);
    }
    fclose($rateHandle);
}

/* Header values are fixed except Reply-To, which has already passed email validation. */
$subjectName = preg_replace('/[\r\n]+/', ' ', $name) ?? 'Website enquiry';
$subject = 'Website consultation request - ' . $subjectName;
$body = "A new consultation request was submitted through altamkeen.ae.\n\n" .
    "Name: {$name}\nPhone: {$phone}\nEmail: {$email}\nService: {$service}\n\n" .
    "Message:\n{$message}\n\n" .
    "Submitted: " . gmdate('Y-m-d H:i:s') . " UTC\n";
$headers = [
    'From: AL TAMKEEN Website <' . CONTACT_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
];

if (@mail(CONTACT_TO, $subject, $body, implode("\r\n", $headers))) {
    redirect_with_status('success');
}
redirect_with_status('mail');
