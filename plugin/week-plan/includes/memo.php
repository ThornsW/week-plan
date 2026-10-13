<?php
/**
 * 右栏底部的便签。
 *
 * 只有一张,和看哪一周无关,翻周它不动。内容是一小段 HTML,整体读写,
 * 所以存在一个不自动加载的 option 里,不建表,也不动 WEEKPLAN_DB_VERSION。
 *
 * 编辑器是浏览器原生的 contenteditable,产出的标签五花八门,存之前过一遍
 * 白名单:只留粗体、斜体、下划线、删除线、列表和换行,属性一律不要。
 * 图片不在白名单里 —— 便签只写字。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WEEKPLAN_MEMO_OPTION = 'weekplan_memo';

function weekplan_memo_allowed_tags(): array {
	$tags = array( 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ul', 'ol', 'li', 'br', 'div', 'p' );
	return array_fill_keys( $tags, array() );
}

function weekplan_get_memo(): string {
	return (string) get_option( WEEKPLAN_MEMO_OPTION, '' );
}

function weekplan_save_memo( string $html ): void {
	$clean = wp_kses( $html, weekplan_memo_allowed_tags() );
	// 删光以后编辑器里常剩一个 <br> 或空 <div>,当成空的存
	if ( '' === trim( wp_strip_all_tags( $clean ) ) ) {
		$clean = '';
	}
	update_option( WEEKPLAN_MEMO_OPTION, $clean, false );
}

function weekplan_render_memo(): void {
	$html = wp_kses( weekplan_get_memo(), weekplan_memo_allowed_tags() );
	?>
	<section class="wp-memo" id="weekplan-memo">
		<header class="wp-memo-head">
			<span class="wp-memo-title">便签</span>
			<span class="wp-memo-status" aria-live="polite"></span>
		</header>
		<div class="wp-memo-body<?php echo '' === $html ? ' is-empty' : ''; ?>"
			contenteditable="true" spellcheck="false" role="textbox" aria-multiline="true"
			data-placeholder="记点什么…"><?php echo $html; ?></div>
		<footer class="wp-memo-tools">
			<button type="button" data-cmd="bold" title="粗体 (Ctrl+B)"><b>B</b></button>
			<button type="button" data-cmd="italic" title="斜体 (Ctrl+I)"><i>I</i></button>
			<button type="button" data-cmd="underline" title="下划线 (Ctrl+U)"><u>U</u></button>
			<button type="button" data-cmd="strikeThrough" title="删除线"><s>ab</s></button>
			<button type="button" data-cmd="insertUnorderedList" title="项目符号列表">
				<svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true"><circle cx="2.5" cy="4" r="1.2"/><circle cx="2.5" cy="8" r="1.2"/><circle cx="2.5" cy="12" r="1.2"/><rect x="5.5" y="3.4" width="9" height="1.2"/><rect x="5.5" y="7.4" width="9" height="1.2"/><rect x="5.5" y="11.4" width="9" height="1.2"/></svg>
			</button>
		</footer>
	</section>
	<?php
}
