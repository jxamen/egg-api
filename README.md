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

---

# 기프티쇼 비즈 연동 (상점 기프티콘 · 네이버페이 포인트 전환)

상점의 기프티콘 교환과 지갑의 네이버페이 포인트 전환은 **같은 경로**를 쓴다.
둘의 차이는 `goods_code` 뿐이다. 네이버페이 포인트 쿠폰은 5,000원권부터 있다.

```
src/giftishow.php        API 클라이언트(인증 토큰·호출·에러)
bin/gs.php               점검·상품 동기화 CLI
public/shop/goods.php    앱: 상점에 뿌릴 목록 (로컬 DB에서 읽는다)
public/shop/order.php    앱: 발주 → 쿠폰 수령
```

## 인증

`config.php` 의 `giftishow` 항목에 넣는다.

| 키 | 어디서 받나 |
|---|---|
| `auth_code` | 오픈API > 서비스관리의 **인증 키** |
| `enc_key` | 담당자에게 **별도로** 받는 32바이트 암호화 키 |
| `user_id` | 기프티쇼 비즈 회원 ID |
| `dev` | 개발 키면 `true`, 상용 키를 받으면 `false` |

`custom_auth_token` 은 `auth_code` 를 `enc_key` 로 **AES-256-ECB/PKCS5 암호화 후 base64** 한 값이다.
매 호출마다 `gs_token()` 이 만든다.

**규격서가 스스로 엇갈리는 곳이 둘 있어 양쪽을 다 보낸다.**
"시스템 연동 방법"은 인증값을 HTTP 헤더에 넣으라 하고 각 API 표는 파라미터라고 하며,
테스트 플래그도 `dev_flag`(헤더 설명)와 `dev_yn`(API 표)으로 이름이 다르다.
헤더와 파라미터 양쪽에 같은 값을 실어 어느 쪽을 읽든 통과하게 했다.

## 상품 목록은 받아 두고 쓴다

규격서 FAQ가 못박아 둔 사항이다 — 전체 상품 API 하나뿐이고 카테고리 검색이 없으니,
**전체를 받아 DB에 저장해 두고 검색·전시는 자체 DB에서** 해야 한다.
상품 정보는 매일 새벽에 갱신되므로 새벽 2~4시에 배치를 돌린다.

```bash
# CLI 는 반드시 PHP 8.3 바이너리로 — 서버 기본 CLI 는 7.2 라 8.1+ 문법에서 죽는다
PHP83=$(ls /usr/local/php*/bin/php 2>/dev/null | tail -1)   # 실제 경로 확인 후 고정할 것

$PHP83 bin/gs.php bizmoney          # 비즈머니 잔액 — 인증키가 맞는지 보는 가장 싼 호출
$PHP83 bin/gs.php sync              # 전체 상품 → gs_goods
$PHP83 bin/gs.php list 20           # 저장된 상품 훑어보기
$PHP83 bin/gs.php detail G00000280811
$PHP83 bin/gs.php coupon egg_20260824_ab12cd34ef56
$PHP83 bin/gs.php cancel egg_20260824_ab12cd34ef56
```

크론:

```
0 3 * * * cd /www/jcurve/egg-api && /usr/local/php83/bin/php bin/gs.php sync >> var/gs_sync.log 2>&1
```

## 앱 엔드포인트

헤더 `X-Egg-Key` 필요(광고 엔드포인트와 같은 키).

```
GET  /shop/goods?max=6000&limit=40&q=아메리카노
     → {"ok":true,"count":N,"syncedAt":…,"items":[{goodsCode,name,brand,price,imgS,validDays,…}]}

POST /shop/order   user_id, goods_code, phone_no
     → {"ok":true,"trId":…,"pinNo":…,"couponImg":…,"validEnd":"20260923","notice":{…}}
     → 실패 시 {"ok":false,"refund":true,"error":…}  ← 호출한 쪽이 포인트를 되돌린다
```

**발주 전에 포인트를 먼저 차감해야 한다**(규격서 8장). `order.php` 는 차감이 끝났다고 보고 발송만 한다.

## 실패 처리 — 여기가 이 연동의 핵심이다

| 상황 | 처리 |
|---|---|
| 발송 타임아웃(15초) | 상대 쪽에서는 **발행됐을 가능성이 높다.** 같은 `tr_id` 로 쿠폰취소(0202)를 보내고 포인트 환급 |
| 비즈머니 부족(`E0010`) | 발주 실패 → 포인트 환급, 상품 판매 중지 |
| 응답은 왔는데 핀이 없음 | 쿠폰취소로는 안 되고 **발송실패취소(0205)** 로 되돌린다 |
| 같은 주문 재요청 | `tr_id` 가 `gs_order` 기본키라 두 번 발송되지 않는다 |

`TR_ID` 는 25자 이하 고유값이어야 한다 — `egg_YYYYMMDD_<12hex>` 로 정확히 25자다.

## 지켜야 하는 판매 정책

기프티쇼 비즈는 **B2B 거래**라 일반 쇼핑몰과 규칙이 다르다.

- **리워드 포인트로 교환하는 형태는 허용**된다. 현금(카드·계좌이체·PG) 판매는 **금지**(재판매 금지).
- 쿠폰 **유효기간 30일**, 수신 고객의 **기간연장·환불 불가**.
- 구매사 할인 6%(상품권 1%) — `discount_price` 가 우리가 내는 값, `sale_price` 가 액면가.
  **`discount_price` 는 앱 응답에 넣지 않는다**(마진 노출).
- 핀번호·바코드이미지로 직접 전달할 때는 판매 화면에 아래를 **반드시** 함께 띄워야 한다.
  `order.php` 가 `notice` 로 내려준다.
  - 상품공급자 : 주식회사 케이티알파
  - 발행사업자 : (주)제이커브인터렉티브
  - 상품의 기본 주의사항과 유효기간
- 5만원 이상 일반 상품(상품권 제외)은 인지세 문구 표시:
  "인지세 삼성 세무서장 후납승인 2019년 100007555호"
- MMS 발송(`gubun=N`)을 쓰면 수신자 번호가 넘어가므로 개인정보처리방침에
  **수탁자 주식회사 케이티알파** 를 명시해야 한다. 핀·이미지 수신만 쓰면 해당 없다.
- 미사용 쿠폰을 특정 시점에 일괄 취소하면 부정사용으로 이용이 중단될 수 있다.

## 아직 안 된 것

- **인증키가 없어 실호출을 못 해봤다.** 키를 받으면 `bizmoney` → `sync` 순으로 확인한다.
- 개발환경은 **테스트 상품 2종만** 호출되고 비즈머니가 차감되지 않는다.
- 포인트 차감·환급이 아직 서버에 없다. 지금은 `order.php` 를 부르는 쪽 책임이다.
- 상점·지갑 화면이 아직 고정 목록이다. `/shop/goods` 를 붙이는 작업이 남았다.
