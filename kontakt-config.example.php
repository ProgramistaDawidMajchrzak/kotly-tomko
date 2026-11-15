<?php
/**
 * WZÓR konfiguracji SMTP formularza.
 * Na serwerze: skopiuj jako kontakt-config.php, wpisz hasło skrzynki i wgraj obok kontakt.php.
 * Plik kontakt-config.php NIE trafia do repozytorium (.gitignore) ani na GitHub.
 */
return [
    'smtp_host' => 'mail.webio.pl',      // certyfikat serwera: *.webio.pl
    'smtp_port' => 587,                  // STARTTLS
    'smtp_user' => 'biuro@kotlytomko.pl', // skrzynka, z której wychodzą wiadomości formularza
    'smtp_pass' => '',                   // ← hasło tej skrzynki
    'from'      => 'biuro@kotlytomko.pl', // musi być taki sam jak smtp_user
];
