/**
 * mrsa-school-senpai-cta
 * 学校詳細ページ [tp_univ_page] に「先輩への導線」を足す（2026-11-27 追加）
 *
 * 1) 入学条件セクション(#admission)の最後に「この大学の先輩に話を聞く」ボックス。
 *    在籍・卒業した先輩の /senpai/○○/ ページへ直接リンクする。
 *    先輩は同じページの「リアルな大学生活を先輩に聞いてみよう」(#voices)に出ている人と同じ
 *    （大学カテゴリの senpai 投稿、最大3件）。先輩がいない大学では何も出さない。
 * 2) スマホのタブバーに「先輩に相談」ボタン。
 *    PCのタブバー右の「【有料】この大学の先輩に相談」はスマホCSSで非表示のため、その代わり。
 * 3) クリックを GA4 に senpai_cta_click（cta_location = admission / tabbar_sp）で送る。
 *
 * 本体スニペット「TP大学詳細ページ ショートコード [tp_univ_page]」は触らず、
 * do_shortcode_tag フィルターで出力に差し込む。無効化すれば元に戻る。
 *
 * 確認中: MRSA_SENPAI_CTA_LIVE が false の間は、ログイン中の編集者か ?senpai_cta=1 のときだけ表示。
 * 公開するときは true にする。
 */
if (!defined('MRSA_SENPAI_CTA_LIVE')) {
    define('MRSA_SENPAI_CTA_LIVE', false);
}

add_filter('do_shortcode_tag', function ($out, $tag) {
    if ($tag !== 'tp_univ_page' || !is_string($out) || $out === '' || is_admin()) {
        return $out;
    }
    if (!MRSA_SENPAI_CTA_LIVE && !current_user_can('edit_pages') && empty($_GET['senpai_cta'])) {
        return $out;
    }
    if (strpos($out, 'id="senpai-cta"') !== false) {
        return $out;
    }
    if (!preg_match_all('#<a class="tp-prof" href="([^"]+)">(.*?)</a>#s', $out, $mm, PREG_SET_ORDER)) {
        return $out;
    }

    $dec = function ($s) {
        return trim(html_entity_decode(wp_strip_all_tags($s), ENT_QUOTES, 'UTF-8'));
    };
    $items = '';
    foreach ($mm as $m) {
        $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $in = $m[2];
        $name = preg_match('#<p class="tp-prof__n">(.*?)</p>#s', $in, $x) ? $dec($x[1]) : '';
        if ($name === '') {
            continue;
        }
        $ph = preg_match('#background:url\(([^)]+)\)#', $in, $x) ? html_entity_decode($x[1], ENT_QUOTES, 'UTF-8') : '';
        $st = preg_match('#<em>(.*?)</em>#s', $in, $x) ? $dec($x[1]) : '';
        $items .= '<a class="tp-scta__item" href="' . esc_url($href) . '" data-senpai-cta="admission">'
            . '<i class="tp-scta__ph"' . ($ph !== '' ? ' style="background:url(' . esc_url($ph) . ') center/cover"' : '') . '></i>'
            . '<span class="tp-scta__tx"><b>' . esc_html($name) . '</b>'
            . ($st !== '' ? '<small>' . esc_html($st) . '</small>' : '') . '</span>'
            . '<span class="tp-scta__go">話を聞く</span></a>';
    }
    if ($items === '') {
        return $out;
    }

    $block = '<div class="tp-scta" id="senpai-cta">'
        . '<p class="tp-scta__ttl">この大学の先輩に話を聞く</p>'
        . '<p class="tp-scta__lead">学費のやりくりや入学条件のクリアの仕方など、実際に通った先輩にオンラインで直接聞けます（マレ留トーク・有料）。</p>'
        . '<div class="tp-scta__list">' . $items . '</div></div>';

    // 1) 入学条件セクションの最後（なければ大学基本情報の最後）
    $done = 0;
    foreach (array('admission', 'basic') as $sec) {
        $out = preg_replace_callback(
            '#(<section[^>]*id="' . $sec . '".*?)(</div>\s*</section>)#s',
            function ($m) use ($block) { return $m[1] . $block . $m[2]; },
            $out, 1, $done
        );
        if ($done) {
            break;
        }
    }
    if (!$done) {
        return $out;
    }

    // 2) スマホのタブバーに「先輩に相談」
    $out = preg_replace(
        '#<div class="tp-tabbar__btns">#',
        '<a class="tp-tabbar__sptalk" href="#senpai-cta" data-senpai-cta="tabbar_sp">先輩に相談</a>$0',
        $out, 1
    );

    $pink = '#ff3773';
    $css = '.tp-scta{margin:40px 0 0;padding:24px;border:2px solid ' . $pink . ';border-radius:12px;background:#fff5f8;scroll-margin-top:130px}'
        . '.tp-scta .tp-scta__ttl{margin:0 0 6px;font-size:20px;font-weight:700;color:' . $pink . ';text-align:center}'
        . '.tp-scta .tp-scta__lead{margin:0 0 16px;font-size:14px;line-height:1.7;text-align:center}'
        . '.tp-scta__list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}'
        . 'a.tp-scta__item{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.1);color:#333;text-decoration:none}'
        . 'a.tp-scta__item:hover{color:#333;box-shadow:0 2px 8px rgba(0,0,0,.18)}'
        . '.tp-scta__ph{flex:0 0 52px;width:52px;height:52px;border-radius:50%;background:#eee}'
        . '.tp-scta__tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;line-height:1.4}'
        . '.tp-scta__tx b{font-size:15px}.tp-scta__tx small{font-size:12px;color:#666}'
        . '.tp-scta__go{flex:0 0 auto;padding:8px 14px;border-radius:999px;background:' . $pink . ';color:#fff;font-size:13px;font-weight:700;white-space:nowrap}'
        . '.tp-tabbar a.tp-tabbar__sptalk{display:none}'
        . '@media (max-width:768px){'
        . '.tp-scta{padding:18px 14px}.tp-scta .tp-scta__ttl{font-size:18px}.tp-scta__list{grid-template-columns:1fr}'
        . '.tp-tabbar a.tp-tabbar__sptalk,.tp-tabbar a.tp-tabbar__sptalk:hover{display:flex;align-items:center;align-self:stretch;flex:0 0 auto;padding:0 14px;background:' . $pink . ';color:#fff;font-size:12px;font-weight:700;white-space:nowrap;text-decoration:none}'
        . '}';
    $js = '<script>document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a[data-senpai-cta]"):null;'
        . 'if(!a||typeof window.gtag!=="function")return;window.gtag("event","senpai_cta_click",{cta_location:a.getAttribute("data-senpai-cta"),'
        . 'link_url:a.href.split("?")[0],page_path:location.pathname,link_text:(a.innerText||"").trim().slice(0,50),transport_type:"beacon"});},true);</script>';

    return '<style>' . $css . '</style>' . $out . $js;
}, 20, 2);
