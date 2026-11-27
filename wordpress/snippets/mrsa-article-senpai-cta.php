<?php
/**
 * mrsa-article-senpai-cta
 * 記事ページから先輩ページ(/senpai/○○/)への導線（2026-11-27 追加・分析チーム案1）
 *
 * - 記事(投稿)と /scholarship/ に「この記事のこと、留学中の先輩に聞いてみませんか？」枠を2か所差し込む。
 *   中盤(真ん中の見出しの前)と本文末。先輩カード2枚（写真・名前・大学・一言）→ 各先輩ページ。
 * - 先輩は記事の話題で選ぶ: 記事の大学カテゴリーと同じ大学の先輩 > 話題キーワード(奨学金・生活・英語など)に
 *   対応する「話せるトピック」を持つ先輩 > それ以外。同じ記事では毎回同じ先輩(記事IDで固定)。
 * - 計測: カードのクリックを GA4 に article_senpai_click(senpai_slug, cta_location=mid/end, page_path)で送る。
 *
 * 2026-11-27 公開済み(true)。MRSA_ARTICLE_SENPAI_LIVE を false にすると、、ログイン中の編集者か ?article_senpai=1 のときだけ反映。
 */
if (!defined('MRSA_ARTICLE_SENPAI_LIVE')) {
    define('MRSA_ARTICLE_SENPAI_LIVE', true);
}

function mrsa_article_senpai_pick($post_id, $n = 2) {
    $text = get_the_title($post_id) . ' ' . get_post_field('post_name', $post_id);
    // 話題キーワード → 話せるトピック(s-ryugaku-topic の term_id)
    $rules = array(
        '/奨学金|scholarship/iu'                       => array(178, 183),
        '/IELTS|TOEIC|英語|english/iu'                  => array(176),
        '/語学|ファンデーション|foundation/iu'          => array(177, 176),
        '/就職|インターン|キャリア|career|job/iu'       => array(181, 182, 180),
        '/親|家族|説得|費用|学費/u'                     => array(183),
        '/大学|学部|ランキング|選び|university/iu'      => array(173),
        '/ビザ|visa|ATM|持ち物|packing|生活|寮|治安|SIM|銀行|物価|食|交通|観光/iu' => array(174),
    );
    $want = array();
    foreach ($rules as $re => $ids) {
        if (preg_match($re, $text)) {
            $want = array_merge($want, $ids);
        }
    }
    if (!$want) {
        $want = array(174, 173);
    }
    $post_cats = wp_get_post_categories($post_id);

    $senpai = get_posts(array('post_type' => 'senpai', 'post_status' => 'publish', 'numberposts' => 50));
    $scored = array();
    foreach ($senpai as $s) {
        $topics = wp_get_post_terms($s->ID, 's-ryugaku-topic', array('fields' => 'ids'));
        if (is_wp_error($topics) || !$topics || !has_post_thumbnail($s->ID)) {
            continue;
        }
        $score = count(array_intersect($topics, $want)) * 10;
        if ($post_cats && array_intersect($post_cats, wp_get_post_categories($s->ID))) {
            $score += 100;
        }
        // 同点は記事IDで並びを固定（記事ごとに違う先輩が出るように）
        $score += (crc32($post_id . '-' . $s->ID) % 10) / 10;
        $scored[] = array($score, $s);
    }
    usort($scored, function ($a, $b) { return $b[0] <=> $a[0]; });
    return array_map(function ($x) { return $x[1]; }, array_slice($scored, 0, $n));
}

