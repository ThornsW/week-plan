<?php
/**
 * 待办本身的增删改。
 *
 * 待办借用 WordPress 的内容类型,所以标题和笔记直接落在 post 上,其余字段
 * 走 meta。标签走自有分类法 weekplan_tag。子任务用 JSON 存一个 meta:
 * 每条待办通常只有几项、永远整体读写,单独建表不值得。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function weekplan_create_todo( string $title ): int|WP_Error {
	$title = trim( $title );
	if ( '' === $title ) {
		return new WP_Error( 'weekplan_empty_title', '标题不能为空。' );
	}

	$id = wp_insert_post(
		array(
			'post_type'   => WEEKPLAN_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_post_meta( $id, '_weekplan_priority', 1 );
	return (int) $id;
}

/**
 * 按传进来的字段更新待办。只处理出现过的键,没传的字段不动 ——
 * 详情栏是逐项自动保存的,每次只会送一个字段过来。
 */
function weekplan_update_todo( int $id, array $fields ): true|WP_Error {
	if ( null === weekplan_todo_summary( $id ) ) {
		return new WP_Error( 'weekplan_no_todo', '待办不存在。' );
	}

	$post = array( 'ID' => $id );

	if ( array_key_exists( 'title', $fields ) ) {
		$title = trim( (string) $fields['title'] );
		if ( '' === $title ) {
			return new WP_Error( 'weekplan_empty_title', '标题不能为空。' );
		}
		$post['post_title'] = $title;
	}

	if ( array_key_exists( 'note', $fields ) ) {
		// Markdown 原文原样存,不经 WordPress 编辑器,也不做 HTML 过滤 ——
		// 渲染时才转义。
		$post['post_content'] = (string) $fields['note'];
	}

	if ( count( $post ) > 1 ) {
		$result = wp_update_post( $post, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( array_key_exists( 'priority', $fields ) ) {
		$priority = (int) $fields['priority'];
		update_post_meta( $id, '_weekplan_priority', max( 0, min( 2, $priority ) ) );
	}

	if ( array_key_exists( 'due_date', $fields ) ) {
		$due = trim( (string) $fields['due_date'] );
		if ( '' === $due ) {
			delete_post_meta( $id, '_weekplan_due_date' );
		} elseif ( null === weekplan_parse_date( $due ) ) {
			return new WP_Error( 'weekplan_bad_date', '截止日格式不对。' );
		} else {
			update_post_meta( $id, '_weekplan_due_date', $due );
		}
	}

	if ( array_key_exists( 'finished', $fields ) ) {
		if ( $fields['finished'] ) {
			update_post_meta( $id, '_weekplan_done', 1 );
			update_post_meta( $id, '_weekplan_done_at', current_time( 'mysql' ) );
		} else {
			delete_post_meta( $id, '_weekplan_done' );
			delete_post_meta( $id, '_weekplan_done_at' );
		}
	}

	if ( array_key_exists( 'tags', $fields ) ) {
		weekplan_set_todo_tags( $id, (array) $fields['tags'] );
	}

	if ( array_key_exists( 'subtasks', $fields ) ) {
		weekplan_set_subtasks( $id, (array) $fields['subtasks'] );
	}

	return true;
}

/** 删除待办。排期由 before_delete_post 钩子级联清掉。 */
function weekplan_delete_todo( int $id ): bool {
	if ( get_post_type( $id ) !== WEEKPLAN_POST_TYPE ) {
		return false;
	}
	return (bool) wp_delete_post( $id, true );
}

/* ---------- 子任务 ---------- */

/** @return array<int, array{text:string, done:bool}> */
function weekplan_get_subtasks( int $id ): array {
	$raw = get_post_meta( $id, '_weekplan_subtasks', true );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}

	$parsed = json_decode( $raw, true );
	if ( ! is_array( $parsed ) ) {
		return array();
	}

	$out = array();
	foreach ( $parsed as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['text'] ) ) {
			continue;
		}
		$text = trim( (string) $item['text'] );
		if ( '' === $text ) {
			continue;
		}
		$out[] = array(
			'text' => $text,
			'done' => ! empty( $item['done'] ),
		);
	}
	return $out;
}

