<?php


namespace Hitrov;


use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use JsonException;

class HttpClient
{
    /**
     * @param array $curlOptions
     * @return array
     * @throws ApiCallException
     * @throws JsonException|CurlException
     */
    public static function getResponse(array $curlOptions, ?array &$responseHeaders = null): array
    {
        $responseHeaders = [];
        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($curl, string $line) use (&$responseHeaders): int {
            if (preg_match('/^HTTP\//i', $line)) {
                $responseHeaders = [];
            } elseif (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        };
        $curl = curl_init();
        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $errNo = curl_errno($curl);
        $info = curl_getinfo($curl);
        // curl_close deprecated in PHP 8.0 - resources are automatically closed

        if ($response === false || ($error && $errNo)) {
            throw new CurlException("curl error occurred: $error, response: $response", $errNo);
        }

        $responseArray = json_decode($response, true);
        $jsonError = json_last_error();
        $logResponse = $response;
        if (!$jsonError) {
            $logResponse = json_encode($responseArray, JSON_PRETTY_PRINT);
        }

        if ($info['http_code'] < 200 || $info['http_code'] >= 300) {
            throw new ApiCallException($logResponse, $info['http_code']);
        }

        if ($jsonError) {
            $jsonErrorMessage = json_last_error_msg();
            throw new JsonException("JSON error occurred: $jsonError ($jsonErrorMessage), response: \n$logResponse");
        }

        return $responseArray;
    }
}
