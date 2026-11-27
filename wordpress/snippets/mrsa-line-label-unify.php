<?php
/**
 * mrsa-line-label-unify
 * 学校詳細ページ [tp_univ_page] の LINE ボタン文言を「【無料】LINEで留学相談する」に統一（2026-11-27 追加）
 *
 * - 以前はセクションごとに「学費・入学時期をLINEで確認」など話題別の文言で、
 *   「その話しか相談できない」ように見えていた。ボタンは共通文言にし、話題はボタン上の吹き出しに回す。
 * - 計測: 既存のサイトJSは LINE クリックの cta_label をボタンの文字(先頭30字)で送っている。
 *   ボタン内に見えない目印 <span hidden>｜学費</span> を入れ、cta_label を
 *   「【無料】LINEで留学相談する｜学費」のようにして設置場所の区別を続ける。
 *   目印: 特徴 / 学費 / カウンセラー / タブ / その他(元の文言)
 * - 本体スニペット(id 26)と mrsa-school-senpai-cta(id 39)には触らず、do_shortcode_tag(優先度20)で置き換える。
 *
 * MRSA_LINE_UNIFY_LIVE が false の間は、ログイン中の編集者か ?line_unify=1 のときだけ反映。
 */
if (!defined('MRSA_LINE_UNIFY_LIVE')) {
    define('MRSA_LINE_UNIFY_LIVE', false);
}

add_filter('do_shortcode_tag', function ($out, $tag) {
    if ($tag !== 'tp_univ_page' || !is_string($out) || $out === '' || is_admin()) {
        return $out;
    }
    if (!MRSA_LINE_UNIFY_LIVE && !current_user_can('edit_pages') && empty($_GET['line_unify'])) {
        return $out;
    }

    $label = '【無料】LINEで留学相談する';

    $out = preg_replace_callback(
        '#<a\b([^>]*\bclass="[^"]*\btp-btn--line\b[^"]*"[^>]*)>(.*?)</a>#su',
        function ($m) use ($label) {
            $attrs = $m[1];
            $old   = trim(wp_strip_all_tags($m[2]));
            if (strpos($attrs, 'data-mrsa-line') !== false) {
                return $m[0];
            }
            if (mb_strpos($old, '特徴') !== false) {
                $mark = '特徴';
                $lead = '学校の特徴も気軽に聞けます';
            } elseif (mb_strpos($old, '学費') !== false) {
                $mark = '学費';
                $lead = '学費・入学時期もすぐ確認できます';
            } elseif (mb_strpos($old, 'カウンセラー') !== false) {
                $mark = 'カウンセラー';
                $lead = 'カウンセラーが直接お答えします';
            } elseif (mb_strpos($old, '資料請求') !== false) {
                $mark = 'タブ';
                $lead = '';
            } else {
                $mark = mb_substr(str_replace(array('【無料】', 'LINE'), '', $old), 0, 10);
                $lead = '';
            }
            $extra = ' data-mrsa-line="' . esc_attr($mark) . '"';
            if ($lead !== '') {
                $extra .= ' data-mrsa-lead="' . esc_attr($lead) . '"';
            }
            return '<a' . $attrs . $extra . '>' . esc_html($label)
                . '<span class="mrsa-line-mark" hidden>｜' . esc_html($mark) . '</span></a>';
        },
        $out
    );

    $css = '<style id="mrsa-line-unify-css">'
        . '.tp-cta__btns:has(a[data-mrsa-lead]){margin-top:18px}'
        . '.tp-support a[data-mrsa-lead]{margin-top:20px}'
        . 'a.tp-btn--line[data-mrsa-lead]{position:relative;overflow:visible}'
        . 'a.tp-btn--line[data-mrsa-lead]::before{content:attr(data-mrsa-lead);position:absolute;left:50%;top:0;'
        . 'transform:translate(-50%,-62%);white-space:nowrap;background:#fff;color:#06a800;border:1.5px solid #06c755;'
        . 'border-radius:999px;font-size:11px;font-weight:700;line-height:1;padding:4px 10px;pointer-events:none}'
        . '</style>';

    return $css . $out;
}, 20, 2);
