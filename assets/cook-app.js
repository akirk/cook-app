(function () {
	document.addEventListener('click', event => {
		const button = event.target.closest('[data-cookbook-confirm]');
		if (button && !window.confirm(button.getAttribute('data-cookbook-confirm'))) {
			event.preventDefault();
		}
	});

	const homeIngredients = document.getElementById('home-ingredients');
	const homeIngredientCloud = homeIngredients ? homeIngredients.querySelector('[data-home-ingredient-cloud]') : null;
	if (homeIngredients && homeIngredientCloud && window.fetch) {
		fetch(homeIngredients.getAttribute('data-home-ingredients-endpoint'), {
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
				'X-WP-Nonce': homeIngredients.getAttribute('data-home-ingredients-nonce') || ''
			}
		}).then(response => {
			if (!response.ok) throw new Error('Ingredient request failed');
			return response.json();
		}).then(data => {
			if (!Array.isArray(data.terms) || !data.terms.length) {
				return;
			}

			data.terms.forEach(term => {
				const link = document.createElement('a');
				link.className = 'ing-chip';
				link.href = term.url;
				link.style.fontSize = term.font_size + 'rem';

				const name = document.createElement('span');
				name.textContent = term.name;
				link.appendChild(name);

				const count = document.createElement('span');
				count.className = 'ing-chip-count';
				count.textContent = term.count;
				link.appendChild(count);

				homeIngredientCloud.appendChild(link);
			});

			const all = document.createElement('a');
			all.className = 'ing-chip';
			all.href = data.all_url;
			all.style.fontSize = '0.85rem';
			all.textContent = data.all_label;
			homeIngredientCloud.appendChild(all);

			homeIngredients.hidden = false;
		}).catch(() => {});
	}

	if (document.getElementById('import-overlay')) {
		const importForm = document.getElementById('import-form');
		if (importForm) importForm.submit();
	}

    document.querySelectorAll('.cooked-edit-toggle').forEach(button => {
        const panel = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!panel) return;
        const row = button.closest('li');
        const setOpen = open => {
            panel.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (row) row.classList.toggle('is-editing', open);
            if (open) {
                const field = panel.querySelector('textarea, input, select, button');
                if (field) field.focus();
            }
        };
        button.addEventListener('click', () => setOpen(panel.hidden));
        panel.querySelectorAll('.cooked-edit-cancel').forEach(cancel => {
            cancel.addEventListener('click', () => {
                setOpen(false);
                button.focus();
            });
        });
    });
})();

(function () {
// Toggle the .on class as the user clicks chips, so the visual state matches
// the checkbox without a round-trip. The form still submits via the button.
document.querySelectorAll('.ing-chip input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => cb.closest('.ing-chip').classList.toggle('on', cb.checked));
});
})();

(function () {
const form    = document.getElementById('mi-form');
const list    = document.getElementById('mi-list');
const search  = document.getElementById('mi-search');
const target  = document.getElementById('mi-target');
const action  = document.getElementById('mi-action');
const merge   = document.getElementById('mi-merge');
const group   = document.getElementById('mi-group');
const counter = document.getElementById('mi-count');
const toolbar = document.getElementById('mi-toolbar');

if (!form || !list) return;

function refresh() {
    const checked = list.querySelectorAll('.mi-check:checked');
    const n = checked.length;
    counter.textContent = String(n);
    toolbar.classList.toggle('is-active', n > 0);
    const haveTarget = parseInt(target.value, 10) > 0;
    // For "merge" the target must not be one of the checked sources.
    let conflict = false;
    if (haveTarget) {
        checked.forEach(cb => { if (cb.value === target.value) conflict = true; });
    }
    merge.disabled = !(n > 0 && haveTarget && !conflict);
    // "Group" allows target = 0 (top-level).
    group.disabled = !(n > 0 && !conflict);
}

let lastOp = 'merge';
list.addEventListener('change', e => {
    if (e.target.classList.contains('mi-check')) refresh();
});
target.addEventListener('change', refresh);
merge.addEventListener('click', () => { lastOp = 'merge'; action.value = 'cookbook_merge_ingredients'; });
group.addEventListener('click', () => { lastOp = 'group'; action.value = 'cookbook_group_ingredients'; });

form.addEventListener('submit', e => {
    const op = lastOp;
    const n  = list.querySelectorAll('.mi-check:checked').length;
    const tgt = target.options[target.selectedIndex].text.replace(/\s*\(\d+\)\s*$/, '');
    const msg = op === 'merge'
        ? `Merge ${n} ingredient(s) into "${tgt}"? This rewrites the linked recipes and deletes the source terms.`
        : (parseInt(target.value, 10) > 0
            ? `Make ${n} ingredient(s) children of "${tgt}"?`
            : `Move ${n} ingredient(s) to top level (no parent)?`);
    if (!window.confirm(msg)) e.preventDefault();
});

// Substring filter — case-insensitive, matches the lowercased name on data-name.
search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    list.querySelectorAll('.mi-row').forEach(row => {
        const name = row.getAttribute('data-name') || '';
        row.classList.toggle('is-hidden', q !== '' && !name.includes(q));
    });
});

