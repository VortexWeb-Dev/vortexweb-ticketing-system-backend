<?php

class ResponseService
{
    /**
     * Send a successful JSON response.
     *
     * @param int $code HTTP status code, defaults to 200.
     * @param mixed $data Response data.
     * @param array $headers Additional headers to send.
     * @param bool $cache Whether to allow caching.
     */
    public function sendSuccess(int $code = 200, $data = [], array $headers = [], bool $cache = true): void
    {
        $this->setHeaders($headers, $cache);
        http_response_code($code);
        echo $this->safeJson($data);
        exit;
    }

    /**
     * Send an error JSON response.
     *
     * @param int $code HTTP status code.
     * @param string $message Error message.
     * @param array $extra Additional data to include.
     */
    public function sendError(int $code, string $message, array $extra = []): void
    {
        $this->setHeaders([], false);
        http_response_code($code);
        $payload = array_merge(['error' => $message], $extra);
        echo $this->safeJson($payload);
        exit;
    }

    /**
     * Set JSON headers and cache policy.
     *
     * @param array $customHeaders Additional headers.
     * @param bool $cache Whether caching is allowed.
     */
    private function setHeaders(array $customHeaders = [], bool $cache = true): void
    {
        header("Content-Type: application/json");
        header("Access-Control-Allow-Origin: *"); // Optional: CORS
        header("Access-Control-Allow-Headers: Content-Type");

        if ($cache) {
            header("Cache-Control: max-age=300, public");
        } else {
            header("Cache-Control: no-cache, no-store, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
        }

        foreach ($customHeaders as $key => $value) {
            header("$key: $value");
        }
    }

    /**
     * Safely encodes the data to JSON and handles encoding errors.
     *
     * @param mixed $data The data to encode.
     * @return string The JSON encoded string.
     */
    private function safeJson($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Encode Error: " . json_last_error_msg()); // Optional logging
            return json_encode(['error' => 'Failed to encode JSON']);
        }
        return $json;
    }
}
