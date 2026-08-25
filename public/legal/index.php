<?php
/**
 * 서비스 소개 — 구글 OAuth 동의 화면의 "애플리케이션 홈페이지" 칸에 넣을 공개 페이지.
 * 앱 스토어 심사와 각 사 로그인 검수도 서비스가 실재하는지 볼 곳을 요구한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/legal.php';

$co = legal_company();
$company = legal_val($co['name']);
$email   = legal_val($co['email']);

legal_page('꼬꼬농장', <<<HTML
<p><strong>꼬꼬농장</strong>은 알을 부화시켜 병아리를 키우고, 모은 포인트를 기프티콘으로 바꾸는
모바일 농장 키우기 서비스입니다.</p>

<h2>주요 기능</h2>
<ul>
  <li><strong>농장 키우기</strong> — 알을 부화시키고 병아리에게 먹이를 주며 성장시킵니다. 알 도감 30종을 모을 수 있습니다.</li>
  <li><strong>보상 적립</strong> — 출석, 광고 시청, 참여형 미션으로 포인트와 사료를 모읍니다.</li>
  <li><strong>기프티콘 교환</strong> — 모은 포인트를 카페·편의점 기프티콘이나 네이버페이 포인트로 바꿉니다.</li>
  <li><strong>친구와 함께</strong> — 초대 코드로 친구를 맞이하고 랭킹을 겨룹니다.</li>
</ul>

<h2>로그인 방식</h2>
<p>서비스는 <strong>소셜 계정(카카오 · 네이버 · 구글 · Apple)으로만</strong> 가입할 수 있습니다.
회사는 이용자의 비밀번호를 수집하거나 보관하지 않으며, 로그인 시 제공자로부터
회원 식별을 위한 고유 번호와 닉네임(선택적으로 이메일)만 전달받습니다.</p>

<h2>문의</h2>
<p>서비스 이용 문의는 앱의 <strong>1:1 문의</strong> 또는 {$email} 로 접수해 주세요.</p>

<h2>약관</h2>
<ul>
  <li><a href="/legal/terms">서비스 이용약관</a></li>
  <li><a href="/legal/privacy">개인정보처리방침</a></li>
</ul>

<p style="margin-top:28px">{$company}</p>
HTML);
