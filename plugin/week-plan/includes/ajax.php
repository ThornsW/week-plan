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
	$actions = array( 'add', 'move', 'remove', 'reorder', 'toggle' );
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
