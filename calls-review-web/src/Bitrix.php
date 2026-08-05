<?php
/** Авторизация через Битрикс24 (OAuth 2.0, локальное приложение). */
final class Bitrix
{
    public function __construct(private array $cfg) {}

    public function authorizeUrl(string $state): string
    {
        return 'https://' . $this->cfg['bitrix_portal'] . '/oauth/authorize/?' . http_build_query([
            'client_id'     => $this->cfg['bitrix_client_id'],
            'response_type' => 'code',
            'redirect_uri'  => $this->cfg['bitrix_redirect_uri'],
            'state'         => $state,
        ]);
    }

    /** Обмен кода на токен → user.current. Возвращает ['id'=>..,'name'=>..]. */
    public function userByCode(string $code): array
    {
        $tokenResp = $this->get('https://oauth.bitrix.info/oauth/token/?' . http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->cfg['bitrix_client_id'],
            'client_secret' => $this->cfg['bitrix_client_secret'],
            'code'          => $code,
        ]));
        $token = json_decode($tokenResp, true);
        if (empty($token['access_token'])) {
            throw new RuntimeException('Bitrix OAuth: ' . substr($tokenResp, 0, 300));
        }
        $endpoint = $token['client_endpoint'] ?? ('https://' . $this->cfg['bitrix_portal'] . '/rest/');

        $userResp = $this->get($endpoint . 'user.current.json?auth=' . rawurlencode($token['access_token']));
        $user = json_decode($userResp, true);
        if (empty($user['result']['ID'])) {
            throw new RuntimeException('Bitrix user.current: ' . substr($userResp, 0, 300));
        }
        $r = $user['result'];
        return [
            'id'   => (string)$r['ID'],
            'name' => trim(($r['LAST_NAME'] ?? '') . ' ' . ($r['NAME'] ?? '')) ?: ('ID ' . $r['ID']),
        ];
    }

    private function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Bitrix HTTP: ' . $err);
        }
        curl_close($ch);
        return (string)$resp;
    }
}
