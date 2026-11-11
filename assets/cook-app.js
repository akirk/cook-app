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
const counter = document.getElementById('mi-count');
const toolbar = document.getElementById('mi-toolbar');

if (!form || !list) return;

function refresh() {
    const n = list.querySelectorAll('.mi-check:checked').length;
    counter.textContent = String(n);
    toolbar.classList.toggle('is-active', n > 0);
    list.querySelectorAll('.mi-row').forEach(row => {
        const button = row.querySelector('.mi-merge');
        button.hidden = n < 2 || !row.querySelector('.mi-check').checked;
        button.disabled = n < 2;
    });
}
list.addEventListener('change', e => {
    if (e.target.classList.contains('mi-check')) refresh();
});
list.addEventListener('click', e => {
    const row = e.target.closest('.mi-row');
    if (!row || row.classList.contains('is-renaming')) return;
    if (e.target.closest('label, input, button, a, select, textarea, .mi-recipes, .mi-rename')) return;
    row.querySelector('.mi-check').click();
});
let merging = false;
form.addEventListener('submit', async e => {
    e.preventDefault();
    const button = e.submitter;
    if (merging || !button || !button.classList.contains('mi-merge') || button.disabled) return;
    const targetRow = button.closest('.mi-row');
    const targetId = Number(targetRow.dataset.id);
    const sources = Array.from(list.querySelectorAll('.mi-check:checked')).map(input => Number(input.value)).filter(id => id !== targetId);
    if (!sources.length) return;
    const name = targetRow.querySelector('.mi-name').textContent;
    if (!window.confirm(`Merge ${sources.length} ingredient(s) into "${name}"? This rewrites the linked recipes and deletes the other selected ingredients.`)) return;
    const status = document.getElementById('mi-merge-status');
    status.textContent = '';
    merging = true;
    form.setAttribute('aria-busy', 'true');
    const controls = Array.from(form.querySelectorAll('input, button')).map(control => [control, control.disabled]);
    controls.forEach(([control]) => { control.disabled = true; });
    let reopenRecipes = false;
    try {
        const response = await fetch(form.dataset.mergeEndpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.restNonce },
            body: JSON.stringify({ target_id: targetId, source_ids: sources })
        });
        const result = await response.json();
        if (Array.isArray(result.terms)) {
            const terms = new Map(result.terms.map(term => [String(term.id), term]));
            reopenRecipes = !targetRow.querySelector('.mi-recipes').hidden;
            list.querySelectorAll('.mi-row').forEach(row => {
                const term = terms.get(row.dataset.id);
                if (!term) {
                    row.remove();
                    return;
                }
                row.querySelector('.mi-count').textContent = String(term.count);
                row.dataset.parentId = String(term.parent_id || 0);
                let parent = row.querySelector('.mi-parent');
                if (term.parent_label) {
                    if (!parent) {
                        parent = document.createElement('span');
                        parent.className = 'mi-parent';
                        row.querySelector('.mi-identity').appendChild(parent);
                    }
                    parent.textContent = '↳ ' + term.parent_label;
                } else if (parent) parent.remove();
                const panel = row.querySelector('.mi-recipes');
                panel.hidden = true;
                panel.textContent = '';
                delete panel.dataset.loaded;
                panel.dataset.generation = String(Number(panel.dataset.generation || 0) + 1);
                delete panel.dataset.loadingRequest;
                row.querySelector('.mi-view').setAttribute('aria-expanded', 'false');
            });
        }
        if (!response.ok) throw new Error(result.message || form.dataset.mergeError);
        list.querySelectorAll('.mi-check').forEach(input => { input.checked = false; });
    } catch (error) {
        status.textContent = error.message || form.dataset.mergeError;
    } finally {
        controls.forEach(([control, disabled]) => { control.disabled = disabled; });
        merging = false;
        form.removeAttribute('aria-busy');
        refresh();
        targetRow.querySelector('.mi-check').focus();
        if (reopenRecipes && targetRow.isConnected) targetRow.querySelector('.mi-view').click();
    }
});
list.addEventListener('click', async e => {
    const button = e.target.closest('.mi-view');
    if (!button) return;
    const row = button.closest('.mi-row');
    const panel = row.querySelector('.mi-recipes');
    panel.hidden = !panel.hidden;
    button.setAttribute('aria-expanded', String(!panel.hidden));
    if (panel.hidden || panel.dataset.loaded || panel.dataset.loadingRequest) return;
    const generation = panel.dataset.generation || '0';
    panel.dataset.loadingRequest = 'true';
    panel.textContent = panel.dataset.loading;
    try {
        const response = await fetch(form.dataset.recipesEndpoint + row.dataset.id, {
            credentials: 'same-origin', headers: { 'X-WP-Nonce': form.dataset.restNonce }
        });
        if (!response.ok) throw new Error('Request failed');
        const recipes = await response.json();
        if ((panel.dataset.generation || '0') !== generation || !panel.isConnected) return;
        panel.textContent = '';
        if (!recipes.length) panel.textContent = panel.dataset.empty;
        recipes.forEach(recipe => {
            const item = document.createElement('div');
            const link = document.createElement('a');
            link.href = recipe.url;
            link.textContent = recipe.title;
            item.appendChild(link);
            if (recipe.servings) {
                const servings = document.createElement('span');
                servings.textContent = ' · ' + form.dataset.servingsLabel + ' ' + recipe.servings;
                item.appendChild(servings);
            }
            recipe.ingredients.forEach(ingredient => {
                const editor = document.createElement('div');
                editor.className = 'mi-recipe-ingredient';
                const inputs = {};
                ['amount', 'unit', 'name'].forEach(field => {
                    const label = document.createElement('label');
                    label.textContent = form.dataset[field + 'Label'];
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.value = ingredient[field];
                    input.disabled = !recipe.editable;
                    inputs[field] = input;
                    label.appendChild(input);
                    editor.appendChild(label);
                });
                if (recipe.editable) {
                    const save = document.createElement('button');
                    save.type = 'button';
                    save.className = 'btn';
                    save.textContent = form.dataset.saveLabel;
                    save.setAttribute('aria-live', 'polite');
                    const status = document.createElement('span');
                    status.setAttribute('role', 'status');
                    let editVersion = 0;
                    Object.values(inputs).forEach(input => input.addEventListener('input', () => {
                        editVersion++;
                        save.textContent = form.dataset.saveLabel;
                        status.textContent = '';
                    }));
                    editor.addEventListener('keydown', event => {
                        if (event.isComposing || !event.target.matches('input')) return;
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            save.click();
                        } else if (event.key === 'Escape') {
                            event.preventDefault();
                            if (save.disabled) return;
                            Object.keys(inputs).forEach(field => { inputs[field].value = ingredient[field]; });
                            editVersion++;
                            save.textContent = form.dataset.saveLabel;
                            status.textContent = '';
                            event.target.blur();
                        }
                    });
                    save.addEventListener('click', async () => {
                        if (!inputs.name.value.trim()) {
                            inputs.name.focus();
                            return;
                        }
                        save.disabled = true;
                        status.textContent = '';
                        const submittedVersion = editVersion;
                        const submitted = { name: inputs.name.value, amount: inputs.amount.value, unit: inputs.unit.value };
                        try {
                            const response = await fetch(form.dataset.recipesEndpoint + recipe.id + '/' + ingredient.index, {
                                method: 'POST', credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.restNonce },
                                body: JSON.stringify({ ...submitted, version: ingredient.version })
                            });
                            const result = await response.json();
                            if (!response.ok) throw new Error(result.message || panel.dataset.error);
                            Object.assign(ingredient, result.ingredient || submitted);
                            ingredient.version = result.version;
                            list.querySelectorAll('.mi-recipes').forEach(details => { delete details.dataset.loaded; });
                            if (editVersion === submittedVersion) save.textContent = '✓ ' + form.dataset.savedLabel;
                        } catch (error) {
                            status.textContent = error.message || panel.dataset.error;
                        } finally {
                            save.disabled = false;
                        }
                    });
                    editor.append(save, status);
                }
                item.appendChild(editor);
            });
            panel.appendChild(item);
        });
        panel.dataset.loaded = 'true';
    } catch (error) {
        if ((panel.dataset.generation || '0') === generation) panel.textContent = panel.dataset.error;
    } finally {
        if ((panel.dataset.generation || '0') === generation) delete panel.dataset.loadingRequest;
    }
});