function weekplan_set_subtasks( int $id, array $subtasks ): void {
	$clean = array();
	foreach ( $subtasks as $item ) {
		$text = isset( $item['text'] ) ? trim( sanitize_text_field( (string) $item['text'] ) ) : '';
		if ( '' === $text ) {
			continue;
		}
		$clean[] = array(
			'text' => $text,
			'done' => ! empty( $item['done'] ) && 'false' !== $item['done'],
		);
	}

	if ( ! $clean ) {
		delete_post_meta( $id, '_weekplan_subtasks' );
		return;
	}

	update_post_meta( $id, '_weekplan_subtasks', wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ) );
}

/* ---------- 标签 ---------- */

/** 全部标签,带颜色和使用次数。 */
function weekplan_all_tags(): array {
	$terms = get_terms(
		array(
			'taxonomy'   => WEEKPLAN_TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	$out = array();
	foreach ( $terms as $term ) {
		$color = get_term_meta( $term->term_id, '_weekplan_color', true );
		$out[] = array(
			'id'    => $term->term_id,
			'name'  => $term->name,
			'color' => $color ? $color : WEEKPLAN_DEFAULT_COLOR,
			'count' => (int) $term->count,
		);
	}
	return $out;
}

/**
 * 按标签名设置待办的标签。名字不存在就新建 —— 详情栏里直接打字就能加标签,
 * 不必先去别处建好。
 */
function weekplan_set_todo_tags( int $id, array $names ): void {
	$ids = array();

	foreach ( $names as $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) {
			continue;
		}

		$term = get_term_by( 'name', $name, WEEKPLAN_TAXONOMY );
		if ( $term ) {
			$ids[] = (int) $term->term_id;
			continue;
		}

		$created = wp_insert_term( $name, WEEKPLAN_TAXONOMY );
		if ( ! is_wp_error( $created ) ) {
			$ids[] = (int) $created['term_id'];
			update_term_meta( $created['term_id'], '_weekplan_color', WEEKPLAN_DEFAULT_COLOR );
		}
	}

	wp_set_object_terms( $id, $ids, WEEKPLAN_TAXONOMY );
}

/** 改名 + 改色。名字撞了就拒绝,不静默合并。 */
function weekplan_save_tag( int $term_id, string $name, string $color ): true|WP_Error {
	$term = get_term( $term_id, WEEKPLAN_TAXONOMY );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'weekplan_no_tag', '标签不存在。' );
	}

	$name = trim( sanitize_text_field( $name ) );
	if ( '' === $name ) {
		return new WP_Error( 'weekplan_empty_tag', '标签名不能为空。' );
	}

	$clash = get_term_by( 'name', $name, WEEKPLAN_TAXONOMY );
	if ( $clash && (int) $clash->term_id !== $term_id ) {
		return new WP_Error( 'weekplan_tag_exists', '已经有同名标签了。' );
	}

	if ( $name !== $term->name ) {
		$result = wp_update_term( $term_id, WEEKPLAN_TAXONOMY, array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	// 颜色必须是 #rrggbb —— 它会原样进 style 属性,不能放任意字符串进去
	if ( 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
		update_term_meta( $term_id, '_weekplan_color', strtoupper( $color ) );
	}

	return true;
}

/** 删标签。待办本身不动,只是不再挂这个标签。 */
function weekplan_delete_tag( int $term_id ): bool {
	$result = wp_delete_term( $term_id, WEEKPLAN_TAXONOMY );
	return true === $result;
}

/** 挂了某个标签的待办 id。用来做筛选。 */
function weekplan_todo_ids_with_tag( int $tag_id ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => WEEKPLAN_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => WEEKPLAN_TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $tag_id,
					),
				),
			)
		)
	);
}
