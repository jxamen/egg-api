<?php
/**
 * 기프티쇼 비즈 API 클라이언트 (연동규격서 v1.05).
 *
 * 인증은 인증키(custom_auth_code)와, 그 인증키를 별도로 받은 암호화 키로
 * AES-256-ECB/PKCS5 암호화해 base64 로 만든 토큰(custom_auth_token)으로 한다.
 *
 * 규격서가 스스로 엇갈리는 부분이 둘 있어 양쪽을 모두 보낸다.
 *   - 인증값 위치: "시스템 연동 방법"은 HTTP 헤더라 하고, 각 API 표는 파라미터라고 한다.
 *   - 테스트 플래그 이름: 헤더 설명은 dev_flag, 각 API 표는 dev_yn 이다.
 * 둘 다 실으면 어느 쪽을 읽든 통과하고, 서로 값이 다를 일도 없다.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const GS_BASE = 'https://bizapi.giftishow.com/bizApi';

/** 규격서 부록 1의 공통 에러코드 중 우리가 분기하는 것들 */
const GS_OK             = '0000';
const GS_NO_BIZMONEY    = 'E0010';

/** 발송은 규격서가 타임아웃 15초를 기준으로 취소 절차를 안내한다 */
const GS_SEND_TIMEOUT   = 15;
const GS_TIMEOUT        = 30;

function gs_config(): array
{
    $c = egg_config()['giftishow'] ?? [];
    return [
        'auth_code' => (string)($c['auth_code'] ?? ''),   // 인증 키 (서비스관리 메뉴)
        'enc_key'   => (string)($c['enc_key'] ?? ''),     // 담당자에게 별도로 받는 암호화 키
        'user_id'   => (string)($c['user_id'] ?? ''),     // 기프티쇼 비즈 회원 ID
        'dev'       => (bool)($c['dev'] ?? true),         // 개발환경이면 true
        'gubun'     => (string)($c['gubun'] ?? 'I'),      // Y 핀번호 / N MMS / I 바코드이미지
        'callback_no' => (string)($c['callback_no'] ?? ''),
    ];
}

/** custom_auth_token — 인증키를 암호화 키로 AES-256-ECB 암호화한 값(base64) */
function gs_token(): string
{
    $c = gs_config();
    if ($c['auth_code'] === '' || $c['enc_key'] === '') {
        throw new RuntimeException('giftishow auth_code/enc_key 가 config.php 에 없습니다');
    }
    if (strlen($c['enc_key']) !== 32) {
        throw new RuntimeException('giftishow enc_key 는 32바이트여야 합니다(현재 ' . strlen($c['enc_key']) . ')');
    }
    $enc = openssl_encrypt($c['auth_code'], 'aes-256-ecb', $c['enc_key'], OPENSSL_RAW_DATA);
    if ($enc === false) throw new RuntimeException('인증 토큰 생성 실패');
    return base64_encode($enc);
}

/**
 * API 한 건 호출.
 *
 * @param string $apiCode 0101 · 0204 …
 * @param string $path    GS_BASE 뒤에 붙는 경로 (예: '/goods')
 * @param array  $params  API 고유 파라미터
 *
 * 응답 모양이 API 마다 달라서(0301 은 balance 가 최상위, 0204 는 result 가 두 겹) raw 도 함께 준다.
 *
 * @return array{ok:bool, code:string, message:string, result:mixed, raw:array, http:int}
 */
