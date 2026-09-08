/**
 * hh_ext_faqs – client-side FAQ live search.
 *
 * Filters FAQ list plugins ([data-faq-list]) while typing:
 * - starts filtering at a minimum number of characters (default 3)
 * - matches question AND answer text, diacritic-insensitive
 *   ("mietrad" also finds "Mieträder")
 * - hides non-matching FAQ items ([data-faq-item])
 * - hides the whole content element container (incl. header) of a list
 *   when it has no matching items
 * - highlights matches with <mark class="highlight"> and expands
 *   matching entries while the search is active (previous open/closed
 *   state is restored when the search is cleared)
 * - announces the result count via an aria-live status element
 */
(function () {
    'use strict';

    var DEFAULT_MIN_CHARS = 3;
    var DEBOUNCE_MS = 120;
    var HIGHLIGHT_CLASS = 'highlight';
    var RESTORE_ATTRIBUTE = 'data-restore-open';

    /**
     * Lowercase + fold diacritics for tolerant matching ("Muller" finds "Müller").
     */
    function normalize(text) {
        var lower = String(text || '').toLocaleLowerCase();
        try {
            return lower.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (e) {
            return lower;
        }
    }

    /**
     * Fold a string character by character and keep a map from every
     * folded character back to the index of its original character.
     * Needed to translate match positions in the folded text back to
     * positions in the original text for highlighting.
     */
    function foldWithMap(text) {
        var folded = '';
        var map = [];
        for (var i = 0; i < text.length; i++) {
            var f = normalize(text.charAt(i));
            for (var k = 0; k < f.length; k++) {
                folded += f.charAt(k);
                map.push(i);
            }
        }
        return { folded: folded, map: map };
    }

    function setHidden(element, hidden) {
        if (!element) {
            return;
        }
        if (hidden) {
            element.setAttribute('hidden', 'hidden');
            element.classList.add('hidden');
        } else {
            element.removeAttribute('hidden');
            element.classList.remove('hidden');
        }
    }

    /**
     * Resolve the wrapper element to hide for a FAQ list: the surrounding
     * content element frame (#c<uid>) if it exists and contains the list,
     * otherwise the list element itself.
     */
    function resolveWrapper(listElement) {
        var uid = listElement.getAttribute('data-faq-list');
        if (uid) {
            var anchor = document.getElementById('c' + uid);
            if (anchor && anchor.contains(listElement)) {
                return anchor;
            }
        }
        return listElement;
    }

    /**
     * Collect the FAQ lists this search input is responsible for.
     */
    function resolveTargets(input) {
        var targetsAttribute = (input.getAttribute('data-faq-targets') || '').trim();
        var lists = [];

        if (targetsAttribute === '') {
            // No explicit selection: search all FAQ lists on the page
            var allLists = document.querySelectorAll('[data-faq-list]');
            for (var i = 0; i < allLists.length; i++) {
                lists.push(allLists[i]);
            }
            return lists;
        }

        var uids = targetsAttribute.split(',');
        for (var j = 0; j < uids.length; j++) {
            var uid = uids[j].trim();
            if (uid === '') {
                continue;
            }
            var list = document.querySelector('[data-faq-list="' + uid + '"]');
            if (list) {
                lists.push(list);
            }
        }
        return lists;
    }

    /**
     * Remove all previously added highlight marks below the given root
     * and merge the remaining text nodes again.
     */
    function removeHighlights(root) {
        var marks = root.querySelectorAll('mark.' + HIGHLIGHT_CLASS);
        for (var i = 0; i < marks.length; i++) {
            var mark = marks[i];
            var parent = mark.parentNode;
            if (!parent) {
                continue;
            }
            while (mark.firstChild) {
                parent.insertBefore(mark.firstChild, mark);
            }
            parent.removeChild(mark);
            parent.normalize();
        }
    }

    /**
     * Merge overlapping/adjacent [start, end] ranges.
     */
    function mergeRanges(ranges) {
        ranges.sort(function (a, b) {
            return a[0] - b[0];
        });
        var merged = [];
        for (var i = 0; i < ranges.length; i++) {
            var last = merged[merged.length - 1];
            if (last && ranges[i][0] <= last[1]) {
                last[1] = Math.max(last[1], ranges[i][1]);
            } else {
                merged.push([ranges[i][0], ranges[i][1]]);
            }
        }
        return merged;
    }

    /**
     * Wrap all token matches inside a single text node into
     * <mark class="highlight"> elements.
     */
    function highlightTextNode(node, tokens) {
        var text = node.nodeValue;
        var data = foldWithMap(text);
        var ranges = [];

        for (var t = 0; t < tokens.length; t++) {
            var token = tokens[t];
            var index = data.folded.indexOf(token);
            while (index !== -1) {
                var start = data.map[index];
                var end = data.map[index + token.length - 1] + 1;
                ranges.push([start, end]);
                index = data.folded.indexOf(token, index + 1);
            }
        }
        if (ranges.length === 0) {
            return;
        }

        var merged = mergeRanges(ranges);
        var fragment = document.createDocumentFragment();
        var position = 0;
        for (var r = 0; r < merged.length; r++) {
            var rangeStart = merged[r][0];
            var rangeEnd = merged[r][1];
            if (rangeStart > position) {
                fragment.appendChild(document.createTextNode(text.slice(position, rangeStart)));
            }
            var mark = document.createElement('mark');
            mark.className = HIGHLIGHT_CLASS;
            mark.appendChild(document.createTextNode(text.slice(rangeStart, rangeEnd)));
            fragment.appendChild(mark);
            position = rangeEnd;
        }
        if (position < text.length) {
            fragment.appendChild(document.createTextNode(text.slice(position)));
        }
        node.parentNode.replaceChild(fragment, node);
    }

    /**
     * Highlight all token matches inside a FAQ item.
     */
    function highlightItem(item, tokens) {
        var walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        var node;
        while ((node = walker.nextNode())) {
            var parent = node.parentNode;
            if (!parent) {
                continue;
            }
            var tag = parent.nodeName;
            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'MARK') {
                continue;
            }
            if (node.nodeValue.trim() === '') {
                continue;
            }
            nodes.push(node);
        }
        for (var i = 0; i < nodes.length; i++) {
            highlightTextNode(nodes[i], tokens);
        }
    }

    /**
     * Expand matching entries while the search is active; restore the
     * previous open/closed state as soon as the search is cleared.
     */
    function updateExpansion(item, active, matches) {
        var details = item.querySelector('details');
        if (!details) {
            return;
        }
        if (active && matches) {
            if (!details.hasAttribute(RESTORE_ATTRIBUTE)) {
                details.setAttribute(RESTORE_ATTRIBUTE, details.open ? '1' : '0');
            }
            details.open = true;
        } else if (details.hasAttribute(RESTORE_ATTRIBUTE)) {
            details.open = details.getAttribute(RESTORE_ATTRIBUTE) === '1';
            details.removeAttribute(RESTORE_ATTRIBUTE);
        }
    }

    function formatMessage(template, shown, total) {
        return String(template || '')
            .replace('{0}', String(shown))
            .replace('{1}', String(total));
    }

    function applyFilter(input, lists, statusElement, minChars, autoExpand) {
        var rawValue = input.value || '';
        var query = normalize(rawValue.trim());
        var active = query.length >= minChars;
        var tokens = active ? query.split(/\s+/).filter(function (token) {
            return token.length > 0;
        }) : [];

        var totalItems = 0;
        var totalShown = 0;

        for (var i = 0; i < lists.length; i++) {
            var list = lists[i];
            var wrapper = resolveWrapper(list);
            var items = list.querySelectorAll('[data-faq-item]');
            var shownInList = 0;

            for (var j = 0; j < items.length; j++) {
                var item = items[j];
                totalItems++;

                removeHighlights(item);

                var matches = true;
                if (active) {
                    var haystack = normalize(item.textContent);
                    for (var t = 0; t < tokens.length; t++) {
                        if (haystack.indexOf(tokens[t]) === -1) {
                            matches = false;
                            break;
                        }
                    }
                }

                if (active && matches) {
                    highlightItem(item, tokens);
                }

                setHidden(item, active && !matches);
                if (autoExpand) {
                    updateExpansion(item, active, matches);
                }

                if (!active || matches) {
                    shownInList++;
                    totalShown++;
                }
            }

            // Hide the whole container (incl. header) if nothing matches
            setHidden(wrapper, active && shownInList === 0);
        }

        if (statusElement) {
            if (!active) {
                statusElement.textContent = '';
            } else if (totalShown === 0) {
                statusElement.textContent = input.getAttribute('data-msg-no-results') || '';
            } else {
                statusElement.textContent = formatMessage(
                    input.getAttribute('data-msg-results'),
                    totalShown,
                    totalItems
                );
            }
        }
    }

    function initSearch(input) {
        if (input.hhextfaqsInitialized) {
            return;
        }
        input.hhextfaqsInitialized = true;

        var minChars = parseInt(input.getAttribute('data-min-chars') || '', 10);
        if (isNaN(minChars) || minChars < 1) {
            minChars = DEFAULT_MIN_CHARS;
        }

        var form = input.closest('form');
        var statusElement = form ? form.querySelector('[data-faq-search-status]') : null;
        var autoExpand = input.getAttribute('data-auto-expand') !== '0';
        var lists = resolveTargets(input);
        if (lists.length === 0) {
            return;
        }

        var debounceTimer = null;
        var run = function () {
            applyFilter(input, lists, statusElement, minChars, autoExpand);
        };

        input.addEventListener('input', function () {
            if (debounceTimer) {
                window.clearTimeout(debounceTimer);
            }
            debounceTimer = window.setTimeout(run, DEBOUNCE_MS);
        });

        // Fires when the native "clear" button of type=search is used
        input.addEventListener('search', run);

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                run();
            });
        }

        // Apply initial state (e.g. browser restored a value after back navigation)
        run();
    }

    function boot() {
        var inputs = document.querySelectorAll('[data-faq-search]');
        for (var i = 0; i < inputs.length; i++) {
            initSearch(inputs[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
