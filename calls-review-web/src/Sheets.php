<?php
/**
 * Чтение Google Sheets сервисным аккаунтом (scope readonly), без composer.
 * Токен и данные кешируются в файлах (cache_dir).
 */
final class Sheets
{
    public function __construct(private array $cfg) {}

    /** Данные обеих вкладок основной таблицы, с кешем cache_ttl_sec. */
    public function readMain(bool $fresh = false): array
    {
        $cacheFile = rtrim($this->cfg['cache_dir'], '/') . '/sheets_main.json';
        if (!$fresh && is_file($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < (int)$this->cfg['cache_ttl_sec']) {
                $data = json_decode((string)file_get_contents($cacheFile), true);
                if (is_array($data)) return $data;
            }
        }

        $ranges = [$this->cfg['calls_sheet_name'], $this->cfg['operators_sheet_name']];
        $qs = http_build_query(['majorDimension' => 'ROWS']);
        foreach ($ranges as $r) $qs .= '&ranges=' . rawurlencode($r);
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
             . rawurlencode($this->cfg['main_spreadsheet_id'])
             . '/values:batchGet?' . $qs;

        $resp = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken()]);
        $json = json_decode($resp, true);
        if (!isset($json['valueRanges'])) {
            throw new RuntimeException('Sheets API: неожиданный ответ: ' . substr($resp, 0, 300));
        }
        $data = [
            'calls'     => $json['valueRanges'][0]['values'] ?? [],
            'operators' => $json['valueRanges'][1]['values'] ?? [],
            'fetched_at' => date('c'),
        ];
        file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }

    public function invalidate(): void
    {
        @unlink(rtrim($this->cfg['cache_dir'], '/') . '/sheets_main.json');
    }

    /** OAuth-токен сервисного аккаунта (JWT RS256), кеш ~55 минут. */
    private function accessToken(): string
    {
        $tokFile = rtrim($this->cfg['cache_dir'], '/') . '/google_token.json';
        if (is_file($tokFile)) {
            $t = json_decode((string)file_get_contents($tokFile), true);
            if (is_array($t) && ($t['exp'] ?? 0) > time() + 60) return $t['token'];
        }

        $sa = json_decode((string)file_get_contents($this->cfg['google_service_account_json']), true);
        if (!isset($sa['client_email'], $sa['private_key'])) {
            throw new RuntimeException('Не удалось прочитать JSON сервисного аккаунта Google.');
        }
        $now = time();
        $claim = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $b64 = fn(array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $input = $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64($claim);
        if (!openssl_sign($input, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Не удалось подписать JWT (openssl).');
        }
        $jwt = $input . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

        $resp = $this->httpPost('https://oauth2.googleapis.com/token', http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $json = json_decode($resp, true);
        if (empty($json['access_token'])) {
            throw new RuntimeException('Google OAuth: ' . substr($resp, 0, 300));
        }
        file_put_contents($tokFile, json_encode([
            'token' => $json['access_token'],
            'exp'   => $now + (int)($json['expires_in'] ?? 3600),
        ]), LOCK_EX);
        return $json['access_token'];
    }

    private function httpGet(string $url, array $headers = []): string
    {
        return $this->curl($url, null, $headers);
    }

    private function httpPost(string $url, string $body, array $headers = []): string
    {
        return $this->curl($url, $body, $headers);
    }

    private function curl(string $url, ?string $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP: ' . $err);
        }
        curl_close($ch);
        return (string)$resp;
    }
}