// Match any alternative separated by a pipe or the word "or".
search.addEventListener('input', () => {
    const queries = search.value.toLowerCase().split(/\||\s+or\s+/i).map(value => value.trim()).filter(Boolean);
    list.querySelectorAll('.mi-row').forEach(row => {
        const name = row.getAttribute('data-name') || '';
        row.classList.toggle('is-hidden', queries.length > 0 && !queries.some(query => name.includes(query)));
    });
});

// Keep renaming in place and use the same actions for pointer and keyboard input.
function closeRename(row) {
    row.classList.remove('is-renaming');
    row.querySelector('.mi-rename').classList.remove('is-open');
    row.querySelector('.mi-rename-toggle').focus();
}
list.addEventListener('keydown', e => {
    if (e.isComposing || !e.target.matches('.mi-rename input')) return;
    if (e.key === 'Enter' || e.key === 'Escape') {
        e.preventDefault();
        const row = e.target.closest('.mi-row');
        row.querySelector(e.key === 'Enter' ? '.mi-rename-save' : '.mi-rename-cancel').click();
    }
});
list.addEventListener('click', async e => {
    const row = e.target.closest('.mi-row');
    if (!row || row.dataset.saving) return;
    const input = row.querySelector('.mi-rename input');
    const status = row.querySelector('.mi-rename-status');
    if (e.target.closest('.mi-rename-toggle')) {
        row.classList.add('is-renaming');
        row.querySelector('.mi-rename').classList.add('is-open');
        status.textContent = '';
        input.focus(); input.select();
    } else if (e.target.closest('.mi-rename-cancel')) {
        input.value = input.dataset.original || '';
        status.textContent = '';
        closeRename(row);
    } else if (e.target.closest('.mi-rename-save')) {
        const name = input.value.trim();
        if (!name) {
            input.focus();
            return;
        }
        if (name === input.dataset.original) {
            closeRename(row);
            return;
        }
        row.dataset.saving = 'true';
        const controls = row.querySelectorAll('.mi-rename input, .mi-rename button');
        controls.forEach(control => { control.disabled = true; });
        status.textContent = '';
        try {
            const response = await fetch(form.dataset.renameEndpoint + row.dataset.id, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.restNonce },
                body: JSON.stringify({ name })
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || form.dataset.renameError);
            row.querySelector('.mi-name').textContent = result.term.name;
            row.dataset.name = result.term.name.toLowerCase();
            input.value = result.term.name;
            input.dataset.original = result.term.name;
            row.querySelector('.mi-rename-toggle').setAttribute('aria-label', result.rename_label);
            list.querySelectorAll('.mi-row').forEach(child => {
                if (child.dataset.parentId === row.dataset.id) child.querySelector('.mi-parent').textContent = '↳ ' + result.parent_label;
            });
            Array.from(list.children).sort((a, b) => a.dataset.name.localeCompare(b.dataset.name)).forEach(item => list.appendChild(item));
            search.dispatchEvent(new Event('input'));
            closeRename(row);
        } catch (error) {
            status.textContent = error.message || form.dataset.renameError;
        } finally {
            controls.forEach(control => { control.disabled = false; });
            delete row.dataset.saving;
        }
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
