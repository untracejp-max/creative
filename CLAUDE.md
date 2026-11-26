# プロジェクトメモ(Claude 向け)

- 返答は日本語で。
- GA4 / Search Console のデータは、まずスプレッドシート「マレ留_週次データ」
  (ID 17xoHUkMtZ61IKHVgqjSsoIOgqC_qdSArTdyMcqMWfZQ)を Google Sheets コネクタで読む。
  毎週月曜に Apps Script で自動更新される週次データ。
- シートにない切り口(日別・任意の期間やフィルタ)が必要なときだけ `scripts/ga4/ga4_report.py` を使う。
  認証情報は環境変数 `GA4_PROPERTY_ID` / `GA4_CREDENTIALS_JSON`(未設定なら `scripts/ga4/README.md` の手順)。
  鍵や認証情報は絶対にコミットしない。
