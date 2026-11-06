<?php
/**
 * Obsługa formularza kontaktowego — Kotły TOMKO
 * Wymaga hostingu z PHP 7.4+ i działającą funkcją mail().
 */
declare(strict_types=1);

// Ostrzeżenia trafiają do logu serwera, nie do odpowiedzi (inaczej psułyby JSON)
ini_set('display_errors', '0');

// ---------- Konfiguracja ----------
const MAIL_TO      = 'tomko1994@o2.pl';
const MAIL_FROM    = 'formularz@kotlytomko.pl'; // adres w domenie strony (lepsza dostarczalność)
const MAIL_SUBJECT = 'Zapytanie ze strony kotlytomko.pl';
const MIN_SECONDS  = 3;   // formularz wysłany szybciej = bot
const RATE_LIMIT   = 60;  // min. odstęp między wysyłkami z jednej sesji (s)

session_start();

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function respond(bool $ok, string $error = ''): void
{
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($ok ? 200 : 400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'error' => $error]);
    } else {
        header('Location: index.html?wyslano=' . ($ok ? '1' : '0') . '#kontakt', true, 303);
    }
    exit;
}

function field(string $key, int $max): string
{
    $v = trim((string)($_POST[$key] ?? ''));
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return mb_substr($v, 0, $max);
}

function oneLine(string $v): string
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html#kontakt', true, 303);
    exit;
}

// Antyspam: honeypot, czas wypełnienia, limit częstotliwości
if (field('website', 200) !== '') {
    respond(true); // udajemy sukces przed botem
}
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() - intdiv($ts, 1000)) < MIN_SECONDS) {
    respond(true);
}
if (isset($_SESSION['last_send']) && time() - $_SESSION['last_send'] < RATE_LIMIT) {
    respond(false, 'rate');
}

$name    = oneLine(field('name', 120));
$company = oneLine(field('company', 160));
$email   = oneLine(field('email', 160));
$phone   = oneLine(field('phone', 40));
$device  = oneLine(field('device', 80));
$service = oneLine(field('service', 80));
$message = field('message', 5000);
$consent = ($_POST['consent'] ?? '') === '1';

if ($name === '' || $message === '' || !$consent || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'validation');
}

$body = "Nowe zapytanie ze strony kotlytomko.pl\n"
      . str_repeat('-', 44) . "\n"
      . "Imię i nazwisko: {$name}\n"
      . "Firma / zakład:  " . ($company ?: '—') . "\n"
      . "E-mail:          {$email}\n"
      . "Telefon:         " . ($phone ?: '—') . "\n"
      . "Urządzenie:      " . ($device ?: '—') . "\n"
      . "Usługa:          " . ($service ?: '—') . "\n"
      . str_repeat('-', 44) . "\n\n"
      . $message . "\n\n"
      . str_repeat('-', 44) . "\n"
      . "Zgoda na przetwarzanie danych: TAK\n"
      . 'Wysłano: ' . date('Y-m-d H:i') . "\n";

$encode  = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
$subject = MAIL_SUBJECT . ($service === 'Awaria — pilne' ? ' [AWARIA]' : '');
$headers = [
    'From: ' . $encode('Kotły TOMKO — formularz') . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$sent = mail(MAIL_TO, $encode($subject), $body, implode("\r\n", $headers), '-f' . MAIL_FROM);

if ($sent) {
    $_SESSION['last_send'] = time();
}
respond($sent, $sent ? '' : 'mail');
