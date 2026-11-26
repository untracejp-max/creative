#!/usr/bin/env python3
"""GA4 Data API からレポートを取得するスクリプト。

認証情報はリポジトリに置かず、環境変数から読みます。
  GA4_PROPERTY_ID          GA4 のプロパティID(数字のみ。例: 123456789)
  GA4_CREDENTIALS_JSON     サービスアカウント鍵(JSONそのもの、または base64)
  (または) GOOGLE_APPLICATION_CREDENTIALS  鍵ファイルのパス

使い方の例:
  python3 scripts/ga4/ga4_report.py check
  python3 scripts/ga4/ga4_report.py report --preset overview --days 28 --compare
  python3 scripts/ga4/ga4_report.py report --preset pages --days 90 --path-contains /talk/
  python3 scripts/ga4/ga4_report.py report --dimensions date --metrics sessions,activeUsers \
      --start 2026-08-01 --end 2026-10-31 --format csv --out data.csv
"""
import argparse
import base64
import csv
import datetime as dt
import io
import json
import os
import sys

try:
    from google.oauth2 import service_account
    from google.auth.transport.requests import AuthorizedSession
    from google.auth.exceptions import RefreshError
except ImportError:
    sys.exit("google-auth と requests が必要です: pip install -r scripts/ga4/requirements.txt")

SCOPES = ["https://www.googleapis.com/auth/analytics.readonly"]
API = "https://analyticsdata.googleapis.com/v1beta"

PRESETS = {
    "overview": (["date"], ["sessions", "activeUsers", "newUsers", "screenPageViews",
                            "engagementRate", "averageSessionDuration"]),
    "pages": (["pagePath", "pageTitle"], ["screenPageViews", "sessions", "activeUsers",
                                          "averageSessionDuration", "engagementRate"]),
    "landing": (["landingPage"], ["sessions", "activeUsers", "engagementRate",
                                  "bounceRate", "averageSessionDuration"]),
    "sources": (["sessionDefaultChannelGroup", "sessionSourceMedium"],
                ["sessions", "activeUsers", "engagementRate"]),
    "devices": (["deviceCategory"], ["sessions", "activeUsers", "engagementRate"]),
    "monthly": (["yearMonth"], ["sessions", "activeUsers", "newUsers", "screenPageViews"]),
    "weekly": (["yearWeek"], ["sessions", "activeUsers", "newUsers", "screenPageViews"]),
    "events": (["eventName"], ["eventCount", "totalUsers"]),
}


def load_credentials():
    raw = os.environ.get("GA4_CREDENTIALS_JSON", "").strip()
    if raw:
        if not raw.startswith("{"):
            try:
                raw = base64.b64decode(raw).decode("utf-8")
            except Exception:
                sys.exit("GA4_CREDENTIALS_JSON が JSON でも base64 でもありません")
        info = json.loads(raw)
        return service_account.Credentials.from_service_account_info(info, scopes=SCOPES)
    path = os.environ.get("GOOGLE_APPLICATION_CREDENTIALS")
    if path and os.path.exists(path):
        return service_account.Credentials.from_service_account_file(path, scopes=SCOPES)
    sys.exit("認証情報がありません。GA4_CREDENTIALS_JSON か GOOGLE_APPLICATION_CREDENTIALS を設定してください")


def property_id(args):
    pid = (getattr(args, "property", None) or os.environ.get("GA4_PROPERTY_ID", "")).strip()
    pid = pid.replace("properties/", "")
    if not pid.isdigit():
        sys.exit("GA4_PROPERTY_ID(数字のプロパティID)を設定するか --property で指定してください")
    return pid


def session():
    return AuthorizedSession(load_credentials())


def call(sess, method, url, body=None):
    try:
        r = sess.request(method, url, json=body, timeout=60)
    except RefreshError as e:
        sys.exit(f"認証に失敗しました(鍵が無効・削除済みの可能性): {e}")
    if r.status_code >= 400:
        try:
            msg = r.json().get("error", {}).get("message", r.text)
        except Exception:
            msg = r.text
        sys.exit(f"GA4 API エラー ({r.status_code}): {msg}")
    return r.json()


