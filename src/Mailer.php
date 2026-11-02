<?php
declare(strict_types=1);

function send_verification_code(string $recipient, string $code, string $purpose = 'registration'): bool
{
    $from = pluschat_mail_from($purpose === 'password_reset' ? 'MAIL_LOGIN_FROM' : 'MAIL_FROM');
    $fromName = str_replace(["\r", "\n"], '', getenv('MAIL_FROM_NAME') ?: 'PlusChat');
    if ($from === null) {
        error_log('PlusChat mail error: MAIL_FROM is missing or invalid.');
        return false;
    }

    $subject = $purpose === 'password_reset' ? 'Код восстановления PlusChat' : 'Код подтверждения PlusChat';
    $heading = $purpose === 'password_reset' ? 'Восстановите пароль' : 'Подтвердите почту';
    $instructions = $purpose === 'password_reset'
        ? 'Введите этот код в приложении, чтобы задать новый пароль.'
        : 'Введите этот код в приложении, чтобы продолжить регистрацию.';
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeName = htmlspecialchars($fromName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html lang="ru"><meta charset="utf-8"><body style="margin:0;background:#f5f6fb;font-family:Arial,sans-serif;color:#20243a">'
        . '<div style="max-width:560px;margin:36px auto;padding:32px 24px;background:#fff;border:1px solid #e8e9f1;border-radius:20px">'
        . '<div style="font-size:18px;font-weight:700;color:#6658e8">+ PlusChat</div>'
        . '<h1 style="margin:28px 0 10px;font-size:25px">' . $heading . '</h1>'
        . '<p style="margin:0;color:#666d80;line-height:1.6">' . $instructions . '</p>'
        . '<div style="margin:24px 0;padding:18px;background:#f5f4ff;border-radius:14px;text-align:center;font-size:34px;font-weight:700;letter-spacing:9px;color:#5144cc">' . $safeCode . '</div>'
        . '<p style="color:#666d80;line-height:1.6">Код действует 10 минут. Если вы не запрашивали этот код, просто проигнорируйте письмо.</p>'
        . '<hr style="margin:26px 0;border:0;border-top:1px solid #ececf2"><p style="margin:0;color:#969bad;font-size:12px">Это автоматическое письмо от ' . $safeName . '.</p>'
        . '</div></body></html>';
    return send_pluschat_html($recipient, $subject, $html, $from, $fromName);
}

function send_registration_success(string $recipient, string $displayName): bool
{
    $from = pluschat_mail_from();
    $fromName = str_replace(["\r", "\n"], '', getenv('MAIL_FROM_NAME') ?: 'PlusChat');
    if ($from === null) {
        return false;
    }

    $subject = 'Аккаунт PlusChat создан';
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B')
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safeName = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html lang="ru"><meta charset="utf-8"><body style="margin:0;background:#f5f6fb;font-family:Arial,sans-serif;color:#20243a">'
        . '<div style="max-width:560px;margin:36px auto;padding:32px 24px;background:#fff;border:1px solid #e8e9f1;border-radius:20px">'
        . '<div style="font-size:18px;font-weight:700;color:#6658e8">+ PlusChat</div>'
        . '<h1 style="margin:28px 0 10px;font-size:25px">Аккаунт создан</h1>'
        . '<p style="color:#666d80;line-height:1.6">Здравствуйте, ' . $safeName . '! Почта подтверждена, и ваш аккаунт готов. Вы уже вошли в PlusChat.</p>'
        . '<p style="color:#666d80;line-height:1.6">В целях безопасности мы никогда не отправляем пароль по электронной почте.</p>'
        . '</div></body></html>';
    return send_pluschat_html($recipient, $subject, $html, $from, $fromName);
}

function pluschat_mail_from(string $variable = 'MAIL_FROM'): ?string
{
    $from = trim((string) (getenv($variable) ?: ''));
    if ($from === '' && $variable !== 'MAIL_FROM') return pluschat_mail_from('MAIL_FROM');
    if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return $from;
    }
    if (!preg_match('/^([^@]+)@([^@]+)$/u', $from, $parts) || !function_exists('idn_to_ascii')) {
        error_log('PlusChat mail error: configure MAIL_FROM as an ASCII email address.');
        return null;
    }
    $asciiDomain = idn_to_ascii($parts[2]);
    $asciiAddress = $parts[1] . '@' . ($asciiDomain ?: '');
    if (!$asciiDomain || !filter_var($asciiAddress, FILTER_VALIDATE_EMAIL)) {
        error_log('PlusChat mail error: MAIL_FROM is invalid.');
        return null;
    }
    return $asciiAddress;
}

