(function () {
    const form = document.getElementById('planner-form');
    if (!form) return;
    const recipes = JSON.parse(form.getAttribute('data-recipes') || '[]');
    const copySourceSlots = JSON.parse(form.getAttribute('data-copy-source-slots') || '{}');
    const copyInsertedSlots = JSON.parse(form.getAttribute('data-copy-inserted-slots') || '{}');
    const placeLabel = form.getAttribute('data-place-label') || 'Place selected recipe here';
    const removeLabel = form.getAttribute('data-remove-label') || 'Remove from stash';
    const valueToId = new Map(recipes.map(recipe => [recipe.value, String(recipe.id)]));
    const idToValue = new Map(recipes.map(recipe => [String(recipe.id), recipe.value]));
    const stashStorageKey = 'cookbook.plannerStash';
    const stashPanel = form.querySelector('[data-planner-stash]');
    const stashItems = form.querySelector('[data-planner-stash-items]');
    const clearStash = form.querySelector('[data-planner-clear-stash]');
    const stash = [];
    let selectedStashKey = '';
    let stashCounter = 0;

    function hiddenFor(input) {
        const hidden = document.getElementById(input.dataset.hiddenId);
        return hidden || null;
    }

    function syncInput(input) {
        const hidden = hiddenFor(input);
        if (!hidden) return;
        hidden.value = valueToId.get(input.value.trim()) || '0';
    }

    function slotItem(input) {
        const hidden = hiddenFor(input);
        if (!hidden || hidden.value === '0' || !input.value.trim()) return null;
        return {
            id: hidden.value,
            value: input.value.trim()
        };
    }

    function itemFromId(id) {
        const recipeId = String(id || '0');
        const value = idToValue.get(recipeId);
        return value ? { id: recipeId, value } : null;
    }

    function selectedStashItem() {
        return stash.find(item => item.key === selectedStashKey) || null;
    }

    function addToStash(item, select = false) {
        if (!item || !item.id || item.id === '0' || !item.value) return;
        const stashItem = {
            key: String(++stashCounter),
            id: String(item.id),
            value: item.value
        };
        stash.push(stashItem);
        if (select || !selectedStashKey) {
            selectedStashKey = stashItem.key;
        }
        renderStash();
        updateSlotActions();
    }

    function removeFromStash(key) {
        const index = stash.findIndex(item => item.key === key);
        if (index === -1) return;
        stash.splice(index, 1);
        if (selectedStashKey === key) {
            selectedStashKey = stash.length ? stash[Math.min(index, stash.length - 1)].key : '';
        }
    }

    function deleteFromStash(key) {
        removeFromStash(key);
        renderStash();
        updateSlotActions();
    }

    function clearWholeStash() {
        stash.splice(0, stash.length);
        selectedStashKey = '';
        renderStash();
        updateSlotActions();
    }

    function setSlot(input, item) {
        const hidden = hiddenFor(input);
        if (!hidden) return;
        input.value = item ? item.value : '';
        hidden.value = item ? String(item.id) : '0';
    }

    const autocompleteLimit = 8;
    let activeAutocompleteInput = null;

    function autocompletePanel(input) {
        return document.getElementById(input.id + '-autocomplete');
    }

    function normalizeAutocompleteText(value) {
        return String(value || '').toLocaleLowerCase();
    }

    function matchingRecipes(value) {
        const needle = normalizeAutocompleteText(value.trim());
        if (!needle) {
            return recipes.slice(0, autocompleteLimit);
        }

        const starts = [];
        const contains = [];
        recipes.forEach(recipe => {
            const haystack = normalizeAutocompleteText(recipe.value);
            if (haystack.startsWith(needle)) {
                starts.push(recipe);
            } else if (haystack.includes(needle)) {
                contains.push(recipe);
            }
        });
        return starts.concat(contains).slice(0, autocompleteLimit);
    }

    function closeAutocomplete(input) {
        const panel = autocompletePanel(input);
        if (panel) {
            panel.hidden = true;
            panel.textContent = '';
        }
        input.dataset.autocompleteIndex = '-1';
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        if (activeAutocompleteInput === input) {
            activeAutocompleteInput = null;
        }
    }

    function autocompleteOptions(input) {
        const panel = autocompletePanel(input);
        return panel && !panel.hidden ? Array.from(panel.querySelectorAll('[data-autocomplete-option]')) : [];
    }

    function setAutocompleteActive(input, index) {
        const options = autocompleteOptions(input);
        if (!options.length) return;
        const next = (index + options.length) % options.length;
        options.forEach((option, optionIndex) => {
            option.setAttribute('aria-selected', optionIndex === next ? 'true' : 'false');
        });
        input.dataset.autocompleteIndex = String(next);
        input.setAttribute('aria-activedescendant', options[next].id);
    }

    function selectAutocompleteRecipe(input, recipe) {
        setSlot(input, {
            id: recipe.id,
            value: recipe.value
        });
        closeAutocomplete(input);
        updateSlotActions();
    }

    function renderAutocomplete(input) {
        const panel = autocompletePanel(input);
        if (!panel) return;

        if (activeAutocompleteInput && activeAutocompleteInput !== input) {
            closeAutocomplete(activeAutocompleteInput);
        }

        const matches = matchingRecipes(input.value);
        if (!matches.length) {
            closeAutocomplete(input);
            return;
        }

        panel.textContent = '';
        matches.forEach((recipe, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = input.id + '-autocomplete-option-' + index;
            option.className = 'planner-autocomplete-option';
            option.textContent = recipe.value;
            option.dataset.autocompleteOption = '1';
            option.dataset.recipeId = String(recipe.id);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.addEventListener('pointerdown', event => {
                event.preventDefault();
                selectAutocompleteRecipe(input, recipe);
                input.focus();
            });
            option.addEventListener('click', event => {
                event.preventDefault();
                selectAutocompleteRecipe(input, recipe);
                input.focus();
            });
            option.addEventListener('mouseenter', () => setAutocompleteActive(input, index));
            panel.appendChild(option);
        });

        panel.hidden = false;
        activeAutocompleteInput = input;
        input.dataset.autocompleteIndex = '-1';
        input.setAttribute('aria-expanded', 'true');
        input.removeAttribute('aria-activedescendant');
    }

    function setupAutocomplete(input) {
        const panel = document.createElement('div');
        panel.id = input.id + '-autocomplete';
        panel.className = 'planner-autocomplete';
        panel.hidden = true;
        panel.setAttribute('role', 'listbox');
        input.insertAdjacentElement('afterend', panel);
        input.dataset.autocompleteIndex = '-1';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', panel.id);
        input.setAttribute('aria-expanded', 'false');

        input.addEventListener('focus', () => renderAutocomplete(input));
        input.addEventListener('blur', () => {
            window.setTimeout(() => closeAutocomplete(input), 120);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                closeAutocomplete(input);
                return;
            }
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter') {
                return;
            }

            const panel = autocompletePanel(input);
            if (!panel || panel.hidden) {
                renderAutocomplete(input);
            }
            const options = autocompleteOptions(input);
            if (!options.length) return;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const current = parseInt(input.dataset.autocompleteIndex, 10);
                const fallback = event.key === 'ArrowDown' ? -1 : 0;
                const base = Number.isNaN(current) ? fallback : current;
                setAutocompleteActive(input, base + (event.key === 'ArrowDown' ? 1 : -1));
                return;
            }

            const activeIndex = parseInt(input.dataset.autocompleteIndex, 10);
            if (!Number.isNaN(activeIndex) && activeIndex >= 0 && options[activeIndex]) {
                const recipe = itemFromId(options[activeIndex].dataset.recipeId);
                if (recipe) {
                    event.preventDefault();
                    selectAutocompleteRecipe(input, recipe);
                }
            }
        });
    }

    function plannerStorage() {
        try {
            return window.localStorage;
        } catch (error) {
            return null;
        }
    }

    function saveStash() {
        const storage = plannerStorage();
        if (!stashStorageKey || !storage) return;
        try {
            if (!stash.length) {
                storage.removeItem(stashStorageKey);
                return;
            }
            storage.setItem(stashStorageKey, JSON.stringify({
                counter: stashCounter,
                selected: selectedStashKey,
                items: stash
            }));
        } catch (error) {
            // Browsers can block storage; the planner still works for this page view.
        }
    }

    function loadStash() {
        const storage = plannerStorage();
        if (!stashStorageKey || !storage) return;
        try {
            const raw = storage.getItem(stashStorageKey);
            if (!raw) return;
            const saved = JSON.parse(raw);
            const savedItems = Array.isArray(saved.items) ? saved.items : [];
            savedItems.forEach(item => {
                const recipe = itemFromId(item.id);
                if (!recipe) return;
                const key = item.key ? String(item.key) : String(++stashCounter);
                stash.push({
                    key,
                    id: recipe.id,
                    value: recipe.value
                });
                stashCounter = Math.max(stashCounter, parseInt(key, 10) || 0);
            });
            selectedStashKey = stash.some(item => item.key === String(saved.selected)) ? String(saved.selected) : (stash[0] ? stash[0].key : '');
            stashCounter = Math.max(stashCounter, parseInt(saved.counter, 10) || 0);
            renderStash();
        } catch (error) {
            storage.removeItem(stashStorageKey);
        }
    }

    function updateSlotActions() {
        const selected = selectedStashItem();
        inputs.forEach(input => {
            const here = form.querySelector('[data-planner-here][data-target-id="' + input.id + '"]');
            const lift = form.querySelector('[data-planner-lift][data-target-id="' + input.id + '"]');
            const item = slotItem(input);
            if (here) {
                here.hidden = !selected;
                if (selected) {
                    here.setAttribute('aria-label', placeLabel);
                    here.title = selected.value;
                }
            }
            if (lift) {
                lift.hidden = !item;
            }
        });
    }

    function renderStash() {
        if (!stashPanel || !stashItems) return;
        stashPanel.hidden = stash.length === 0;
        stashItems.textContent = '';
        stash.forEach(item => {
            const entry = document.createElement('span');
            entry.className = 'planner-stash-item' + (item.key === selectedStashKey ? ' is-selected' : '');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'planner-stash-select';
            button.textContent = item.value;
            button.setAttribute('aria-pressed', item.key === selectedStashKey ? 'true' : 'false');
            button.addEventListener('click', () => {
                selectedStashKey = item.key;
                renderStash();
                updateSlotActions();
            });
            entry.appendChild(button);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'planner-stash-remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', removeLabel);
            remove.addEventListener('click', () => deleteFromStash(item.key));
            entry.appendChild(remove);

            stashItems.appendChild(entry);
        });
        saveStash();
    }

    function applyCopiedWeek() {
        if (!copySourceSlots || Object.keys(copySourceSlots).length === 0) return;
        inputs.forEach(input => {
            if (!Object.prototype.hasOwnProperty.call(copySourceSlots, input.id)) return;
            const incoming = itemFromId(copySourceSlots[input.id]);
            setSlot(input, incoming);
            if (Object.prototype.hasOwnProperty.call(copyInsertedSlots, input.id)) {
                input.dataset.copyHighlight = '1';
            } else {
                delete input.dataset.copyHighlight;
            }
        });
        renderStash();
        updateSlotActions();
    }

    const inputs = Array.from(form.querySelectorAll('[data-meal-input]'));
    inputs.forEach(input => {
        setupAutocomplete(input);
        input.addEventListener('input', () => {
            syncInput(input);
            renderAutocomplete(input);
            updateSlotActions();
        });
        input.addEventListener('change', () => {
            syncInput(input);
            updateSlotActions();
        });
    });
    document.addEventListener('pointerdown', event => {
        if (!activeAutocompleteInput) return;
        const panel = autocompletePanel(activeAutocompleteInput);
        if (event.target === activeAutocompleteInput || (panel && panel.contains(event.target))) {
            return;
        }
        closeAutocomplete(activeAutocompleteInput);
    });
    form.addEventListener('submit', () => inputs.forEach(syncInput));

    if (clearStash) {
        clearStash.addEventListener('click', clearWholeStash);
    }

    form.querySelectorAll('[data-copy-previous-put-back]').forEach(button => {
        button.addEventListener('click', () => {
            const previous = button.closest('[data-copy-previous]');
            const target = document.getElementById(button.dataset.targetId);
            if (!previous || !target) return;
            const displaced = slotItem(target);
            setSlot(target, {
                id: previous.dataset.recipeId,
                value: previous.dataset.recipeValue
            });
            if (displaced && displaced.id !== previous.dataset.recipeId) {
                addToStash(displaced, true);
            }
            previous.hidden = true;
            updateSlotActions();
            target.focus();
        });
    });

    form.querySelectorAll('[data-planner-lift]').forEach(button => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.targetId);
            if (!target) return;
            const item = slotItem(target);
            if (!item) return;
            setSlot(target, null);
            addToStash(item, true);
            target.focus();
        });
    });

    form.querySelectorAll('[data-planner-here]').forEach(button => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.targetId);
            const selected = selectedStashItem();
            if (!target || !selected) return;
            const displaced = slotItem(target);
            setSlot(target, selected);
            removeFromStash(selected.key);
            if (displaced) {
                addToStash(displaced, true);
            } else {
                renderStash();
                updateSlotActions();
            }
            target.focus();
        });
    });

    loadStash();
    applyCopiedWeek();

    const pending = parseInt(form.dataset.pendingRecipe, 10) || 0;
    const pendingItem = pending ? itemFromId(pending) : null;
    if (pendingItem && !stash.some(item => item.id === pendingItem.id)) {
        addToStash(pendingItem, true);
    }
    updateSlotActions();
})();
