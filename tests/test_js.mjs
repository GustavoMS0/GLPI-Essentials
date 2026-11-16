import assert from 'node:assert/strict';
import essentials from '../essentials/public/js/essentials.js';

const {
    stripCategoryCode,
    stripCategoryCodeFromMap,
    updateRenderedTextNodes,
    formatQuickCopyText,
} = essentials;

function createText(value) {
    return {
        nodeType: 3,
        nodeValue: value,
        parentNode: null,
        get textContent() {
            return this.nodeValue;
        },
    };
}

function createElement(tagName, attrs = {}, children = []) {
    const attrMap = new Map(Object.entries(attrs));
    const el = {
        nodeType: 1,
        tagName: tagName.toUpperCase(),
        parentNode: null,
        childNodes: [],
        get className() {
            return attrMap.get('class') || '';
        },
        classList: {
            contains(cls) {
                const raw = attrMap.get('class') || '';
                return (' ' + raw + ' ').includes(' ' + cls + ' ');
            },
        },
        getAttribute(name) {
            return attrMap.has(name) ? attrMap.get(name) : null;
        },
        setAttribute(name, val) {
            attrMap.set(name, String(val));
        },
        appendChild(child) {
            child.parentNode = el;
            el.childNodes.push(child);
            return child;
        },
        removeChild(child) {
            const idx = el.childNodes.indexOf(child);
            if (idx !== -1) {
                el.childNodes.splice(idx, 1);
                child.parentNode = null;
            }
            return child;
        },
        get textContent() {
            return el.childNodes.map((c) => c.textContent).join('');
        },
    };
    for (const child of children) {
        el.appendChild(child);
    }
    return el;
}

// 1. Names containing hyphens WITHOUT a matching DB code must remain untouched
assert.equal(stripCategoryCode('ERP - Financeiro', ''), 'ERP - Financeiro');
assert.equal(stripCategoryCode('ERP - Financeiro', null), 'ERP - Financeiro');
assert.equal(stripCategoryCode('ERP - Financeiro', undefined), 'ERP - Financeiro');
assert.equal(stripCategoryCode('ERP - Financeiro', 'FIN01'), 'ERP - Financeiro');

// 2. Category with matching DB code strips only the trailing " - <code>" suffix
assert.equal(stripCategoryCode('Hardware - HW01', 'HW01'), 'Hardware');
assert.equal(stripCategoryCode('Hardware - HW01   ', 'HW01'), 'Hardware');
assert.equal(stripCategoryCode('Hardware - HW01 (42)', 'HW01'), 'Hardware (42)');

// 3. Hierarchical completename preserves parent chain and any legitimate hyphens in parent names
assert.equal(
    stripCategoryCode('TI > Infraestrutura > Hardware - HW01', 'HW01'),
    'TI > Infraestrutura > Hardware'
);
assert.equal(
    stripCategoryCode('TI - Corporativo > Hardware - HW01', 'HW01'),
    'TI - Corporativo > Hardware'
);

// 4. Idempotent when already stripped
assert.equal(stripCategoryCode('Hardware', 'HW01'), 'Hardware');

// 5. Map lookup helper respects category ID and missing entries
const codeMap = {
    '10': 'HW01',
    '11': 'NET-02',
};
assert.equal(stripCategoryCodeFromMap('TI > Hardware - HW01', '10', codeMap), 'TI > Hardware');
assert.equal(stripCategoryCodeFromMap('Redes > Switch - NET-02', 11, codeMap), 'Redes > Switch');
assert.equal(stripCategoryCodeFromMap('ERP - Financeiro', '99', codeMap), 'ERP - Financeiro');
assert.equal(stripCategoryCodeFromMap('ERP - Financeiro', '0', codeMap), 'ERP - Financeiro');

// 6. Quick copy payload formatting
assert.equal(
    formatQuickCopyText(123, 'Impressora sem rede', 'https://glpi.local/front/ticket.form.php?id=123'),
    '#123 - Impressora sem rede\nhttps://glpi.local/front/ticket.form.php?id=123'
);

// 7. Select2 match highlight splitting across text nodes and <span class="select2-rendered__match">
{
    // Case A: search matched only the code ("HW01") -> code + empty match span removed cleanly
    const liA = createElement('li', { class: 'select2-results__option', title: 'Hardware - HW01' }, [
        createText('Hardware - '),
        createElement('span', { class: 'select2-rendered__match' }, [createText('HW01')]),
    ]);
    updateRenderedTextNodes(liA, '10', codeMap);
    assert.equal(liA.textContent, 'Hardware');
    assert.equal(liA.getAttribute('title'), 'Hardware');
    assert.equal(liA.childNodes.length, 1, 'Empty match span removed cleanly');

    // Case B: search matched across base name and code ("ware - HW") -> base highlight preserved, code removed
    const matchSpanB = createElement('span', { class: 'select2-rendered__match' }, [createText('ware - HW')]);
    const liB = createElement('li', { class: 'select2-results__option' }, [
        createText('Hard'),
        matchSpanB,
        createText('01'),
    ]);
    updateRenderedTextNodes(liB, '10', codeMap);
    assert.equal(liB.textContent, 'Hardware');
    assert.equal(matchSpanB.textContent, 'ware', 'Matched base characters remain highlighted');

    // Case C: rendered selection preserves .select2-selection__clear and updates title without code
    const clearBtn = createElement('span', { class: 'select2-selection__clear' }, [createText('×')]);
    const rendered = createElement(
        'span',
        { class: 'select2-selection__rendered', title: 'TI > Hardware - HW01' },
        [clearBtn, createText('TI > Hardware - '), createElement('span', { class: 'select2-rendered__match' }, [createText('HW01')])]
    );
    updateRenderedTextNodes(rendered, '10', codeMap);
    assert.equal(clearBtn.textContent, '×', 'Clear button preserved');
    assert.equal(rendered.getAttribute('title'), 'TI > Hardware');
    assert.equal(rendered.textContent, '×TI > Hardware');

    // Case D: uncoded category with hyphen and highlight span remains untouched
    const matchSpanD = createElement('span', { class: 'select2-rendered__match' }, [createText('Financeiro')]);
    const liD = createElement('li', { class: 'select2-results__option' }, [
        createText('ERP - '),
        matchSpanD,
    ]);
    updateRenderedTextNodes(liD, '99', codeMap);
    assert.equal(liD.textContent, 'ERP - Financeiro');
    assert.equal(matchSpanD.textContent, 'Financeiro');
}

console.log('All JS unit tests passed.');
