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

	function updateProgress( todoId, doneDays ) {
		var item = document.querySelector( '.wp-item[data-todo="' + todoId + '"] .wp-item-meta' );
		if ( ! item ) {
			return;
		}
		var rest = item.textContent.split( ' · ' ).slice( 1 );
		rest.unshift( doneDays > 0 ? '已完成 ' + doneDays + ' 天' : '还没开始' );
		item.textContent = rest.join( ' · ' );
	}

	function pushOrder( body ) {
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
			date: el.dataset.date || ''
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
		if ( side && dragged.scheduleId ) {
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
		if ( side && dragged.scheduleId ) {
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
			}
			refreshCounts();
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
