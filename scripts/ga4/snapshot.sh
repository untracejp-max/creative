#!/usr/bin/env bash
# 定期取得用: 主要レポートを一式まとめて保存する。
# 使い方: bash scripts/ga4/snapshot.sh [出力先ディレクトリ] [日数]
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-ga4-reports/$(date -u +%Y-%m-%d)}"
DAYS="${2:-28}"
python3 -c "import google.auth, requests" 2>/dev/null || pip install -q -r "$HERE/requirements.txt"
mkdir -p "$OUT"
R="python3 $HERE/ga4_report.py report --days $DAYS"
$R --preset overview --compare --format csv --out "$OUT/overview.csv"
$R --preset monthly --days 400 --format csv --out "$OUT/monthly.csv"
$R --preset sources --compare --format csv --out "$OUT/sources.csv"
$R --preset landing --compare --limit 200 --format csv --out "$OUT/landing.csv"
$R --preset pages --compare --limit 300 --format csv --out "$OUT/pages.csv"
$R --preset pages --compare --path-contains /talk/ --format csv --out "$OUT/pages_talk.csv"
$R --preset devices --compare --format csv --out "$OUT/devices.csv"
echo "完了: $OUT"
