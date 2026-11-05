<?php

declare(strict_types=1);

/**
 * Lightweight CI guard: PHP syntax checks alone cannot detect an unresolved
 * Schema facade in a namespaced controller (e.g. Schema::hasTable()).
 * No Laravel bootstrap, environment variables or database connection needed.
 */
$directory = realpath(__DIR__.'/../app/Http/Controllers');
if ($directory === false) {
    fwrite(STDERR, "No se encontraron los controladores PHP.\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $directory,
    FilesystemIterator::SKIP_DOTS
));

$checked = 0;
$failures = [];

foreach ($files as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    ++$checked;
    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        $failures[] = $file->getPathname().': no se pudo leer';
        continue;
    }

    $tokens = token_get_all($source);
    $needsFacade = false;

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'Schema') {
            continue;
        }
        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next])
            && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            ++$next;
        }
        if (isset($tokens[$next]) && is_array($tokens[$next])
            && $tokens[$next][0] === T_DOUBLE_COLON) {
            $needsFacade = true;
            break;
        }
    }

    if ($needsFacade && ! preg_match('/^use\s+Illuminate\\\\Support\\\\Facades\\\\Schema\s*;/m', $source)) {
        $failures[] = $file->getPathname().': falta use Illuminate\\Support\\Facades\\Schema;';
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

echo "OK: {$checked} controladores verificados para Schema:: y su importación.\n";
