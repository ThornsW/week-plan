<?php
/**
 * 排期的读写。
 *
 * 一条排期是「某待办 × 某一天」。一个待办可以有任意多条,日期不必连续 ——
 * 长期计划就是一堆排期。单天的完成状态挂在排期上(done),整个待办的
 * 「结项」是另一回事,挂在待办的 _weekplan_done 上。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WEEKPLAN_DEFAULT_COLOR = '#7AA2F7';

/** 待办的标签,附带颜色。 */
function weekplan_todo_tags( int $todo_id ): array {
	$terms = wp_get_object_terms( $todo_id, WEEKPLAN_TAXONOMY );
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
		);
	}
	return $out;
}

/** 卡片和侧栏共用的待办快照。 */
function weekplan_todo_summary( int $todo_id ): ?array {
	$post = get_post( $todo_id );
	if ( ! $post || WEEKPLAN_POST_TYPE !== $post->post_type ) {
		return null;
	}

	$tags = weekplan_todo_tags( $todo_id );

	return array(
		'id'        => $todo_id,
		'title'     => $post->post_title,
		'priority'  => (int) get_post_meta( $todo_id, '_weekplan_priority', true ),
		'due_date'  => (string) get_post_meta( $todo_id, '_weekplan_due_date', true ),
		'finished'  => (bool) get_post_meta( $todo_id, '_weekplan_done', true ),
		'tags'      => $tags,
		// 卡片只有一条色带,用第一个标签的颜色;没有标签就用默认色。
		'accent'    => $tags ? $tags[0]['color'] : WEEKPLAN_DEFAULT_COLOR,
		'done_days' => weekplan_done_day_count( $todo_id ),
	);
}

function weekplan_done_day_count( int $todo_id ): int {
	global $wpdb;
	$table = weekplan_schedule_table();
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE todo_id = %d AND done = 1", $todo_id )
	);
}

/**
 * 一段日期范围内的排期,按天分组。
 *
 * @return array<string, array<int, array>> 键是 'YYYY-MM-DD'
 */
function weekplan_schedule_between( string $from, string $to, ?array $only = null ): array {
	global $wpdb;
	$table = weekplan_schedule_table();

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, todo_id, plan_date, done, position
			   FROM $table
			  WHERE plan_date BETWEEN %s AND %s
			  ORDER BY plan_date ASC, position ASC, id ASC",
			$from,
			$to
		)
	);

	$by_day = array();
	foreach ( $rows as $row ) {
		$todo = weekplan_todo_summary( (int) $row->todo_id );
		if ( null === $todo ) {
			continue; // 待办已被删除,排期是孤儿,跳过
		}
		if ( null !== $only && ! in_array( (int) $row->todo_id, $only, true ) ) {
			continue; // 标签筛选掉了
		}
		$by_day[ $row->plan_date ][] = array(
			'schedule_id' => (int) $row->id,
			'date'        => $row->plan_date,
			'done'        => (bool) $row->done,
			'todo'        => $todo,
		);
	}

	return $by_day;
}

/** 逾期区:只汇总上一周的未完成排期,更早的静默留存,不再提醒。 */
function weekplan_overdue_last_week( ?array $only = null ): array {
	$this_week = weekplan_week_start_of( weekplan_today() );
	$from      = $this_week->modify( '-7 days' )->format( 'Y-m-d' );
	$to        = $this_week->modify( '-1 day' )->format( 'Y-m-d' );

	$items = array();
	foreach ( weekplan_schedule_between( $from, $to, $only ) as $entries ) {
		foreach ( $entries as $entry ) {
			if ( ! $entry['done'] ) {
				$items[] = $entry;
			}
		}
	}
	return $items;
}

/**
 * 侧栏的待办清单。
 *
 * 已排期的按最近一次排期日期倒序;完全没排过期的收进「未来计划」,按截止日排序。
 *
 * @return array{scheduled: array, unscheduled: array}
 */
