<?php
/**
 * Obsługa formularza kontaktowego — Kotły TOMKO
 * PHP 7.4+. Wysyłka przez SMTP z logowaniem (dane w kontakt-config.php),
 * a gdy pliku konfiguracyjnego brak — awaryjnie przez mail().
 */
declare(strict_types=1);

// Ostrzeżenia trafiają do logu serwera, nie do odpowiedzi (inaczej psułyby JSON)
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Warsaw');

// ---------- Konfiguracja ----------
const MAIL_TO      = 'tomko1994@o2.pl';
const MAIL_SUBJECT = 'Zapytanie ze strony kotlytomko.pl';
const MIN_SECONDS  = 3;   // formularz wysłany szybciej = bot
const RATE_LIMIT   = 60;  // min. odstęp między wysyłkami z jednej sesji (s)

// Dane SMTP trzymamy poza repozytorium — patrz kontakt-config.example.php
$config = is_file(__DIR__ . '/kontakt-config.php') ? require __DIR__ . '/kontakt-config.php' : null;

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

function encodeHeader(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/**
 * Minimalny klient SMTP: STARTTLS + AUTH LOGIN, treść w base64.
 * Błędy trafiają do logu serwera (bez haseł).
 */
function smtpSend(array $c, string $to, string $subject, string $body, string $replyTo): bool
{
    $host = $c['smtp_host'];
    $from = $c['from'] ?? $c['smtp_user'];
    $ctx  = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_name'        => $host,
    ]]);
    $fp = @stream_socket_client("tcp://{$host}:{$c['smtp_port']}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("kontakt.php SMTP: brak połączenia ({$errno}) {$errstr}");
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = static function (string $line, array $ok, string $label) use ($fp, $read): void {
        if ($line !== '') {
            fwrite($fp, $line . "\r\n");
        }
        $reply = $read();
        if (!in_array((int)substr($reply, 0, 3), $ok, true)) {
            throw new RuntimeException($label . ': ' . trim($reply));
        }
    };

    $ehlo = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'kotlytomko.pl';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . encodeHeader('Kotły TOMKO — formularz') . " <{$from}>",
        "To: <{$to}>",
        "Reply-To: <{$replyTo}>",
        'Subject: ' . encodeHeader($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@kotlytomko.pl>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n"
             . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));

    try {
        $cmd('', [220], 'powitanie');
        $cmd("EHLO {$ehlo}", [250], 'EHLO');
        $cmd('STARTTLS', [220], 'STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            throw new RuntimeException('nie udało się włączyć TLS');
        }
        $cmd("EHLO {$ehlo}", [250], 'EHLO po TLS');
        $cmd('AUTH LOGIN', [334], 'AUTH');
        $cmd(base64_encode($c['smtp_user']), [334], 'AUTH login');
        $cmd(base64_encode($c['smtp_pass']), [235], 'AUTH hasło');
        $cmd("MAIL FROM:<{$from}>", [250], 'MAIL FROM');
        $cmd("RCPT TO:<{$to}>", [250, 251], 'RCPT TO');
        $cmd('DATA', [354], 'DATA');
        $cmd($message . "\r\n.", [250], 'treść');
        $cmd('QUIT', [221], 'QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('kontakt.php SMTP: ' . $e->getMessage());
        fclose($fp);
        return false;
    }
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
      . 'Wysłano: ' . date('Y-m-d H:i') . "\n"
      . "Aby odpowiedzieć, użyj opcji „Odpowiedz” — wiadomość trafi do nadawcy.\n";

$subject = MAIL_SUBJECT . ($service === 'Awaria — pilne' ? ' [AWARIA]' : '');

if (is_array($config) && !empty($config['smtp_pass'])) {
    $sent = smtpSend($config, MAIL_TO, $subject, $body, $email);
} else {
    // Awaryjnie: mail() serwera (na Windows/IIS wymaga skonfigurowanego SMTP w php.ini)
    $from    = 'biuro@kotlytomko.pl';
    $headers = [
        'From: ' . encodeHeader('Kotły TOMKO — formularz') . " <{$from}>",
        'Reply-To: ' . $email,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $sent = mail(MAIL_TO, encodeHeader($subject), $body, implode("\r\n", $headers));
}

if ($sent) {
    $_SESSION['last_send'] = time();
}
respond($sent, $sent ? '' : 'mail');
