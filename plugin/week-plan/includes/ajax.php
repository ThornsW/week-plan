<?php
/**
 * 拖拽和打勾的写入端点。
 *
 * 每个端点都先验 nonce 再验权限,两道都过才动数据。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WEEKPLAN_NONCE = 'weekplan_action';

function weekplan_register_ajax(): void {
	$actions = array( 'add', 'move', 'remove', 'reorder', 'toggle', 'detail', 'create', 'save', 'delete', 'tag_save', 'tag_delete' );
	foreach ( $actions as $action ) {
		add_action( "wp_ajax_weekplan_$action", "weekplan_ajax_$action" );
	}
}

/** 所有端点的共同门禁。 */
function weekplan_ajax_guard(): void {
	check_ajax_referer( WEEKPLAN_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足。' ), 403 );
	}
}

function weekplan_ajax_reply( mixed $result ): void {
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
	}
	wp_send_json_success( is_array( $result ) ? $result : array() );
}

function weekplan_post_int( string $key ): int {
	return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
}

function weekplan_post_text( string $key ): string {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
}

function weekplan_ajax_add(): void {
	weekplan_ajax_guard();

	$todo_id = weekplan_post_int( 'todo_id' );
	$date    = weekplan_post_text( 'date' );
	$result  = weekplan_add_schedule( $todo_id, $date );

	if ( is_wp_error( $result ) ) {
		weekplan_ajax_reply( $result );
	}

	weekplan_ajax_reply(
		array(
			'schedule_id' => $result,
			'html'        => weekplan_card_html(
				array(
					'schedule_id' => $result,
					'date'        => $date,
					'done'        => false,
					'todo'        => weekplan_todo_summary( $todo_id ),
				)
			),
		)
	);
}

function weekplan_ajax_move(): void {
	weekplan_ajax_guard();
	weekplan_ajax_reply( weekplan_move_schedule( weekplan_post_int( 'schedule_id' ), weekplan_post_text( 'date' ) ) );
}

function weekplan_ajax_remove(): void {
	weekplan_ajax_guard();
	weekplan_remove_schedule( weekplan_post_int( 'schedule_id' ) );
	weekplan_ajax_reply( array() );
}

function weekplan_ajax_reorder(): void {
	weekplan_ajax_guard();

	$raw = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
	$ids = array_map( 'intval', $raw );

	weekplan_ajax_reply( weekplan_reorder_day( weekplan_post_text( 'date' ), $ids ) );
}

function weekplan_ajax_toggle(): void {
	weekplan_ajax_guard();
	weekplan_ajax_reply( weekplan_toggle_done( weekplan_post_int( 'schedule_id' ) ) );
}

/* ---------- 待办本身 ---------- */

function weekplan_ajax_detail(): void {
	weekplan_ajax_guard();

	ob_start();
	weekplan_render_detail( weekplan_post_int( 'todo_id' ) );
	weekplan_ajax_reply( array( 'html' => (string) ob_get_clean() ) );
}

function weekplan_ajax_create(): void {
	weekplan_ajax_guard();

	$id = weekplan_create_todo( weekplan_post_text( 'title' ) );
	if ( is_wp_error( $id ) ) {
		weekplan_ajax_reply( $id );
	}

	ob_start();
	weekplan_render_sidebar_item( weekplan_todo_summary( $id ) );
	$item = (string) ob_get_clean();

	ob_start();
	weekplan_render_detail( $id );
	weekplan_ajax_reply(
		array(
			'todo_id'     => $id,
			'item_html'   => $item,
			'detail_html' => (string) ob_get_clean(),
		)
	);
}

/**
 * 详情栏逐项保存,一次只送一个字段。
 * tags 和 subtasks 是结构化的,单独解一下。
 */
function weekplan_ajax_save(): void {
	weekplan_ajax_guard();

	$id    = weekplan_post_int( 'todo_id' );
	$field = weekplan_post_text( 'field' );

	$allowed = array( 'title', 'note', 'due_date', 'priority', 'finished', 'tags', 'subtasks' );
	if ( ! in_array( $field, $allowed, true ) ) {
		weekplan_ajax_reply( new WP_Error( 'weekplan_bad_field', '不认识的字段。' ) );
	}

	// 笔记是 Markdown 原文,不能过 sanitize_text_field(会吃掉换行)
	$raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

	switch ( $field ) {
		case 'note':
			$value = (string) $raw;
			break;
		case 'tags':
			$value = array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) );
			break;
		case 'subtasks':
			$decoded = json_decode( (string) $raw, true );
			$value   = is_array( $decoded ) ? $decoded : array();
			break;
		case 'finished':
			$value = ( '1' === (string) $raw || 'true' === (string) $raw );
			break;
		case 'priority':
			$value = (int) $raw;
			break;
		default:
			$value = sanitize_text_field( (string) $raw );
	}

	$result = weekplan_update_todo( $id, array( $field => $value ) );
	if ( is_wp_error( $result ) ) {
		weekplan_ajax_reply( $result );
	}

	ob_start();
	weekplan_render_sidebar_item( weekplan_todo_summary( $id ) );
	weekplan_ajax_reply( array( 'item_html' => (string) ob_get_clean() ) );
}

function weekplan_ajax_delete(): void {
	weekplan_ajax_guard();

	$id = weekplan_post_int( 'todo_id' );
	if ( ! weekplan_delete_todo( $id ) ) {
		weekplan_ajax_reply( new WP_Error( 'weekplan_delete_failed', '删除失败。' ) );
	}
	weekplan_ajax_reply( array() );
}

/* ---------- 标签管理 ---------- */

function weekplan_ajax_tag_save(): void {
	weekplan_ajax_guard();

	$result = weekplan_save_tag(
		weekplan_post_int( 'term_id' ),
		weekplan_post_text( 'name' ),
		weekplan_post_text( 'color' )
	);
	weekplan_ajax_reply( $result );
}

function weekplan_ajax_tag_delete(): void {
	weekplan_ajax_guard();

	if ( ! weekplan_delete_tag( weekplan_post_int( 'term_id' ) ) ) {
		weekplan_ajax_reply( new WP_Error( 'weekplan_tag_delete_failed', '删除失败。' ) );
	}
	weekplan_ajax_reply( array() );
}