function weekplan_sidebar_todos( ?array $only = null ): array {
	global $wpdb;
	$table = weekplan_schedule_table();

	$posts = get_posts(
		array(
			'post_type'      => WEEKPLAN_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$latest = array();
	foreach ( $wpdb->get_results( "SELECT todo_id, MAX(plan_date) AS last_date, COUNT(*) AS total FROM $table GROUP BY todo_id" ) as $row ) {
		$latest[ (int) $row->todo_id ] = array(
			'last_date' => $row->last_date,
			'total'     => (int) $row->total,
		);
	}

	$scheduled   = array();
	$unscheduled = array();

	foreach ( $posts as $post ) {
		$todo = weekplan_todo_summary( $post->ID );
		if ( null === $todo || $todo['finished'] ) {
			continue;
		}
		if ( null !== $only && ! in_array( (int) $post->ID, $only, true ) ) {
			continue; // 标签筛选掉了
		}

		if ( isset( $latest[ $post->ID ] ) ) {
			$todo['last_date']   = $latest[ $post->ID ]['last_date'];
			$todo['total_days']  = $latest[ $post->ID ]['total'];
			$scheduled[]         = $todo;
		} else {
			$unscheduled[] = $todo;
		}
	}

	usort(
		$scheduled,
		static fn( $a, $b ) => strcmp( $b['last_date'], $a['last_date'] )
	);

	// 有截止日的排前面,按日期升序;没有的垫底
	usort(
		$unscheduled,
		static function ( $a, $b ) {
			$x = '' === $a['due_date'] ? '9999-12-31' : $a['due_date'];
			$y = '' === $b['due_date'] ? '9999-12-31' : $b['due_date'];
			return strcmp( $x, $y );
		}
	);

	return array(
		'scheduled'   => $scheduled,
		'unscheduled' => $unscheduled,
	);
}

/* ---------- 写操作 ---------- */

/** 侧栏 → 某一天:新增一条排期。待办本身仍留在侧栏。 */
function weekplan_add_schedule( int $todo_id, string $date ): int|WP_Error {
	global $wpdb;

	if ( null === weekplan_parse_date( $date ) ) {
		return new WP_Error( 'weekplan_bad_date', '日期格式不对。' );
	}
	if ( null === weekplan_todo_summary( $todo_id ) ) {
		return new WP_Error( 'weekplan_no_todo', '待办不存在。' );
	}

	$table = weekplan_schedule_table();

	$existing = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM $table WHERE todo_id = %d AND plan_date = %s", $todo_id, $date )
	);
	if ( $existing ) {
		return (int) $existing; // 同一天已经有了,幂等返回
	}

	$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position), -1) + 1 FROM $table WHERE plan_date = %s", $date ) );

	$ok = $wpdb->insert(
		$table,
		array(
			'todo_id'   => $todo_id,
			'plan_date' => $date,
			'done'      => 0,
			'position'  => $next,
		),
		array( '%d', '%s', '%d', '%d' )
	);

	return $ok ? (int) $wpdb->insert_id : new WP_Error( 'weekplan_insert_failed', '写入失败。' );
}

/** 某一天 → 另一天:只移动被拖的这一天,不是整体平移。 */
function weekplan_move_schedule( int $schedule_id, string $date ): array|WP_Error {
	global $wpdb;

	if ( null === weekplan_parse_date( $date ) ) {
		return new WP_Error( 'weekplan_bad_date', '日期格式不对。' );
	}

	$table = weekplan_schedule_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, todo_id FROM $table WHERE id = %d", $schedule_id ) );
	if ( ! $row ) {
		return new WP_Error( 'weekplan_no_schedule', '排期不存在。' );
	}

	// 目标日已经有同一个待办 —— 合并成一条,不制造重复
	$clash = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM $table WHERE todo_id = %d AND plan_date = %s AND id <> %d", $row->todo_id, $date, $schedule_id )
	);
	if ( $clash ) {
		$wpdb->delete( $table, array( 'id' => $schedule_id ), array( '%d' ) );
		return array( 'merged' => true );
	}

	$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position), -1) + 1 FROM $table WHERE plan_date = %s", $date ) );
	$wpdb->update( $table, array( 'plan_date' => $date, 'position' => $next ), array( 'id' => $schedule_id ), array( '%s', '%d' ), array( '%d' ) );

	return array( 'merged' => false );
}

/** 拖出周视图:删掉这一天的排期(待办本身不动)。 */
function weekplan_remove_schedule( int $schedule_id ): bool {
	global $wpdb;
	return (bool) $wpdb->delete( weekplan_schedule_table(), array( 'id' => $schedule_id ), array( '%d' ) );
}

/** 同一天内上下拖:重写这一天的顺序。 */
function weekplan_reorder_day( string $date, array $schedule_ids ): true|WP_Error {
	global $wpdb;

	if ( null === weekplan_parse_date( $date ) ) {
		return new WP_Error( 'weekplan_bad_date', '日期格式不对。' );
	}

	$table = weekplan_schedule_table();
	foreach ( array_values( $schedule_ids ) as $position => $id ) {
		$wpdb->update(
			$table,
			array( 'position' => $position ),
			array( 'id' => (int) $id, 'plan_date' => $date ),
			array( '%d' ),
			array( '%d', '%s' )
		);
	}

	return true;
}

/** 卡片上的勾:单天完成。 */
function weekplan_toggle_done( int $schedule_id ): array|WP_Error {
	global $wpdb;
	$table = weekplan_schedule_table();

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, todo_id, done FROM $table WHERE id = %d", $schedule_id ) );
	if ( ! $row ) {
		return new WP_Error( 'weekplan_no_schedule', '排期不存在。' );
	}

	$done = $row->done ? 0 : 1;
	$wpdb->update(
		$table,
		array( 'done' => $done, 'done_at' => $done ? current_time( 'mysql' ) : null ),
		array( 'id' => $schedule_id ),
		array( '%d', '%s' ),
		array( '%d' )
	);

	return array(
		'done'      => (bool) $done,
		'todo_id'   => (int) $row->todo_id,
		'done_days' => weekplan_done_day_count( (int) $row->todo_id ),
	);
}
