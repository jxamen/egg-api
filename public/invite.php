<?php
/**
 * 초대 링크 랜딩 — /i/{code}
 *
 * 카톡·문자로 받은 사람이 열면:
 *  1) 앱이 깔려 있으면 앱 스킴(kkokkofarm://invite?code=…)으로 바로 열려 코드가 자동 등록된다.
 *  2) 안 깔려 있으면 스토어로 보낸다. 설치 후 첫 실행에서 코드를 자동으로 못 읽으므로
 *     이 화면이 코드를 **클립보드에 복사**해 두고, 크게 보여 주기도 한다.
 *
 * 서버가 코드를 검증하지 않는다 — 코드는 앱이 회원 ID 에서 만들고 앱이 확인한다.
 * 여기서는 형식(영문·숫자 4~12자)만 보고 그대로 넘긴다.
 */
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['code'] ?? '')));
if ($code === '' || strlen($code) > 12) {
    $code = '';
}

$scheme = 'kkokkofarm://invite?code=' . rawurlencode($code);
$ios    = 'https://apps.apple.com/kr/app/id6804574363';
$aos    = 'https://play.google.com/store/apps/details?id=kr.co.jcurve.eggfarm';

/**
 * 초대받은 사람이 받는 것 — 어드민 '친구 초대 보상' 이 원본이다.
 * 공용 API 의 공개 설정을 읽어 쓰고, 못 읽으면 문구에서 숫자를 빼고 보여 준다
 * (옛 숫자가 박혀 있으면 설정을 바꿔도 링크만 다른 약속을 하게 된다 — 2026-08-31 지시).
 */
function invite_reward_text(): string
{
    $cache = sys_get_temp_dir().'/kkokko-invite-reward.json';
    if (is_readable($cache) && time() - filemtime($cache) < 600) {
        $txt = (string) file_get_contents($cache);
        if ($txt !== '') { return $txt; }
    }

    $txt = '';
    $ctx = stream_context_create(['http' => [
        'timeout' => 3,
        'header' => "Accept: application/json\r\nX-App-Token: 7U4R3tn6Lb5YXDVNUlVxXxhHXbRVdNRejU18y4sT\r\n",
    ]]);
    $raw = @file_get_contents('https://api.j-curve.co.kr/v1/kkokkofarm/content/config/daily', false, $ctx);
    $cfg = $raw ? (json_decode($raw, true)['config'] ?? []) : [];
    if ($cfg) {
        $parts = [];
        if (($cfg['refeePoint'] ?? 0) > 0) { $parts[] = number_format((int) $cfg['refeePoint']).'P'; }
        if (($cfg['refeeFeed'] ?? 0) > 0) { $parts[] = '사료 '.(int) $cfg['refeeFeed'].'개'; }
        if (($cfg['refeeWater'] ?? 0) > 0) { $parts[] = '물 '.(int) $cfg['refeeWater'].'개'; }
        if (($cfg['refeeVita'] ?? 0) > 0) { $parts[] = '영양제 '.(int) $cfg['refeeVita'].'개'; }
        $txt = implode(' + ', $parts);
    }
    if ($txt !== '') { @file_put_contents($cache, $txt); }

    return $txt;
}

$reward = invite_reward_text();
$desc = $reward !== ''
    ? '초대 링크로 시작하면 '.$reward.'를 바로 받아요. 닭을 키우고 알을 모아 포인트로 바꿔 보세요.'
    : '초대 링크로 시작하면 시작 선물을 바로 받아요. 닭을 키우고 알을 모아 포인트로 바꿔 보세요.';
