<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Structured logger that writes to storage/logs/app.log.
 * Never log full customer messages or sensitive credentials.
 */
class Logger
{
    private static ?string $logFile = null;

    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            $path = BASE_PATH . '/' . env('LOG_FILE', 'storage/logs/app.log');
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$logFile = $path;
        }
        return self::$logFile;
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $ts      = date('Y-m-d H:i:s');
        $ctx     = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line    = "[$ts] [$level] $message$ctx" . PHP_EOL;
        @file_put_contents(self::getLogFile(), $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $msg, array $ctx = []): void  { self::write('INFO',  $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::write('ERROR', $msg, $ctx); }
    public static function warn(string $msg, array $ctx = []): void  { self::write('WARN',  $msg, $ctx); }
    public static function debug(string $msg, array $ctx = []): void
    {
        if (env('APP_DEBUG', 'false') === 'true') {
            self::write('DEBUG', $msg, $ctx);
        }
    }
}

