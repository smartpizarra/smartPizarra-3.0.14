<?php
// Versión del archivo: 1.0.0 (2026-11-17)
/*
 * REDUCIR LAS IMÁGENES YA SUBIDAS (2026-11-17), una vez, por SSH:
 *     cd ~/domains/smartpizarra.cloud/private && php shrink_images.php           (solo muestra qué haría)
 *     cd ~/domains/smartpizarra.cloud/private && php shrink_images.php --apply   (las reduce)
 *
 * Desde ese día las subidas nuevas se reducen solas (MediaRepository::shrinkImage:
 * máx. 1080x1920 / 1920x1080). Este script aplica lo mismo al contenido de los usuarios
 * (sp_media), anuncios (sp_ads) y banners (sp_overlays) que ya estaban subidos, y
 * actualiza size_bytes y sha256: la "revision" de api/media.php cambia y las pantallas
 * descargan la versión pequeña en su próxima consulta (por la cola de descargas).
 * Con --apply guarda antes cada original en private/backup-originales-AAAAMMDD/.
 * Solo por línea de comandos: private/ no es accesible desde la web.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib.php';

$apply = in_array('--apply', $argv, true);
$pdo = Database::pdo();
$backup = __DIR__ . '/backup-originales-' . gmdate('Ymd');
$total = ['antes' => 0, 'despues' => 0, 'n' => 0];

foreach (['sp_media' => 'contenido', 'sp_ads' => 'anuncio', 'sp_overlays' => 'banner'] as $table => $label) {
    $rows = $pdo->query("SELECT id, mime, stored_name, size_bytes FROM $table WHERE kind = 'image' AND stored_name IS NOT NULL")->fetchAll();
    foreach ($rows as $r) {
        $path = MediaRepository::path($r);
        $info = is_file($path) ? @getimagesize($path) : false;
        if ($info === false) {
            continue;
        }
        $big = max($info[0], $info[1]) > MediaRepository::MAX_IMAGE_LONG || min($info[0], $info[1]) > MediaRepository::MAX_IMAGE_SHORT;
        if (!$big) {
            continue;
        }
        $before = filesize($path);
        if (!$apply) {
            printf("%-9s #%-4d %5dx%-5d %6.1f MB  => se reduciría\n", $label, $r['id'], $info[0], $info[1], $before / 1048576);
            continue;
        }
        if (!is_dir($backup) && !mkdir($backup, 0750, true)) {
            exit("No se pudo crear $backup\n");
        }
        if (!copy($path, $backup . '/' . basename($path))) {
            echo "$label #{$r['id']}: no se pudo copiar el original, se omite\n";
            continue;
        }
        if (!MediaRepository::shrinkImage($path, (string) $r['mime'])) {
            echo "$label #{$r['id']}: no se pudo reducir, se deja igual\n";
            continue;
        }
        clearstatcache(true, $path);
        $after = filesize($path);
        $pdo->prepare("UPDATE $table SET size_bytes = ?, sha256 = ? WHERE id = ?")
            ->execute([$after, hash_file('sha256', $path), $r['id']]);
        $s = getimagesize($path);
        printf("%-9s #%-4d %5dx%-5d %6.1f MB  =>  %4dx%-4d %5.2f MB\n", $label, $r['id'], $info[0], $info[1], $before / 1048576, $s[0], $s[1], $after / 1048576);
        $total['antes'] += $before;
        $total['despues'] += $after;
        $total['n']++;
    }
}

if ($apply) {
    printf("\n%d imágenes reducidas: %.1f MB => %.1f MB. Originales en %s\n", $total['n'], $total['antes'] / 1048576, $total['despues'] / 1048576, $backup);
} else {
    echo "\n(Solo muestra. Para reducirlas: php shrink_images.php --apply)\n";
}
