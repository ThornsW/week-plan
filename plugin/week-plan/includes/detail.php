<?php
/**
 * 右栏详情。
 *
 * 用常驻侧栏而不是弹层:编辑和排期是交替进行的动作,弹层每次遮挡整个
 * 日程表会打断节奏。
 *
 * 每个字段各自保存(改完即存),没有「保存」按钮 —— 这是个人工具,
 * 多一次点击就是多一次摩擦。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function weekplan_render_detail_empty(): void {
	?>
	<div class="wp-group wp-detail">
		<div class="wp-group-head">详情</div>
		<p class="weekplan-empty">点一条待办,在这里编辑。</p>
	</div>
	<?php
}

function weekplan_render_detail( int $todo_id ): void {
	$todo = weekplan_todo_summary( $todo_id );
	if ( null === $todo ) {
		weekplan_render_detail_empty();
		return;
	}

	$post      = get_post( $todo_id );
	$subtasks  = weekplan_get_subtasks( $todo_id );
	$all_tags  = weekplan_all_tags();
	$tag_names = wp_list_pluck( $todo['tags'], 'name' );
	$dates     = weekplan_todo_dates( $todo_id );
	?>
	<div class="wp-group wp-detail" data-todo="<?php echo esc_attr( (string) $todo_id ); ?>">
		<div class="wp-group-head">
			详情
			<button type="button" class="wp-del-todo" title="删除这条待办">删除</button>
		</div>

		<div class="wp-group-body wp-detail-body">
			<label class="wp-field">
				<span class="wp-label">标题</span>
				<input type="text" class="wp-input" data-field="title"
					value="<?php echo esc_attr( $todo['title'] ); ?>">
			</label>

			<button type="button" class="wp-finish<?php echo $todo['finished'] ? ' is-on' : ''; ?>" data-field="finished">
				<?php echo $todo['finished'] ? '已结项 · 点击恢复' : '标记为结项'; ?>
			</button>

			<div class="wp-field-row">
				<label class="wp-field">
					<span class="wp-label">截止日</span>
					<input type="date" class="wp-input" data-field="due_date"
						value="<?php echo esc_attr( $todo['due_date'] ); ?>">
				</label>
				<label class="wp-field">
					<span class="wp-label">优先级</span>
					<select class="wp-input" data-field="priority">
						<?php foreach ( array( 0 => '低', 1 => '中', 2 => '高' ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>"
								<?php selected( $todo['priority'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<div class="wp-field">
				<span class="wp-label">标签</span>
				<div class="wp-tag-editor">
					<div class="wp-tag-chips">
						<?php foreach ( $todo['tags'] as $tag ) : ?>
							<span class="wp-tag wp-tag-chip" style="--wp-tag: <?php echo esc_attr( $tag['color'] ); ?>"
								data-name="<?php echo esc_attr( $tag['name'] ); ?>">
								<?php echo esc_html( $tag['name'] ); ?><button type="button" class="wp-tag-x" title="移除">×</button>
							</span>
						<?php endforeach; ?>
					</div>
					<input type="text" class="wp-input wp-tag-input" list="weekplan-taglist"
						placeholder="打字加标签,回车确认">
					<datalist id="weekplan-taglist">
						<?php foreach ( $all_tags as $tag ) : ?>
							<option value="<?php echo esc_attr( $tag['name'] ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<input type="hidden" data-field="tags" value="<?php echo esc_attr( implode( "\n", $tag_names ) ); ?>">
				</div>
			</div>

			<div class="wp-field">
				<span class="wp-label">子任务<?php echo $subtasks ? ' · ' . count( array_filter( $subtasks, static fn( $s ) => $s['done'] ) ) . '/' . count( $subtasks ) : ''; ?></span>
				<ul class="wp-subtasks">
					<?php foreach ( $subtasks as $index => $item ) : ?>
						<li class="wp-subtask<?php echo $item['done'] ? ' is-done' : ''; ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">
							<button type="button" class="wp-check wp-sub-check" aria-pressed="<?php echo $item['done'] ? 'true' : 'false'; ?>"></button>
							<span class="wp-subtask-text"><?php echo esc_html( $item['text'] ); ?></span>
							<button type="button" class="wp-sub-x" title="删除">×</button>
						</li>
					<?php endforeach; ?>
				</ul>
				<input type="text" class="wp-input wp-sub-input" placeholder="加一条子任务,回车确认">
			</div>

			<label class="wp-field">
				<span class="wp-label">笔记 · Markdown</span>
				<textarea class="wp-input wp-note" data-field="note" rows="7"><?php echo esc_textarea( $post->post_content ); ?></textarea>
			</label>

			<div class="wp-field">
				<span class="wp-label">排期</span>
				<p class="wp-detail-progress"><?php echo esc_html( weekplan_progress_label( $todo ) ); ?></p>
				<?php if ( $dates ) : ?>
					<div class="wp-datechips">
						<?php foreach ( $dates as $row ) : ?>
							<span class="wp-datechip<?php echo $row['done'] ? ' is-done' : ''; ?>"
								style="--wp-accent: <?php echo esc_attr( $todo['accent'] ); ?>">
								<?php echo esc_html( $row['label'] ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="weekplan-empty">还没排期。把它从左栏拖进某一天。</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/** 这条待办排了哪些天,按日期升序。 */
function weekplan_todo_dates( int $todo_id ): array {
	global $wpdb;
	$table = weekplan_schedule_table();

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT plan_date, done FROM $table WHERE todo_id = %d ORDER BY plan_date ASC", $todo_id )
	);

	$out = array();
	foreach ( $rows as $row ) {
		$date  = weekplan_parse_date( $row->plan_date );
		$out[] = array(
			'date'  => $row->plan_date,
			'label' => $date ? $date->format( 'n/j' ) : $row->plan_date,
			'done'  => (bool) $row->done,
		);
	}
	return $out;
}
