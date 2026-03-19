<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Config.php';

class ProxyChecker {
    public function check(array $proxy, int $timeout, int $connectTimeout): array {
        $status = 'offline';
        $errorMessage = null;
        $responseTime = 0;
        $ch = null;
        $verifySsl = (bool) config('security.verify_ssl', true);

        try {
            $ch = curl_init();
            if ($ch === false) {
                throw new Exception('curl_init() 失败');
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => TEST_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_USERAGENT => 'NetWatch Monitor/1.0'
            ]);

            if ($proxy['type'] === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }

            $proxyUrl = $proxy['ip'] . ':' . $proxy['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);

            if (!empty($proxy['username']) && !empty($proxy['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            $transferTime = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);

            $responseTime = max(0, ($transferTime - $connectTime)) * 1000;
            if ($responseTime <= 0 || $transferTime <= 0) {
                $responseTime = $transferTime * 1000;
            }

            curl_close($ch);
            $ch = null;

            $isPartialFileError = ($curlErrno === 18);
            if ($response !== false && $httpCode === 200) {
                $status = 'online';
            } elseif ($isPartialFileError && $httpCode === 200) {
                $status = 'online';
                $errorMessage = 'Partial transfer: ' . $curlError;
            } else {
                $errorMessage = $curlError ?: "HTTP Code: $httpCode";
                if ($transferTime > 0) {
                    $responseTime = $transferTime * 1000;
                } else {
                    $responseTime = 0;
                }
            }

            return [
                'status' => $status,
                'response_time' => $responseTime,
                'error_message' => $errorMessage,
                'is_exception' => false,
                'is_partial_file_error' => $isPartialFileError,
            ];
        } catch (Exception $e) {
            if ($ch !== null) {
                curl_close($ch);
            }

            return [
                'status' => 'offline',
                'response_time' => 0,
                'error_message' => $e->getMessage(),
                'is_exception' => true,
                'is_partial_file_error' => false,
            ];
        }
    }
}