function send_pluschat_html(string $recipient, string $subject, string $html, string $from, string $fromName): bool
{
    $smtpHost = trim((string) (getenv('SMTP_HOST') ?: ''));
    if ($smtpHost !== '') {
        return send_pluschat_smtp($recipient, $subject, $html, $from, $fromName);
    }

    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B')
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($fromName, 'UTF-8', 'B')
        : '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $encodedFromName . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    return mail($recipient, $encodedSubject, $html, implode("\r\n", $headers));
}

/**
 * SMTP sender accounts may differ for registration and password recovery.
 * Legacy SMTP_USER/SMTP_PASSWORD remain supported as a fallback.
 */
function pluschat_smtp_credentials(string $from): array
{
    $registrationFrom = pluschat_mail_from('MAIL_FROM');
    $loginFrom = pluschat_mail_from('MAIL_LOGIN_FROM');

    if ($loginFrom !== null && strcasecmp($from, $loginFrom) === 0) {
        $user = trim((string) (getenv('SMTP_LOGIN_USER') ?: ''));
        $password = (string) (getenv('SMTP_LOGIN_PASSWORD') ?: '');
    } elseif ($registrationFrom !== null && strcasecmp($from, $registrationFrom) === 0) {
        $user = trim((string) (getenv('SMTP_REGISTER_USER') ?: ''));
        $password = (string) (getenv('SMTP_REGISTER_PASSWORD') ?: '');
    } else {
        $user = '';
        $password = '';
    }

    if ($user === '') $user = trim((string) (getenv('SMTP_USER') ?: ''));
    if ($password === '') $password = (string) (getenv('SMTP_PASSWORD') ?: '');

    // SMTP servers commonly expect internationalized mailbox domains in IDNA ASCII form.
    if (preg_match('/^([^@]+)@([^@]+)$/u', $user, $parts) && preg_match('/[^\\x20-\\x7E]/', $parts[2])) {
        if (!function_exists('idn_to_ascii')) {
            error_log('PlusChat mail error: PHP Intl is required for an internationalized SMTP username.');
            throw new RuntimeException('PHP Intl is required for an internationalized SMTP username.');
        }
        $asciiDomain = idn_to_ascii($parts[2]) ?: '';
        if ($asciiDomain === '') {
            error_log('PlusChat mail error: SMTP username domain is invalid.');
            throw new RuntimeException('SMTP username domain is invalid.');
        }
        $user = $parts[1] . '@' . $asciiDomain;
    }
    return [$user, $password];
}

function pluschat_smtp_ascii_host(string $host): ?string
{
    $host = trim($host);
    if ($host === '' || preg_match('/[\r\n\/\\\\@]/', $host)) return null;

    if (preg_match('/[^\x20-\x7E]/', $host)) {
        if (!function_exists('idn_to_ascii')) return null;
        $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
        $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
        $host = idn_to_ascii($host, $flags, $variant) ?: '';
    }

    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || strlen($host) > 253
        || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host)
        || str_contains($host, '..')) {
        return null;
    }
    return $host;
}