function gs_call(string $apiCode, string $path, array $params = [], ?int $timeout = null): array
{
    $c = gs_config();
    $auth = [
        'api_code'          => $apiCode,
        'custom_auth_code'  => $c['auth_code'],
        'custom_auth_token' => gs_token(),
        'dev_yn'            => $c['dev'] ? 'Y' : 'N',
        'dev_flag'          => $c['dev'] ? 'Y' : 'N',
    ];
    $body = http_build_query($auth + $params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init(GS_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout ?? GS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            'Accept: application/json',
            'api_code: ' . $apiCode,
            'custom_auth_code: ' . $auth['custom_auth_code'],
            'custom_auth_token: ' . $auth['custom_auth_token'],
            'dev_yn: ' . $auth['dev_yn'],
            'dev_flag: ' . $auth['dev_flag'],
        ],
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        // 타임아웃도 여기로 온다 — 발송(0204)이었다면 호출한 쪽이 반드시 취소를 보내야 한다
        return ['ok' => false, 'code' => 'TIMEOUT', 'message' => $cerr ?: '응답 없음', 'result' => null, 'raw' => [], 'http' => 0];
    }
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'code' => 'BADJSON', 'message' => substr((string)$raw, 0, 200), 'result' => null, 'raw' => [], 'http' => $http];
    }
    $code = (string)($j['code'] ?? '');
    return [
        'ok'      => $code === GS_OK,
        'code'    => $code,
        'message' => (string)($j['message'] ?? ''),
        'result'  => $j['result'] ?? null,
        'raw'     => $j,
        'http'    => $http,
    ];
}

/* ───────────────────────── 각 API ───────────────────────── */

/** 1. 상품 리스트 (0101) — 한 페이지씩 */
function gs_goods(int $start = 1, int $size = 100): array
{
    return gs_call('0101', '/goods', ['start' => (string)$start, 'size' => (string)$size]);
}

/** 2. 상품 상세 정보 (0111) */
function gs_goods_detail(string $goodsCode): array
{
    return gs_call('0111', '/goods/' . rawurlencode($goodsCode), ['goods_code' => $goodsCode]);
}

/** 5. 쿠폰 상세 정보 (0201) — 발송에 쓴 tr_id 로 조회 */
function gs_coupon(string $trId): array
{
    return gs_call('0201', '/coupons', ['tr_id' => $trId]);
}

/** 6. 쿠폰 취소 (0202) — 오발송 취소. 미사용 쿠폰 일괄 취소는 부정사용으로 간주된다 */
function gs_cancel(string $trId): array
{
    return gs_call('0202', '/cancel', ['tr_id' => $trId, 'user_id' => gs_config()['user_id']]);
}

/** 8. 쿠폰발송요청 (0204) — 포인트를 먼저 차감한 뒤에 호출해야 한다 */
function gs_send(string $trId, string $goodsCode, string $phoneNo, string $title, string $msg, ?string $orderNo = null): array
{
    $c = gs_config();
    return gs_call('0204', '/send', array_filter([
        'goods_code'  => $goodsCode,
        'tr_id'       => $trId,
        'order_no'    => $orderNo,
        'user_id'     => $c['user_id'],
        'phone_no'    => preg_replace('/\D/', '', $phoneNo),
        'callback_no' => preg_replace('/\D/', '', $c['callback_no']),
        'mms_title'   => mb_substr($title, 0, 10),      // 규격: 10자 초과 불가
        'mms_msg'     => $msg,
        'gubun'       => $c['gubun'],
    ], static fn($v) => $v !== null && $v !== ''), GS_SEND_TIMEOUT);
}

/** 9. 현재 비즈머니 잔액 (0301) — 잔액이 없으면 발송이 되지 않는다 */
function gs_bizmoney(): array
{
    return gs_call('0301', '/bizmoney', ['user_id' => gs_config()['user_id']]);
}

/** 10. 발송실패 취소 (0205) — 비즈머니는 빠졌는데 핀이 발행되지 않은 경우 */
function gs_send_fail_cancel(string $trId): array
{
    return gs_call('0205', '/sendFail/cancel', ['tr_id' => $trId, 'user_id' => gs_config()['user_id']]);
}

/* ───────────────────────── 거래 ID ───────────────────────── */

/**
 * TR_ID 는 25자 이하 고유값이어야 한다. 규격서 권고 형식은 service_20190814_12345678.
 * 'egg_' + YYYYMMDD + '_' + 12hex = 25자.
 */
function gs_new_tr_id(): string
{
    return 'egg_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
}
