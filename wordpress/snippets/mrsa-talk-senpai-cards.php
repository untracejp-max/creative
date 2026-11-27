<?php
/**
 * mrsa-talk-senpai-cards
 * /talk/ のファーストビュー直後に「いま話せる先輩」カードを横スクロールで表示（2026-11-27 追加・分析チーム案2）
 *
 * - /talk/ は滞在が短く(1人18秒)、先輩一覧(#senpai)はページの中ほど(約2,100px下)にある。
 *   実績(※1/※2)のすぐ下に先輩カード(写真・名前・大学・話せるトピック2つ・「この先輩と話す」)を並べ、
 *   各先輩ページへ直接つなぐ。末尾に「条件で絞り込んで探す」→ #senpai。
 * - Elementor のページ本体には触らず、wp_footer で JS 差し込み。
 * - 計測: カードのクリックを GA4 に talk_senpai_card_click(senpai_slug, card_index)で送る。
 *   /talk→/senpai の遷移は既存の週次データ(GA4_遷移)でも追える。
 *
 * MRSA_TALK_CARDS_LIVE が false の間は、ログイン中の編集者か ?talk_cards=1 のときだけ反映。
 */
if (!defined('MRSA_TALK_CARDS_LIVE')) {
    define('MRSA_TALK_CARDS_LIVE', false);
}

add_action('wp_footer', function () {
    if (is_admin() || !is_page('talk')) {
        return;
    }
    if (!MRSA_TALK_CARDS_LIVE && !current_user_can('edit_pages') && empty($_GET['talk_cards'])) {
        return;
    }
    $cards = array();
    foreach (get_posts(array('post_type' => 'senpai', 'post_status' => 'publish', 'numberposts' => 50)) as $s) {
        $topics = wp_get_post_terms($s->ID, 's-ryugaku-topic', array('fields' => 'names'));
        if (is_wp_error($topics) || !$topics || !has_post_thumbnail($s->ID)) {
            continue;
        }
        $topics = array_map(function ($t) { return preg_replace('/[（(].*$/u', '', $t); }, $topics);
        $cards[] = array('s' => $s, 'topics' => array_slice($topics, 0, 2), 'n' => count($topics));
    }
    if (!$cards) {
        return;
    }
    // 話せるトピックが多い先輩から
    usort($cards, function ($a, $b) { return $b['n'] <=> $a['n']; });
    ob_start();
    ?>
<section class="mrsa-tcards" aria-label="いま話せる先輩">
  <p class="mrsa-tcards__ttl">いま話せる先輩<span><?php echo count($cards); ?>人</span></p>
  <p class="mrsa-tcards__sub">気になる先輩を選んで、プロフィールから予約できます</p>
  <div class="mrsa-tcards__row">
    <?php foreach ($cards as $i => $c) : $s = $c['s']; ?>
    <a class="mrsa-tcards__card" href="<?php echo esc_url(get_permalink($s)); ?>" data-senpai="<?php echo esc_attr($s->post_name); ?>" data-idx="<?php echo (int) $i + 1; ?>">
      <img src="<?php echo esc_url(get_the_post_thumbnail_url($s, 'medium')); ?>" alt="" loading="lazy">
      <span class="mrsa-tcards__name"><?php echo esc_html(get_the_title($s)); ?>さん</span>
      <span class="mrsa-tcards__univ"><?php echo esc_html(get_post_meta($s->ID, 's-univ-name', true)); ?></span>
      <span class="mrsa-tcards__tags"><?php foreach ($c['topics'] as $t) : ?><span><?php echo esc_html($t); ?></span><?php endforeach; ?></span>
      <span class="mrsa-tcards__go">この先輩と話す</span>
    </a>
    <?php endforeach; ?>
  </div>
  <a class="mrsa-tcards__more" href="#senpai">条件で絞り込んで探す ↓</a>
</section>
    <?php
    $html = ob_get_clean();
    ?>
<template id="mrsa-tcards-tpl"><?php echo $html; ?></template>
<style id="mrsa-tcards-css">
.mrsa-tcards{padding:22px 0 20px;background:#fff}
.mrsa-tcards__ttl{margin:0 16px 2px;font-size:19px;font-weight:700;color:#333}
.mrsa-tcards__ttl span{margin-left:8px;font-size:13px;color:#2bb7c8}
.mrsa-tcards__sub{margin:0 16px 12px;font-size:12px;color:#777}
.mrsa-tcards__row{display:flex;gap:10px;overflow-x:auto;padding:4px 16px 10px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
.mrsa-tcards__card{flex:0 0 150px;scroll-snap-align:start;display:flex;flex-direction:column;align-items:center;gap:4px;padding:12px 10px;
  border:1px solid #e3eef0;border-radius:12px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);text-decoration:none!important;color:#333!important;text-align:center}
.mrsa-tcards__card img{width:76px;height:76px;border-radius:50%;object-fit:cover;margin:0 0 4px!important}
.mrsa-tcards__name{font-size:15px;font-weight:700}
.mrsa-tcards__univ{font-size:11px;color:#888;line-height:1.4;min-height:2.8em}
.mrsa-tcards__tags{display:flex;flex-wrap:wrap;justify-content:center;gap:4px;min-height:44px}
.mrsa-tcards__tags span{font-size:10px;line-height:1.3;padding:3px 6px;border-radius:999px;background:#eaf8fa;color:#1f8fa0}
.mrsa-tcards__go{margin-top:auto;width:100%;padding:8px 0;border-radius:999px;background:#e4407b;color:#fff;font-size:12px;font-weight:700}
.mrsa-tcards__more{display:block;margin:4px 16px 0;text-align:center;font-size:13px;font-weight:700;color:#2bb7c8!important}
</style>
<script>
(function () {
  var tpl = document.getElementById('mrsa-tcards-tpl');
  if (!tpl) { return; }
  // 実績の注記(※1/※2)のブロックの直後。見つからなければ #senpai の前。
  var anchor = null;
  var page = document.querySelector('[data-elementor-type="wp-page"]') || document.body;
  [].slice.call(page.querySelectorAll('.e-con')).some(function (el) {
    if (/※1/.test(el.textContent) && /※2/.test(el.textContent) && el.textContent.length < 200) { anchor = el; return true; }
    return false;
  });
  var box = tpl.content.firstElementChild.cloneNode(true);
  if (anchor) {
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
  } else {
    var s = document.getElementById('senpai');
    if (!s) { return; }
    s.parentNode.insertBefore(box, s);
  }
  box.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a.mrsa-tcards__card') : null;
    if (!a || typeof window.gtag !== 'function') { return; }
    window.gtag('event', 'talk_senpai_card_click', {
      senpai_slug: a.getAttribute('data-senpai'),
      card_index: Number(a.getAttribute('data-idx')),
      transport_type: 'beacon'
    });
  }, true);
})();
</script>
    <?php
}, 100);
