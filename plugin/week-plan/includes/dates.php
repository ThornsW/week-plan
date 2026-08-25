<?php
/**
 * 周的边界计算。
 *
 * 「今天」必须按站点时区算,不能用服务器的 UTC 时间 —— 差八小时会让
 * 晚上八点之后的一切都排到明天去。周起始日复用 WordPress 内置的
 * start_of_week 选项,不另设开关。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WEEKPLAN_WEEKDAY_LABELS = array( '周日', '周一', '周二', '周三', '周四', '周五', '周六' );

function weekplan_today(): DateTimeImmutable {
	return new DateTimeImmutable( 'today', wp_timezone() );
}

/** 0 = 周日, 1 = 周一 …… 6 = 周六 */
function weekplan_start_of_week(): int {
	return (int) get_option( 'start_of_week', 1 );
}

/** 给定某一天,返回它所在那一周的第一天。 */
function weekplan_week_start_of( DateTimeImmutable $day ): DateTimeImmutable {
	$dow  = (int) $day->format( 'w' );
	$back = ( $dow - weekplan_start_of_week() + 7 ) % 7;
	return $day->modify( "-{$back} days" );
}

/** 把 'YYYY-MM-DD' 解析成站点时区的日期;格式不对或日期不存在返回 null。 */
function weekplan_parse_date( string $value ): ?DateTimeImmutable {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return null;
	}

	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
	if ( false === $date ) {
		return null;
	}

	// 正则只保证「两位数字」,13 月 99 日照样通过,而 PHP 会把它静默进位到
	// 下一年(2026-13-99 会变成 2027-04-09)。原样格式化回去比对,是唯一
	// 能挡住进位的办法。
	return $date->format( 'Y-m-d' ) === $value ? $date : null;
}

/**
 * 展开一周的 7 天。
 *
 * @return array<int, array{date:string, label:string, day_label:string, is_today:bool, is_past:bool}>
 */
function weekplan_week_days( DateTimeImmutable $week_start ): array {
	$today = weekplan_today();
	$days  = array();

	for ( $i = 0; $i < 7; $i++ ) {
		$day    = $week_start->modify( "+{$i} days" );
		$days[] = array(
			'date'      => $day->format( 'Y-m-d' ),
			'label'     => WEEKPLAN_WEEKDAY_LABELS[ (int) $day->format( 'w' ) ],
			'day_label' => $day->format( 'n/j' ),
			'is_today'  => $day->format( 'Y-m-d' ) === $today->format( 'Y-m-d' ),
			'is_past'   => $day < $today,
		);
	}

	return $days;
}
