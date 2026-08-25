<?php
/**
 * 后台页面:三栏骨架。
 *
 * 界面放 wp-admin 而不是博客前台,是因为日程表需要整屏宽度和三栏布局,
 * 放在前台会被主题的正文容器限宽;后台页面是空白画布,主题在那里没有
 * 影响力,而且天生在登录之后。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function weekplan_register_admin_page(): void {
	add_menu_page(
		'周计划',
		'周计划',
		'manage_options',
		WEEKPLAN_MENU_SLUG,
		'weekplan_render_admin_page',
		'dashicons-calendar-alt',
		3
	);
}

function weekplan_enqueue_admin_assets( string $hook ): void {
	if ( 'toplevel_page_' . WEEKPLAN_MENU_SLUG !== $hook ) {
		return;
	}
	wp_enqueue_style( 'weekplan-admin', WEEKPLAN_URL . 'assets/admin.css', array(), WEEKPLAN_VERSION );
	wp_enqueue_script( 'weekplan-admin', WEEKPLAN_URL . 'assets/admin.js', array(), WEEKPLAN_VERSION, true );
}

/** 从地址栏读要显示的那一周,没有或不合法就用本周。 */
function weekplan_requested_week_start(): DateTimeImmutable {
	$raw = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : '';

	if ( '' !== $raw ) {
		$parsed = weekplan_parse_date( $raw );
		if ( null !== $parsed ) {
			return weekplan_week_start_of( $parsed );
		}
	}

	return weekplan_week_start_of( weekplan_today() );
}

function weekplan_week_url( DateTimeImmutable $week_start ): string {
	return add_query_arg(
		array(
			'page' => WEEKPLAN_MENU_SLUG,
			'week' => $week_start->format( 'Y-m-d' ),
		),
		admin_url( 'admin.php' )
	);
}

function weekplan_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足。' );
	}

	$week_start   = weekplan_requested_week_start();
	$week_end     = $week_start->modify( '+6 days' );
	$days         = weekplan_week_days( $week_start );
	$this_week    = weekplan_week_start_of( weekplan_today() );
	$is_this_week = $week_start->format( 'Y-m-d' ) === $this_week->format( 'Y-m-d' );
	?>
	<div class="wrap weekplan">
		<div class="weekplan-bar">
			<h1 class="weekplan-title">周计划</h1>
			<span class="weekplan-range">
				<?php echo esc_html( $week_start->format( 'Y 年 n 月 j 日' ) . ' — ' . $week_end->format( 'n 月 j 日' ) ); ?>
			</span>
			<span class="weekplan-nav">
				<a class="button" href="<?php echo esc_url( weekplan_week_url( $week_start->modify( '-7 days' ) ) ); ?>">← 上一周</a>
				<a class="button" href="<?php echo esc_url( weekplan_week_url( $week_start->modify( '+7 days' ) ) ); ?>">下一周 →</a>
				<?php if ( ! $is_this_week ) : ?>
					<a class="button" href="<?php echo esc_url( weekplan_week_url( $this_week ) ); ?>">回到本周</a>
				<?php endif; ?>
				<a class="button button-primary" href="<?php echo esc_url( weekplan_week_url( $this_week->modify( '+7 days' ) ) ); ?>">规划下周</a>
			</span>
		</div>

		<div class="weekplan-layout">
			<aside class="weekplan-side">
				<div class="weekplan-panel-head">待办</div>
				<p class="weekplan-empty">还没有待办。</p>
			</aside>

			<main class="weekplan-week">
				<?php foreach ( $days as $day ) : ?>
					<section class="weekplan-day<?php echo $day['is_today'] ? ' is-today' : ''; ?><?php echo $day['is_past'] ? ' is-past' : ''; ?>"
						data-date="<?php echo esc_attr( $day['date'] ); ?>">
						<header class="weekplan-day-head">
							<span class="weekplan-day-name"><?php echo esc_html( $day['label'] ); ?></span>
							<span class="weekplan-day-date"><?php echo esc_html( $day['day_label'] ); ?></span>
							<?php if ( $day['is_today'] ) : ?>
								<span class="weekplan-today-badge">今天</span>
							<?php endif; ?>
						</header>
						<div class="weekplan-day-body"></div>
					</section>
				<?php endforeach; ?>
			</main>

			<aside class="weekplan-detail">
				<div class="weekplan-panel-head">详情</div>
				<p class="weekplan-empty">选中一个待办后在这里编辑。</p>
			</aside>
		</div>
	</div>
	<?php
}
