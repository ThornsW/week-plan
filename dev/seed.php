<?php
/**
 * 往开发站填一批假数据,用来验证拖拽手感和配色。
 * 只在开发环境用:wp eval-file dev/seed.php --path=/root/wp-dev --allow-root
 *
 * 会先清空所有待办和排期,是破坏性的。
 */

if ( ! defined( 'WEEKPLAN_POST_TYPE' ) ) {
	WP_CLI::error( '插件没启用。' );
}

global $wpdb;

// 清空
foreach ( get_posts( array( 'post_type' => WEEKPLAN_POST_TYPE, 'posts_per_page' => -1, 'post_status' => 'any' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
$wpdb->query( 'TRUNCATE TABLE ' . weekplan_schedule_table() );
foreach ( get_terms( array( 'taxonomy' => WEEKPLAN_TAXONOMY, 'hide_empty' => false ) ) as $t ) {
	wp_delete_term( $t->term_id, WEEKPLAN_TAXONOMY );
}

// 标签
$tags = array(
	'学习' => '#7AA2F7',
	'代码' => '#10B981',
	'阅读' => '#F59E0B',
	'生活' => '#EC4899',
);
$tag_ids = array();
foreach ( $tags as $name => $color ) {
	$term = wp_insert_term( $name, WEEKPLAN_TAXONOMY );
	$tag_ids[ $name ] = $term['term_id'];
	update_term_meta( $term['term_id'], '_weekplan_color', $color );
}

$today      = weekplan_today();
$week_start = weekplan_week_start_of( $today );
$d          = static fn( $offset ) => $week_start->modify( "{$offset} days" )->format( 'Y-m-d' );

/**
 * @param string $title
 * @param array  $opts tags, priority, due, plans(=[[偏移天数, 是否完成], ...]), finished
 */
$make = static function ( string $title, array $opts ) use ( $tag_ids, $d ) {
	$id = wp_insert_post(
		array(
			'post_type'    => WEEKPLAN_POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $opts['note'] ?? '',
		)
	);

	if ( ! empty( $opts['tags'] ) ) {
		wp_set_object_terms( $id, array_map( static fn( $n ) => (int) $tag_ids[ $n ], $opts['tags'] ), WEEKPLAN_TAXONOMY );
	}
	update_post_meta( $id, '_weekplan_priority', $opts['priority'] ?? 1 );
	if ( ! empty( $opts['due'] ) ) {
		update_post_meta( $id, '_weekplan_due_date', $d( $opts['due'] ) );
	}
	if ( ! empty( $opts['finished'] ) ) {
		update_post_meta( $id, '_weekplan_done', 1 );
	}

	foreach ( $opts['plans'] ?? array() as $plan ) {
		list( $offset, $done ) = $plan;
		$sid = weekplan_add_schedule( $id, $d( $offset ) );
		if ( $done && ! is_wp_error( $sid ) ) {
			weekplan_toggle_done( $sid );
		}
	}

	return $id;
};

// 长期计划:横跨三周,前面的日子大多做完了 —— 用来看「点亮」堆起来的效果
$long = array();
foreach ( range( -14, 6 ) as $i ) {
	if ( 0 === $i % 3 ) {
		continue; // 故意不连续
	}
	$long[] = array( $i, $i < 0 && 0 !== $i % 4 );
}
$make( '背考研单词 30 个', array( 'tags' => array( '学习' ), 'plans' => $long ) );

$make( '重构 Memo 的数据层', array( 'tags' => array( '代码' ), 'priority' => 2, 'due' => 4, 'plans' => array( array( 1, true ), array( 2, false ), array( 4, false ) ) ) );
$make( '读《设计模式》第 4 章', array( 'tags' => array( '阅读' ), 'plans' => array( array( 2, false ), array( 3, false ) ) ) );
$make( '把阳台的花搬进来', array( 'tags' => array( '生活' ), 'plans' => array( array( 0, true ) ) ) );
$make( '整理这一周的读书笔记', array( 'tags' => array( '阅读', '学习' ), 'plans' => array( array( 6, false ) ) ) );
$make( '交房租', array( 'tags' => array( '生活' ), 'priority' => 2, 'plans' => array( array( 5, false ) ) ) );

// 上周没做完的,进逾期区
$make( '给显示器换个支架', array( 'tags' => array( '生活' ), 'plans' => array( array( -3, false ), array( -5, false ) ) ) );

// 已结项的,看角标
$make( '备份旧的 Memo 数据库', array( 'tags' => array( '代码' ), 'finished' => true, 'plans' => array( array( -2, true ), array( 3, true ) ) ) );

// 未排期的,落在「未来计划」里
$make( '学一遍 WordPress 的钩子机制', array( 'tags' => array( '学习' ), 'due' => 12 ) );
$make( '换掉客厅的灯泡', array( 'tags' => array( '生活' ) ) );
$make( '整理硬盘里的论文', array( 'tags' => array( '阅读' ), 'priority' => 0, 'due' => 30 ) );
$make( '给博客写一篇年终总结', array( 'tags' => array( '生活' ) ) );

WP_CLI::success( sprintf(
	'已生成 %d 条待办、%d 条排期。',
	count( get_posts( array( 'post_type' => WEEKPLAN_POST_TYPE, 'posts_per_page' => -1 ) ) ),
	(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . weekplan_schedule_table() )
) );
