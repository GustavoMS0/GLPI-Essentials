/**
 * Essentials - Client-side helpers for GLPI 10 / 11 / 12
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 */
(function (root) {
    'use strict';

    /**
     * Finds the character range [start, end) to remove for trailing " - <code>"
     * (preserving optional trailing " (<id>)"), or returns null if not applicable.
     *
     * @param {string} label
     * @param {string|null|undefined} code
     * @returns {{ start: number, end: number }|null}
     */
    function findCodeRemovalRange(label, code) {
        if (typeof label !== 'string' || typeof code !== 'string') {
            return null;
        }
        const cleanCode = code.trim();
        if (cleanCode === '') {
            return null;
        }

        const trimmedEnd = label.replace(/\s+$/, '');
        const suffix = ' - ' + cleanCode;

        if (trimmedEnd.endsWith(suffix)) {
            return {
                start: trimmedEnd.length - suffix.length,
                end: label.length,
            };
        }

        const idMatch = trimmedEnd.match(/(\s\(\d+\))$/);
        if (idMatch) {
            const idSuffix = idMatch[1];
            const withoutId = trimmedEnd.slice(0, trimmedEnd.length - idSuffix.length);
            if (withoutId.endsWith(suffix)) {
                return {
                    start: withoutId.length - suffix.length,
                    end: trimmedEnd.length - idSuffix.length,
                };
            }
        }

        return null;
    }

    /**
     * Strips trailing " - <code>" (and optional " (<id>)" suffix) from a category
     * label ONLY when a non-empty matching DB code is provided.
     *
     * @param {string} label
     * @param {string|null|undefined} code
     * @returns {string}
     */
    function stripCategoryCode(label, code) {
        if (typeof label !== 'string') {
            return '';
        }
        const range = findCodeRemovalRange(label, code);
        if (!range) {
            return label;
        }
        const trimmedEnd = label.replace(/\s+$/, '');
        const tail = range.end < trimmedEnd.length ? trimmedEnd.slice(range.end) : label.slice(range.end);
        return label.slice(0, range.start) + tail;
    }

    /**
     * Looks up the DB code for categoryId in codeMap and strips it from label.
     *
     * @param {string} label
     * @param {string|number|null|undefined} categoryId
     * @param {Record<string, string>|null|undefined} codeMap
     * @returns {string}
     */
    function stripCategoryCodeFromMap(label, categoryId, codeMap) {
        if (!codeMap || categoryId === null || categoryId === undefined) {
            return typeof label === 'string' ? label : '';
        }
        const key = String(categoryId).trim();
        if (key === '' || !Object.prototype.hasOwnProperty.call(codeMap, key)) {
            return typeof label === 'string' ? label : '';
        }
        return stripCategoryCode(label, codeMap[key]);
    }

    /**
     * Checks whether a DOM element carries a CSS class safely.
     *
     * @param {Element} el
     * @param {string} className
     * @returns {boolean}
     */
    function hasCssClass(el, className) {
        if (!el) {
            return false;
        }
        if (el.classList && typeof el.classList.contains === 'function') {
            return el.classList.contains(className);
        }
        return typeof el.className === 'string'
            && (' ' + el.className + ' ').indexOf(' ' + className + ' ') !== -1;
    }

    /**
     * Identifies non-label elements (clear buttons, decorative icons) that must remain untouched.
     *
     * @param {Element} el
     * @returns {boolean}
     */
    function isNonLabelElement(el) {
        if (hasCssClass(el, 'select2-selection__clear')) {
            return true;
        }
        if (typeof el.getAttribute === 'function' && el.getAttribute('aria-hidden') === 'true') {
            return true;
        }
        const tag = typeof el.tagName === 'string' ? el.tagName.toUpperCase() : '';
        return tag === 'I' || tag === 'SVG' || tag === 'BUTTON';
    }

    /**
     * Collects text nodes participating in the visible label across text and match spans.
     *
     * @param {Node} node
     * @param {Node[]} out
     */
    function collectLabelTextNodes(node, out) {
        if (!node || !node.childNodes) {
            return;
        }
        const children = node.childNodes;
        for (let i = 0; i < children.length; i++) {
            const child = children[i];
            if (!child) {
                continue;
            }
            if (child.nodeType === 3) {
                out.push(child);
            } else if (child.nodeType === 1 && !isNonLabelElement(child)) {
                collectLabelTextNodes(child, out);
            }
        }
    }

    /**
     * Strips trailing " - <code>" across split text nodes and Select2 highlight spans
     * via pure DOM text-node updates while preserving clear buttons and icons.
     *
     * @param {Element} container
     * @param {string|number|null|undefined} categoryId
     * @param {Record<string, string>|null|undefined} codeMap
     */
    function updateRenderedTextNodes(container, categoryId, codeMap) {
        if (!container || categoryId === null || categoryId === undefined || !codeMap) {
            return;
        }
        const key = String(categoryId).trim();
        if (key === '' || !Object.prototype.hasOwnProperty.call(codeMap, key)) {
            return;
        }
        const code = codeMap[key];
        if (typeof code !== 'string' || code.trim() === '') {
            return;
        }

        if (typeof container.getAttribute === 'function' && typeof container.setAttribute === 'function') {
            const title = container.getAttribute('title');
            if (title) {
                const strippedTitle = stripCategoryCode(title, code);
                if (strippedTitle !== title) {
                    container.setAttribute('title', strippedTitle);
                }
            }
        }

        const textNodes = [];
        collectLabelTextNodes(container, textNodes);
        if (textNodes.length === 0) {
            return;
        }

        let fullText = '';
        const segments = [];
        for (let i = 0; i < textNodes.length; i++) {
            const tn = textNodes[i];
            const str = typeof tn.nodeValue === 'string' ? tn.nodeValue : '';
            if (str === '') {
                continue;
            }
            const start = fullText.length;
            fullText += str;
            segments.push({
                node: tn,
                start: start,
                end: fullText.length,
                text: str,
            });
        }

        if (segments.length === 0) {
            return;
        }

        const range = findCodeRemovalRange(fullText, code);
        if (!range) {
            return;
        }

        const trimmedEnd = fullText.replace(/\s+$/, '');
        const removeEnd = range.end < trimmedEnd.length ? range.end : fullText.length;

        for (let i = 0; i < segments.length; i++) {
            const seg = segments[i];
            if (seg.end <= range.start || seg.start >= removeEnd) {
                continue;
            }
            const cutStart = Math.max(range.start, seg.start) - seg.start;
            const cutEnd = Math.min(removeEnd, seg.end) - seg.start;
            const nextText = seg.text.slice(0, cutStart) + seg.text.slice(cutEnd);
            if (nextText !== seg.text) {
                seg.node.nodeValue = nextText;
            }
            if (nextText === '') {
                const parent = seg.node.parentNode;
                if (
                    parent
                    && parent !== container
                    && hasCssClass(parent, 'select2-rendered__match')
                    && parent.parentNode
                    && (parent.textContent === '' || parent.textContent === undefined)
                ) {
                    parent.parentNode.removeChild(parent);
                }
            }
        }
    }

    /**
     * Formats quick-copy clipboard payload for a ticket.
     *
     * @param {number|string} ticketId
     * @param {string} title
     * @param {string} url
     * @returns {string}
     */
    function formatQuickCopyText(ticketId, title, url) {
        const idNum = Number(ticketId);
        const prefix = idNum > 0 ? '#' + String(idNum) : '';
        const cleanTitle = typeof title === 'string' ? title.trim() : '';
        const cleanUrl = typeof url === 'string' ? url.trim() : '';

        let header = prefix;
        if (cleanTitle !== '') {
            header = header !== '' ? header + ' - ' + cleanTitle : cleanTitle;
        }

        if (header !== '' && cleanUrl !== '') {
            return header + '\n' + cleanUrl;
        }
        return header || cleanUrl;
    }

    const api = {
        stripCategoryCode,
        stripCategoryCodeFromMap,
        updateRenderedTextNodes,
        formatQuickCopyText,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }

    if (typeof document === 'undefined' || typeof window === 'undefined') {
        return;
    }

    let categoryCodeMap = null;
    let fetchPromise = null;
    let listenerBound = false;
    let observerStarted = false;
    let refreshScheduled = false;
    let isApplying = false;

    function getConfig() {
        const cfg = window.GLPI_ESSENTIALS_CFG;
        return cfg && typeof cfg === 'object' ? cfg : {};
    }

    function resolveCopyText(button) {
        const explicit = button.getAttribute('data-copy-text');
        if (explicit) {
            const lines = explicit.split('\n');
            if (lines.length >= 2 && lines[1] && lines[1].startsWith('/')) {
                try {
                    lines[1] = new URL(lines[1], window.location.origin).toString();
                    return lines.join('\n');
                } catch (_err) {
                    return explicit;
                }
            }
            return explicit;
        }
        const cfg = getConfig();
        let url = cfg.ticket_url || window.location.href;
        if (url && url.startsWith('/')) {
            try {
                url = new URL(url, window.location.origin).toString();
            } catch (_err) {
                // keep original string
            }
        }
        return formatQuickCopyText(cfg.ticket_id, cfg.ticket_title, url);
    }

    function copyTextToClipboard(text) {
        if (
            window.isSecureContext &&
            navigator.clipboard &&
            typeof navigator.clipboard.writeText === 'function'
        ) {
            return navigator.clipboard.writeText(text).catch(function () {
                return fallbackCopy(text);
            });
        }
        return fallbackCopy(text);
    }

    function fallbackCopy(text) {
        return new Promise(function (resolve, reject) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            let ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (_err) {
                ok = false;
            }
            document.body.removeChild(ta);
            if (ok) {
                resolve();
            } else {
                reject(new Error('Copy failed'));
            }
        });
    }

    function handleCopyClick(event) {
        const btn = event.target && event.target.closest
            ? event.target.closest('.glpi-essentials-copy-btn')
            : null;
        if (!btn) {
            return;
        }
        event.preventDefault();

        const payload = resolveCopyText(btn);
        if (!payload) {
            return;
        }

        copyTextToClipboard(payload).then(function () {
            const labelEl = btn.querySelector('.glpi-essentials-copy-label');
            const defaultLabel = btn.getAttribute('data-default-label') || 'Copiar resumo e link';
            const copiedLabel = btn.getAttribute('data-copied-label') || 'Copiado!';

            btn.classList.add('is-copied');
            if (labelEl) {
                labelEl.textContent = copiedLabel;
            }
            window.setTimeout(function () {
                btn.classList.remove('is-copied');
                if (labelEl) {
                    labelEl.textContent = defaultLabel;
                }
            }, 2000);
        }).catch(function () {
            // Silent fallback when clipboard API is blocked by browser policy
        });
    }

    function getCategorySelects() {
        return document.querySelectorAll(
            'select[name="itilcategories_id"], select[name^="itilcategories_id"], select[id^="dropdown_itilcategories_id"]'
        );
    }

    function extractOptionIdFromResultLi(li) {
        if (window.jQuery) {
            try {
                const data = window.jQuery(li).data('data');
                if (data && data.id !== undefined && data.id !== null) {
                    return String(data.id);
                }
            } catch (_err) {
                // fallback to DOM id parsing
            }
        }
        if (li.id) {
            const match = li.id.match(/-(\d+)$/);
            if (match) {
                return match[1];
            }
        }
        return null;
    }

    function applyCategoryStripping(codeMap) {
        if (!codeMap || typeof codeMap !== 'object') {
            return;
        }

        const selects = getCategorySelects();
        const selectIds = [];

        selects.forEach(function (select) {
            if (select.id) {
                selectIds.push(select.id);
            }

            const options = select.options;
            for (let i = 0; i < options.length; i++) {
                const opt = options[i];
                if (!opt.value || opt.value === '0') {
                    continue;
                }
                const currentText = opt.textContent;
                const stripped = stripCategoryCodeFromMap(currentText, opt.value, codeMap);
                if (stripped !== currentText) {
                    opt.textContent = stripped;
                }
            }

            if (select.id) {
                const rendered = document.getElementById('select2-' + select.id + '-container');
                if (rendered) {
                    updateRenderedTextNodes(rendered, select.value, codeMap);
                }
                const resultsList = document.getElementById('select2-' + select.id + '-results');
                if (resultsList) {
                    const items = resultsList.querySelectorAll('li.select2-results__option');
                    items.forEach(function (li) {
                        const catId = extractOptionIdFromResultLi(li);
                        if (catId) {
                            updateRenderedTextNodes(li, catId, codeMap);
                        }
                    });
                }
            }
        });

        // Also handle open Select2 result lists matching category dropdown naming
        const openCategoryLists = document.querySelectorAll(
            'ul[id^="select2-dropdown_itilcategories_id"][id$="-results"]'
        );
        openCategoryLists.forEach(function (list) {
            const items = list.querySelectorAll('li.select2-results__option');
            items.forEach(function (li) {
                const catId = extractOptionIdFromResultLi(li);
                if (catId) {
                    updateRenderedTextNodes(li, catId, codeMap);
                }
            });
        });

        // Readonly category links in ticket view
        const catLinks = document.querySelectorAll('a[href*="itilcategory.form.php?id="]');
        catLinks.forEach(function (anchor) {
            const href = anchor.getAttribute('href') || '';
            const match = href.match(/[?&]id=(\d+)/);
            if (match) {
                updateRenderedTextNodes(anchor, match[1], codeMap);
            }
        });
    }

    function runApply(codeMap) {
        if (isApplying) {
            return;
        }
        isApplying = true;
        try {
            applyCategoryStripping(codeMap);
        } finally {
            isApplying = false;
        }
    }

    function scheduleCategoryRefresh() {
        if (!categoryCodeMap || refreshScheduled || isApplying) {
            return;
        }
        refreshScheduled = true;
        window.setTimeout(function () {
            refreshScheduled = false;
            runApply(categoryCodeMap);
        }, 0);
    }

    function ensureCategoryObserver() {
        if (observerStarted || typeof MutationObserver === 'undefined' || !document.body) {
            return;
        }
        observerStarted = true;
        const observer = new MutationObserver(function (mutations) {
            if (isApplying) {
                return;
            }
            for (let i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length > 0) {
                    scheduleCategoryRefresh();
                    return;
                }
            }
        });
        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
        document.addEventListener('change', function (event) {
            const target = event.target;
            if (target && target.tagName === 'SELECT') {
                scheduleCategoryRefresh();
            }
        });
    }

    function fetchCategoryCodes(url) {
        if (categoryCodeMap !== null) {
            return Promise.resolve(categoryCodeMap);
        }
        if (fetchPromise) {
            return fetchPromise;
        }
        fetchPromise = window.fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        }).then(function (resp) {
            if (!resp.ok) {
                return {};
            }
            return resp.json();
        }).then(function (data) {
            categoryCodeMap = (data && typeof data === 'object') ? data : {};
            return categoryCodeMap;
        }).catch(function () {
            categoryCodeMap = {};
            return categoryCodeMap;
        });
        return fetchPromise;
    }

    function init() {
        if (!listenerBound) {
            listenerBound = true;
            document.addEventListener('click', handleCopyClick);
        }

        const cfg = getConfig();
        if (cfg.hide_category_code && cfg.categories_url) {
            ensureCategoryObserver();
            fetchCategoryCodes(cfg.categories_url).then(function (map) {
                runApply(map);
            });
        }
    }

    window.glpiEssentialsRefresh = init;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(typeof globalThis !== 'undefined' ? globalThis : this);
