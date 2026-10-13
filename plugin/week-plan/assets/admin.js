/*
 * 四种拖拽手势:
 *   侧栏 → 某一天       新增一条排期,待办本身留在侧栏
 *   某一天 → 另一天     只移动被拖的这一天,不是整体平移
 *   某一天 → 侧栏       删掉这一天的排期
 *   同一天内上下拖      调整当天顺序
 *
 * 界面先动、请求后发。手上刚做完的动作立刻看到结果,失败了整页重载回到
 * 服务器的真实状态 —— 拖拽的手感经不起等一个来回。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};
	var dragged = null;   // { el, todoId, scheduleId, date }
	var insertBefore = null;

	/* ---------- 与服务器通信 ---------- */

	function post( action, payload ) {
		var body = new URLSearchParams();
		body.set( 'action', 'weekplan_' + action );
		body.set( 'nonce', config.nonce );

		Object.keys( payload ).forEach( function ( key ) {
			var value = payload[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.set( key, value );
			}
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || '请求失败' );
			}
			return json.data || {};
		} );
	}

	function fail( error ) {
		window.console && console.error( '[week-plan]', error );
		window.alert( '操作没成功:' + error.message + '\n页面将重新载入,回到服务器上的真实状态。' );
		window.location.reload();
	}

	/* ---------- 视图上的零碎更新 ---------- */

	function refreshCounts() {
		document.querySelectorAll( '.weekplan-day' ).forEach( function ( day ) {
			var count = day.querySelectorAll( '.wp-card' ).length;
			var label = day.querySelector( '.weekplan-day-count' );
			if ( label ) {
				label.textContent = count ? count + ' 项' : '';
			}
		} );
	}

	/* 逾期区的「N 条」是服务端渲染的,卡片被拖走后要自己跟上;空了整块收掉。 */
	function refreshOverdue() {
		var box = document.querySelector( '.wp-overdue' );
		if ( ! box ) {
			return;
		}
		var count = box.querySelectorAll( '.wp-card' ).length;
		if ( ! count ) {
			box.remove();
			return;
		}
		var label = box.querySelector( '.wp-overdue-count' );
		if ( label ) {
			label.textContent = count;
		}
	}

	/*
	 * 目标日已经有同一个待办时,服务器把两条并成一条,被拖的那张就地消失。
	 * 不闪一下留下来的那张,看着就像刚才那一拖把待办弄丢了。
	 */
	function flashMerged( body, todoId ) {
		var survivor = body.querySelector( '.wp-card[data-todo="' + todoId + '"]' );
		if ( ! survivor ) {
			return;
		}
		survivor.classList.add( 'is-merged' );
		window.setTimeout( function () {
			survivor.classList.remove( 'is-merged' );
		}, 1600 );
	}

	function updateProgress( todoId, doneDays ) {
		// 只改平铺列表里的那条;按标签分组时的克隆件由分组那块自己跟上
		var item = document.querySelector( '.wp-flat .wp-item[data-todo="' + todoId + '"] .wp-item-meta' );
		if ( ! item ) {
			return;
		}
		var rest = item.textContent.split( ' · ' ).slice( 1 );
		rest.unshift( doneDays > 0 ? '已完成 ' + doneDays + ' 天' : '还没开始' );
		item.textContent = rest.join( ' · ' );
	}

	/*
	 * 只有「某一天」那个容器才有顺序可写。逾期区也装着 .wp-card,但它是
	 * 上周若干天的汇总,没有自己的日期 —— 早先没挡住,从逾期区拖走一张卡
	 * 就会拿 date=undefined 去请求排序,换回一句「日期格式不对」,再把整页
	 * 刷掉,看起来就像刚才那一拖失败了。
	 */
	function pushOrder( body ) {
		if ( ! body || 'day' !== ( body.dataset && body.dataset.drop ) || ! body.dataset.date ) {
			return;
		}

		var ids = Array.prototype.map.call(
			body.querySelectorAll( '.wp-card' ),
			function ( card ) {
				return card.dataset.schedule;
			}
		);
		if ( ! ids.length ) {
			return;
		}
		post( 'reorder', { date: body.dataset.date, ids: ids } ).catch( fail );
	}

	function clearMarks() {
		document.querySelectorAll( '.is-over' ).forEach( function ( el ) {
			el.classList.remove( 'is-over' );
		} );
		document.querySelectorAll( '.drop-before, .drop-after' ).forEach( function ( el ) {
			el.classList.remove( 'drop-before', 'drop-after' );
		} );
	}

	/* 光标落在哪张卡片之前 —— 用卡片的垂直中线判断。 */
	function findInsertBefore( body, clientY ) {
		var cards = Array.prototype.slice.call( body.querySelectorAll( '.wp-card' ) );
		for ( var i = 0; i < cards.length; i++ ) {
			if ( cards[ i ] === ( dragged && dragged.el ) ) {
				continue;
			}
			var box = cards[ i ].getBoundingClientRect();
			if ( clientY < box.top + box.height / 2 ) {
				return cards[ i ];
			}
		}
		return null;
	}

	/* ---------- 拖拽 ---------- */

	document.addEventListener( 'dragstart', function ( event ) {
		var target = event.target;
		if ( ! target.closest ) {
			return;
		}
		// 点在勾上不算拖拽
		if ( target.closest( '.wp-check' ) ) {
			event.preventDefault();
			return;
		}

		var el = target.closest( '.wp-card, .wp-item' );
		if ( ! el ) {
			return;
		}

		dragged = {
			el: el,
			todoId: el.dataset.todo,
			scheduleId: el.dataset.schedule || '',
			date: el.dataset.date || '',
			// 「拖回侧栏 = 取消排期」只对中栏里的卡片成立。逾期区的卡片本来
			// 就长在侧栏里,手一抖松开就等于删掉上周没做完的那条,连问都不问。
			// 逾期卡要取消排期,走右键菜单里的「取消这一天的排期」。
			fromDay: !! el.closest( '[data-drop="day"]' )
		};
		el.classList.add( 'is-dragging' );
		event.dataTransfer.effectAllowed = 'move';
		// Firefox 不设 data 就不会真的开始拖
		event.dataTransfer.setData( 'text/plain', el.dataset.todo || '' );
	} );

	document.addEventListener( 'dragend', function () {
		if ( dragged ) {
			dragged.el.classList.remove( 'is-dragging' );
		}
		dragged = null;
		insertBefore = null;
		clearMarks();
	} );

	document.addEventListener( 'dragover', function ( event ) {
		if ( ! dragged || ! event.target.closest ) {
			return;
		}

		var dayBody = event.target.closest( '[data-drop="day"]' );
		if ( dayBody ) {
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			clearMarks();
			dayBody.classList.add( 'is-over' );
			insertBefore = findInsertBefore( dayBody, event.clientY );
			if ( insertBefore ) {
				insertBefore.classList.add( 'drop-before' );
			}
			return;
		}

		// 拖回侧栏 = 取消排期。只有从周视图里拖出来的卡片才有意义。
		var side = event.target.closest( '[data-drop="sidebar"]' );
		if ( side && dragged.scheduleId && dragged.fromDay ) {
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			clearMarks();
			side.classList.add( 'is-over' );
			insertBefore = null;
		}
	} );

	document.addEventListener( 'drop', function ( event ) {
		if ( ! dragged || ! event.target.closest ) {
			return;
		}

		var dayBody = event.target.closest( '[data-drop="day"]' );
		if ( dayBody ) {
			event.preventDefault();
			dropOnDay( dayBody );
			clearMarks();
			return;
		}

		var side = event.target.closest( '[data-drop="sidebar"]' );
		if ( side && dragged.scheduleId && dragged.fromDay ) {
			event.preventDefault();
			dropOnSidebar();
			clearMarks();
		}
	} );

	function dropOnDay( body ) {
		var date = body.dataset.date;
		var source = dragged;
		var anchor = insertBefore;

		// 侧栏 → 某一天:新增。卡片的 HTML 由服务器渲染,避免前后端各写一份。
		if ( ! source.scheduleId ) {
			post( 'add', { todo_id: source.todoId, date: date } ).then( function ( data ) {
				var holder = document.createElement( 'div' );
				holder.innerHTML = ( data.html || '' ).trim();
				var card = holder.firstElementChild;
				if ( card ) {
					body.insertBefore( card, anchor );
					refreshCounts();
					pushOrder( body );
				}
			} ).catch( fail );
			return;
		}

		// 同一天内:只是重排
		if ( source.date === date ) {
			body.insertBefore( source.el, anchor );
			pushOrder( body );
			return;
		}

		// 某一天 → 另一天:只移动这一天
		var origin = source.el.parentElement;
		body.insertBefore( source.el, anchor );
		source.el.dataset.date = date;

		post( 'move', { schedule_id: source.scheduleId, date: date } ).then( function ( data ) {
			// 目标日已经有同一个待办,服务器把两条并成一条 —— 这里也要去重
			if ( data.merged ) {
				source.el.remove();
				flashMerged( body, source.todoId );
			}
			refreshCounts();
			refreshOverdue();
			pushOrder( body );
			if ( origin && origin !== body ) {
				pushOrder( origin );
			}
		} ).catch( fail );
	}

	function dropOnSidebar() {
		var source = dragged;
		var origin = source.el.parentElement;

		source.el.remove();
		post( 'remove', { schedule_id: source.scheduleId } ).then( function () {
			refreshCounts();
			refreshOverdue();
			if ( origin ) {
				pushOrder( origin );
			}
		} ).catch( fail );
	}

	/* ---------- 打勾 ---------- */

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}
		var button = event.target.closest( '.wp-check' );
		if ( ! button ) {
			return;
		}

		var card = button.closest( '.wp-card' );
		var wasDone = 'true' === button.getAttribute( 'aria-pressed' );

		button.setAttribute( 'aria-pressed', wasDone ? 'false' : 'true' );
		card.classList.toggle( 'is-done', ! wasDone );

		post( 'toggle', { schedule_id: card.dataset.schedule } ).then( function ( data ) {
			updateProgress( card.dataset.todo, data.done_days );
		} ).catch( fail );
	} );
}() );

