<?php
/**
 * 애드몹 보상형 서버 측 확인(SSV) 서명 검증.
 * 문서: 쿼리스트링에서 &signature= 앞까지가 서명 대상이고, 공개키는 key_id 로 고른다.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** 구글 공개키 목록(하루 1회 캐시) */
function egg_verifier_keys(bool $force = false): array
{
    $fresh = is_file(EGG_KEYS) && (time() - (int)filemtime(EGG_KEYS) < 86400);
    if (!$force && $fresh) {
        $j = json_decode((string)file_get_contents(EGG_KEYS), true);
        if (is_array($j['keys'] ?? null)) return $j['keys'];
    }
    $ch = curl_init('https://gstatic.com/admob/reward/verifier-keys.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    if (is_array($j['keys'] ?? null)) {
        @file_put_contents(EGG_KEYS, $body);
        return $j['keys'];
    }
    // 내려받기 실패 시 만료된 캐시라도 쓴다
    if (is_file(EGG_KEYS)) {
        $j = json_decode((string)file_get_contents(EGG_KEYS), true);
        if (is_array($j['keys'] ?? null)) return $j['keys'];
    }
    return [];
}

/**
 * 콜백 서명을 검증한다.
 * @return array{ok:bool, reason:string}
 */
function egg_verify_ssv(string $queryString): array
{
    $pos = strpos($queryString, '&signature=');
    if ($pos === false) return ['ok' => false, 'reason' => 'no_signature'];

    $signed = substr($queryString, 0, $pos);          // 서명 대상 원문(디코딩하지 않는다)
    parse_str($queryString, $q);

    $sigB64 = (string)($q['signature'] ?? '');
    $keyId  = (string)($q['key_id'] ?? '');
    if ($sigB64 === '' || $keyId === '') return ['ok' => false, 'reason' => 'missing_fields'];

    $sig = base64_decode(strtr($sigB64, '-_', '+/'), false);
    if ($sig === false || $sig === '') return ['ok' => false, 'reason' => 'bad_base64'];

    // 구글은 값이 URL 디코딩된 상태로 서명한다(보상 항목이 한글이면 인코딩본과 달라진다).
    // 구현체마다 해석이 갈려 두 형태를 모두 시도한다.
    $candidates = [$signed];
    $decoded = rawurldecode($signed);
    if ($decoded !== $signed) $candidates[] = $decoded;

    foreach ([false, true] as $force) {               // 키를 못 찾으면 한 번 갱신해서 재시도
        foreach (egg_verifier_keys($force) as $k) {
            if ((string)($k['keyId'] ?? '') !== $keyId) continue;
            foreach ($candidates as $data) {
                if (openssl_verify($data, $sig, (string)$k['pem'], OPENSSL_ALGO_SHA256) === 1) {
                    return ['ok' => true, 'reason' => 'verified'];
                }
            }
            return ['ok' => false, 'reason' => 'bad_signature'];
        }
    }
    return ['ok' => false, 'reason' => 'unknown_key'];
}
