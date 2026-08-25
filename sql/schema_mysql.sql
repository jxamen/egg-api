-- 꼬꼬농장 egg-api — MySQL 스키마
-- phpMyAdmin > SQL 탭에 붙여넣어 실행한다.
--
-- SQLite 로 시작했지만 회원·포인트가 붙으면서 옮긴다. 행 수가 아니라 **쓰기가 한 번에 하나**인
-- 성질 때문이다 — 앱 트래픽과 어드민 조회가 겹치면 거기서 막히고, 테이블을 월별로 쪼개도
-- 같은 파일·같은 락이라 풀리지 않는다. 출시 전이라 옮길 실사용 데이터가 없는 지금이 가장 싸다.
--
-- 실행 후 서버 config.php 에 접속 정보를 넣으면 그 순간부터 MySQL 을 쓴다.
-- 상품 2,466종은 옮길 필요 없이 /ops?do=sync 로 다시 받으면 된다.

-- ─────────────────────────────────────────────────────────────
-- 0) DB·계정  ※ 공유 호스팅은 CREATE USER 권한이 없을 수 있다.
--    권한 오류가 나면 이 블록은 건너뛰고 호스팅 패널에서 DB·계정을 만든 뒤
--    1) 부터 실행할 것.
-- ─────────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS `kkokkofarm`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'kkokkofarm'@'localhost'
  IDENTIFIED BY 'j884Yhd3Hg7AgUShbXYBZQj6';          -- 바꿔도 된다. 바꾸면 알려줄 것

GRANT ALL PRIVILEGES ON `kkokkofarm`.* TO 'kkokkofarm'@'localhost';
FLUSH PRIVILEGES;

USE `kkokkofarm`;

-- ─────────────────────────────────────────────────────────────
-- 1) 광고 보상 · 상품 · 교환 · 감사 로그
-- ─────────────────────────────────────────────────────────────