/*
 * 详情栏。
 *
 * 每个字段各自保存(改完即存),没有「保存」按钮 —— 个人工具里多一次点击
 * 就是多一次摩擦。文本框会防抖,不然每敲一个字都发一次请求。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};
	var panel = document.getElementById( 'weekplan-detail' );
	if ( ! panel ) {
		return;
	}

	var noteTimer = null;

	function post( action, payload ) {
		var body = new URLSearchParams();
		body.set( 'action', 'weekplan_' + action );
		body.set( 'nonce', config.nonce );
		Object.keys( payload ).forEach( function ( key ) {
			body.set( key, payload[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || '请求失败' );
			}
			return json.data || {};
		} );
	}

	function complain( error ) {
		window.console && console.error( '[week-plan]', error );
		window.alert( '没保存成功:' + error.message );
	}

	function currentTodo() {
		var box = panel.querySelector( '[data-todo]' );
		return box ? box.dataset.todo : '';
	}

	/* 保存后侧栏那条要跟着变(标题、进度、标签都可能改了) */
	function replaceSidebarItem( todoId, html ) {
		var old = document.querySelector( '.wp-flat .wp-item[data-todo="' + todoId + '"]' );
		if ( ! old || ! html ) {
			return;
		}
		var holder = document.createElement( 'div' );
		holder.innerHTML = html.trim();
		var fresh = holder.firstElementChild;
		if ( fresh ) {
			old.replaceWith( fresh );
		}
	}

	function save( field, value ) {
		var todoId = currentTodo();
		if ( ! todoId ) {
			return Promise.resolve( {} );
		}
		return post( 'save', { todo_id: todoId, field: field, value: value } )
			.then( function ( data ) {
				replaceSidebarItem( todoId, data.item_html );
				return data;
			} )
			.catch( complain );
	}

	function openTodo( todoId ) {
		document.querySelectorAll( '.is-selected' ).forEach( function ( el ) {
			el.classList.remove( 'is-selected' );
		} );
		document.querySelectorAll( '[data-todo="' + todoId + '"]' ).forEach( function ( el ) {
			if ( el.classList.contains( 'wp-card' ) || el.classList.contains( 'wp-item' ) ) {
				el.classList.add( 'is-selected' );
			}
		} );

		post( 'detail', { todo_id: todoId } ).then( function ( data ) {
			panel.innerHTML = data.html || '';
		} ).catch( complain );
	}

	/* ---------- 选中 ---------- */

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}
		// 勾和详情栏内部的点击不算「选中某条待办」
		if ( event.target.closest( '.wp-check' ) || event.target.closest( '#weekplan-detail' ) ) {
			return;
		}
		var el = event.target.closest( '.wp-card, .wp-item' );
		if ( el && el.dataset.todo ) {
			openTodo( el.dataset.todo );
		}
	} );

	/* ---------- 新建 ---------- */

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest || ! event.target.closest( '.wp-new-todo' ) ) {
			return;
		}
		var title = window.prompt( '新建待办,叫什么?' );
		if ( null === title || '' === title.trim() ) {
			return;
		}

		post( 'create', { title: title } ).then( function ( data ) {
			// 新待办没有排期,落在「未来计划」里。塞进平铺列表,分组视图会自己跟上。
			var target = document.querySelector( '.wp-group-body[data-split="unscheduled"] .wp-flat' );
			if ( target ) {
				var empty = target.querySelector( '.weekplan-empty' );
				if ( empty ) {
					empty.remove();
				}
				var holder = document.createElement( 'div' );
				holder.innerHTML = ( data.item_html || '' ).trim();
				if ( holder.firstElementChild ) {
					target.prepend( holder.firstElementChild );
				}
			}
			panel.innerHTML = data.detail_html || '';
			var titleInput = panel.querySelector( '[data-field="title"]' );
			if ( titleInput ) {
				titleInput.focus();
				titleInput.select();
			}
		} ).catch( complain );
	} );

	/* ---------- 字段保存 ---------- */

	panel.addEventListener( 'change', function ( event ) {
		var field = event.target.dataset && event.target.dataset.field;
		if ( ! field || 'note' === field ) {
			return;
		}
		save( field, event.target.value );
	} );

	panel.addEventListener( 'input', function ( event ) {
		if ( 'note' !== ( event.target.dataset && event.target.dataset.field ) ) {
			return;
		}
		var value = event.target.value;
		window.clearTimeout( noteTimer );
		noteTimer = window.setTimeout( function () {
			save( 'note', value );
		}, 700 );
	} );

	/* ---------- 结项 / 删除 ---------- */

	panel.addEventListener( 'click', function ( event ) {
		var finish = event.target.closest( '.wp-finish' );
		if ( finish ) {
			var turningOn = ! finish.classList.contains( 'is-on' );
			finish.classList.toggle( 'is-on', turningOn );
			finish.textContent = turningOn ? '已结项 · 点击恢复' : '标记为结项';
			save( 'finished', turningOn ? '1' : '0' ).then( function () {
				document.querySelectorAll( '.wp-card[data-todo="' + currentTodo() + '"]' )
					.forEach( function ( card ) {
						card.classList.toggle( 'is-finished', turningOn );
					} );
			} );
			return;
		}

		var del = event.target.closest( '.wp-del-todo' );
		if ( del ) {
			var todoId = currentTodo();
			if ( ! todoId || ! window.confirm( '删掉这条待办?它的所有排期也会一起消失,不能撤销。' ) ) {
				return;
			}
			post( 'delete', { todo_id: todoId } ).then( function () {
				document.querySelectorAll( '[data-todo="' + todoId + '"]' ).forEach( function ( el ) {
					if ( el.classList.contains( 'wp-card' ) || el.classList.contains( 'wp-item' ) ) {
						el.remove();
					}
				} );
				panel.innerHTML = '<div class="wp-group wp-detail"><div class="wp-group-head">详情</div>' +
					'<p class="weekplan-empty">点一条待办,在这里编辑。</p></div>';
			} ).catch( complain );
		}
	} );

	/* ---------- 标签 ---------- */

	function tagNames() {
		return Array.prototype.map.call(
			panel.querySelectorAll( '.wp-tag-chip' ),
			function ( chip ) {
				return chip.dataset.name;
			}
		);
	}

	function saveTags() {
		save( 'tags', tagNames().join( '\n' ) ).then( function () {
			// 标签变了,卡片上的色带和小签也要重画 —— 重新拉一次详情最省事
			openTodo( currentTodo() );
		} );
	}

	panel.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' !== event.key ) {
			return;
		}

		var tagInput = event.target.closest( '.wp-tag-input' );
		if ( tagInput ) {
			event.preventDefault();
			var name = tagInput.value.trim();
			if ( '' === name || tagNames().indexOf( name ) !== -1 ) {
				tagInput.value = '';
				return;
			}
			var chips = panel.querySelector( '.wp-tag-chips' );
			var chip = document.createElement( 'span' );
			chip.className = 'wp-tag wp-tag-chip';
			chip.dataset.name = name;
			chip.textContent = name;
			chip.insertAdjacentHTML( 'beforeend', '<button type="button" class="wp-tag-x" title="移除">×</button>' );
			chips.appendChild( chip );
			tagInput.value = '';
			saveTags();
			return;
		}

		var subInput = event.target.closest( '.wp-sub-input' );
		if ( subInput ) {
			event.preventDefault();
			var text = subInput.value.trim();
			if ( '' === text ) {
				return;
			}
			var list = panel.querySelector( '.wp-subtasks' );
			var li = document.createElement( 'li' );
			li.className = 'wp-subtask';
			li.innerHTML = '<button type="button" class="wp-check wp-sub-check" aria-pressed="false"></button>' +
				'<span class="wp-subtask-text"></span>' +
				'<button type="button" class="wp-sub-x" title="删除">×</button>';
			li.querySelector( '.wp-subtask-text' ).textContent = text;
			list.appendChild( li );
			subInput.value = '';
			saveSubtasks();
		}
	} );

	panel.addEventListener( 'click', function ( event ) {
		var remove = event.target.closest( '.wp-tag-x' );
		if ( remove ) {
			remove.closest( '.wp-tag-chip' ).remove();
			saveTags();
			return;
		}

		var subCheck = event.target.closest( '.wp-sub-check' );
		if ( subCheck ) {
			var on = 'true' === subCheck.getAttribute( 'aria-pressed' );
			subCheck.setAttribute( 'aria-pressed', on ? 'false' : 'true' );
			subCheck.closest( '.wp-subtask' ).classList.toggle( 'is-done', ! on );
			saveSubtasks();
			return;
		}

		var subRemove = event.target.closest( '.wp-sub-x' );
		if ( subRemove ) {
			subRemove.closest( '.wp-subtask' ).remove();
			saveSubtasks();
		}
	} );

	function saveSubtasks() {
		var items = Array.prototype.map.call(
			panel.querySelectorAll( '.wp-subtask' ),
			function ( li ) {
				return {
					text: li.querySelector( '.wp-subtask-text' ).textContent,
					done: 'true' === li.querySelector( '.wp-sub-check' ).getAttribute( 'aria-pressed' )
				};
			}
		);
		save( 'subtasks', JSON.stringify( items ) );
	}
}() );

