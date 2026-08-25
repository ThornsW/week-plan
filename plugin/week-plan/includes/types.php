<?php
/**
 * 待办和标签的注册。
 *
 * 待办借用 WordPress 的内容类型,是为了白拿它的标签系统、回收站、权限和
 * 数据关联,不必自己写一遍标签的增删改。但界面完全由本插件提供,所以
 * show_ui 关掉 —— 待办不会出现在后台的文章列表里。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function weekplan_register_types(): void {
	register_post_type(
		WEEKPLAN_POST_TYPE,
		array(
			'label'               => '待办',
			// public => false 意味着待办没有前台链接,不会被搜索引擎收录,
			// 也不会因为猜到 URL 而泄露。
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			// editor 对应 post_content,里面存 Markdown 原文,不经 WordPress 编辑器。
			'supports'            => array( 'title', 'editor' ),
		)
	);

	// 必须是自己的分类法。复用博客文章的标签会让待办标签混进标签云,
	// 出现在公开页面上。
	register_taxonomy(
		WEEKPLAN_TAXONOMY,
		WEEKPLAN_POST_TYPE,
		array(
			'label'        => '待办标签',
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'hierarchical' => false,
		)
	);
}
