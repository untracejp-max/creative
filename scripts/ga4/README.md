# GA4 データ自動取得

「新マレーシア留学支援機構」の GA4 データを、Claude(クラウドのセッション)が
GA4 Data API から直接読めるようにする仕組みです。Supermetrics などの外部サービスは使いません。

- 認証: Google Cloud の **サービスアカウント**(GA4 には「閲覧者」権限のみ)
- 鍵の置き場所: Claude のクラウド環境の **環境変数**(リポジトリには絶対に置かない)
- 取得: `scripts/ga4/ga4_report.py`(Python、依存は google-auth と requests だけ)
- 定期取得: `scripts/ga4/snapshot.sh` を Claude の「スケジュールタスク」で毎週実行

---

## 1. 初回セットアップ(人が行う作業・15分ほど)

### ① Google Cloud でサービスアカウントと鍵を作る
1. https://console.cloud.google.com/ を開き、上部のプロジェクト選択で **新しいプロジェクト** を作成
   (名前は例: `malaysia-ga4`)。既存のプロジェクトがあればそれでも可。
2. https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com を開き
   **Google Analytics Data API** を「有効にする」。
3. https://console.cloud.google.com/iam-admin/serviceaccounts を開き **サービスアカウントを作成**
   - 名前: `claude-ga4-reader` など。ロールの付与は不要(スキップで OK)。
4. 作成したサービスアカウントを開き **鍵 → 鍵を追加 → 新しい鍵を作成 → JSON**。
   JSON ファイルがダウンロードされます。**このファイルはチャットに貼らず、リポジトリにも入れないでください。**
5. 一覧に表示されるサービスアカウントの **メールアドレス**
   (`claude-ga4-reader@xxxx.iam.gserviceaccount.com`)をコピーしておく。

> 組織ポリシーで「サービスアカウントキーの作成が無効」と出た場合は、個人の Google アカウントで作った
> プロジェクトなら IAM の組織ポリシー `iam.disableServiceAccountKeyCreation` を解除するか、Claude に相談してください。

### ② GA4 にサービスアカウントを追加する
1. GA4 → 管理(左下の歯車)→ プロパティ「新マレーシア留学支援機構」→ **プロパティのアクセス管理**
2. 右上「+」→ ユーザーを追加 → ①-5 のメールアドレスを入力、役割は **閲覧者**、「新しいユーザーにメールで通知」はオフ → 追加
3. 同じ「管理」画面の **プロパティの詳細** で **プロパティID**(9〜10桁の数字)を控える。

### ③ 鍵を1行の文字列にする(Mac のターミナル)
```bash
base64 -i ~/Downloads/<ダウンロードしたファイル名>.json | pbcopy
```
これでクリップボードに1行の文字列が入ります(Windows は PowerShell で
`[Convert]::ToBase64String([IO.File]::ReadAllBytes("C:\path\key.json")) | Set-Clipboard`)。

### ④ Claude のクラウド環境に登録する
1. Claude のプロジェクト「TG」→ **プロジェクト設定 → Environment(クラウド環境)**
2. 「Cloud environment」メニュー → **Add cloud environment**(名前例: `creative`、個人用)
3. **Environment variables** に次の2行を入れて保存
   ```
   GA4_PROPERTY_ID=<②-3 のプロパティID>
   GA4_CREDENTIALS_JSON=<③ でコピーした文字列を貼り付け>
   ```
4. 保存すると、以降に始まる Claude のセッションでこの値が使えるようになります。
5. 鍵の JSON ファイルはパソコンから削除して構いません(必要になれば①-4 で作り直せます)。

---

## 2. 使い方(Claude が実行)

```bash
pip install -q -r scripts/ga4/requirements.txt

# 接続確認
python3 scripts/ga4/ga4_report.py check

# 直近28日の日別推移(前の28日と比較)
python3 scripts/ga4/ga4_report.py report --preset overview --days 28 --compare

# /talk/ 配下のページ別(直近90日)
python3 scripts/ga4/ga4_report.py report --preset pages --days 90 --path-contains /talk/

# 自由指定(期間・ディメンション・指標)
python3 scripts/ga4/ga4_report.py report --start 2026-01-01 --end 2026-10-31 \
  --dimensions yearMonth,sessionDefaultChannelGroup --metrics sessions,activeUsers --format csv

# 主要レポート一式を保存
bash scripts/ga4/snapshot.sh /mnt/project-files/ga4/$(date -u +%Y-%m-%d)
```

プリセット: `overview`(日別) `monthly` `weekly` `pages` `landing` `sources` `devices` `events`
出力形式: `--format md`(既定、表) / `csv` / `json`、`--out` でファイル保存。
使えるディメンション・指標の名前は
https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema を参照。

## 3. 定期取得

セットアップ後、Claude が「スケジュールタスク」を作り、毎週 `snapshot.sh` を実行して
プロジェクトファイル `ga4/<日付>/` に CSV を保存し、前期比の要約を残します。

## 4. 安全面
- 鍵は GA4 の **閲覧者** 権限のみ。データの変更はできません。
- 鍵はリポジトリに入れない(`.gitignore` で `*service-account*.json` などを除外済み)。
- 鍵を無効にしたいときは Google Cloud のサービスアカウント → 鍵 から削除するだけで即無効になります。