def date_ranges(args):
    if args.start:
        end = args.end or "yesterday"
        ranges = [{"startDate": args.start, "endDate": end, "name": "current"}]
        if args.compare:
            s = dt.date.fromisoformat(args.start)
            e = dt.date.today() - dt.timedelta(days=1) if end == "yesterday" else dt.date.fromisoformat(end)
            span = (e - s).days + 1
            ps, pe = s - dt.timedelta(days=span), s - dt.timedelta(days=1)
            ranges.append({"startDate": ps.isoformat(), "endDate": pe.isoformat(), "name": "previous"})
        return ranges
    d = args.days
    ranges = [{"startDate": f"{d}daysAgo", "endDate": "yesterday", "name": "current"}]
    if args.compare:
        ranges.append({"startDate": f"{2 * d}daysAgo", "endDate": f"{d + 1}daysAgo", "name": "previous"})
    return ranges


def build_filter(args, dims):
    exprs = []
    if args.path_contains:
        field = "landingPage" if "landingPage" in dims else "pagePath"
        for p in args.path_contains:
            exprs.append({"filter": {"fieldName": field, "stringFilter": {"matchType": "CONTAINS", "value": p}}})
    if args.filter:
        for f in args.filter:  # 形式: field=value (完全一致) / field~value (部分一致)
            if "~" in f:
                k, v = f.split("~", 1)
                exprs.append({"filter": {"fieldName": k, "stringFilter": {"matchType": "CONTAINS", "value": v}}})
            elif "=" in f:
                k, v = f.split("=", 1)
                exprs.append({"filter": {"fieldName": k, "stringFilter": {"matchType": "EXACT", "value": v}}})
    if not exprs:
        return None
    if len(exprs) == 1:
        return exprs[0]
    # path_contains 同士は OR、それ以外は AND にしたいが、単純化して:
    # 複数の --path-contains は OR、--filter は AND で結合
    path_exprs = exprs[: len(args.path_contains or [])]
    other = exprs[len(args.path_contains or []):]
    parts = []
    if path_exprs:
        parts.append(path_exprs[0] if len(path_exprs) == 1 else {"orGroup": {"expressions": path_exprs}})
    parts.extend(other)
    return parts[0] if len(parts) == 1 else {"andGroup": {"expressions": parts}}


def run_report(args):
    pid = property_id(args)
    if args.preset:
        dims, mets = PRESETS[args.preset]
    else:
        dims, mets = [], []
    if args.dimensions:
        dims = [d for d in args.dimensions.split(",") if d]
    if args.metrics:
        mets = [m for m in args.metrics.split(",") if m]
    if not mets:
        sys.exit("--preset か --metrics を指定してください")
    ranges = date_ranges(args)
    body = {
        "dateRanges": ranges,
        "dimensions": [{"name": d} for d in dims],
        "metrics": [{"name": m} for m in mets],
        "limit": args.limit,
        "keepEmptyRows": False,
    }
    f = build_filter(args, dims)
    if f:
        body["dimensionFilter"] = f
    order_by = args.order_by or (dims[0] if dims and dims[0] in ("date", "yearMonth", "yearWeek") else mets[0])
    if order_by in dims:
        body["orderBys"] = [{"dimension": {"dimensionName": order_by}, "desc": False}]
    else:
        body["orderBys"] = [{"metric": {"metricName": order_by}, "desc": True}]
    data = call(session(), "POST", f"{API}/properties/{pid}:runReport", body)

    headers = [h["name"] for h in data.get("dimensionHeaders", [])]
    mheaders = [h["name"] for h in data.get("metricHeaders", [])]
    rows = []
    for r in data.get("rows", []):
        rows.append([v["value"] for v in r.get("dimensionValues", [])] +
                    [v["value"] for v in r.get("metricValues", [])])
    cols = headers + mheaders
    meta = {
        "property": pid,
        "dateRanges": ranges,
        "rowCount": data.get("rowCount", 0),
        "fetchedAt": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
    }
    output(cols, rows, meta, args)