// 뒤의 v 는 그림을 고쳤을 때 올린다 — 카카오·CF 가 주소별로 캐시해 두어
// 파일만 바꾸면 한동안 옛 그림이 계속 나간다(2026-08-31 확인)
$ogimg = 'https://kkokkofarm.j-curve.co.kr/og-invite.png?v=2';
$ogurl = 'https://kkokkofarm.j-curve.co.kr/i/'.rawurlencode($code);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>꼬꼬농장 초대 — 함께 닭 키우고 포인트 받아요</title>
<meta name="description" content="<?= htmlspecialchars($desc, ENT_QUOTES) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="꼬꼬농장">
<meta property="og:locale" content="ko_KR">
<meta property="og:title" content="꼬꼬농장에서 함께 닭 키워요! 🐔">
<meta property="og:description" content="<?= htmlspecialchars($desc, ENT_QUOTES) ?>">
<meta property="og:url" content="<?= htmlspecialchars($ogurl, ENT_QUOTES) ?>">
<meta property="og:image" content="<?= $ogimg ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="꼬꼬농장 — 함께 닭 키우고 포인트 받아요">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="꼬꼬농장에서 함께 닭 키워요! 🐔">
<meta name="twitter:description" content="<?= htmlspecialchars($desc, ENT_QUOTES) ?>">
<meta name="twitter:image" content="<?= $ogimg ?>">
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif;
         background: linear-gradient(180deg, #EAF6E0, #FDF6E3); color: #3A2E17;
         min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .card { width: 100%; max-width: 380px; background: rgba(255,253,246,.97); border-radius: 26px; padding: 30px 24px 26px;
          box-shadow: 0 12px 30px -12px rgba(90,66,26,.45); text-align: center; }
  h1 { font-size: 22px; margin: 0 0 8px; letter-spacing: -.02em; }
  p { font-size: 15px; line-height: 1.6; color: #6B6047; margin: 0 0 20px; }
  .code { font-size: 30px; font-weight: 800; letter-spacing: .06em; background: #FCF3DC; border-radius: 16px;
          padding: 14px 10px; margin-bottom: 8px; }
  .hint { font-size: 13px; color: #8A7F63; margin: 0 0 20px; }
  a.btn { display: block; text-decoration: none; border-radius: 16px; padding: 15px; font-size: 16.5px; font-weight: 800; margin-bottom: 10px; }
  .go { background: linear-gradient(180deg, #F5A238, #ED8B21); color: #fff; }
  .store { background: #fff; color: #3A2E17; border: 2px solid #EFE4C8; }
  .egg { font-size: 54px; margin-bottom: 6px; }
</style>
</head>
<body>
  <div class="card">
    <div class="egg">🥚</div>
    <h1>꼬꼬농장에 초대받았어요!</h1>
    <p>친구와 함께 닭을 키우고 알을 모아<br>커피·기프티콘으로 바꿔 보세요.</p>
    <?php if ($code !== ''): ?>
      <div class="code" id="code"><?= htmlspecialchars($code, ENT_QUOTES) ?></div>
      <p class="hint">코드가 복사됐어요 — 앱에서 자동으로 입력됩니다</p>
    <?php endif; ?>
    <a class="btn go" id="open" href="<?= htmlspecialchars($scheme, ENT_QUOTES) ?>">앱으로 열기</a>
    <a class="btn store" id="store" href="<?= htmlspecialchars($ios, ENT_QUOTES) ?>">앱 설치하기</a>
  </div>
<script>
(function () {
  var code = <?= json_encode($code) ?>;
  var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var store = isIOS ? <?= json_encode($ios) ?> : <?= json_encode($aos) ?>;
  document.getElementById('store').href = store;

  // 설치 후 첫 실행에서 코드를 자동으로 넣기 위해 미리 복사해 둔다(클립보드는 사용자 제스처 없이도
  // 대부분 브라우저가 허용하지 않으므로 실패해도 조용히 넘어간다 — 화면의 코드가 대안이다)
  if (code && navigator.clipboard) { navigator.clipboard.writeText(code).catch(function () {}); }

  // 앱이 깔려 있으면 스킴이 열리고, 아니면 잠시 뒤 스토어로 보낸다.
  var hidden = false;
  document.addEventListener('visibilitychange', function () { if (document.hidden) hidden = true; });
  function tryOpen() {
    window.location.href = <?= json_encode($scheme) ?>;
    setTimeout(function () { if (!hidden) window.location.href = store; }, 1400);
  }
  document.getElementById('open').addEventListener('click', function (e) { e.preventDefault(); tryOpen(); });
  // 카톡 인앱 브라우저는 자동 스킴 이동을 막는 경우가 많아 사용자가 버튼을 누르게 둔다
  if (!/KAKAOTALK|NAVER|Instagram|FBAN|FBAV/i.test(navigator.userAgent)) { setTimeout(tryOpen, 300); }
})();
</script>
</body>
</html>
