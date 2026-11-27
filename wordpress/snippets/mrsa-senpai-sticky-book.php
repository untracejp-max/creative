<?php
/**
 * mrsa-senpai-sticky-book
 * 先輩ページ (/senpai/○○/) のスマホ画面下に「この先輩にLINEで予約する」を固定表示（2026-11-27 追加）
 *
 * - 予約ボタン「LINEで予約に進む」(liff.line.me / @619nztzb) は本文の下のほうにしかないため、
 *   スマホ(≤768px)では画面下に同じリンクのボタンを固定する。元のボタンが画面内にある間は隠す。
 * - 先輩ページではサイト共通の下部フローティング(.mrsa-cta)と重なるので、スマホでは .mrsa-cta を隠す。
 * - 計測: 既存スニペット mrsa-talk-line-click(id 38) が @619nztzb へのクリックを talk_apply_click で送る。
 *   このボタンは link_text「この先輩にLINEで予約する」で本文のボタンと区別できる。新しいイベントは無し。
 *
 * MRSA_SENPAI_STICKY_LIVE が false の間は、ログイン中の編集者か ?senpai_sticky=1 のときだけ反映。
 */
if (!defined('MRSA_SENPAI_STICKY_LIVE')) {
    define('MRSA_SENPAI_STICKY_LIVE', false);
}

add_action('wp_footer', function () {
    if (is_admin() || !is_singular('senpai')) {
        return;
    }
    if (!MRSA_SENPAI_STICKY_LIVE && !current_user_can('edit_pages') && empty($_GET['senpai_sticky'])) {
        return;
    }
    ?>
<style id="mrsa-senpai-sticky-css">
.mrsa-sbook{display:none}
@media (max-width:768px){
  body.mrsa-sbook-on .mrsa-cta{display:none!important}
  .mrsa-sbook{display:block;position:fixed;left:0;right:0;bottom:0;z-index:9990;padding:8px 12px calc(8px + env(safe-area-inset-bottom));
    background:rgba(255,255,255,.96);box-shadow:0 -2px 10px rgba(0,0,0,.12);transition:transform .25s}
  .mrsa-sbook.is-hidden{transform:translateY(110%)}
  .mrsa-sbook a{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:52px;border-radius:10px;
    background:#06c755;color:#fff!important;text-decoration:none;font-weight:700;font-size:16px;line-height:1.3}
  .mrsa-sbook small{font-size:11px;font-weight:600;opacity:.95}
  body.mrsa-sbook-on{padding-bottom:76px}
}
</style>
<script>
(function () {
  var src = null;
  document.querySelectorAll('a[href]').forEach(function (a) {
    if (src) { return; }
    var h = a.href || '';
    try { h = decodeURIComponent(h); } catch (e) {}
    if (h.indexOf('619nztzb') !== -1) { src = a; }
  });
  if (!src) { return; }
  var bar = document.createElement('div');
  bar.className = 'mrsa-sbook';
  var a = document.createElement('a');
  a.href = src.href;
  a.target = '_blank';
  a.rel = 'noopener';
  a.innerHTML = 'この先輩にLINEで予約する<small>オンライン30分 ¥3,000〜</small>';
  bar.appendChild(a);
  document.body.appendChild(bar);
  document.body.classList.add('mrsa-sbook-on');
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (es) {
      es.forEach(function (en) { bar.classList.toggle('is-hidden', en.isIntersecting); });
    }).observe(src);
  }
})();
</script>
    <?php
}, 100);
