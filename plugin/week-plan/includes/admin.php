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
	wp_add_inline_style( 'weekplan-admin', weekplan_font_face_css() );
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

/**
 * 界面用博客那套 Maple Mono NF CN,和站点视觉统一。
 *
 * 字体文件在 uploads 里(两个 woff2 共约 12 MB),是全站共享的,不属于任何
 * 主题 —— 所以这里只引用,不往插件里打包一份。生产环境上浏览器逛博客时
 * 早就缓存过了。文件不在就不输出 @font-face,自动落回后面的备用字体。
 */
function weekplan_font_face_css(): string {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return '';
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'fonts/';
	$url = trailingslashit( $uploads['baseurl'] ) . 'fonts/';

	$faces = array(
		'MapleMono-NF-CN-Regular.woff2' => 400,
		'MapleMono-NF-CN-Bold.woff2'    => 700,
	);

	$css = '';
	foreach ( $faces as $file => $weight ) {
		if ( ! file_exists( $dir . $file ) ) {
			continue;
		}
		$css .= sprintf(
			"@font-face{font-family:'Maple Mono NF CN';src:url('%s') format('woff2');font-weight:%d;font-style:normal;font-display:swap;}",
			esc_url( $url . $file ),
			$weight
		);
	}

	return $css;
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

/** 翻周时保留当前的标签筛选,不然翻一页筛选就丢了。 */
function weekplan_week_url( DateTimeImmutable $week_start ): string {
	$args = array(
		'page' => WEEKPLAN_MENU_SLUG,
		'week' => $week_start->format( 'Y-m-d' ),
	);

	$tag = weekplan_requested_tag();
	if ( $tag > 0 ) {
		$args['tag'] = $tag;
	}

	return add_query_arg( $args, admin_url( 'admin.php' ) );
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
	// data-tags 是给左栏二级分组用的。id 的顺序和下面小签的顺序一一对应,
	// 前端按下标取名字和颜色,不必再往页面里塞一份标签表。
	$tag_ids = implode( ',', array_column( $todo['tags'], 'id' ) );
	?>
	<article class="wp-item" draggable="true"
		data-todo="<?php echo esc_attr( (string) $todo['id'] ); ?>"
		data-tags="<?php echo esc_attr( $tag_ids ); ?>"
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

/**
 * 左栏两组各自的「按标签分组」开关。
 *
 * 开关状态存在浏览器里,服务器不知道,所以这里一律渲染成关闭态,由前端在
 * 加载时按 localStorage 拨过去。开关只影响这一栏怎么排,和地址栏上的标签
 * 筛选是两回事。
 */
function weekplan_render_split_toggle( string $key ): void {
	?>
	<button type="button" class="wp-split" data-split="<?php echo esc_attr( $key ); ?>"
		aria-pressed="false" title="按标签分组显示">分组</button>
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

	// 标签筛选:先算出这个标签下的待办 id,后面三个查询都按它过滤。
	$active_tag = weekplan_requested_tag();
	$only       = $active_tag ? weekplan_todo_ids_with_tag( $active_tag ) : null;

	$schedule = weekplan_schedule_between( $week_start->format( 'Y-m-d' ), $week_end->format( 'Y-m-d' ), $only );
	$sidebar  = weekplan_sidebar_todos( $only );
	// 逾期区只在看「今天所在的这一周」时出现,而且只汇总上一周。
	$overdue  = $is_this_week ? weekplan_overdue_last_week( $only ) : array();
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

				<?php weekplan_render_tag_group( $week_start, $active_tag ); ?>

				<div class="wp-group">
					<div class="wp-group-head">
						待办
						<?php weekplan_render_split_toggle( 'scheduled' ); ?>
						<button type="button" class="wp-new-todo" title="新建待办">+ 新建</button>
					</div>
					<div class="wp-group-body" data-split="scheduled">
						<div class="wp-flat">
							<?php if ( $sidebar['scheduled'] ) : ?>
								<?php foreach ( $sidebar['scheduled'] as $todo ) : ?>
									<?php weekplan_render_sidebar_item( $todo ); ?>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="weekplan-empty">还没有排过期的待办。</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<details class="wp-group" open>
					<summary>未来计划 · <?php echo count( $sidebar['unscheduled'] ); ?> 条<?php weekplan_render_split_toggle( 'unscheduled' ); ?></summary>
					<div class="wp-group-body" data-split="unscheduled">
						<div class="wp-flat">
							<?php if ( $sidebar['unscheduled'] ) : ?>
								<?php foreach ( $sidebar['unscheduled'] as $todo ) : ?>
									<?php weekplan_render_sidebar_item( $todo ); ?>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="weekplan-empty">空的。</p>
							<?php endif; ?>
						</div>
					</div>
				</details>
			</aside>

			<main class="weekplan-week">
				<?php foreach ( $days as $day ) : ?>
					<?php $entries = $schedule[ $day['date'] ] ?? array(); ?>
					<section class="weekplan-day<?php echo $day['is_today'] ? ' is-today' : ''; ?><?php echo $day['is_past'] ? ' is-past' : ''; ?>">
						<header class="weekplan-day-head">
							<span class="wp-daynum"><?php echo esc_html( $day['day_num'] ); ?></span>
							<span class="wp-daystack">
								<span class="wp-weekday"><?php echo esc_html( $day['weekday'] ); ?></span>
								<?php if ( $day['show_month'] ) : ?>
									<span class="wp-month"><?php echo esc_html( $day['month'] ); ?></span>
								<?php endif; ?>
							</span>
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

			<aside class="weekplan-detail" id="weekplan-detail">
				<?php weekplan_render_detail_empty(); ?>
			</aside>
		</div>

		<?php weekplan_render_batch_dialog(); ?>
	</div>
	<?php
}

/** 新增排期后要把卡片塞回页面,HTML 由服务器渲染,前后端不各写一份。 */
function weekplan_card_html( array $entry ): string {
	ob_start();
	weekplan_render_card( $entry );
	return (string) ob_get_clean();
}

/** 当前生效的标签筛选,没有或不合法就返回 0。 */
function weekplan_requested_tag(): int {
	$raw = isset( $_GET['tag'] ) ? (int) $_GET['tag'] : 0;
	if ( $raw <= 0 ) {
		return 0;
	}
	$term = get_term( $raw, WEEKPLAN_TAXONOMY );
	return ( $term && ! is_wp_error( $term ) ) ? $raw : 0;
}

/** 保留当前周,只换标签筛选。 */
function weekplan_tag_url( DateTimeImmutable $week_start, int $tag_id ): string {
	$args = array(
		'page' => WEEKPLAN_MENU_SLUG,
		'week' => $week_start->format( 'Y-m-d' ),
	);
	if ( $tag_id > 0 ) {
		$args['tag'] = $tag_id;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * 侧栏的标签区。
 *
 * 标签在这里同时是筛选器和管理入口 —— 点名字筛选,点铅笔就地改名改色。
 * 筛选走地址栏参数而不是前端隐藏,这样刷新后还在,每天的「N 项」也不会
 * 和看到的卡片对不上。
 */
function weekplan_render_tag_group( DateTimeImmutable $week_start, int $active ): void {
	$tags = weekplan_all_tags();
	?>
	<details class="wp-group wp-taggroup" <?php echo $active ? 'open' : ''; ?>>
		<summary>标签 · <?php echo count( $tags ); ?> 个<?php if ( $active ) : ?><span class="wp-hint">筛选中</span><?php endif; ?></summary>
		<div class="wp-group-body">
			<?php if ( ! $tags ) : ?>
				<p class="weekplan-empty">还没有标签。在详情栏里打字就能建。</p>
			<?php else : ?>
				<?php if ( $active ) : ?>
					<a class="wp-tagrow wp-tagrow-clear" href="<?php echo esc_url( weekplan_tag_url( $week_start, 0 ) ); ?>">× 取消筛选</a>
				<?php endif; ?>
				<?php foreach ( $tags as $tag ) : ?>
					<div class="wp-tagrow<?php echo $active === $tag['id'] ? ' is-active' : ''; ?>"
						data-term="<?php echo esc_attr( (string) $tag['id'] ); ?>"
						data-name="<?php echo esc_attr( $tag['name'] ); ?>"
						data-color="<?php echo esc_attr( $tag['color'] ); ?>">
						<a class="wp-tagrow-link" href="<?php echo esc_url( weekplan_tag_url( $week_start, $active === $tag['id'] ? 0 : $tag['id'] ) ); ?>">
							<span class="wp-dot" style="--wp-tag: <?php echo esc_attr( $tag['color'] ); ?>"></span>
							<span class="wp-tagrow-name"><?php echo esc_html( $tag['name'] ); ?></span>
							<span class="wp-tagrow-count"><?php echo esc_html( (string) $tag['count'] ); ?></span>
						</a>
						<button type="button" class="wp-tag-edit" title="改名 / 改色">✎</button>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</details>
	<?php
}

/**
 * 批量排期对话框。
 *
 * 用原生 <dialog>,不引任何库 —— 它自带模态遮罩、Esc 关闭和焦点管理。
 * 整页只渲染一份,详情栏换待办时只改里面的 todo_id。
 */
function weekplan_render_batch_dialog(): void {
	$today = weekplan_today();
	?>
	<dialog class="wp-dialog" id="weekplan-batch">
		<form method="dialog" class="wp-dialog-form">
			<h2 class="wp-dialog-title">批量排期</h2>
			<p class="wp-dialog-sub" id="weekplan-batch-for"></p>

			<div class="wp-field-row">
				<label class="wp-field">
					<span class="wp-label">从</span>
					<input type="date" class="wp-input" id="weekplan-batch-from"
						value="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>">
				</label>
				<label class="wp-field">
					<span class="wp-label">到</span>
					<input type="date" class="wp-input" id="weekplan-batch-to"
						value="<?php echo esc_attr( $today->modify( '+29 days' )->format( 'Y-m-d' ) ); ?>">
				</label>
			</div>

			<div class="wp-field">
				<span class="wp-label">星期几</span>
				<div class="wp-weekpick">
					<?php
					// 按站点的周起始日排,和周视图一致
					$start = weekplan_start_of_week();
					for ( $i = 0; $i < 7; $i++ ) :
						$dow = ( $start + $i ) % 7;
						?>
						<label class="wp-weekpick-item">
							<input type="checkbox" value="<?php echo esc_attr( (string) $dow ); ?>" checked>
							<span><?php echo esc_html( WEEKPLAN_WEEKDAY_LABELS[ $dow ] ); ?></span>
						</label>
					<?php endfor; ?>
				</div>
			</div>

			<p class="wp-dialog-preview" id="weekplan-batch-preview"></p>

			<div class="wp-dialog-actions">
				<button type="button" class="button" id="weekplan-batch-cancel">取消</button>
				<button type="button" class="button button-primary" id="weekplan-batch-go">排上</button>
			</div>
		</form>
	</dialog>
	<?php
}
