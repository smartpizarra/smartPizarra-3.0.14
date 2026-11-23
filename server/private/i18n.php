<?php
// Versión del archivo: 1.0.1 (2026-11-22)
/*
 * =====================================================================
 *  SMART PIZARRA - IDIOMAS DE LOS PANELES (español / inglés), 2026-11-22
 * =====================================================================
 *  - I18n::lang()  idioma actual: 'es' (por defecto: el panel del
 *    administrador y las API siguen en español) o 'en'.
 *  - I18n::set()   lo cambia para esta petición (lo llama la página).
 *  - t('Texto en español', ...args)  traduce: la CLAVE es el propio texto
 *    en español; en inglés se busca en i18n_en.php y, si falta, se deja el
 *    español. Con argumentos se rellena con vsprintf (%s, %d, %1$s...).
 *  - I18n::pick()  elige el idioma de una página: ?lang= => guardado del
 *    usuario => cookie => idioma del navegador (Accept-Language).
 *  Las traducciones al inglés están en private/i18n_en.php (una línea por
 *  texto). Va en private/ junto a lib.php, que lo carga.
 */

declare(strict_types=1);

final class I18n
{
    /** Idiomas disponibles (código => nombre en su idioma, para el selector). */
    public const LANGS = ['es' => 'Español', 'en' => 'English'];

    private static string $lang = 'es';
    /** @var array<string,string>|null Diccionario español => inglés (se carga al primer uso). */
    private static ?array $en = null;

    public static function lang(): string
    {
        return self::$lang;
    }

    /** Cambia el idioma de esta petición (valor raro => español). */
    public static function set(string $lang): void
    {
        self::$lang = isset(self::LANGS[$lang]) ? $lang : 'es';
    }

    /** ¿Es un código de idioma válido? */
    public static function valid(?string $lang): bool
    {
        return $lang !== null && isset(self::LANGS[$lang]);
    }

    /**
     * Idioma de la página: el elegido ahora (?lang=), el guardado del usuario, la
     * cookie o el del navegador (español si empieza por "es"; si no, inglés).
     */
    public static function pick(?string $requested, ?string $saved, ?string $cookie): string
    {
        foreach ([$requested, $saved, $cookie] as $l) {
            if (self::valid($l)) {
                return (string) $l;
            }
        }
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        return $accept === '' || str_starts_with(trim($accept), 'es') ? 'es' : 'en';
    }

    /** Traducción del texto (sin rellenar). */
    public static function text(string $es): string
    {
        if (self::$lang === 'es') {
            return $es;
        }
        if (self::$en === null) {
            $file = __DIR__ . '/i18n_en.php';
            self::$en = is_file($file) ? (array) require $file : [];
        }
        return self::$en[$es] ?? $es;
    }

    /** Todas las claves con traducción (para las pruebas). @return array<string,string> */
    public static function dictionary(): array
    {
        $file = __DIR__ . '/i18n_en.php';
        return is_file($file) ? (array) require $file : [];
    }
}

/**
 * Traduce un texto escrito en español al idioma actual y, si hay argumentos,
 * los inserta (vsprintf: %s, %d, %1$s...).
 */
function t(string $es, string|int|float ...$args): string
{
    $s = I18n::text($es);
    return $args ? vsprintf($s, $args) : $s;
}
