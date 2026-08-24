#!/usr/bin/env bash
#
# egg-api 재배포 — 서버에서 jcurve 유저로 실행 (root 금지: 파일 소유권 유지).
#   서버:  cd /www/jcurve/egg-api && bash deploy.sh
#   전제:  로컬에서 git push 완료. master 브랜치 배포.
#
# rankfree(/www/jcurve/rankfree/deploy.sh)와 같은 원칙이다 — git 이 유일한 진실이고
# FTP 로 서버 파일을 직접 고치지 않는다(다음 배포에 덮어써진다).
# 다른 점: egg-api 는 순수 PHP 라 composer·npm·마이그레이션·캐시 재생성이 없다.
set -euo pipefail
cd /www/jcurve/egg-api

echo "▶ 코드 동기화(origin/master로 강제 일치)"
git fetch origin master
git reset --hard origin/master

echo "▶ 런타임 디렉터리"
# SQLite DB·로그가 사는 곳. git 에 없으므로(gitignore) 배포마다 보장해 준다.
mkdir -p var
chmod 700 var

echo "▶ 설정 확인"
# config.php 는 시크릿이라 git 에 없다. 최초 1회 수동 업로드(600, jcurve 소유).
if [ ! -f config.php ]; then
  echo "  ✗ config.php 가 없습니다 — 앱 키·기프티쇼 인증정보를 넣어 만들어야 합니다." >&2
  exit 1
fi

echo "▶ 스키마 보장 + 헬스 체크"
# bootstrap.php 가 CREATE TABLE IF NOT EXISTS 로 스키마를 맞춘다. PHP 8.3 으로 실행해야 한다
# (서버 기본 CLI 는 7.2 라 8.1+ 문법에서 죽는다).
php83 -r 'require "src/bootstrap.php"; egg_db(); echo "  db ok\n";'

echo "✅ 배포 완료: $(git rev-parse --short HEAD)"