/*
 * 标签的就地编辑。
 *
 * 点铅笔把那一行换成「名字 + 颜色 + 保存/删除」,改完刷新页面 —— 标签色
 * 散落在周视图和侧栏的每一张卡片上,局部更新要碰的地方太多,不值得。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};

	function post( action, payload ) {
		var body = new URLSearchParams();
		body.set( 'action', 'weekplan_' + action );
		body.set( 'nonce', config.nonce );
		Object.keys( payload ).forEach( function ( key ) {
			body.set( key, payload[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || '请求失败' );
			}
			return json.data || {};
		} );
	}

	function closeEditor( row ) {
		var editor = row.querySelector( '.wp-tag-editrow' );
		if ( editor ) {
			editor.remove();
		}
		row.classList.remove( 'is-editing' );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}

		var openBtn = event.target.closest( '.wp-tag-edit' );
		if ( openBtn ) {
			event.preventDefault();
			var row = openBtn.closest( '.wp-tagrow' );
			if ( row.classList.contains( 'is-editing' ) ) {
				closeEditor( row );
				return;
			}
			row.classList.add( 'is-editing' );
			var box = document.createElement( 'div' );
			box.className = 'wp-tag-editrow';
			box.innerHTML =
				'<input type="text" class="wp-input wp-tagname">' +
				'<input type="color" class="wp-tagcolor">' +
				'<button type="button" class="wp-tag-ok">保存</button>' +
				'<button type="button" class="wp-tag-rm">删除</button>';
			box.querySelector( '.wp-tagname' ).value = row.dataset.name;
			box.querySelector( '.wp-tagcolor' ).value = row.dataset.color;
			row.appendChild( box );
			box.querySelector( '.wp-tagname' ).focus();
			return;
		}

		var save = event.target.closest( '.wp-tag-ok' );
		if ( save ) {
			var saveRow = save.closest( '.wp-tagrow' );
			post( 'tag_save', {
				term_id: saveRow.dataset.term,
				name: saveRow.querySelector( '.wp-tagname' ).value,
				color: saveRow.querySelector( '.wp-tagcolor' ).value
			} ).then( function () {
				window.location.reload();
			} ).catch( function ( error ) {
				window.alert( error.message );
			} );
			return;
		}

		var remove = event.target.closest( '.wp-tag-rm' );
		if ( remove ) {
			var rmRow = remove.closest( '.wp-tagrow' );
			if ( ! window.confirm( '删掉标签「' + rmRow.dataset.name + '」?待办本身不会被删,只是不再挂这个标签。' ) ) {
				return;
			}
			post( 'tag_delete', { term_id: rmRow.dataset.term } ).then( function () {
				window.location.reload();
			} ).catch( function ( error ) {
				window.alert( error.message );
			} );
		}
	} );
}() );

/*
 * 批量排期对话框。
 *
 * 用原生 <dialog>,自带模态遮罩、Esc 关闭和焦点管理,不需要任何库。
 * 排完刷新页面 —— 生成的日子多半落在当前这一周之外,局部更新没意义。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};
	var dialog = document.getElementById( 'weekplan-batch' );
	var panel = document.getElementById( 'weekplan-detail' );
	if ( ! dialog || ! panel ) {
		return;
	}

	var fromInput = document.getElementById( 'weekplan-batch-from' );
	var toInput = document.getElementById( 'weekplan-batch-to' );
	var preview = document.getElementById( 'weekplan-batch-preview' );
	var forLabel = document.getElementById( 'weekplan-batch-for' );
	var todoId = '';

	function pickedWeekdays() {
		return Array.prototype.filter.call(
			dialog.querySelectorAll( '.wp-weekpick input' ),
			function ( box ) {
				return box.checked;
			}
		).map( function ( box ) {
			return Number( box.value );
		} );
	}

	/* 本地先算一遍要生成多少天,让人在点「排上」之前就知道规模 */
	function refreshPreview() {
		var from = new Date( fromInput.value + 'T00:00:00' );
		var to = new Date( toInput.value + 'T00:00:00' );
		var days = pickedWeekdays();

		if ( isNaN( from ) || isNaN( to ) || to < from || ! days.length ) {
			preview.textContent = '';
			return;
		}

		var count = 0;
		for ( var d = new Date( from ); d <= to; d.setDate( d.getDate() + 1 ) ) {
			if ( days.indexOf( d.getDay() ) !== -1 ) {
				count++;
			}
		}
		preview.textContent = '将排上 ' + count + ' 天(已经排过的会跳过)。';
	}

	dialog.addEventListener( 'change', refreshPreview );
	dialog.addEventListener( 'input', refreshPreview );

	panel.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest || ! event.target.closest( '.wp-batch-open' ) ) {
			return;
		}
		var box = panel.querySelector( '[data-todo]' );
		if ( ! box ) {
			return;
		}
		todoId = box.dataset.todo;
		var title = panel.querySelector( '[data-field="title"]' );
		forLabel.textContent = title ? title.value : '';
		refreshPreview();
		dialog.showModal();
	} );

	document.getElementById( 'weekplan-batch-cancel' ).addEventListener( 'click', function () {
		dialog.close();
	} );

	document.getElementById( 'weekplan-batch-go' ).addEventListener( 'click', function () {
		var body = new URLSearchParams();
		body.set( 'action', 'weekplan_batch' );
		body.set( 'nonce', config.nonce );
		body.set( 'todo_id', todoId );
		body.set( 'from', fromInput.value );
		body.set( 'to', toInput.value );
		pickedWeekdays().forEach( function ( day ) {
			body.append( 'weekdays[]', day );
		} );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || '请求失败' );
			}
			dialog.close();
			window.location.reload();
		} ).catch( function ( error ) {
			window.alert( error.message );
		} );
	} );
}() );