function send_pluschat_smtp(string $recipient, string $subject, string $html, string $from, string $fromName): bool
{
    $configuredHost = trim((string) (getenv('SMTP_HOST') ?: ''));
    $host = pluschat_smtp_ascii_host($configuredHost);
    $port = (int) (getenv('SMTP_PORT') ?: 25);
    $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'auto')));
    [$username, $password] = pluschat_smtp_credentials($from);

    if ($host === null || $port < 1 || $port > 65535
        || !in_array($encryption, ['auto', 'starttls', 'tls', 'ssl', 'none'], true)
        || (($username === '') !== ($password === ''))
        || ($username !== '' && $encryption === 'none')
        || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('PlusChat mail error: SMTP configuration or email address is invalid.');
        return false;
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);
    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errorMessage,
        12,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        error_log('PlusChat mail error: SMTP connection failed; errno=' . (int) $errno . '.');
        return false;
    }

    stream_set_timeout($socket, 15);
    $phase = 'greeting';

    try {
        smtp_expect($socket, [220]);
        $hello = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'pluschat.local')) ?: 'pluschat.local';

        $phase = 'EHLO';
        $ehloResponse = smtp_command($socket, 'EHLO ' . $hello, [250]);
        $supportsStartTls = (bool) preg_match('/(?:^|\r\n)250[ -]STARTTLS(?:\s|$)/i', $ehloResponse);

        $useStartTls = $encryption === 'starttls'
            || $encryption === 'tls'
            || ($encryption === 'auto' && $supportsStartTls);

        if (($encryption === 'starttls' || $encryption === 'tls') && !$supportsStartTls) {
            throw new RuntimeException('SMTP server does not advertise STARTTLS (status 250).');
        }
        if ($useStartTls) {
            $phase = 'STARTTLS';
            smtp_command($socket, 'STARTTLS', [220]);
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('TLS negotiation failed.');
            }
            $phase = 'EHLO after TLS';
            $ehloResponse = smtp_command($socket, 'EHLO ' . $hello, [250]);
        }

        if ($username !== '') {
            if (!$useStartTls && $encryption !== 'ssl') {
                throw new RuntimeException('SMTP authentication refused because the connection is not encrypted.');
            }
            $phase = 'AUTH LOGIN';
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($username), [334]);
            smtp_command($socket, base64_encode($password), [235]);
        }

        $phase = 'MAIL FROM';
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        $phase = 'RCPT TO';
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        $phase = 'DATA';
        smtp_command($socket, 'DATA', [354]);

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B')
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($fromName, 'UTF-8', 'B')
            : '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $messageIdDomain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $messageIdDomain . '>',
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = chunk_split(base64_encode($html), 76, "\r\n");
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $message = preg_replace('/(^|\r\n)\./', '$1..', $message);
        $phase = 'message body';
        smtp_write($socket, $message . ".\r\n");
        smtp_expect($socket, [250]);

        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    } catch (Throwable $error) {
        // Do not log server-provided text, addresses, usernames, or credentials.
        error_log('PlusChat mail error: SMTP failed during ' . $phase . ': ' . $error->getMessage());
        if (is_resource($socket)) fclose($socket);
        return false;
    }
}

function smtp_write($socket, string $data): void
{
    $length = strlen($data);
    $written = 0;
    while ($written < $length) {
        $result = @fwrite($socket, substr($data, $written));
        if ($result === false || $result === 0) {
            throw new RuntimeException('SMTP socket write failed.');
        }
        $written += $result;
    }
}

function smtp_command($socket, string $command, array $expected): string
{
    smtp_write($socket, $command . "\r\n");
    return smtp_expect($socket, $expected);
}

function smtp_expect($socket, array $expected): string
{
    $response = '';
    $line = '';
    do {
        $line = @fgets($socket, 2048);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP response timed out.' : 'SMTP server closed the connection.');
        }
        $response .= $line;
        $continue = isset($line[3]) && $line[3] === '-';
    } while ($continue);

    $code = (int) substr($line, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP server returned status ' . $code . '.');
    }
    return $response;
}
