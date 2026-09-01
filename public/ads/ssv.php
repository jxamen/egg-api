<?php
/**
 * AdMob 보상형 서버 측 확인(SSV) 콜백 — **공용 API 로 넘긴다.**
 *
 * 애드몹 콘솔에 이 주소가 등록돼 있는데(2026-09-01 확인), 앱은 이제 보상을
 * 공용 API(jcurve-api)에서 가져간다. 여기서 지급하면 그 기록을 앱이 못 봐서
 * 「시청을 확인하는 중이에요」에 멈춘다 — 그래서 받은 그대로 넘기고 답을 돌려준다.
 *
 * 서명 검증·중복 방지·한도 판단은 **넘겨받는 쪽이 한 곳에서** 한다.
 * 두 곳에서 따로 검증하면 규칙이 갈린다.
 *
 * 콘솔 URL 을 공용 API 로 바꾸면 이 파일은 지워도 된다.
 */
declare(strict_types=1);

$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$to = 'https://api.j-curve.co.kr/v1/kkokkofarm/ads/ssv' . ($qs !== '' ? '?' . $qs : '');

$ch = curl_init($to);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
    // 구글이 보내는 헤더는 서명에 안 들어간다 — 쿼리만 그대로 전달하면 된다
    CURLOPT_HTTPHEADER => ['Accept: text/plain'],
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');

if ($body === false || $code === 0) {
    // 못 넘겼으면 5xx 로 답해 구글이 다시 보내게 둔다(지급 기회를 잃지 않는다)
    error_log('ssv forward failed: ' . $err);
    http_response_code(502);
    echo 'forward_failed';
    exit;
}

http_response_code($code);
echo $body;
