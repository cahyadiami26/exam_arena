<?php
declare(strict_types=1);

/**
 * Register the small set of PSR-4 maps needed by the manually installed Dompdf distribution.
 * This keeps the application Composer-free while retaining the upstream library source.
 */
function app_load_dompdf(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $root = dirname(__DIR__) . '/lib/dompdf';
    $requiredFiles = [
        $root . '/src/Dompdf.php',
        $root . '/src/Options.php',
        $root . '/lib/Cpdf.php',
        $root . '/vendor/dompdf/php-font-lib/src/FontLib/Font.php',
        $root . '/vendor/dompdf/php-svg-lib/src/Svg/Document.php',
        $root . '/vendor/masterminds/html5/src/HTML5.php',
        $root . '/vendor/sabberworm/php-css-parser/src/Rule/Rule.php',
        $root . '/vendor/sabberworm/php-css-parser/src/RuleSet/RuleContainer.php',
    ];
    foreach ($requiredFiles as $file) {
        if (!is_file($file)) {
            $available = false;
            return false;
        }
    }

    $prefixes = [
        'Dompdf\\' => $root . '/src/',
        'FontLib\\' => $root . '/vendor/dompdf/php-font-lib/src/FontLib/',
        'Svg\\' => $root . '/vendor/dompdf/php-svg-lib/src/Svg/',
        'Masterminds\\' => $root . '/vendor/masterminds/html5/src/',
        'Sabberworm\\CSS\\' => $root . '/vendor/sabberworm/php-css-parser/src/',
    ];
    spl_autoload_register(static function (string $class) use ($root, $prefixes): void {
        if ($class === 'Dompdf\\Cpdf') {
            require_once $root . '/lib/Cpdf.php';
            return;
        }
        foreach ($prefixes as $prefix => $baseDirectory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDirectory . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }, true, true);

    try {
        // Sabberworm declares these aliases through Composer's "files" autoload section.
        require_once $root . '/vendor/sabberworm/php-css-parser/src/Rule/Rule.php';
        require_once $root . '/vendor/sabberworm/php-css-parser/src/RuleSet/RuleContainer.php';
        $available = class_exists(\Dompdf\Dompdf::class);
    } catch (Throwable $exception) {
        error_log('E-Assessment Dompdf autoload failed: ' . $exception->getMessage());
        $available = false;
    }

    return $available;
}
