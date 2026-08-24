<?php
/**
 * 기프티쇼 비즈 API 클라이언트 (연동규격서 v1.05).
 *
 * 인증은 발급받은 인증키(custom_auth_code)와 토큰키(custom_auth_token)를
 * 그대로 파라미터로 실어 보낸다. 규격서 8쪽이 AES256/ECB 를 언급하지만
 * 이어지는 괄호가 "기프티쇼 비즈에서 암호화하며, 고객사는 암호화 필요 없음"이다.
 * 상용 키로 0101·0111 을 호출해 파라미터 전송만으로 code 0000 이 오는 것을 확인했다.
 *
 * dev_yn 은 N 고정이다 — 규격서가 "N만 호출 가능", "현재 개발 환경은 지원하지 않습니다".
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
        'auth_code'   => (string)($c['auth_code'] ?? ''),   // 인증 Key
        'token_key'   => (string)($c['token_key'] ?? ''),   // 인증 Token Key (그대로 전송)
        'user_id'     => (string)($c['user_id'] ?? ''),     // 기프티쇼 비즈 회원 ID
        'gubun'       => (string)($c['gubun'] ?? 'I'),      // Y 핀번호 / N MMS / I 바코드이미지
        'callback_no' => (string)($c['callback_no'] ?? ''),
        // 발송 화면에 쓰는 우리 이미지 — 비즈 사이트에서 등록하고 받은 ID
        'banner_id'   => (string)($c['banner_id'] ?? ''),
        'template_id' => (string)($c['template_id'] ?? ''),
    ];
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
    if ($c['auth_code'] === '' || $c['token_key'] === '') {
        throw new RuntimeException('giftishow auth_code/token_key 가 config.php 에 없습니다');
    }
    $auth = [
        'api_code'          => $apiCode,
        'custom_auth_code'  => $c['auth_code'],
        'custom_auth_token' => $c['token_key'],
        'dev_yn'            => 'N',
    ];
    // form-urlencoded 규격대로 공백은 + 로 보낸다(RFC3986 은 %20 이 되어 MMS 본문이 어긋난다)
    $body = http_build_query($auth + $params);

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
        'banner_id'   => $c['banner_id'],
        'template_id' => $c['template_id'],
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
