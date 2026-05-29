<?php

namespace App\Infrastructure\Config;

/**
 * Cargador de variables de entorno desde un archivo .env.
 */
class DotEnv
{
    /**
     * Carga el archivo .env especificado.
     *
     * @param string $path Ruta absoluta al archivo .env
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Ignorar comentarios
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Separar llave=valor
            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Quitar comillas del valor
            if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match('/^\'(.*)\'$/', $value, $matches)) {
                $value = $matches[1];
            }

            // Definir en entorno si no existe
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
