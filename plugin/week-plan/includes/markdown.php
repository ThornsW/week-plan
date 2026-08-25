<?php
/**
 * Markdown 渲染。
 *
 * 用 Parsedown(单文件、无依赖),因为这个项目明确不引构建步骤。
 * 它 2019 年后没再更新、历史上有过 XSS 公告 —— 单用户、内容是自己写的,
 * 这条威胁不成立;但仍然开了安全模式当兜底,见 vendor/README.md。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function weekplan_markdown(): Parsedown {
	static $parser = null;

	if ( null === $parser ) {
		require_once WEEKPLAN_DIR . 'vendor/Parsedown.php';
		$parser = new Parsedown();
		$parser->setSafeMode( true );        // 转义原始 HTML,不让它直接生效
		$parser->setMarkupEscaped( true );
		$parser->setBreaksEnabled( true );   // 单个换行就换行,符合写笔记的直觉
	}

	return $parser;
}

/** 把 Markdown 原文渲染成可以直接插进页面的 HTML。 */
function weekplan_render_markdown( string $text ): string {
	$text = trim( $text );
	if ( '' === $text ) {
		return '';
	}

	// Parsedown 的安全模式已经转义了原始 HTML;再过一遍 wp_kses_post,
	// 把标签集合收敛到 WordPress 认可的范围内。
	return wp_kses_post( weekplan_markdown()->text( $text ) );
}
