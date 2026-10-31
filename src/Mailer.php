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
    if (getenv('SMTP_HOST')) {
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

function send_pluschat_smtp(string $recipient, string $subject, string $html, string $from, string $fromName): bool
{
    $host = trim((string) getenv('SMTP_HOST'));
    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
    $username = (string) (getenv('SMTP_USER') ?: '');
    $password = (string) (getenv('SMTP_PASSWORD') ?: '');
    if ($host === '' || preg_match('/[\r\n]/', $host) || $port < 1 || $port > 65535 || !in_array($encryption, ['tls', 'ssl', 'none'], true)
        || ($username !== '' && $encryption === 'none')) {
        error_log('PlusChat mail error: SMTP configuration is invalid.');
        return false;
    }
    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log('PlusChat mail error: SMTP connection failed (' . $errno . ').');
        return false;
    }
    stream_set_timeout($socket, 10);
    try {
        smtp_expect($socket, [220]);
        $hello = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'pluschat.local')) ?: 'pluschat.local';
        smtp_command($socket, 'EHLO ' . $hello, [250]);
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . $hello, [250]);
        }
        if ($username !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($username), [334]);
            smtp_command($socket, base64_encode($password), [235]);
        }
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
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
        fwrite($socket, $message . ".\r\n");
        smtp_expect($socket, [250]);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    } catch (Throwable $error) {
        error_log('PlusChat mail error: SMTP delivery failed (' . $error->getMessage() . ').');
        fclose($socket);
        return false;
    }
}

function smtp_command($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expected);
}

function smtp_expect($socket, array $expected): string
{
    $response = '';
    do {
        $line = fgets($socket, 2048);
        if ($line === false) throw new RuntimeException('SMTP server closed the connection.');
        $response .= $line;
        $continue = isset($line[3]) && $line[3] === '-';
    } while ($continue);
    $code = (int) substr($line, 0, 3);
    if (!in_array($code, $expected, true)) throw new RuntimeException('SMTP server returned status ' . $code . '.');
    return $response;
}