/*
 * 右键菜单。
 *
 * 「整体顺延」放在这里而不是绑到拖拽上:拖拽必须是所见即所得的单格操作,
 * 否则一次误拖就能打乱三个月的排期。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};
	var menu = null;

	function post( action, payload ) {
		var body = new URLSearchParams();
		body.set( 'action', 'weekplan_' + action );
		body.set( 'nonce', config.nonce );
		Object.keys( payload ).forEach( function ( key ) {
			body.set( key, payload[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || '请求失败' );
			}
			return json.data || {};
		} );
	}

	function closeMenu() {
		if ( menu ) {
			menu.remove();
			menu = null;
		}
	}

	function run( promise ) {
		promise.then( function () {
			window.location.reload();
		} ).catch( function ( error ) {
			window.alert( error.message );
		} );
	}

	function shift( todoId, days ) {
		run( post( 'shift', { todo_id: todoId, days: days } ) );
	}

	document.addEventListener( 'contextmenu', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}
		var el = event.target.closest( '.wp-card, .wp-item' );
		if ( ! el || ! el.dataset.todo ) {
			return;
		}

		event.preventDefault();
		closeMenu();

		var todoId = el.dataset.todo;
		var scheduleId = el.dataset.schedule || '';
		var title = el.querySelector( '.wp-card-title, .wp-item-title' );

		menu = document.createElement( 'div' );
		menu.className = 'wp-menu';
		menu.style.left = event.pageX + 'px';
		menu.style.top = event.pageY + 'px';

		var head = document.createElement( 'div' );
		head.className = 'wp-menu-head';
		head.textContent = title ? title.textContent : '';
		menu.appendChild( head );

		function item( label, handler, danger ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'wp-menu-item' + ( danger ? ' is-danger' : '' );
			button.textContent = label;
			button.addEventListener( 'click', function () {
				closeMenu();
				handler();
			} );
			menu.appendChild( button );
		}

		item( '未做的整体顺延 1 天', function () {
			shift( todoId, 1 );
		} );
		item( '未做的整体顺延 7 天', function () {
			shift( todoId, 7 );
		} );
		item( '自定义顺延…', function () {
			var answer = window.prompt( '顺延几天?负数是提前。已经打过勾的日子不动。', '3' );
			if ( null === answer || '' === answer.trim() ) {
				return;
			}
			var days = parseInt( answer, 10 );
			if ( isNaN( days ) ) {
				window.alert( '要填一个整数。' );
				return;
			}
			shift( todoId, days );
		} );

		if ( scheduleId ) {
			item( '取消这一天的排期', function () {
				run( post( 'remove', { schedule_id: scheduleId } ) );
			} );
		}

		item( '删除这条待办', function () {
			if ( window.confirm( '删掉这条待办?它的所有排期也会一起消失,不能撤销。' ) ) {
				run( post( 'delete', { todo_id: todoId } ) );
			}
		}, true );

		document.body.appendChild( menu );

		// 贴到窗口边上时往回收一点,别被裁掉
		var box = menu.getBoundingClientRect();
		if ( box.right > window.innerWidth ) {
			menu.style.left = ( event.pageX - box.width ) + 'px';
		}
		if ( box.bottom > window.innerHeight ) {
			menu.style.top = ( event.pageY - box.height ) + 'px';
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( menu && ! event.target.closest( '.wp-menu' ) ) {
			closeMenu();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeMenu();
		}
	} );

	window.addEventListener( 'scroll', closeMenu, true );
}() );

/*
 * 笔记的编辑 / 预览切换。
 *
 * Markdown 在服务端渲染 —— 前端再引一个解析器就是同一件事做两遍,而且
 * 两边的方言迟早会对不上。切到预览时把当前文本发过去换 HTML 回来。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};
	var panel = document.getElementById( 'weekplan-detail' );
	if ( ! panel ) {
		return;
	}

	panel.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}
		var tab = event.target.closest( '.wp-notetab' );
		if ( ! tab ) {
			return;
		}

		var field = tab.closest( '.wp-notefield' );
		var note = field.querySelector( '.wp-note' );
		var view = field.querySelector( '.wp-noteview' );
		var toView = 'view' === tab.dataset.mode;

		field.querySelectorAll( '.wp-notetab' ).forEach( function ( other ) {
			other.classList.toggle( 'is-on', other === tab );
		} );

		note.hidden = toView;
		view.hidden = ! toView;

		if ( ! toView ) {
			return;
		}

		// 预览的是当前文本框里的内容,不是上次保存的 —— 边写边看才有意义
		var body = new URLSearchParams();
		body.set( 'action', 'weekplan_preview' );
		body.set( 'nonce', config.nonce );
		body.set( 'value', note.value );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( json && json.success ) {
				view.innerHTML = json.data.html || '<p class="weekplan-empty">还没写笔记。</p>';
			}
		} ).catch( function ( error ) {
			window.console && console.error( '[week-plan]', error );
		} );
	} );
}() );

/*
 * 左栏的二级标签分组。
 *
 * 平铺列表是唯一的真相 —— 服务器只渲染它一份。开了分组就把平铺整个藏起来,
 * 按标签克隆出一份二级 <details>。一条待办挂几个标签就克隆几份,所以二级标题
 * 上的数字是「这个标签下有几条」,不是待办条数。
 *
 * 克隆件靠 MutationObserver 跟着源头走:改标题、加标签、新建、删除都会动到
 * 平铺列表,观察到就重新派生一次 —— 不必在每个改动的地方各记得刷一次。
 */
