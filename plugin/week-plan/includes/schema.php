<?php
/**
 * 排期表的建表与升级。
 *
 * 排期是「某待办 × 某一天」的记录,一个待办可以有任意多条。这是几千行、
 * 需要按日期区间查询的热数据,塞进 WordPress 的 postmeta(键值结构、值字段
 * 无索引)会让「查这一周」既慢又难写,所以单独建表。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function weekplan_schedule_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'weekplan_schedule';
}

function weekplan_install_schema(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = weekplan_schedule_table();
	$collate = $wpdb->get_charset_collate();

	// dbDelta 对格式极其挑剔:字段名与类型之间两个空格、用 KEY 而非 INDEX、
	// PRIMARY KEY 后两个空格。写错不会报错,表现是每次加载都重复执行 ALTER。
	$sql = "CREATE TABLE $table (
		id  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		todo_id  bigint(20) unsigned NOT NULL,
		plan_date  date NOT NULL,
		done  tinyint(1) NOT NULL DEFAULT 0,
		done_at  datetime DEFAULT NULL,
		position  int(11) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_todo_date (todo_id,plan_date),
		KEY idx_date (plan_date),
		KEY idx_todo (todo_id)
	) $collate;";

	dbDelta( $sql );
	update_option( 'weekplan_db_version', WEEKPLAN_DB_VERSION );
}

function weekplan_maybe_upgrade_schema(): void {
	if ( (int) get_option( 'weekplan_db_version', 0 ) < WEEKPLAN_DB_VERSION ) {
		weekplan_install_schema();
	}
}

/**
 * 删除待办时一并清掉它的排期。
 * WordPress 只管自己的表,自建表的级联要自己做。
 */
add_action( 'before_delete_post', 'weekplan_delete_schedule_for_todo' );

function weekplan_delete_schedule_for_todo( int $post_id ): void {
	if ( get_post_type( $post_id ) !== WEEKPLAN_POST_TYPE ) {
		return;
	}
	global $wpdb;
	$wpdb->delete( weekplan_schedule_table(), array( 'todo_id' => $post_id ), array( '%d' ) );
}
