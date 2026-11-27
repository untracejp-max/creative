/**
 * mrsa-school-senpai-cta
 * 学校詳細ページ [tp_univ_page] の「先輩への導線」を強める（2026-11-27 追加）
 *
 * 1) 既存の「リアルな大学生活を先輩に聞いてみよう」(#voices) を、入学条件セクション(#admission)の直後に移動。
 *    （学費・入学条件を読んだ直後に、在籍先輩の /senpai/○○/ ページへ進めるように）
 * 2) スマホのタブバーに「先輩に相談」ボタン（#voices へ移動）。
 *    PCのタブバー右の「【有料】この大学の先輩に相談」はスマホCSSで非表示のため、その代わり。
 * 3) 先輩カードと上記ボタンのクリックを GA4 に senpai_cta_click（cta_location = voices / tabbar_sp）で送る。
 *
 * 先輩がいない大学(#voices がない)では何もしない。
 * 本体スニペット「TP大学詳細ページ ショートコード [tp_univ_page]」は触らず、
 * do_shortcode_tag フィルターで出力を並べ替える。無効化すれば元に戻る。
 *
 * 確認中: MRSA_SENPAI_CTA_LIVE が false の間は、ログイン中の編集者か ?senpai_cta=1 のときだけ反映。
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
    if (strpos($out, 'data-senpai-cta=') !== false) {
        return $out;
    }

    // 1) #voices を取り出して #admission の直後へ
    if (!preg_match('#<section[^>]*id="voices".*?</section>#s', $out, $vm)) {
        return $out;
    }
    $voices = $vm[0];
    $rest = str_replace($voices, '', $out);
    $moved = 0;
    $rest = preg_replace_callback(
        '#<section[^>]*id="admission".*?</div>\s*</section>#s',
        function ($m) use ($voices) { return $m[0] . $voices; },
        $rest, 1, $moved
    );
    if (!$moved) {
        return $out;
    }
    $out = $rest;

    // 先輩カードにクリック計測用の目印
    $out = str_replace('<a class="tp-prof" href=', '<a class="tp-prof" data-senpai-cta="voices" href=', $out);

    // 2) スマホのタブバーに「先輩に相談」
    $out = preg_replace(
        '#<div class="tp-tabbar__btns">#',
        '<a class="tp-tabbar__sptalk" href="#voices" data-senpai-cta="tabbar_sp">先輩に相談</a>$0',
        $out, 1
    );

    $css = '.tp-tabbar a.tp-tabbar__sptalk{display:none}'
        . '@media (max-width:768px){'
        . '.tp-tabbar a.tp-tabbar__sptalk,.tp-tabbar a.tp-tabbar__sptalk:hover{display:flex;align-items:center;align-self:stretch;flex:0 0 auto;padding:0 14px;background:#ff3773;color:#fff;font-size:12px;font-weight:700;white-space:nowrap;text-decoration:none}'
        . '}';
    $js = '<script>document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a[data-senpai-cta]"):null;'
        . 'if(!a||typeof window.gtag!=="function")return;window.gtag("event","senpai_cta_click",{cta_location:a.getAttribute("data-senpai-cta"),'
        . 'link_url:a.href.split("?")[0],page_path:location.pathname,link_text:(a.innerText||"").trim().slice(0,50),transport_type:"beacon"});},true);</script>';

    return '<style>' . $css . '</style>' . $out . $js;
}, 20, 2);
