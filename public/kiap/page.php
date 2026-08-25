<?php
/**
 * GET /auth/page?uid=&ret= — 앱 WebBrowser(openAuthSessionAsync)가 여는 간편인증 페이지.
 * kiap.js(자체 호스팅)를 iframe 모드로 띄우고, 완료되면 콜백이 ret 스킴으로 복귀시킨다.
 * 자격증명은 서버가 렌더 시점에 심는다 — 앱 번들에는 어떤 키도 실리지 않는다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/kiap.php';

$uid = trim((string)($_GET['uid'] ?? ''));
$ret = trim((string)($_GET['ret'] ?? ''));

/** 상단 이동으로 앱 스킴 복귀(실패 포함 모든 종료가 이 길로 나간다) */
function kiap_page_exit(string $ret, array $q): never
{
    $url = $ret . (str_contains($ret, '?') ? '&' : '?') . http_build_query($q);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><script>location.replace(' . json_encode($url) . ');</script>'
        . '<p style="font-family:sans-serif;text-align:center;margin-top:40vh;">앱으로 돌아갑니다…</p>';
    exit;
}

if (!kiap_ret_ok($ret)) {
    http_response_code(400);
    exit('잘못된 복귀 주소입니다.');
}
if ($uid === '' || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $uid)) {
    kiap_page_exit($ret, ['ok' => 0, 'reason' => 'BAD_UID']);
}
if (!kiap_enabled()) {
    kiap_page_exit($ret, ['ok' => 0, 'reason' => 'CONFIG_MISSING']);
}

$db = kiap_db();
// 같은 사용자의 연타 방지(5초)
$st = $db->prepare('SELECT MAX(created_at) t FROM kiap_session WHERE uid = ?');
$st->execute([$uid]);
if ((int)($st->fetch()['t'] ?? 0) > time() - KIAP_INIT_RATE) {
    kiap_page_exit($ret, ['ok' => 0, 'reason' => 'RATE_LIMITED']);
}

$sid = bin2hex(random_bytes(16));
$db->prepare('INSERT INTO kiap_session (sid, uid, ret, status, created_at) VALUES (?,?,?,?,?)')
   ->execute([$sid, $uid, $ret, 'pending', time()]);

$cfg = kiap_config();
$base = 'https://' . (string)($_SERVER['HTTP_HOST'] ?? 'kkokkofarm.j-curve.co.kr');
$js = json_encode([
    'host'        => $cfg['host'],
    'clientId'    => $cfg['client_id'],
    'accessToken' => $cfg['access_token'],
    'callbackUrl' => $base . '/auth/kiap-callback?sid=' . $sid,
    'cancelUrl'   => $ret . (str_contains($ret, '?') ? '&' : '?') . 'ok=0&reason=CANCELED',
], JSON_UNESCAPED_SLASHES);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>본인인증 · 꼬꼬농장</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:-apple-system, 'Pretendard', sans-serif; background:#FFFDF7; min-height:100dvh;
         display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:24px; }
  h1 { font-size:20px; color:#3A2E1F; }
  p  { font-size:13px; color:#8A7B66; text-align:center; line-height:1.5; }
  .btn { width:100%; max-width:320px; padding:15px 0; border:0; border-radius:14px; font-size:16px;
         font-weight:700; cursor:pointer; }
  .kakao { background:#FEE500; color:#191919; }
  .naver { background:#03C75A; color:#fff; }
  .toss  { background:#0064FF; color:#fff; }
  .cancel { background:none; color:#8A7B66; font-size:14px; font-weight:400; margin-top:8px; }
  /* iOS 100vh 가 툴바를 포함해 인증창 하단이 잘린다 — dvh 로 보정(insurance-db LPLG-3170) */
  #kiap-sass { height:100dvh !important; }
</style>
<script src="/auth/kiap.js"></script>
</head>
<body>
<h1>간편인증으로 본인확인</h1>
<p>이용 중인 인증 앱을 선택하세요.<br>인증 결과는 중복 계정 방지에만 사용됩니다.</p>
<button class="btn kakao" onclick="go('KAKAO')">카카오 인증</button>
<button class="btn naver" onclick="go('NAVER')">네이버 인증</button>
<button class="btn toss"  onclick="go('TOSS')">토스 인증</button>
<button class="btn cancel" onclick="location.replace(CFG.cancelUrl)">취소하고 돌아가기</button>
<script>
var CFG = <?= $js ?>;
window.kiap && kiap.configure({ host: CFG.host, mode: 'iframe' });
// iOS: 클릭과 request 사이에 await 가 있으면 user activation 이 끊긴다 — 동기 호출만.
function go(provider) {
  if (!window.kiap) { alert('인증 모듈을 불러오지 못했어요. 잠시 후 다시 시도해 주세요.'); return; }
  kiap.request(CFG.clientId, CFG.accessToken, {
    service_code: 'AUTH',
    callback_url: CFG.callbackUrl,
    default_provider: provider,
    device_type: 'MO',
  }).catch(function (e) {
    // 사용자가 인증창을 닫은 경우 등 — 페이지에 머물러 재시도 가능하게 둔다
    console.log('kiap', e && e.code, e && e.message);
  });
}
</script>
</body>
</html>
