(function () {
	var NAME = 'Repile';
	var suggest = null;
	var activeEditor = null;

	function config() {
		var el = document.getElementById('repile-config');
		return { avatar: el ? el.getAttribute('data-avatar') : '' };
	}

	function isNoteEditor(editable) {
		var form = $(editable).closest('form');
		var flag = form.find(':input[name="is_note"]:first').val();
		return flag === '1' || flag === 1;
	}

	function partialBeforeCaret() {
		var sel = window.getSelection();
		if (!sel || !sel.rangeCount) {
			return null;
		}
		var range = sel.getRangeAt(0);
		if (!range.collapsed || range.startContainer.nodeType !== 3) {
			return null;
		}
		var before = range.startContainer.textContent.slice(0, range.startOffset);
		var match = /(^|[\s\u00a0(])@([a-z]{0,6})$/i.exec(before);
		if (!match || NAME.toLowerCase().indexOf(match[2].toLowerCase()) !== 0) {
			return null;
		}
		return { node: range.startContainer, end: range.startOffset, length: match[2].length + 1, range: range };
	}

	function hide() {
		if (suggest) {
			suggest.remove();
			suggest = null;
		}
		activeEditor = null;
	}

	function show(editable, partial) {
		var rect = partial.range.getBoundingClientRect();
		if (!suggest) {
			suggest = $('<div class="repile-suggest" role="listbox">' +
				'<div class="repile-suggest-item active" role="option">' +
				'<img alt=""><div><div class="repile-suggest-name">Repile</div>' +
				'<div class="repile-suggest-role">AI teammate</div></div></div></div>');
			suggest.find('img').attr('src', config().avatar);
			suggest.on('mousedown', function (e) {
				e.preventDefault();
				insert();
			});
			$('body').append(suggest);
		}
		activeEditor = editable;
		suggest.css({ top: rect.bottom + window.scrollY + 6, left: rect.left + window.scrollX });
	}

	function insert() {
		var partial = partialBeforeCaret();
		if (!partial) {
			hide();
			return;
		}
		var node = partial.node;
		var start = partial.end - partial.length;
		var text = '@' + NAME + '\u00a0';
		node.textContent = node.textContent.slice(0, start) + text + node.textContent.slice(partial.end);
		var range = document.createRange();
		range.setStart(node, start + text.length);
		range.collapse(true);
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
		$(activeEditor).trigger('input');
		hide();
	}

	$(document).on('keyup click', '.note-editable', function (e) {
		if (e.type === 'keyup' && (e.key === 'Enter' || e.key === 'Tab' || e.key === 'Escape')) {
			return;
		}
		if (!isNoteEditor(this)) {
			hide();
			return;
		}
		var partial = partialBeforeCaret();
		if (partial) {
			show(this, partial);
		} else {
			hide();
		}
	});

	document.addEventListener('keydown', function (e) {
		if (!suggest || !activeEditor || !activeEditor.contains(e.target)) {
			return;
		}
		if (e.key === 'Enter' || e.key === 'Tab') {
			e.preventDefault();
			e.stopPropagation();
			insert();
		} else if (e.key === 'Escape') {
			e.preventDefault();
			e.stopPropagation();
			hide();
		}
	}, true);

	$(document).on('blur', '.note-editable', function () {
		setTimeout(hide, 150);
	});

	var MENTION = /(^|[\s(\u00a0])(@[A-Za-z][A-Za-z0-9_-]*)/g;

	function highlightMentions(root) {
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
		var nodes = [];
		while (walker.nextNode()) {
			MENTION.lastIndex = 0;
			if (MENTION.test(walker.currentNode.textContent) && !$(walker.currentNode.parentNode).closest('.repile-mention, a').length) {
				nodes.push(walker.currentNode);
			}
		}
		nodes.forEach(function (node) {
			var text = node.textContent;
			var frag = document.createDocumentFragment();
			var last = 0;
			var match;
			MENTION.lastIndex = 0;
			while ((match = MENTION.exec(text)) !== null) {
				var at = match.index + match[1].length;
				frag.appendChild(document.createTextNode(text.slice(last, at)));
				var span = document.createElement('span');
				var isRepile = match[2].toLowerCase() === '@repile';
				span.className = isRepile ? 'repile-mention' : 'repile-mention repile-mention-person';
				span.textContent = isRepile ? '@' + NAME : match[2];
				frag.appendChild(span);
				last = at + match[2].length;
			}
			frag.appendChild(document.createTextNode(text.slice(last)));
			node.parentNode.replaceChild(frag, node);
		});
	}

	function pollWorking() {
		var el = $('.repile-working[data-repile-state-url]:first');
		if (!el.length) {
			return;
		}
		var url = el.attr('data-repile-state-url');
		var timer = setInterval(function () {
			$.getJSON(url, function (data) {
				if (!data.working) {
					clearInterval(timer);
					window.location.reload();
				}
			});
		}, 5000);
	}

	$(document).on('click', '.repile-recheck', function (e) {
		e.preventDefault();
		$.ajax({
			url: $(this).attr('data-url'),
			method: 'POST',
			headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
			complete: function () {
				window.location.reload();
			}
		});
	});

	$(function () {
		$('.thread-type-note .thread-body').each(function () {
			highlightMentions(this);
		});
		pollWorking();
	});
})();
