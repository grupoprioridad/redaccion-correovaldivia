<?php
/**
 * Exportación de entregas aprobadas a WordPress vía REST API.
 */

function wp_config_get(string $clave): string {
    static $cache = [];
    if (isset($cache[$clave])) return $cache[$clave];
    $row = getDB()->prepare("SELECT valor FROM wp_config WHERE clave = ? LIMIT 1");
    $row->execute([$clave]);
    $cache[$clave] = $row->fetchColumn() ?: '';
    return $cache[$clave];
}

function wp_export_activo(): bool {
    return wp_config_get('export_activo') === '1';
}

/**
 * Sube una imagen local al media library de WordPress.
 * Devuelve la URL pública en WP, o false si falla.
 */
function wp_subir_imagen(string $ruta_local, string $nombre_archivo, string $wp_base, string $auth): string|false {
    if (!file_exists($ruta_local)) return false;

    $mime = mime_content_type($ruta_local);
    $datos = file_get_contents($ruta_local);
    if (!$datos) return false;

    $ch = curl_init($wp_base . '/wp-json/wp/v2/media');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $datos,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Disposition: attachment; filename="' . $nombre_archivo . '"',
            'Content-Type: ' . $mime,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 201) return false;
    $json = json_decode($resp, true);
    return $json['source_url'] ?? false;
}

/**
 * Reemplaza las URLs de imágenes de redacción por las URLs de WordPress.
 * Sube cada imagen al media library y retorna el contenido modificado.
 */
function wp_procesar_imagenes(string $html, string $uploads_path, string $wp_base, string $auth): string {
    return preg_replace_callback(
        '/<img([^>]*?)src=["\'](' . preg_quote(UPLOADS_URL, '/') . '\/([^"\']+))["\']([^>]*?)>/i',
        function ($m) use ($uploads_path, $wp_base, $auth) {
            $ruta_relativa = $m[3];
            $ruta_local    = $uploads_path . '/' . $ruta_relativa;
            $nombre        = basename($ruta_relativa);
            $nueva_url     = wp_subir_imagen($ruta_local, $nombre, $wp_base, $auth);
            if (!$nueva_url) return $m[0]; // si falla, deja la URL original
            return '<img' . $m[1] . 'src="' . htmlspecialchars($nueva_url, ENT_QUOTES) . '"' . $m[4] . '>';
        },
        $html
    );
}

/**
 * Exporta una entrega aprobada a WordPress.
 * Registra el resultado en wp_exports.
 *
 * @param  int $historia_id
 * @param  PDO $db
 * @return array{ok:bool, mensaje:string, wp_post_id:int|null}
 */
function wp_exportar_entrega(int $historia_id, PDO $db): array {
    $fail = fn(string $msg) => ['ok' => false, 'mensaje' => $msg, 'wp_post_id' => null];

    if (!wp_export_activo()) {
        return $fail('Exportación a WordPress desactivada.');
    }

    $wp_base = rtrim(wp_config_get('wp_url'), '/');
    $wp_user = wp_config_get('wp_user');
    $wp_pass = wp_config_get('wp_app_password');
    $status  = wp_config_get('exportar_como') ?: 'draft';

    if (!$wp_base || !$wp_user || !$wp_pass) {
        return $fail('Configuración de WordPress incompleta.');
    }

    $auth = base64_encode($wp_user . ':' . $wp_pass);

    // Obtener entrega + historia
    $stmt = $db->prepare("
        SELECT e.id AS entrega_id, e.contenido, e.imagenes,
               h.titulo, h.descripcion, h.categoria_id,
               c.nombre AS categoria_nombre, c.slug AS categoria_slug,
               u.nombre AS autor_nombre
        FROM entregas e
        JOIN historias h ON h.id = e.historia_id
        LEFT JOIN categorias_redaccion c ON c.id = h.categoria_id
        LEFT JOIN usuarios u ON u.id = h.periodista_asignado
        WHERE e.historia_id = ? AND e.estado = 'aprobado'
        ORDER BY e.id DESC LIMIT 1
    ");
    $stmt->execute([$historia_id]);
    $row = $stmt->fetch();

    if (!$row) {
        return $fail('No se encontró entrega aprobada para historia #' . $historia_id);
    }

    $entrega_id = (int)$row['entrega_id'];

    // Verificar que no se haya exportado ya
    $ya = $db->prepare("SELECT id FROM wp_exports WHERE entrega_id = ? AND estado = 'ok' LIMIT 1");
    $ya->execute([$entrega_id]);
    if ($ya->fetch()) {
        return $fail('Esta entrega ya fue exportada anteriormente.');
    }

    // Procesar imágenes: sube a WP y reemplaza URLs en el contenido
    $contenido = wp_procesar_imagenes(
        $row['contenido'],
        UPLOADS_PATH,
        $wp_base,
        $auth
    );

    // Construir cuerpo del post
    $post = [
        'title'   => $row['titulo'],
        'content' => $contenido,
        'status'  => $status,
        'excerpt' => $row['descripcion'] ?? '',
    ];

    // Intentar mapear categoría al ID de WP
    if ($row['categoria_nombre']) {
        $cat_id = wp_obtener_o_crear_categoria($row['categoria_nombre'], $row['categoria_slug'], $wp_base, $auth);
        if ($cat_id) {
            $post['categories'] = [$cat_id];
        }
    }

    // Crear el post
    $ch = curl_init($wp_base . '/wp-json/wp/v2/posts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($post),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $wp_post_id = null;
    $ok         = false;
    $mensaje    = '';

    if ($code === 201) {
        $json       = json_decode($resp, true);
        $wp_post_id = (int)($json['id'] ?? 0);
        $ok         = true;
        $mensaje    = 'Exportado como borrador #' . $wp_post_id;
    } else {
        $json    = json_decode($resp, true);
        $mensaje = 'Error WP (HTTP ' . $code . '): ' . ($json['message'] ?? $resp);
    }

    // Registrar resultado
    $db->prepare("
        INSERT INTO wp_exports (historia_id, entrega_id, wp_post_id, estado, mensaje, exportado_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $historia_id,
        $entrega_id,
        $wp_post_id ?: null,
        $ok ? 'ok' : 'error',
        $mensaje,
        $_SESSION['usuario_id'] ?? null,
    ]);

    return ['ok' => $ok, 'mensaje' => $mensaje, 'wp_post_id' => $wp_post_id];
}

/**
 * Busca o crea una categoría en WordPress y devuelve su ID.
 */
function wp_obtener_o_crear_categoria(string $nombre, ?string $slug, string $wp_base, string $auth): int|false {
    $slug_buscar = $slug ?: sanitize_slug($nombre);

    // Buscar primero
    $ch = curl_init($wp_base . '/wp-json/wp/v2/categories?slug=' . urlencode($slug_buscar) . '&per_page=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $cats = json_decode($resp, true);
    if (!empty($cats[0]['id'])) return (int)$cats[0]['id'];

    // Crear
    $ch = curl_init($wp_base . '/wp-json/wp/v2/categories');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['name' => $nombre, 'slug' => $slug_buscar]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 201) {
        $json = json_decode($resp, true);
        return (int)($json['id'] ?? 0) ?: false;
    }
    return false;
}

function sanitize_slug(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}
