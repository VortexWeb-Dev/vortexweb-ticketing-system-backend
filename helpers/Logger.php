<?php

namespace Helpers;

class Logger
{
    protected static function getLogFilePath(): string
    {
        $date = date('Y/m/d');
        $baseDir = __DIR__ . "/../logs/{$date}";

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        return $baseDir . '/requests.log';
    }

    public static function logRequest(array $data): void
    {
        $filePath = self::getLogFilePath();
        $timestamp = date('[Y-m-d H:i:s]');
        $logData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $logEntry = "$timestamp\n$logData\n\n";

        file_put_contents($filePath, $logEntry, FILE_APPEND);
    }
}
