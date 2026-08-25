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

/** 批量排期一次最多生成多少条。挡住「随手选了十年」这种误操作。 */
const WEEKPLAN_BATCH_LIMIT = 400;

/**
 * 批量排期:给定区间和星期几,一次生成多条排期。
 *
 * 解决的是长期计划没法手拖的问题 —— 「接下来三个月每周一三五」用拖的要
 * 拖近四十次。星期几用 0(周日)到 6(周六),和 PHP 的 'w' 一致。
 *
 * 已经排过的日子会跳过,不报错也不重复 —— 表上 (todo_id, plan_date) 是
 * 唯一键,重跑一次同样的区间是安全的。
 *
 * @return array{added:int, skipped:int}|WP_Error
 */
function weekplan_batch_schedule( int $todo_id, string $from, string $to, array $weekdays ): array|WP_Error {
	global $wpdb;

	if ( null === weekplan_todo_summary( $todo_id ) ) {
		return new WP_Error( 'weekplan_no_todo', '待办不存在。' );
	}

	$start = weekplan_parse_date( $from );
	$end   = weekplan_parse_date( $to );
	if ( null === $start || null === $end ) {
		return new WP_Error( 'weekplan_bad_date', '日期格式不对。' );
	}
	if ( $end < $start ) {
		return new WP_Error( 'weekplan_bad_range', '结束日期不能早于开始日期。' );
	}

	$wanted = array_values( array_unique( array_filter(
		array_map( 'intval', $weekdays ),
		static fn( $d ) => $d >= 0 && $d <= 6
	) ) );
	if ( ! $wanted ) {
		return new WP_Error( 'weekplan_no_weekday', '至少要勾一个星期几。' );
	}

	// 先把日子列出来,超限就整批拒绝,不做「排一半」这种半吊子结果
	$dates = array();
	for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
		if ( in_array( (int) $day->format( 'w' ), $wanted, true ) ) {
			$dates[] = $day->format( 'Y-m-d' );
		}
		if ( count( $dates ) > WEEKPLAN_BATCH_LIMIT ) {
			return new WP_Error(
				'weekplan_too_many',
				sprintf( '一次最多排 %d 天,当前区间超了。缩短区间或少勾几个星期几。', WEEKPLAN_BATCH_LIMIT )
			);
		}
	}
	if ( ! $dates ) {
		return new WP_Error( 'weekplan_no_dates', '这个区间里没有勾选的星期几。' );
	}

	$table = weekplan_schedule_table();

	// 已经排过的日子
	$existing = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT plan_date FROM $table WHERE todo_id = %d AND plan_date BETWEEN %s AND %s",
			$todo_id,
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' )
		)
	);

	// 每天当前的最大 position,批量算一次,不要一天一次查询
	$max = array();
	foreach ( $wpdb->get_results( $wpdb->prepare(
		"SELECT plan_date, MAX(position) AS m FROM $table WHERE plan_date BETWEEN %s AND %s GROUP BY plan_date",
		$start->format( 'Y-m-d' ),
		$end->format( 'Y-m-d' )
	) ) as $row ) {
		$max[ $row->plan_date ] = (int) $row->m;
	}

	$fresh = array_values( array_diff( $dates, $existing ) );
	if ( ! $fresh ) {
		return array(
			'added'   => 0,
			'skipped' => count( $dates ),
		);
	}

	$values = array();
	$args   = array();
	foreach ( $fresh as $date ) {
		$position = isset( $max[ $date ] ) ? $max[ $date ] + 1 : 0;
		$max[ $date ] = $position;

		$values[] = '(%d, %s, 0, %d)';
		array_push( $args, $todo_id, $date, $position );
	}

	$sql = "INSERT INTO $table (todo_id, plan_date, done, position) VALUES " . implode( ', ', $values );
	$wpdb->query( $wpdb->prepare( $sql, $args ) );

	return array(
		'added'   => count( $fresh ),
		'skipped' => count( $dates ) - count( $fresh ),
	);
}

/** 整体顺延一次最多挪多少天。 */
const WEEKPLAN_SHIFT_LIMIT = 365;

/**
 * 把一个待办的排期整体挪 N 天(负数是提前)。
 *
 * 只挪**没做完**的那些。已经打过勾的日子是发生过的事实,顺延会把历史改掉 ——
 * 「我落后了,把还没做的往后推」才是这个操作的本意。
 *
 * 放在右键菜单而不是拖拽上:拖拽必须是所见即所得的单格操作,否则一次误拖
 * 就能打乱三个月的排期。
 *
 * @return array{shifted:int}|WP_Error
 */
function weekplan_shift_todo( int $todo_id, int $days ): array|WP_Error {
	global $wpdb;

	if ( null === weekplan_todo_summary( $todo_id ) ) {
		return new WP_Error( 'weekplan_no_todo', '待办不存在。' );
	}
	if ( 0 === $days ) {
		return array( 'shifted' => 0 );
	}
	if ( abs( $days ) > WEEKPLAN_SHIFT_LIMIT ) {
		return new WP_Error(
			'weekplan_shift_too_far',
			sprintf( '一次最多挪 %d 天。', WEEKPLAN_SHIFT_LIMIT )
		);
	}

	$table = weekplan_schedule_table();

	// (todo_id, plan_date) 是唯一键,而 MySQL 是逐行更新的:往后挪时如果先动
	// 早的那一行,它会撞上还没挪的晚的那一行。按方向排序更新可以避开。
	$order = $days > 0 ? 'DESC' : 'ASC';

	$affected = $wpdb->query(
		$wpdb->prepare(
			"UPDATE $table
			    SET plan_date = DATE_ADD(plan_date, INTERVAL %d DAY)
			  WHERE todo_id = %d AND done = 0
			  ORDER BY plan_date $order",
			$days,
			$todo_id
		)
	);

	return array( 'shifted' => (int) $affected );
}
