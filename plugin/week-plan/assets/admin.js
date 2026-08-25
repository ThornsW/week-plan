/* 拖拽和交互在原型阶段实现。这里先占位,确认资源加载链路是通的。 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var days = document.querySelectorAll( '.weekplan-day' );
		if ( days.length !== 7 ) {
			console.warn( '[week-plan] 期望 7 个日期分组,实际', days.length );
		}
	} );
}() );
