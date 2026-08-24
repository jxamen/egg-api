# egg-api — 애드몹 보상형 광고 서버 측 확인(SSV)

`https://egg-api.j-curve.co.kr` 로 서비스되는 작은 PHP 엔드포인트.
광고 보상(사료)을 **클라이언트가 아니라 구글 콜백을 검증한 서버가** 적립한다.

```
egg-api/
  config.sample.php     앱 키 샘플 — 서버에서는 config.php 로 복사해 쓴다(문서 루트 밖)
  src/bootstrap.php     설정·SQLite 연결·스키마·로그
  src/verify.php        애드몹 서명 검증(ECDSA P-256 / SHA-256)
  public/index.php      헬스 체크
  public/ads/ssv.php    애드몹 콜백 수신 → 검증 → 적립
  public/ads/pending.php  앱: 받을 보상 조회
  public/ads/claim.php    앱: 보상 수령 처리
  var/                  SQLite DB·로그 (웹 접근 차단)
```

## 배포 위치

| | 경로 |
|---|---|
| 서버 | `/www/jcurve/egg-api` (문서 루트 = `public`) |
| PHP | 8.3 (php-fpm, **user=jcurve 인 풀**을 써야 한다) |
| DB | `var/egg.sqlite` — 계정 없이 파일 하나로 운영 |

## 콜백 처리 규칙

1. **서명 검증** — 쿼리스트링에서 `&signature=` 앞까지가 서명 대상. 공개키는 `key_id` 로 고르고
   `https://gstatic.com/admob/reward/verifier-keys.json` 를 하루 1회 캐시한다.
2. **재전송 차단** — `timestamp`(ms)가 10분보다 오래되면 버린다.
3. **중복 지급 차단** — `transaction_id` 가 기본키. 같은 콜백이 다시 와도 한 번만 적립하고 200 을 준다.
4. **수량은 서버가 정한다** — 콜백의 `reward_amount` 는 로그만 남기고 실제 보상은 상수(사료 1개).
5. **하루 한도** — 사용자당 `DAILY_LIMIT`(5회, KST 기준). 초과하면 적립하지 않고 200.
6. **테스트 콜백** — `user_id` 가 비었거나 `ssv-test…` 로 시작하면 검증만 하고 적립하지 않는다.
7. 응답 코드: 400 형식 오류 / 403 서명 실패 / 500 DB 오류(구글이 재시도) / 그 외 200.

## 앱용 엔드포인트

헤더 `X-Egg-Key: <config.php 의 app_key>` 필요. **실제 회원 세션이 붙기 전까지 쓰는 임시 보호**다.

```
GET  /ads/pending?user_id=<id>   → {"ok":true,"pending":N,"item":"사료","todayUsed":n,"todayLeft":m}
POST /ads/claim  user_id=<id>    → {"ok":true,"granted":N,"item":"사료","amount":1}
```

앱 흐름: 광고 시청 → (구글이 서버로 콜백) → 앱이 `pending` 확인 → `claim` 으로 수령 →
`granted` 개수만큼 사료 게이지를 올린다. 콜백이 몇 분 늦을 수 있으니 즉시 지급하지 말고 안내 후 재조회한다.

## 점검 명령

```bash
curl -s https://egg-api.j-curve.co.kr/                                  # egg-api ok
curl -s -o /dev/null -w '%{http_code}\n' 'https://egg-api.j-curve.co.kr/ads/ssv?user_id=x&transaction_id=t'   # 403
curl -s -H "X-Egg-Key: $KEY" 'https://egg-api.j-curve.co.kr/ads/pending?user_id=demo'

# 최근 콜백 로그
sqlite3 /www/jcurve/egg-api/var/egg.sqlite 'SELECT datetime(at,"unixepoch","+9 hours"),ok,reason,user_id FROM ssv_log ORDER BY id DESC LIMIT 10;'
tail -5 /www/jcurve/egg-api/var/ssv.log
```

## 남은 일

- **실제 회원 식별자 연결** — 지금 앱은 목업 인증이라 `user_id` 가 데모 값이다. 실제 로그인이 붙으면
  앱이 `setServerSideVerificationOptions({userId})` 에 그 값을 넣고, 서버는 회원 테이블과 대조해야 한다.
- **앱 키를 세션 토큰으로 교체** — `X-Egg-Key` 는 앱을 뜯으면 나온다. 회원 인증이 생기면 그걸로 바꾼다.
- **사료 적립을 서버 상태로** — 현재 사료·게이지는 앱 로컬 상태다. 서버에 농장 상태가 생기면
  `claim` 이 직접 게이지를 올리도록 옮긴다.
