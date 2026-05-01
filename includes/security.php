<?php
/**
 * Helpers de seguridad: CSRF, sanitizado de HTML, rate limit, headers.
 */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_verify(): void {
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $real === '' || !hash_equals($real, $sent)) {
        http_response_code(403);
        exit('CSRF token inválido. Recarga la página.');
    }
}

/**
 * Sanitiza HTML de Quill: solo deja tags y atributos seguros.
 * Bloquea javascript:, data: no-imagen, on*=, <script>, <style>, <iframe>, etc.
 */
function sanitizarHTMLEntrega(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $allowedTags = [
        'p','br','strong','b','em','i','u','s','strike','blockquote',
        'ol','ul','li','h1','h2','h3','h4','h5','h6','a','img','span','pre','code','hr'
    ];
    $allowedAttrs = [
        'a'   => ['href','title','target','rel'],
        'img' => ['src','alt','title','width','height'],
    ];

    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        '<?xml encoding="UTF-8"?><div id="__root__">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);

    foreach (iterator_to_array($xpath->query('//*')) as $node) {
        if (!($node instanceof DOMElement)) continue;
        $tag = strtolower($node->nodeName);

        if ($tag === '__root__' || $tag === 'div' && $node->getAttribute('id') === '__root__') {
            continue;
        }

        if (!in_array($tag, $allowedTags, true)) {
            $text = $doc->createTextNode($node->textContent);
            $node->parentNode->replaceChild($text, $node);
            continue;
        }

        $attrsToRemove = [];
        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->name);
            $allowed = $allowedAttrs[$tag] ?? [];

            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $attrsToRemove[] = $attr->name;
                continue;
            }
            if (in_array($name, ['href','src'], true)) {
                $val = trim(strtolower($attr->value));
                $bad = str_starts_with($val, 'javascript:')
                    || str_starts_with($val, 'vbscript:')
                    || (str_starts_with($val, 'data:') && !str_starts_with($val, 'data:image/'));
                if ($bad) $attrsToRemove[] = $attr->name;
            }
        }
        foreach ($attrsToRemove as $a) $node->removeAttribute($a);

        if ($tag === 'a' && $node->getAttribute('target') === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    $root = $xpath->query('//*[@id="__root__"]')->item(0);
    if (!$root) return '';
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/**
 * Rate limit por IP basado en archivo en /tmp.
 * 10 intentos fallidos por 15min -> bloqueo 15min.
 */
function rateLimitFile(string $key): string {
    return sys_get_temp_dir() . '/redaccion_rl_' . md5($key) . '.json';
}

function rateLimitOk(string $key, int $maxAttempts = 10, int $windowSec = 900): bool {
    $file = rateLimitFile($key);
    $data = ['attempts' => [], 'blocked_until' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = $raw ? json_decode($raw, true) : null;
        if (is_array($parsed)) $data = array_merge($data, $parsed);
    }
    $now = time();
    if (($data['blocked_until'] ?? 0) > $now) return false;
    $data['attempts'] = array_values(array_filter(
        $data['attempts'] ?? [],
        fn($t) => is_int($t) && $t > $now - $windowSec
    ));
    return count($data['attempts']) < $maxAttempts;
}

function rateLimitRecord(string $key, int $maxAttempts = 10, int $windowSec = 900): void {
    $file = rateLimitFile($key);
    $data = ['attempts' => [], 'blocked_until' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = $raw ? json_decode($raw, true) : null;
        if (is_array($parsed)) $data = array_merge($data, $parsed);
    }
    $now = time();
    $data['attempts'][] = $now;
    $data['attempts'] = array_values(array_filter(
        $data['attempts'],
        fn($t) => is_int($t) && $t > $now - $windowSec
    ));
    if (count($data['attempts']) >= $maxAttempts) {
        $data['blocked_until'] = $now + $windowSec;
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function rateLimitClear(string $key): void {
    @unlink(rateLimitFile($key));
}

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Headers de seguridad básicos. Llamar antes de cualquier output.
 */
function securityHeaders(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}