( function () {
	'use strict';

	var UNTAGGED = '0';
	var groups = document.querySelectorAll( '.wp-group-body[data-split]' );
	if ( ! groups.length || ! window.MutationObserver ) {
		return;
	}

	/* 隐私窗口里 localStorage 会直接抛异常,读写都包起来。记不住不影响功能。 */
	function remember( key, value ) {
		try {
			window.localStorage.setItem( 'weekplan.' + key, value );
		} catch ( error ) {
			window.console && console.warn( '[week-plan] 状态没存下:', error );
		}
	}

	function recall( key, fallback ) {
		try {
			var value = window.localStorage.getItem( 'weekplan.' + key );
			return null === value ? fallback : value;
		} catch ( error ) {
			return fallback;
		}
	}

	function isOn( key ) {
		return 'on' === recall( 'split.' + key, 'off' );
	}

	/* 二级分组默认展开 —— 收起来是明确点过的,才记成 closed。 */
	function isOpen( key, tagId ) {
		return 'closed' !== recall( 'taggroup.' + key + '.' + tagId, 'open' );
	}

	/* 一条待办属于哪几个标签。data-tags 里的 id 和小签一一对应。 */
	function tagsOf( item ) {
		var ids = ( item.dataset.tags || '' ).split( ',' ).filter( Boolean );
		var chips = item.querySelectorAll( '.wp-tag' );

		if ( ! ids.length ) {
			return [ { id: UNTAGGED, name: '无标签', color: '' } ];
		}

		return ids.map( function ( id, index ) {
			var chip = chips[ index ];
			return {
				id: id,
				name: chip ? chip.textContent : '#' + id,
				color: chip ? chip.style.getPropertyValue( '--wp-tag' ).trim() : ''
			};
		} );
	}

	function bucketize( flat ) {
		var order = [];
		var buckets = {};

		Array.prototype.forEach.call( flat.querySelectorAll( '.wp-item' ), function ( item ) {
			tagsOf( item ).forEach( function ( tag ) {
				if ( ! buckets[ tag.id ] ) {
					buckets[ tag.id ] = { tag: tag, items: [] };
					order.push( tag.id );
				}
				buckets[ tag.id ].items.push( item );
			} );
		} );

		// 按标签名排,和左栏标签区一致;「无标签」永远垫底
		order.sort( function ( a, b ) {
			if ( a === UNTAGGED || b === UNTAGGED ) {
				return a === UNTAGGED ? 1 : -1;
			}
			return buckets[ a ].tag.name.localeCompare( buckets[ b ].tag.name, 'zh-Hans-CN' );
		} );

		return order.map( function ( id ) {
			return buckets[ id ];
		} );
	}

	function subgroup( key, bucket ) {
		var box = document.createElement( 'details' );
		box.className = 'wp-subgroup';
		box.dataset.tag = bucket.tag.id;
		box.open = isOpen( key, bucket.tag.id );

		var head = document.createElement( 'summary' );

		// 「无标签」也占一个色点的位置,空心的 —— 不然那一行的名字比别人靠左
		var dot = document.createElement( 'span' );
		dot.className = bucket.tag.color ? 'wp-dot' : 'wp-dot wp-dot-none';
		if ( bucket.tag.color ) {
			dot.style.setProperty( '--wp-tag', bucket.tag.color );
		}
		head.appendChild( dot );

		var name = document.createElement( 'span' );
		name.className = 'wp-subgroup-name';
		name.textContent = bucket.tag.name;
		head.appendChild( name );

		var count = document.createElement( 'span' );
		count.className = 'wp-subgroup-count';
		count.textContent = bucket.items.length;
		head.appendChild( count );

		box.appendChild( head );

		var body = document.createElement( 'div' );
		body.className = 'wp-subgroup-body';
		bucket.items.forEach( function ( item ) {
			body.appendChild( item.cloneNode( true ) );
		} );
		box.appendChild( body );

		box.addEventListener( 'toggle', function () {
			remember( 'taggroup.' + key + '.' + bucket.tag.id, box.open ? 'open' : 'closed' );
		} );

		return box;
	}

	function render( group ) {
		var key = group.dataset.split;
		var flat = group.querySelector( '.wp-flat' );
		var old = group.querySelector( '.wp-grouped' );
		var on = isOn( key );

		if ( old ) {
			old.remove();
		}

		var button = document.querySelector( '.wp-split[data-split="' + key + '"]' );
		if ( button ) {
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		}

		if ( ! on ) {
			flat.hidden = false;
			return;
		}

		var buckets = bucketize( flat );
		// 一条都没有时让平铺的空状态露着 —— 它那句话比「空的」说得更准
		flat.hidden = buckets.length > 0;
		if ( ! buckets.length ) {
			return;
		}

		var box = document.createElement( 'div' );
		box.className = 'wp-grouped';
		buckets.forEach( function ( bucket ) {
			box.appendChild( subgroup( key, bucket ) );
		} );
		group.appendChild( box );
	}

	function renderAll() {
		Array.prototype.forEach.call( groups, render );
	}

	// 一次改动会连着触发好几条 mutation,攒到下一帧只重画一次
	var pending = false;
	var watcher = new window.MutationObserver( function () {
		if ( pending ) {
			return;
		}
		pending = true;
		window.requestAnimationFrame( function () {
			pending = false;
			renderAll();
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}
		var button = event.target.closest( '.wp-split' );
		if ( ! button ) {
			return;
		}
		// 「未来计划」的开关长在 <summary> 里,不拦住就会顺手把整组折起来
		event.preventDefault();

		var key = button.dataset.split;
		remember( 'split.' + key, isOn( key ) ? 'off' : 'on' );
		renderAll();
	} );

	Array.prototype.forEach.call( groups, function ( group ) {
		watcher.observe( group.querySelector( '.wp-flat' ), {
			childList: true,
			subtree: true,
			characterData: true
		} );
	} );

	renderAll();
}() );

/*
 * 右栏底部的便签。
 *
 * 编辑器是原生 contenteditable,格式按钮走 document.execCommand —— 它被标成
 * 过时,但各家浏览器都还支持,而且是不引库就能做所见即所得的唯一办法。
 *
 * 停手 0.7 秒自动保存,失焦和关页面时再补一次。同一时间只发一个请求:
 * 生产上 PHP-FPM 是并发的,两个保存请求赛跑,旧内容可能后到、把新内容盖掉。
 */
( function () {
	'use strict';

	var config = window.weekplanData || {};
	var memo = document.getElementById( 'weekplan-memo' );
	if ( ! memo ) {
		return;
	}

	var editor = memo.querySelector( '.wp-memo-body' );
	var status = memo.querySelector( '.wp-memo-status' );
	var buttons = memo.querySelectorAll( '.wp-memo-tools button' );

	var timer = null;
	var statusTimer = null;
	var dirty = false;
	var inflight = false;

	function body() {
		var data = new URLSearchParams();
		data.set( 'action', 'weekplan_memo' );
		data.set( 'nonce', config.nonce );
		data.set( 'value', editor.innerHTML );
		return data;
	}

	function say( text, fade ) {
		window.clearTimeout( statusTimer );
		status.textContent = text;
		if ( fade ) {
			statusTimer = window.setTimeout( function () {
				status.textContent = '';
			}, 1500 );
		}
	}

	function flush() {
		window.clearTimeout( timer );
		timer = null;
		if ( ! dirty || inflight ) {
			return;
		}

		dirty = false;
		inflight = true;
		say( '保存中…' );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body()
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || '请求失败' );
			}
			inflight = false;
			// 请求在路上时又改过,接着存最新的
			if ( dirty ) {
				flush();
			} else {
				say( '已保存', true );
			}
		} ).catch( function ( error ) {
			inflight = false;
			dirty = true;
			window.console && console.error( '[week-plan]', error );
			say( '没保存成功,再改一下会重试' );
		} );
	}

	function changed() {
		editor.classList.toggle( 'is-empty', '' === editor.textContent.trim() && ! editor.querySelector( 'li' ) );
		dirty = true;
		window.clearTimeout( timer );
		timer = window.setTimeout( flush, 700 );
	}

	function syncButtons() {
		var sel = document.getSelection();
		var inside = sel && sel.rangeCount && editor.contains( sel.anchorNode );
		buttons.forEach( function ( button ) {
			var on = false;
			if ( inside ) {
				try {
					on = document.queryCommandState( button.dataset.cmd );
				} catch ( e ) {}
			}
			button.classList.toggle( 'is-on', on );
		} );
	}

	editor.addEventListener( 'focus', function () {
		// 回车另起一行时用 <div>,不让各家浏览器各用各的
		document.execCommand( 'defaultParagraphSeparator', false, 'div' );
	} );

	editor.addEventListener( 'input', changed );
	editor.addEventListener( 'blur', flush );
	document.addEventListener( 'selectionchange', syncButtons );

	// 按下时不抢焦点,选区还留在编辑器里
	memo.querySelector( '.wp-memo-tools' ).addEventListener( 'mousedown', function ( event ) {
		event.preventDefault();
	} );

	memo.querySelector( '.wp-memo-tools' ).addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button[data-cmd]' );
		if ( ! button ) {
			return;
		}
		if ( document.activeElement !== editor ) {
			editor.focus();
		}
		document.execCommand( button.dataset.cmd, false, null );
		syncButtons();
		changed();
	} );

	// 粘贴只要文字:别处带来的颜色、字号、图片都不进便签
	editor.addEventListener( 'paste', function ( event ) {
		event.preventDefault();
		var text = ( event.clipboardData || window.clipboardData ).getData( 'text/plain' );
		if ( text ) {
			document.execCommand( 'insertText', false, text );
		}
	} );

	// 卡片拖过来会把待办 id 当文字塞进来,图片拖进来会变成内嵌图。一律不收
	editor.addEventListener( 'dragover', function ( event ) {
		event.preventDefault();
		event.dataTransfer.dropEffect = 'none';
	} );
	editor.addEventListener( 'drop', function ( event ) {
		event.preventDefault();
	} );

	// 停手不到 0.7 秒就关页面,用 sendBeacon 把最后一笔送出去
	window.addEventListener( 'pagehide', function () {
		if ( dirty && navigator.sendBeacon ) {
			navigator.sendBeacon( config.ajaxUrl, body() );
		}
	} );
}() );