// Inline rename: posts via the hidden mi-rename-form so we can keep this page sticky-stateful.
const renameForm = document.getElementById('mi-rename-form');
list.addEventListener('click', e => {
    const row = e.target.closest('.mi-row');
    if (!row) return;
    if (e.target.classList.contains('mi-rename-toggle')) {
        row.classList.add('is-renaming');
        row.querySelector('.mi-rename').classList.add('is-open');
        const inp = row.querySelector('.mi-rename input');
        inp.focus(); inp.select();
    } else if (e.target.classList.contains('mi-rename-cancel')) {
        row.classList.remove('is-renaming');
        row.querySelector('.mi-rename').classList.remove('is-open');
        const inp = row.querySelector('.mi-rename input');
        inp.value = inp.getAttribute('data-original') || '';
    } else if (e.target.classList.contains('mi-rename-save')) {
        const inp = row.querySelector('.mi-rename input');
        const name = inp.value.trim();
        if (name === '' || name === inp.getAttribute('data-original')) {
            row.classList.remove('is-renaming');
            row.querySelector('.mi-rename').classList.remove('is-open');
            return;
        }
        document.getElementById('mi-rename-term').value = row.getAttribute('data-id');
        document.getElementById('mi-rename-name').value = name;
        renameForm.submit();
    }
});

refresh();
})();