add_action('wp_footer', function () {
    if (is_admin() || !(is_singular('post') || is_page('scholarship'))) {
        return;
    }
    if (!MRSA_ARTICLE_SENPAI_LIVE && !current_user_can('edit_pages') && empty($_GET['article_senpai'])) {
        return;
    }
    $picks = mrsa_article_senpai_pick(get_queried_object_id());
    if (!$picks) {
        return;
    }
    ob_start();
    ?>
<aside class="mrsa-asenpai" aria-label="留学中の先輩に相談">
  <p class="mrsa-asenpai__ttl">この記事のこと、<span>実際に留学した先輩</span>に聞いてみませんか？</p>
  <p class="mrsa-asenpai__txt">マレ留トーク：オンラインで先輩と1対1。30分 ¥3,000〜</p>
  <div class="mrsa-asenpai__cards">
    <?php foreach ($picks as $s) :
        $univ = get_post_meta($s->ID, 's-univ-name', true);
        $ttl  = wp_strip_all_tags(get_post_meta($s->ID, 's-ttl', true));
        if (mb_strlen($ttl) > 42) { $ttl = mb_substr($ttl, 0, 41) . '…'; }
        ?>
    <a class="mrsa-asenpai__card" href="<?php echo esc_url(get_permalink($s)); ?>" data-senpai="<?php echo esc_attr($s->post_name); ?>">
      <img src="<?php echo esc_url(get_the_post_thumbnail_url($s, 'thumbnail')); ?>" alt="" width="64" height="64" loading="lazy">
      <span class="mrsa-asenpai__meta">
        <span class="mrsa-asenpai__univ"><?php echo esc_html($univ); ?></span>
        <span class="mrsa-asenpai__name"><?php echo esc_html(get_the_title($s)); ?>さん</span>
        <span class="mrsa-asenpai__msg"><?php echo esc_html($ttl); ?></span>
        <span class="mrsa-asenpai__go">この先輩に話を聞く ›</span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <a class="mrsa-asenpai__all" href="/talk/">ほかの先輩も見る</a>
</aside>
    <?php
    $block = ob_get_clean();
    ?>
<template id="mrsa-asenpai-tpl"><?php echo $block; ?></template>
<style id="mrsa-asenpai-css">
.mrsa-asenpai{margin:32px 0;padding:20px;border:2px solid #f6a5c0;border-radius:14px;background:#fff7fa}
.mrsa-asenpai__ttl{margin:0 0 4px;font-size:17px;font-weight:700;line-height:1.5;color:#333}
.mrsa-asenpai__ttl span{color:#e4407b}
.mrsa-asenpai__txt{margin:0 0 14px;font-size:13px;color:#666}
.mrsa-asenpai__cards{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:768px){.mrsa-asenpai__cards{grid-template-columns:1fr}.mrsa-asenpai{padding:16px}}
.mrsa-asenpai__card{display:flex;gap:12px;align-items:flex-start;padding:12px;background:#fff;border-radius:10px;
  box-shadow:0 1px 4px rgba(0,0,0,.08);text-decoration:none!important;color:#333!important}
.mrsa-asenpai__card img{width:64px;height:64px;border-radius:50%;object-fit:cover;flex:none;margin:0!important}
.mrsa-asenpai__meta{display:flex;flex-direction:column;gap:2px;min-width:0}
.mrsa-asenpai__univ{font-size:11px;color:#888}
.mrsa-asenpai__name{font-size:15px;font-weight:700}
.mrsa-asenpai__msg{font-size:12px;line-height:1.5;color:#555}
.mrsa-asenpai__go{margin-top:6px;align-self:flex-start;padding:6px 12px;border-radius:999px;background:#e4407b;color:#fff;font-size:12px;font-weight:700}
.mrsa-asenpai__all{display:inline-block;margin-top:12px;font-size:13px;color:#e4407b!important}
</style>
<script>
(function () {
  var tpl = document.getElementById('mrsa-asenpai-tpl');
  if (!tpl) { return; }
  var root = document.querySelector('.elementor-widget-theme-post-content') ||
             document.querySelector('[data-elementor-type="wp-page"]') ||
             document.querySelector('.entry-content') ||
             document.querySelector('main') || document.body;
  var h2s = [].slice.call(root.querySelectorAll('h2'));
  function make(loc) {
    var el = tpl.content.firstElementChild.cloneNode(true);
    el.setAttribute('data-loc', loc);
    return el;
  }
  function blockOf(h) {
    // 見出しを含む「横幅いっぱいの縦並びの塊」まで上がって、その前後に入れる（カードの横に入り込まないように）
    var rw = root.getBoundingClientRect().width;
    var e = h;
    while (e.parentElement && e.parentElement !== root) {
      var cs = getComputedStyle(e.parentElement);
      var col = cs.display.indexOf('flex') === -1 || cs.flexDirection.indexOf('column') === 0;
      if (col && e.getBoundingClientRect().width >= rw * 0.95) { return e; }
      e = e.parentElement;
    }
    return e;
  }
  if (h2s.length >= 3) {
    var mid = blockOf(h2s[Math.floor(h2s.length / 2)]);
    mid.parentNode.insertBefore(make('mid'), mid);
  }
  if (root.classList.contains('elementor-widget-theme-post-content')) {
    var box = root.querySelector('.elementor-widget-container') || root;
    box.appendChild(make('end'));
  } else if (h2s.length) {
    var last = blockOf(h2s[h2s.length - 1]);
    last.parentNode.insertBefore(make('end'), last.nextSibling);
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('.mrsa-asenpai a') : null;
    if (!a || typeof window.gtag !== 'function') { return; }
    var box = a.closest('.mrsa-asenpai');
    window.gtag('event', 'article_senpai_click', {
      senpai_slug: a.getAttribute('data-senpai') || 'all',
      cta_location: box ? box.getAttribute('data-loc') : '',
      page_path: window.location.pathname,
      transport_type: 'beacon'
    });
  }, true);
})();
</script>
    <?php
}, 100);
