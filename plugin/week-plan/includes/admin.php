<?php
/**
 * 后台页面。
 *
 * 界面放 wp-admin 而不是博客前台,是因为日程表需要整屏宽度和三栏布局,
 * 放在前台会被主题的正文容器限宽;后台页面是空白画布。
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
	wp_localize_script(
		'weekplan-admin',
		'weekplanData',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( WEEKPLAN_NONCE ),
		)
	);
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

/** 「已完成 12 天」—— 不给分母,因为计划总天数会变,分母看着像承诺。 */
function weekplan_progress_label( array $todo ): string {
	return $todo['done_days'] > 0 ? sprintf( '已完成 %d 天', $todo['done_days'] ) : '还没开始';
}

function weekplan_render_card( array $entry ): void {
	$todo    = $entry['todo'];
	$classes = 'wp-card';
	$classes .= $entry['done'] ? ' is-done' : '';
	$classes .= $todo['finished'] ? ' is-finished' : '';
	?>
	<article class="<?php echo esc_attr( $classes ); ?>"
		draggable="true"
		data-schedule="<?php echo esc_attr( (string) $entry['schedule_id'] ); ?>"
		data-todo="<?php echo esc_attr( (string) $todo['id'] ); ?>"
		data-date="<?php echo esc_attr( $entry['date'] ); ?>"
		style="--wp-accent: <?php echo esc_attr( $todo['accent'] ); ?>">
		<button type="button" class="wp-check" aria-pressed="<?php echo $entry['done'] ? 'true' : 'false'; ?>"
			title="<?php echo $entry['done'] ? '取消这一天的完成' : '标记这一天完成'; ?>"></button>
		<div class="wp-card-main">
			<div class="wp-card-title"><?php echo esc_html( $todo['title'] ); ?></div>
			<?php if ( $todo['tags'] || 2 === $todo['priority'] ) : ?>
				<div class="wp-card-meta">
					<?php if ( 2 === $todo['priority'] ) : ?>
						<span class="wp-flag" title="高优先级">!</span>
					<?php endif; ?>
					<?php foreach ( $todo['tags'] as $tag ) : ?>
						<span class="wp-tag" style="--wp-tag: <?php echo esc_attr( $tag['color'] ); ?>"><?php echo esc_html( $tag['name'] ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</article>
	<?php
}

function weekplan_render_sidebar_item( array $todo ): void {
	$bits = array( weekplan_progress_label( $todo ) );
	if ( '' !== $todo['due_date'] ) {
		$due = weekplan_parse_date( $todo['due_date'] );
		if ( $due ) {
			$bits[] = '截止 ' . $due->format( 'n/j' );
		}
	}
	?>
	<article class="wp-item" draggable="true"
		data-todo="<?php echo esc_attr( (string) $todo['id'] ); ?>"
		style="--wp-accent: <?php echo esc_attr( $todo['accent'] ); ?>">
		<div class="wp-item-title"><?php echo esc_html( $todo['title'] ); ?></div>
		<div class="wp-item-meta"><?php echo esc_html( implode( ' · ', $bits ) ); ?></div>
		<?php if ( $todo['tags'] ) : ?>
			<div class="wp-card-meta">
				<?php foreach ( $todo['tags'] as $tag ) : ?>
					<span class="wp-tag" style="--wp-tag: <?php echo esc_attr( $tag['color'] ); ?>"><?php echo esc_html( $tag['name'] ); ?></span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</article>
	<?php
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

	$schedule = weekplan_schedule_between( $week_start->format( 'Y-m-d' ), $week_end->format( 'Y-m-d' ) );
	$sidebar  = weekplan_sidebar_todos();
	// 逾期区只在看「今天所在的这一周」时出现,而且只汇总上一周。
	$overdue  = $is_this_week ? weekplan_overdue_last_week() : array();
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
			<aside class="weekplan-side" data-drop="sidebar">
				<?php if ( $overdue ) : ?>
					<details class="wp-group wp-overdue">
						<summary>逾期 · <?php echo count( $overdue ); ?> 条<span class="wp-hint">上周没做完</span></summary>
						<div class="wp-group-body">
							<?php foreach ( $overdue as $entry ) : ?>
								<?php weekplan_render_card( $entry ); ?>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>

				<div class="wp-group">
					<div class="wp-group-head">待办</div>
					<div class="wp-group-body">
						<?php if ( $sidebar['scheduled'] ) : ?>
							<?php foreach ( $sidebar['scheduled'] as $todo ) : ?>
								<?php weekplan_render_sidebar_item( $todo ); ?>
							<?php endforeach; ?>
						<?php else : ?>
							<p class="weekplan-empty">还没有排过期的待办。</p>
						<?php endif; ?>
					</div>
				</div>

				<details class="wp-group" open>
					<summary>未来计划 · <?php echo count( $sidebar['unscheduled'] ); ?> 条</summary>
					<div class="wp-group-body">
						<?php if ( $sidebar['unscheduled'] ) : ?>
							<?php foreach ( $sidebar['unscheduled'] as $todo ) : ?>
								<?php weekplan_render_sidebar_item( $todo ); ?>
							<?php endforeach; ?>
						<?php else : ?>
							<p class="weekplan-empty">空的。</p>
						<?php endif; ?>
					</div>
				</details>
			</aside>

			<main class="weekplan-week">
				<?php foreach ( $days as $day ) : ?>
					<?php $entries = $schedule[ $day['date'] ] ?? array(); ?>
					<section class="weekplan-day<?php echo $day['is_today'] ? ' is-today' : ''; ?><?php echo $day['is_past'] ? ' is-past' : ''; ?>">
						<header class="weekplan-day-head">
							<span class="weekplan-day-name"><?php echo esc_html( $day['label'] ); ?></span>
							<span class="weekplan-day-date"><?php echo esc_html( $day['day_label'] ); ?></span>
							<?php if ( $day['is_today'] ) : ?>
								<span class="weekplan-today-badge">今天</span>
							<?php endif; ?>
							<span class="weekplan-day-count"><?php echo $entries ? esc_html( count( $entries ) . ' 项' ) : ''; ?></span>
						</header>
						<div class="weekplan-day-body" data-drop="day" data-date="<?php echo esc_attr( $day['date'] ); ?>">
							<?php foreach ( $entries as $entry ) : ?>
								<?php weekplan_render_card( $entry ); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</main>

			<aside class="weekplan-detail">
				<div class="wp-group-head">详情</div>
				<p class="weekplan-empty">详情编辑在下一步做。现在先验证拖拽和配色。</p>
			</aside>
		</div>
	</div>
	<?php
}

/** 新增排期后要把卡片塞回页面,HTML 由服务器渲染,前后端不各写一份。 */
function weekplan_card_html( array $entry ): string {
	ob_start();
	weekplan_render_card( $entry );
	return (string) ob_get_clean();
}
