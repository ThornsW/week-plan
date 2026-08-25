<?php
/**
 * Plugin Name: 周计划
 * Description: 以周为单位的计划表。把待办排进具体的某一天,长期计划按天累计。界面在「周计划」菜单里。
 * Version:     0.1.0
 * Author:      ThornsW
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WEEKPLAN_VERSION    = '0.1.0';
const WEEKPLAN_DB_VERSION = 1;
const WEEKPLAN_POST_TYPE  = 'weekplan_todo';
const WEEKPLAN_TAXONOMY   = 'weekplan_tag';
const WEEKPLAN_MENU_SLUG  = 'week-plan';

define( 'WEEKPLAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEEKPLAN_URL', plugin_dir_url( __FILE__ ) );

require_once WEEKPLAN_DIR . 'includes/schema.php';
require_once WEEKPLAN_DIR . 'includes/types.php';
require_once WEEKPLAN_DIR . 'includes/dates.php';
require_once WEEKPLAN_DIR . 'includes/schedule.php';
require_once WEEKPLAN_DIR . 'includes/todo.php';
require_once WEEKPLAN_DIR . 'includes/markdown.php';
require_once WEEKPLAN_DIR . 'includes/ajax.php';
require_once WEEKPLAN_DIR . 'includes/admin.php';
require_once WEEKPLAN_DIR . 'includes/detail.php';

/**
 * 内容类型和分类法在前后台都要注册。
 * 只在后台注册会让 wp-cli 和任何后台之外的写入被 WordPress 拒绝。
 */
add_action( 'init', 'weekplan_register_types' );

/** 数据库结构升级:插件文件更新后第一次加载时补上。 */
add_action( 'plugins_loaded', 'weekplan_maybe_upgrade_schema' );

/**
 * 界面相关的一切只在后台挂钩子。
 * 插件不注册任何前台钩子,博客前台不受它影响 —— 即使这里出致命错误,
 * 访客看到的站点也不会白屏。
 */
if ( is_admin() ) {
	add_action( 'admin_menu', 'weekplan_register_admin_page' );
	add_action( 'admin_enqueue_scripts', 'weekplan_enqueue_admin_assets' );
	weekplan_register_ajax();
}

register_activation_hook( __FILE__, 'weekplan_activate' );

function weekplan_activate(): void {
	weekplan_register_types();
	weekplan_install_schema();
}