-- 광고 보상 원장. transaction_id 가 기본키라 콜백이 다시 와도 한 번만 쌓인다.
CREATE TABLE IF NOT EXISTS `ad_reward` (
  `transaction_id` VARCHAR(191) NOT NULL,
  `user_id`        VARCHAR(64)  NOT NULL,
  `ad_unit`        VARCHAR(191) DEFAULT NULL,
  `reward_item`    VARCHAR(64)  DEFAULT NULL,
  `reward_amount`  INT          DEFAULT NULL,
  `custom_data`    TEXT,
  `created_at`     BIGINT NOT NULL,
  `claimed_at`     BIGINT DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `ix_reward_user` (`user_id`, `claimed_at`),
  KEY `ix_reward_day`  (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기프티쇼 상품 캐시. 전체 목록 API 하나뿐이라 받아 두고 검색·전시는 여기서 한다.
-- discount_price 는 우리 매입가라 앱 응답에 넣지 않는다.
CREATE TABLE IF NOT EXISTS `gs_goods` (
  `goods_code`     VARCHAR(64) NOT NULL,
  `goods_name`     VARCHAR(255) NOT NULL,
  `brand_name`     VARCHAR(255) DEFAULT NULL,
  `brand_code`     VARCHAR(64)  DEFAULT NULL,
  `affiliate`      VARCHAR(255) DEFAULT NULL,
  `sale_price`     INT DEFAULT NULL,
  `discount_price` INT DEFAULT NULL,
  `img_s`          VARCHAR(500) DEFAULT NULL,
  `img_b`          VARCHAR(500) DEFAULT NULL,
  `valid_days`     INT DEFAULT NULL,
  `type_dtl`       VARCHAR(255) DEFAULT NULL,
  `category1`      INT DEFAULT NULL,
  `state_cd`       VARCHAR(16) DEFAULT NULL,
  `synced_at`      BIGINT NOT NULL,
  PRIMARY KEY (`goods_code`),
  KEY `ix_goods_state` (`state_cd`, `sale_price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 교환 원장. tr_id 가 기본키라 같은 주문으로 두 번 발송되지 않는다.
CREATE TABLE IF NOT EXISTS `gs_order` (
  `tr_id`       VARCHAR(32) NOT NULL,
  `user_id`     VARCHAR(64) NOT NULL,
  `goods_code`  VARCHAR(64) NOT NULL,
  `point_price` INT NOT NULL,
  `status`      VARCHAR(16) NOT NULL,      -- pending / sent / failed / canceled
  `order_no`    VARCHAR(64)  DEFAULT NULL,
  `pin_no`      VARCHAR(64)  DEFAULT NULL,
  `coupon_img`  VARCHAR(500) DEFAULT NULL,
  `valid_end`   VARCHAR(16)  DEFAULT NULL,
  `err_code`    VARCHAR(32)  DEFAULT NULL,
  `err_msg`     VARCHAR(500) DEFAULT NULL,
  `created_at`  BIGINT NOT NULL,
  `sent_at`     BIGINT DEFAULT NULL,
  `canceled_at` BIGINT DEFAULT NULL,
  PRIMARY KEY (`tr_id`),
  KEY `ix_order_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 광고 콜백 감사 로그. 거절된 것도 남긴다 — 무한정 쌓이므로 보존기간을 두고 정리한다.
CREATE TABLE IF NOT EXISTS `ssv_log` (
  `id`      BIGINT NOT NULL AUTO_INCREMENT,
  `at`      BIGINT NOT NULL,
  `ok`      TINYINT NOT NULL,
  `reason`  VARCHAR(191) DEFAULT NULL,
  `user_id` VARCHAR(64)  DEFAULT NULL,
  `qs`      TEXT,
  PRIMARY KEY (`id`),
  KEY `ix_ssv_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 2) 회원 · 세션 (SNS 로그인)
-- ─────────────────────────────────────────────────────────────

-- 식별자는 이메일이 아니라 (provider, social_id) 다.
-- 카카오 이메일은 선택 동의라 안 올 수 있고, 애플은 가린 주소가 온다.
CREATE TABLE IF NOT EXISTS `member` (
  `id`            VARCHAR(64) NOT NULL,
  `provider`      VARCHAR(16) NOT NULL,        -- kakao / naver / google / apple
  `social_id`     VARCHAR(191) NOT NULL,
  `email`         VARCHAR(191) DEFAULT NULL,
  `name`          VARCHAR(100) DEFAULT NULL,   -- 농장 이름
  `avatar_url`    VARCHAR(500) DEFAULT NULL,
  `phone`         VARCHAR(32)  DEFAULT NULL,   -- 본인인증으로 확인된 번호(쿠폰 수신처)
  `marketing`     TINYINT NOT NULL DEFAULT 0,
  `agreed_at`     BIGINT DEFAULT NULL,         -- NULL 이면 가입 미완료(약관 동의 전)
  `points`        BIGINT NOT NULL DEFAULT 0,   -- 잔액 캐시. 진실은 point_ledger 다
  `created_at`    BIGINT NOT NULL,
  `last_login_at` BIGINT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_member_social` (`provider`, `social_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_session` (
  `token`      CHAR(64) NOT NULL,
  `user_id`    VARCHAR(64) NOT NULL,
  `issued_at`  BIGINT NOT NULL,
  `expires_at` BIGINT NOT NULL,
  PRIMARY KEY (`token`),
  KEY `ix_session_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAuth 인가 요청 상태(CSRF 방지) — 콜백이 이 값을 확인해야 우리 요청으로 인정한다.
CREATE TABLE IF NOT EXISTS `oauth_state` (
  `state`      CHAR(32) NOT NULL,
  `provider`   VARCHAR(16) NOT NULL,
  `created_at` BIGINT NOT NULL,
  `used_at`    BIGINT DEFAULT NULL,
  PRIMARY KEY (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 로그인 끝나고 앱으로 돌려보내는 1회용 티켓(2분). 세션 토큰을 URL 에 싣지 않으려는 것이다.
CREATE TABLE IF NOT EXISTS `auth_ticket` (
  `ticket`     CHAR(48) NOT NULL,
  `user_id`    VARCHAR(64) NOT NULL,
  `created_at` BIGINT NOT NULL,
  `used_at`    BIGINT DEFAULT NULL,
  PRIMARY KEY (`ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 3) 포인트 원장
--    잔액은 member.points 에 캐시하고 여기엔 이력을 쌓는다(조회 O(1)).
--    ref 는 같은 근거로 두 번 적립되는 것을 막는다 — NULL 은 UNIQUE 에 걸리지 않는다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `point_ledger` (
  `id`         BIGINT NOT NULL AUTO_INCREMENT,
  `user_id`    VARCHAR(64) NOT NULL,
  `delta`      INT NOT NULL,                 -- +적립 / -사용
  `balance`    BIGINT NOT NULL,              -- 이 거래 직후 잔액(대사·복구용)
  `kind`       VARCHAR(16) NOT NULL,         -- ad/attend/mission/invite/exchange/npay/adjust
  `title`      VARCHAR(191) NOT NULL,        -- 이용내역에 그대로 보여 줄 문구
  `ref`        VARCHAR(191) DEFAULT NULL,    -- transaction_id · tr_id · mission_id …
  `created_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ledger_user` (`user_id`, `id`),
  KEY `ix_ledger_day` (`created_at`),
  UNIQUE KEY `ux_ledger_ref` (`kind`, `ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 4) 운영 콘텐츠 — 지금은 앱 코드에 하드코딩돼 있어 문구를 고칠 때마다 배포가 필요하다.
--    어드민에서 고치고 앱이 받아 가게 하려면 여기로 옮겨야 한다.
-- ─────────────────────────────────────────────────────────────

-- 공지사항 (앱 G-04/G-05). 앱 주석에도 "공지는 어드민이 발행한다" 고 적혀 있다.
CREATE TABLE IF NOT EXISTS `notice` (
  `id`           BIGINT NOT NULL AUTO_INCREMENT,
  `category`     VARCHAR(16) NOT NULL DEFAULT 'svc',   -- svc(서비스) / evt(이벤트) — 앱 공지 탭
  `title`        VARCHAR(191) NOT NULL,
  `body`         MEDIUMTEXT NOT NULL,
  `pinned`       TINYINT NOT NULL DEFAULT 0,
  `published`    TINYINT NOT NULL DEFAULT 1,
  `published_at` BIGINT DEFAULT NULL,
  `created_at`   BIGINT NOT NULL,
  `updated_at`   BIGINT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notice_pub` (`published`, `published_at`),
  KEY `ix_notice_cat` (`category`, `published`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 자주 묻는 질문 (앱 G-06). category 는 앱의 탭 값(earn/exch/account/etc)을 그대로 쓴다.
CREATE TABLE IF NOT EXISTS `faq` (
  `id`         BIGINT NOT NULL AUTO_INCREMENT,
  `category`   VARCHAR(32) NOT NULL,
  `question`   VARCHAR(500) NOT NULL,
  `answer`     MEDIUMTEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `published`  TINYINT NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_faq_cat` (`category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 약관·개인정보처리방침 (앱 G-08/G-09 + 웹 /legal/*).
-- 버전을 남기는 이유: 내용이 바뀌면 재동의를 받아야 하고, 회원이 어느 버전에 동의했는지
-- 증빙할 수 있어야 한다. 시행일은 개정 시 7일(불리한 변경은 30일) 전에 공지한다.
CREATE TABLE IF NOT EXISTS `legal_doc` (
  `id`           BIGINT NOT NULL AUTO_INCREMENT,
  `kind`         VARCHAR(16) NOT NULL,       -- terms / privacy
  `version`      VARCHAR(16) NOT NULL,       -- 1.0
  `effective_at` BIGINT NOT NULL,            -- 시행일
  `body`         MEDIUMTEXT NOT NULL,        -- HTML
  `published`    TINYINT NOT NULL DEFAULT 0,
  `created_at`   BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_legal` (`kind`, `version`),
  KEY `ix_legal_pub` (`kind`, `published`, `effective_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1:1 문의 (앱 G-07). 지금은 앱이 접수 토스트만 띄우고 아무 데도 보내지 않는다.
CREATE TABLE IF NOT EXISTS `inquiry` (
  `id`          BIGINT NOT NULL AUTO_INCREMENT,
  `user_id`     VARCHAR(64) NOT NULL,
  `type`        VARCHAR(32) DEFAULT NULL,
  `title`       VARCHAR(191) NOT NULL,
  `body`        MEDIUMTEXT NOT NULL,
  `status`      VARCHAR(16) NOT NULL DEFAULT 'open',   -- open / answered / closed
  `answer`      MEDIUMTEXT,
  `answered_at` BIGINT DEFAULT NULL,
  `created_at`  BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_inquiry_user` (`user_id`, `created_at`),
  KEY `ix_inquiry_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 5) KICA(KIAP) 간편인증 — 다른 작업자가 만든 모듈이라 정의만 옮겨 둔다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `kiap_session` (
  `sid`            VARCHAR(64) NOT NULL,
  `uid`            VARCHAR(64) NOT NULL,
  `ret`            VARCHAR(500) NOT NULL,
  `status`         VARCHAR(16) NOT NULL,     -- pending / verified / consumed / failed
  `provider`       VARCHAR(32)  DEFAULT NULL,
  `name`           VARCHAR(100) DEFAULT NULL,
  `phone`          VARCHAR(32)  DEFAULT NULL,
  `ci_hash`        VARCHAR(191) DEFAULT NULL,
  `fail_reason`    VARCHAR(191) DEFAULT NULL,
  `result_token`   VARCHAR(191) DEFAULT NULL,
  `result_expires` BIGINT DEFAULT NULL,
  `created_at`     BIGINT NOT NULL,
  `verified_at`    BIGINT DEFAULT NULL,
  PRIMARY KEY (`sid`),
  KEY `ix_kiap_uid` (`uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CI 원장 — 1 CI = 1 계정(반복 수령 차단). 평문 CI 는 저장하지 않는다.
CREATE TABLE IF NOT EXISTS `kiap_ci` (
  `ci_hash`     VARCHAR(191) NOT NULL,
  `uid`         VARCHAR(64) NOT NULL,
  `verified_at` BIGINT NOT NULL,
  PRIMARY KEY (`ci_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
