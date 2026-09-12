// Draws the favourite buttons.
//
// Pages are cached and shared between users, so they cannot contain anyone's
// favourite marks: the server emits empty <span class="flib-fav"> slots and this
// fills them in for whoever is actually looking. Anonymous visitors have no
// favourites, so their slots simply stay empty - nothing appears and then
// disappears again.
//
// The state (and the CSRF token used by the generated forms) comes from
// user_state.php, which is never cached.
(function () {
	'use strict';

	var ROOT = (window.FLIBUSTA_WEBROOT || '');

	var STYLES = {
		book:   { on: 'btn-primary',  off: 'btn-outline-secondary',
		          html: "<i class='fas fa-heart'></i>", title: 'В избранное', cls: 'btn btn-sm' },
		author: { on: 'btn-warning',  off: 'btn-secondary',
		          onText: 'Из избранного', offText: 'В избранное', cls: 'btn mt-2 w-100' },
		series: { on: 'btn-info',     off: 'btn-info', cls: 'btn btn-sm' }
	};

	function build(slot, isFav, csrf) {
		var type = slot.getAttribute('data-fav-type');
		var id = slot.getAttribute('data-fav-id');
		var label = slot.getAttribute('data-fav-label') || '';
		var style = STYLES[type] || STYLES.book;
		var action = (isFav ? 'unfav_' : 'fav_') + (type === 'series' ? 'seq' : type);

		var form = document.createElement('form');
		form.method = 'POST';
		form.action = '';
		form.style.display = 'inline';

		var fields = { action: action, id: id, csrf_token: csrf };
		Object.keys(fields).forEach(function (name) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = fields[name];
			form.appendChild(input);
		});

		var button = document.createElement('button');
		button.type = 'submit';
		button.className = style.cls + ' ' + (isFav ? style.on : style.off);
		if (type === 'book') {
			button.title = style.title;
			button.innerHTML = style.html;
		} else if (type === 'author') {
			button.textContent = isFav ? style.onText : style.offText;
		} else {
			button.textContent = label + (isFav ? ' из Избранного' : ' в Избранное');
		}
		form.appendChild(button);

		slot.appendChild(form);
	}

	function fill(state) {
		if (!state || !state.logged_in) {
			return;   // anonymous: leave every slot empty
		}
		var sets = {
			book:   new Set(state.fav.books || []),
			author: new Set(state.fav.authors || []),
			series: new Set(state.fav.series || [])
		};
		document.querySelectorAll('.flib-fav').forEach(function (slot) {
			if (slot.firstChild) return;               // already drawn
			var type = slot.getAttribute('data-fav-type');
			var set = sets[type];
			if (!set) return;
			build(slot, set.has(parseInt(slot.getAttribute('data-fav-id'), 10)), state.csrf);
		});
	}

	// Shared with position handling on reader pages so a book page makes one
	// request, not two.
	window.flibUserState = window.flibUserState || {
		promise: null,
		load: function (params) {
			if (!this.promise) {
				this.promise = fetch(ROOT + '/user_state.php' + (params || ''), { credentials: 'same-origin' })
					.then(function (r) { return r.ok ? r.json() : null; })
					.catch(function () { return null; });
			}
			return this.promise;
		}
	};

	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('.flib-fav')) return;
		window.flibUserState.load(window.FLIBUSTA_STATE_PARAMS || '').then(fill);
	});
})();
