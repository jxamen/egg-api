<?php
/**
 * 약관·개인정보처리방침 웹 문서.
 *
 * 왜 웹에 두나: 앱 안(G-08/G-09) 화면만으로는 부족하다. 구글 OAuth 동의 화면(브랜딩),
 * App Store 심사, 네이버 로그인 검수가 모두 **공개된 URL** 을 요구한다.
 * 앱 화면과 이 문서의 내용은 같아야 한다 — 한쪽만 고치지 말 것.
 *
 * 사업자 정보는 config.php 의 'company' 에서 읽는다. 비어 있으면 화면에 "확인 필요" 로
 * 드러나게 두었다(거짓 값을 채워 두면 심사에서 그대로 문제가 된다).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const LEGAL_EFFECTIVE = '2026년 9월 1일';

/** config.php 의 company 항목 — 없으면 비워 둔 채로 표시한다 */
function legal_company(): array
{
    $c = egg_config()['company'] ?? [];
    return [
        'name'    => $c['name']    ?? '주식회사 제이커브인터렉티브',
        'ceo'     => $c['ceo']     ?? '',
        'addr'    => $c['addr']    ?? '',
        'bizno'   => $c['bizno']   ?? '',
        'email'   => $c['email']   ?? '',
        'officer' => $c['officer'] ?? '',
    ];
}

function legal_val(string $v): string
{
    return $v !== '' ? htmlspecialchars($v, ENT_QUOTES) : '<span class="todo">확인 필요</span>';
}

/** 문서 공통 껍데기 — 앱 색(#E8760F)과 같은 톤으로 최소한만 꾸민다 */
function legal_page(string $title, string $bodyHtml): never
{
    $co = legal_company();
    $name = htmlspecialchars($co['name'], ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · 꼬꼬농장</title>
<style>
  :root { color-scheme: light }
  * { box-sizing: border-box }
  body { margin: 0; padding: 0 20px 72px; background: #FBF7EC; color: #2B2622;
         font: 15px/1.7 -apple-system, BlinkMacSystemFont, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif;
         -webkit-text-size-adjust: 100% }
  .wrap { max-width: 720px; margin: 0 auto }
  header { padding: 40px 0 8px }
  header a { color: #E8760F; text-decoration: none; font-weight: 700; font-size: 14px }
  h1 { font-size: 26px; letter-spacing: -.02em; margin: 16px 0 6px }
  .eff { color: #7A736B; font-size: 13.5px; margin: 0 0 32px }
  h2 { font-size: 17px; letter-spacing: -.02em; margin: 34px 0 10px }
  p, li { color: #3D3831 }
  ul { padding-left: 20px; margin: 8px 0 }
  li { margin: 4px 0 }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 14px }
  th, td { border: 1px solid #E4DCC9; padding: 9px 11px; text-align: left; vertical-align: top }
  th { background: #F3EDDD; font-weight: 700; white-space: nowrap }
  .todo { color: #C0392B; font-weight: 700 }
  footer { margin-top: 48px; padding-top: 20px; border-top: 1px solid #E4DCC9; color: #7A736B; font-size: 13px }
  @media (max-width: 480px) { body { padding: 0 16px 56px } h1 { font-size: 22px } }
</style>
</head>
<body><div class="wrap">
<header><a href="/legal/">꼬꼬농장</a></header>
<h1>{$title}</h1>
<p class="eff">시행일 · 2026년 9월 1일</p>
{$bodyHtml}
<footer>{$name} · 꼬꼬농장</footer>
</div></body>
</html>
HTML;
    exit;
}
