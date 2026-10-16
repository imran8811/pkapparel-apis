<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Turns every problem into a clean JSON error. PHP warnings and notices become
 * exceptions, so nothing is ever printed into a response, and unexpected errors
 * are logged with a reference id that is shown to the client.
 */
final class ErrorHandler
{
    private const GENERIC_MESSAGE = 'Something went wrong on our side. Please try again.';

    public function __construct(private readonly Config $config, private readonly Logger $logger)
    {
    }

    /** Install PHP-level handlers. Call once per web request. */
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            // Deprecations are warnings about future PHP versions: log them, never fail a request.
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                error_log("[pkapparel-api] Deprecated: {$message} in {$file}:{$line}");

                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => ['code' => 'server_error', 'message' => self::GENERIC_MESSAGE]]);
        });
    }

    public function render(Throwable $e, ?Request $request = null): Response
    {
        if ($e instanceof HttpException) {
            $response = Response::error($e->errorCode, $e->getMessage(), $e->status, $e->fields);
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        }

        $reference = bin2hex(random_bytes(6));
        $this->logger->error($e->getMessage(), [
            'reference' => $reference,
            'exception' => $e::class,
            'at' => $e->getFile() . ':' . $e->getLine(),
            'request' => $request === null ? null : $request->method . ' ' . $request->path,
            'trace' => $e->getTraceAsString(),
        ]);

        $error = ['code' => 'server_error', 'message' => self::GENERIC_MESSAGE, 'reference' => $reference];
        if ($this->config->bool('debug')) {
            $error['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ];
        }

        return Response::json(['error' => $error], 500);
    }
}