(function () {
let updateBulkState = () => {};

const form = document.getElementById('shopping-list-form');
if (form) {
    form.addEventListener('click', e => {
        if (!e.target.classList || !e.target.classList.contains('remove')) return;
        const row = e.target.closest('.shopping-row');
        if (row) {
            row.remove();
            updateBulkState();
        }
    });
}

const shopList = document.getElementById('shop-list');
if (shopList) {
    const remaining = document.getElementById('shop-remaining-count');
    const undo = document.getElementById('undo-shop-check');
    let lastChange = null;

    function checkedRows() {
        return Array.from(shopList.querySelectorAll('.shop-item')).filter(row => row.querySelector('.shop-check').checked);
    }

    function updateShopState() {
        const rows = Array.from(shopList.querySelectorAll('.shop-item'));
        let remainingCount = 0;
        rows.forEach(row => {
            const isChecked = row.querySelector('.shop-check').checked;
            row.classList.toggle('is-checked', isChecked);
            if (!isChecked) remainingCount++;
        });
        if (remaining) remaining.textContent = String(remainingCount);
        checkedRows().forEach(row => shopList.appendChild(row));
    }

    shopList.querySelectorAll('.shop-check').forEach(cb => {
        cb.addEventListener('change', () => {
            lastChange = { checkbox: cb, checked: !cb.checked };
            if (undo) undo.hidden = false;
            updateShopState();
        });
    });

    if (undo) {
        undo.addEventListener('click', () => {
            if (!lastChange) return;
            lastChange.checkbox.checked = lastChange.checked;
            lastChange = null;
            undo.hidden = true;
            updateShopState();
        });
    }

    updateShopState();
}

const shoppingList = document.querySelector('.shopping-list');
const bulkBar = document.getElementById('shopping-bulk-bar');
const selectedCount = document.getElementById('shopping-selected-count');
const bulkName = document.getElementById('bulk-item-name');
const bulkMerge = document.getElementById('bulk-merge-selected');
const bulkRemove = document.getElementById('bulk-remove-selected');
	const multipleRecipesLabels = JSON.parse(shoppingList ? shoppingList.getAttribute('data-multiple-recipes-labels') || '[]' : '[]');

function shoppingRows() {
    return shoppingList ? Array.from(shoppingList.querySelectorAll('.shopping-row')) : [];
}

function rowNameInput(row) {
    return row ? row.querySelector('input[name$="[name]"]') : null;
}

function selectedRows() {
    return shoppingRows().filter(row => {
        const cb = row.querySelector('.shopping-row-select');
        return cb && cb.checked;
    });
}

function allInputs(row) {
    return Array.from(row.querySelectorAll('input'));
}

function inputByName(row, name) {
    return allInputs(row).find(input => input.name === name) || null;
}

function itemPrefix(row) {
    const idInput = row.querySelector('input[name$="[id]"]');
    return idInput ? idInput.name.replace(/\[id\]$/, '') : '';
}

function itemInput(row, field) {
    return row.querySelector('input[name$="[' + field + ']"]');
}

function inputValue(row, field) {
    const input = itemInput(row, field);
    return input ? input.value.trim() : '';
}

function isMultipleRecipesLabel(value) {
    const title = String(value || '').trim();
    return multipleRecipesLabels.includes(title);
}

function setInputValue(row, field, value) {
    const input = itemInput(row, field);
    if (input) input.value = value;
}

function appendHidden(row, name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    row.insertBefore(input, row.querySelector('.shopping-row-select'));
}

function replaceGroupedInputs(row, groupName, values, fields) {
    const prefix = itemPrefix(row);
    if (!prefix) return;
    row.querySelectorAll('input[name*="[' + groupName + ']"]').forEach(input => input.remove());
    values.forEach((value, index) => {
        if (fields) {
            fields.forEach(field => appendHidden(row, prefix + '[' + groupName + '][' + index + '][' + field + ']', value[field] || ''));
        } else {
            appendHidden(row, prefix + '[' + groupName + '][' + index + ']', value);
        }
    });
}

function collectTermIds(rows) {
    const ids = new Set();
    rows.forEach(row => {
        row.querySelectorAll('input[name*="[term_ids]"]').forEach(input => {
            if (input.value) ids.add(input.value);
        });
    });
    return Array.from(ids);
}

function collectSourceRecipes(rows) {
    const sources = new Map();
    rows.forEach(row => {
        let hasSourceRecipes = false;
        row.querySelectorAll('input[name*="[source_recipes]"][name$="[id]"]').forEach(idInput => {
            const titleInput = inputByName(row, idInput.name.replace(/\[id\]$/, '[title]'));
            const source = {
                id: idInput.value.trim(),
                title: titleInput ? titleInput.value.trim() : ''
            };
            if (!source.id && isMultipleRecipesLabel(source.title)) return;
            const key = source.id ? 'id:' + source.id : 'title:' + source.title;
            if (source.id || source.title) {
                sources.set(key, source);
                hasSourceRecipes = true;
            }
        });

        const fallback = {
            id: inputValue(row, 'source_recipe_id'),
            title: inputValue(row, 'source_recipe_title')
        };
        const key = fallback.id ? 'id:' + fallback.id : 'title:' + fallback.title;
        if (!hasSourceRecipes && (fallback.id || (fallback.title && !isMultipleRecipesLabel(fallback.title)))) sources.set(key, fallback);
    });
    return Array.from(sources.values());
}

function formatNumber(value) {
    if (Math.abs(value - Math.round(value)) < 0.0001) return String(Math.round(value));
    return value.toFixed(2).replace(/\.?0+$/, '');
}

function parseAmount(value) {
    const normalized = String(value || '').trim().replace(',', '.');
    if (!normalized) return null;
    const number = Number(normalized);
    return Number.isFinite(number) ? number : null;
}

function mergeQuantity(primary, rows) {
    const units = rows.map(row => inputValue(row, 'unit')).filter(Boolean);
    const uniqueUnits = Array.from(new Set(units.map(unit => unit.toLowerCase())));
    if (uniqueUnits.length > 1) return;

    let sum = 0;
    let hasNumber = false;
    for (const row of rows) {
        const amount = inputValue(row, 'amount');
        if (!amount) continue;
        const parsed = parseAmount(amount);
        if (parsed === null) return;
        sum += parsed;
        hasNumber = true;
    }
    if (hasNumber) setInputValue(primary, 'amount', formatNumber(sum));
    if (units.length) setInputValue(primary, 'unit', units[0]);
}

function mergeSelectedRows() {
    const rows = selectedRows();
    if (!rows.length) return;
    const primary = rows[0];
    const target = bulkName ? bulkName.value.trim() : '';
    if (target) setInputValue(primary, 'name', target);

    mergeQuantity(primary, rows);
    const notes = Array.from(new Set(rows.map(row => inputValue(row, 'notes')).filter(Boolean)));
    setInputValue(primary, 'notes', notes.join('; '));
    setInputValue(primary, 'checked', rows.every(row => inputValue(row, 'checked')) ? '1' : '');
    replaceGroupedInputs(primary, 'term_ids', collectTermIds(rows));
    replaceGroupedInputs(primary, 'source_recipes', collectSourceRecipes(rows), ['id', 'title']);

    rows.slice(1).forEach(row => row.remove());
    const cb = primary.querySelector('.shopping-row-select');
    if (cb) cb.checked = false;
    updateBulkState();
}

function preferredName(rows) {
    const counts = new Map();
    rows.forEach(row => {
        const input = rowNameInput(row);
        if (!input || !input.value.trim()) return;
        const label = input.value.trim();
        const current = counts.get(label) || { label, count: 0 };
        current.count++;
        counts.set(label, current);
    });
    return Array.from(counts.values()).sort((a, b) => {
        if (a.count !== b.count) return b.count - a.count;
        return a.label.localeCompare(b.label);
    })[0]?.label || '';
}

updateBulkState = function () {
    if (!bulkBar) return;
    const rows = selectedRows();
    bulkBar.hidden = rows.length === 0;
    if (form) form.classList.toggle('has-shopping-bulk-bar', rows.length > 0);
    if (selectedCount) selectedCount.textContent = String(rows.length);
    if (bulkName && rows.length) bulkName.value = preferredName(rows);
    shoppingRows().forEach(row => {
        const cb = row.querySelector('.shopping-row-select');
        row.classList.toggle('is-selected', !!cb && cb.checked);
    });
};

if (shoppingList) {
    shoppingList.addEventListener('change', e => {
        if (!e.target.matches('.shopping-row-select')) return;
        updateBulkState();
    });
    updateBulkState();
}

if (bulkMerge) {
    bulkMerge.addEventListener('click', mergeSelectedRows);
}

if (bulkRemove) {
    bulkRemove.addEventListener('click', () => {
        selectedRows().forEach(row => row.remove());
        updateBulkState();
    });
}

const root = document.getElementById('manual-items');
const add = document.getElementById('add-manual-item');
if (!root || !add) return;

function renumber() {
    root.querySelectorAll('.manual-item-row').forEach((row, index) => {
        row.querySelectorAll('input').forEach(input => {
            input.name = input.name.replace(/new_items\[\d+\]/, 'new_items[' + index + ']');
        });
    });
}

add.addEventListener('click', () => {
    const row = root.querySelector('.manual-item-row').cloneNode(true);
    row.querySelectorAll('input').forEach(input => input.value = '');
    root.appendChild(row);
    renumber();
    row.querySelector('input[name$="[name]"]').focus();
});

root.addEventListener('click', e => {
    if (!e.target.classList || !e.target.classList.contains('remove')) return;
    const rows = root.querySelectorAll('.manual-item-row');
    const row = e.target.closest('.manual-item-row');
    if (rows.length > 1) {
        row.remove();
        renumber();
    } else {
        row.querySelectorAll('input').forEach(input => input.value = '');
    }
});
})();