def fmt_num(v):
    try:
        x = float(v)
    except ValueError:
        return v
    if x.is_integer():
        return f"{int(x):,}"
    return f"{x:,.3f}" if abs(x) < 10 else f"{x:,.1f}"


def output(cols, rows, meta, args):
    buf = io.StringIO()
    if args.format == "json":
        json.dump({"meta": meta, "columns": cols, "rows": rows}, buf, ensure_ascii=False, indent=2)
        buf.write("\n")
    elif args.format == "csv":
        w = csv.writer(buf)
        w.writerow(cols)
        w.writerows(rows)
    else:
        rng = ", ".join(f"{r['name']}: {r['startDate']}〜{r['endDate']}" for r in meta["dateRanges"])
        buf.write(f"<!-- GA4 property {meta['property']} / {rng} / rows {meta['rowCount']} / {meta['fetchedAt']} -->\n")
        buf.write("| " + " | ".join(cols) + " |\n")
        buf.write("|" + "---|" * len(cols) + "\n")
        for r in rows:
            buf.write("| " + " | ".join(fmt_num(c) for c in r) + " |\n")
    text = buf.getvalue()
    if args.out:
        os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
        with open(args.out, "w", encoding="utf-8", newline="") as fh:
            fh.write(text)
        print(f"保存しました: {args.out} ({len(rows)} 行)", file=sys.stderr)
    else:
        sys.stdout.write(text)


def check(args):
    pid = property_id(args)
    creds = load_credentials()
    sess = AuthorizedSession(creds)
    md = call(sess, "GET", f"{API}/properties/{pid}/metadata")
    print(f"OK: サービスアカウント {creds.service_account_email} でプロパティ {pid} に接続できました")
    print(f"  使えるディメンション {len(md.get('dimensions', []))} 個 / 指標 {len(md.get('metrics', []))} 個")
    rep = call(sess, "POST", f"{API}/properties/{pid}:runReport", {
        "dateRanges": [{"startDate": "7daysAgo", "endDate": "yesterday"}],
        "metrics": [{"name": "sessions"}, {"name": "activeUsers"}]})
    vals = rep.get("rows", [{}])[0].get("metricValues", [{"value": "0"}, {"value": "0"}])
    print(f"  直近7日: セッション {vals[0]['value']} / アクティブユーザー {vals[1]['value']}")


def main():
    p = argparse.ArgumentParser(description="GA4 Data API レポート取得")
    p.add_argument("--property", help="プロパティID(未指定なら GA4_PROPERTY_ID)")
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("check", help="接続確認")
    r = sub.add_parser("report", help="レポート取得")
    r.add_argument("--preset", choices=sorted(PRESETS))
    r.add_argument("--dimensions", help="カンマ区切り (例: date,pagePath)")
    r.add_argument("--metrics", help="カンマ区切り (例: sessions,activeUsers)")
    r.add_argument("--days", type=int, default=28, help="直近N日(昨日まで)。既定28")
    r.add_argument("--start", help="開始日 YYYY-MM-DD(指定時は --days より優先)")
    r.add_argument("--end", help="終了日 YYYY-MM-DD(既定: 昨日)")
    r.add_argument("--compare", action="store_true", help="直前の同じ長さの期間と比較")
    r.add_argument("--path-contains", action="append", help="ページパスの部分一致(複数可、OR)")
    r.add_argument("--filter", action="append", help="field=value(完全一致) / field~value(部分一致)。AND")
    r.add_argument("--order-by", help="並べ替えに使う列名")
    r.add_argument("--limit", type=int, default=1000)
    r.add_argument("--format", choices=["md", "csv", "json"], default="md")
    r.add_argument("--out", help="出力ファイル(未指定なら標準出力)")
    args = p.parse_args()
    if args.cmd == "check":
        check(args)
    else:
        run_report(args)


if __name__ == "__main__":
    main()
