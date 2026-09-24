(function () {
    const ingredients = document.getElementById('ingredients');
    const servingsInput = document.getElementById('servings');
    const shoppingServings = document.getElementById('shopping-servings');
    const unitButtons = document.querySelectorAll('.unit-toggle button');
    const recipeConfig = document.getElementById('cookbook-recipe-config');
    const recipeStrings = JSON.parse(recipeConfig ? recipeConfig.getAttribute('data-strings') || '{}' : '{}');
    const editUrl = recipeConfig ? recipeConfig.getAttribute('data-edit-url') || '' : '';
    const cookMode = document.getElementById('cook-mode');
    const cookOpen = document.getElementById('cook-mode-open');
    const cookClose = document.getElementById('cook-mode-close');
    const cookActiveStep = document.getElementById('cook-active-step');
    const cookActiveCheck = document.getElementById('cook-active-check');
    const cookPrev = document.getElementById('cook-prev-step');
    const cookNext = document.getElementById('cook-next-step');
    const cookReset = document.getElementById('cook-reset');
    const cookProgress = document.getElementById('cook-step-progress');
    const cookStepCount = document.getElementById('cook-step-count');
    const cookDoneCount = document.getElementById('cook-step-done-count');
    const cookFinish = document.getElementById('cook-finish');
    const cookFinishForm = document.getElementById('cook-finish-form');
    const cookFinishDismiss = document.getElementById('cook-finish-dismiss');
    const cookStepRows = cookMode ? Array.from(cookMode.querySelectorAll('[data-cook-step-index]')) : [];
    const cookStepChecks = cookMode ? Array.from(cookMode.querySelectorAll('[data-cook-step-check]')) : [];
    const cookIngredientRows = cookMode ? Array.from(cookMode.querySelectorAll('[data-cook-ingredient-index]')) : [];
    const cookIngredientChecks = cookMode ? Array.from(cookMode.querySelectorAll('[data-cook-ingredient-check]')) : [];
    const cookIngredientSections = cookMode ? Array.from(cookMode.querySelectorAll('[data-cook-ingredient-section]')) : [];
    const cookStepIngredientMatches = [];
    const cookStrings = recipeStrings;
    const cookStateKey = recipeConfig ? recipeConfig.getAttribute('data-cook-state-key') || 'cookbook:cook-mode' : 'cookbook:cook-mode';
    let activeCookStep = 0;
    let cookFinishDismissed = false;
    let cookFinishPrompted = false;
    let closeAfterCookFinishDismiss = false;
    let wakeLock = null;

    document.addEventListener('keydown', (e) => {
        if (isCookModeOpen()) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeCookMode();
                return;
            }
            if (e.target && e.target.closest && e.target.closest('input, textarea, select, button, [contenteditable="true"]')) return;
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                setCookStep(activeCookStep - 1, true);
                return;
            }
            if (e.key === 'ArrowRight' || e.key === ' ' || e.code === 'Space') {
                e.preventDefault();
                advanceCookStep(true);
                return;
            }
        }
        if (e.defaultPrevented || e.key.toLowerCase() !== 'e' || e.metaKey || e.ctrlKey || e.altKey) return;
        const target = e.target;
        if (
            target.closest &&
            target.closest('input, textarea, select, button, [contenteditable="true"]')
        ) {
            return;
        }
        e.preventDefault();
        window.location.href = editUrl;
    });

    let preference = recipeConfig ? recipeConfig.getAttribute('data-preference') || '' : '';
    const baseServings = servingsInput ? (parseInt(servingsInput.dataset.default, 10) || 1) : 1;

    if (ingredients) {
        ingredients.addEventListener('click', (e) => {
            const toggle = e.target.closest('.ingredient-replace-toggle');
            if (toggle) {
                const form = document.getElementById(toggle.dataset.replaceTarget);
                if (!form) return;
                form.hidden = !form.hidden;
                if (!form.hidden) {
                    const input = form.querySelector('input[name="name"]');
                    if (input) input.focus();
                }
                return;
            }

            if (e.target.classList && e.target.classList.contains('ingredient-replace-cancel')) {
                const form = e.target.closest('.ingredient-replace-form');
                if (form) form.hidden = true;
            }
        });
    }

    // Conversion tables (kept in sync with src/Units.php).
    const MASS = { g:1, kg:1000, oz:28.3495, lb:453.592 };
    const VOLUME = { ml:1, l:1000, tsp:4.92892, tbsp:14.7868, floz:29.5735, cup:236.588, pt:473.176, qt:946.353, gal:3785.41 };
    // tsp/tbsp pass through both modes — see Units::system_of() in PHP.
    const IMPERIAL = ['oz','lb','cup','floz','pt','qt','gal'];
    const UNIT_LABEL = { g:'g', kg:'kg', ml:'ml', l:'l', oz:'oz', lb:'lb', tsp:'tsp', tbsp:'tbsp', floz:'fl oz', cup:'cup', pt:'pt', qt:'qt', gal:'gal' };

    function fmt(n, max) {
        if (Math.abs(n - Math.round(n)) < 0.05) return String(Math.round(n));
        return parseFloat(n.toFixed(max ?? 2)).toString();
    }

    function kindOf(unit) {
        if (unit in MASS) return 'mass';
        if (unit in VOLUME) return 'volume';
        return null;
    }

    function systemOf(unit) { return IMPERIAL.indexOf(unit) >= 0 ? 'imperial' : 'metric'; }

    function convert(amount, unit, pref) {
        const kind = kindOf(unit);
        if (kind === null) return { amount: fmt(amount), unit: unit ? (UNIT_LABEL[unit] || unit) : '' };
        if (systemOf(unit) === pref) {
            if (pref === 'metric') {
                if (unit === 'g'  && amount >= 1000) return { amount: fmt(amount/1000, 2), unit: 'kg' };
                if (unit === 'ml' && amount >= 1000) return { amount: fmt(amount/1000, 2), unit: 'l' };
            }
            return { amount: fmt(amount, amount < 10 ? 2 : 0), unit: UNIT_LABEL[unit] };
        }
        const canonical = (kind === 'mass' ? MASS[unit] : VOLUME[unit]) * amount;
        if (kind === 'mass') {
            if (pref === 'metric') {
                return canonical >= 1000
                    ? { amount: fmt(canonical/1000, 2), unit: 'kg' }
                    : { amount: fmt(canonical, 0), unit: 'g' };
            }
            const oz = canonical / MASS.oz;
            return oz >= 16
                ? { amount: fmt(canonical / MASS.lb, 2), unit: 'lb' }
                : { amount: fmt(oz, 1), unit: 'oz' };
        }
        if (pref === 'metric') {
            return canonical >= 1000
                ? { amount: fmt(canonical/1000, 2), unit: 'l' }
                : { amount: fmt(canonical, 0), unit: 'ml' };
        }
        const cups = canonical / VOLUME.cup;
        if (cups >= 0.25) return { amount: fmt(cups, 2), unit: 'cup' };
        const tbsp = canonical / VOLUME.tbsp;
        if (tbsp >= 1) return { amount: fmt(tbsp, 1), unit: 'tbsp' };
        return { amount: fmt(canonical / VOLUME.tsp, 1), unit: 'tsp' };
    }

    function syncCookIngredientAmounts() {
        if (!ingredients || !cookMode) return;
        ingredients.querySelectorAll('.ingredient-row').forEach((li, index) => {
            const amount = li.querySelector('.amt');
            const target = cookMode.querySelector('[data-cook-ingredient-index="' + index + '"] .cook-ingredient-amount');
            if (amount && target) target.textContent = amount.textContent;
        });
        refreshActiveStepIngredients();
    }

    function rerender() {
        if (!ingredients || !servingsInput) {
            syncCookIngredientAmounts();
            return;
        }
        const wanted = Math.max(1, parseInt(servingsInput.value, 10) || baseServings);
        if (shoppingServings) shoppingServings.value = wanted;
        const scale = wanted / baseServings;
        ingredients.querySelectorAll('.ingredient-row').forEach(li => {
            const amt = li.querySelector('.amt');
            const raw = li.dataset.amount;
            const unit = li.dataset.unit;
            if (raw === '' || raw === undefined || isNaN(parseFloat(raw))) {
                amt.textContent = (li.dataset.amountRaw || '') + (unit ? ' ' + (UNIT_LABEL[unit] || unit) : '');
                return;
            }
            const value = parseFloat(raw) * scale;
            const out = convert(value, unit, preference);
            amt.textContent = (out.amount + ' ' + (out.unit || '')).trim();
        });
        syncCookIngredientAmounts();
    }

    function formatCookString(template, first, second) {
        return template.replace('%1$d', first).replace('%2$d', second);
    }

    function normalizeCookWords(value) {
        return (value || '')
            .toLowerCase()
            .replace(/ß/g, 'ss')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim()
            .split(/\s+/)
            .filter(Boolean);
    }

    function cookIngredientMatchWords(row) {
        const name = row.querySelector('.cook-ingredient-name');
        return normalizeCookWords(name ? name.textContent : '').filter(word => word.length >= 2);
    }

    function cookStepWords(row) {
        const full = row.querySelector('.cook-step-full');
        const text = full ? full.textContent : '';
        return normalizeCookWords(text);
    }

    function cookWordMatchesIngredient(stepWord, ingredientWord) {
        if (!stepWord || !ingredientWord) return false;
        if (stepWord === ingredientWord) return true;
        if (ingredientWord.length >= 5 && ingredientWord.indexOf(stepWord) >= 0) return true;
        if (stepWord.length >= 5 && stepWord.indexOf(ingredientWord) >= 0) return true;
        return false;
    }

    function buildCookStepIngredientMatches() {
        if (!cookMode || !cookStepRows.length || !cookIngredientRows.length) return;
        const ingredientWords = cookIngredientRows.map(cookIngredientMatchWords);
        cookStepRows.forEach((row, stepIndex) => {
            const stepWords = cookStepWords(row);
            const stepPartIndex = row.dataset.cookPartIndex || '';
            const candidateIndexes = cookIngredientRows
                .map((ingredientRow, ingredientIndex) => {
                    if (stepPartIndex && ingredientRow.dataset.cookPartIndex !== stepPartIndex) return null;
                    return ingredientIndex;
                })
                .filter(index => index !== null);
            const matchedIndexes = candidateIndexes
                .map(ingredientIndex => {
                    const matched = ingredientWords[ingredientIndex].some(ingredientWord => {
                        return stepWords.some(stepWord => cookWordMatchesIngredient(stepWord, ingredientWord));
                    });
                    return matched ? ingredientIndex : null;
                })
                .filter(index => index !== null);
            cookStepIngredientMatches[stepIndex] = matchedIndexes.length || !stepPartIndex ? matchedIndexes : candidateIndexes;
        });
    }

    function renderActiveStepIngredients() {
        const indexes = cookStepIngredientMatches[activeCookStep] || [];
        if (!indexes.length) return null;
        const panel = document.createElement('div');
        panel.className = 'cook-active-ingredients';

        const title = document.createElement('strong');
        title.textContent = recipeStrings.forThisStep || 'For this step';
        panel.appendChild(title);

        const list = document.createElement('ul');
        indexes.forEach(index => {
            const row = cookIngredientRows[index];
            if (!row) return;
            const amount = row.querySelector('.cook-ingredient-amount');
            const name = row.querySelector('.cook-ingredient-name');
            const item = document.createElement('li');
            const itemAmount = document.createElement('span');
            itemAmount.className = 'cook-active-ingredient-amount';
            itemAmount.textContent = amount ? amount.textContent.trim() : '';
            const itemName = document.createElement('span');
            itemName.textContent = name ? name.textContent.trim() : '';
            item.appendChild(itemAmount);
            item.appendChild(itemName);
            list.appendChild(item);
        });
        panel.appendChild(list);
        return list.children.length ? panel : null;
    }

    function refreshActiveStepIngredients() {
        if (!cookActiveStep) return;
        const existing = cookActiveStep.querySelector('.cook-active-ingredients');
        if (existing) existing.remove();
        const panel = renderActiveStepIngredients();
        if (panel) cookActiveStep.appendChild(panel);
    }

    function updateCookIngredientSections() {
        if (!cookIngredientSections.length || !cookStepRows[activeCookStep]) return;
        const activePartIndex = cookStepRows[activeCookStep].dataset.cookPartIndex || '';
        cookIngredientSections.forEach(section => {
            section.classList.toggle('is-active', !!activePartIndex && section.dataset.cookPartIndex === activePartIndex);
        });
    }

    function isCookModeOpen() {
        return cookMode && !cookMode.hidden;
    }

    function loadCookState() {
        if (!cookMode) return;
        try {
            const state = JSON.parse(window.localStorage.getItem(cookStateKey) || '{}');
            activeCookStep = Math.max(0, Math.min(cookStepRows.length - 1, parseInt(state.activeStep, 10) || 0));
            const checkedSteps = Array.isArray(state.checkedSteps) ? state.checkedSteps : [];
            const checkedIngredients = Array.isArray(state.checkedIngredients) ? state.checkedIngredients : [];
            cookFinishDismissed = !!state.finishDismissed;
            cookFinishPrompted = !!state.finishPrompted;
            cookStepChecks.forEach((check, index) => {
                check.checked = checkedSteps.indexOf(index) >= 0;
            });
            cookIngredientChecks.forEach((check, index) => {
                check.checked = checkedIngredients.indexOf(index) >= 0;
            });
        } catch (e) {
            activeCookStep = 0;
            cookFinishDismissed = false;
            cookFinishPrompted = false;
        }
    }

    function saveCookState() {
        if (!cookMode) return;
        try {
            window.localStorage.setItem(cookStateKey, JSON.stringify({
                activeStep: activeCookStep,
                checkedSteps: cookStepChecks.reduce((out, check, index) => {
                    if (check.checked) out.push(index);
                    return out;
                }, []),
                checkedIngredients: cookIngredientChecks.reduce((out, check, index) => {
                    if (check.checked) out.push(index);
                    return out;
                }, []),
                finishDismissed: cookFinishDismissed,
                finishPrompted: cookFinishPrompted
            }));
        } catch (e) {}
    }

    function hasCookProgress() {
        return activeCookStep > 0 ||
            cookStepChecks.some(check => check.checked) ||
            cookIngredientChecks.some(check => check.checked);
    }

    function focusCookFinishForm() {
        if (!cookFinish) return;
        cookFinish.scrollIntoView({ block: 'nearest' });
        const date = cookFinish.querySelector('input[type="date"]');
        if (date) date.focus({ preventScroll: true });
    }

    function showCookFinishPrompt(closeAfterDismiss) {
        if (!cookFinish) return false;
        cookFinishDismissed = false;
        cookFinishPrompted = true;
        closeAfterCookFinishDismiss = !!closeAfterDismiss;
        updateCookState();
        focusCookFinishForm();
        return true;
    }

    function updateCookState() {
        if (!cookMode || !cookStepRows.length) return;
        const completed = cookStepChecks.filter(check => check.checked).length;
        if (completed < cookStepRows.length) {
            cookFinishDismissed = false;
        } else {
            cookFinishPrompted = true;
        }
        cookStepRows.forEach((row, index) => {
            row.classList.toggle('is-active', index === activeCookStep);
            row.classList.toggle('is-checked', !!(cookStepChecks[index] && cookStepChecks[index].checked));
        });
        cookIngredientRows.forEach((row, index) => {
            row.classList.toggle('is-checked', !!(cookIngredientChecks[index] && cookIngredientChecks[index].checked));
        });
        updateCookIngredientSections();
        if (cookActiveCheck) {
            cookActiveCheck.checked = !!(cookStepChecks[activeCookStep] && cookStepChecks[activeCookStep].checked);
        }
        if (cookPrev) cookPrev.disabled = activeCookStep <= 0;
        if (cookNext) {
            const onLastStep = activeCookStep >= cookStepRows.length - 1;
            const activeStepDone = !!(cookStepChecks[activeCookStep] && cookStepChecks[activeCookStep].checked);
            cookNext.disabled = onLastStep && activeStepDone;
            cookNext.textContent = onLastStep ? cookStrings.finish : cookStrings.next;
        }
        if (cookFinish) {
            cookFinish.hidden = !cookFinishPrompted || cookFinishDismissed;
        }
        if (cookProgress) {
            cookProgress.max = cookStepRows.length;
            cookProgress.value = activeCookStep + 1;
        }
        if (cookStepCount) {
            cookStepCount.textContent = formatCookString(cookStrings.stepOf, activeCookStep + 1, cookStepRows.length);
        }
        if (cookDoneCount) {
            cookDoneCount.textContent = formatCookString(cookStrings.doneCount, completed, cookStepRows.length);
        }
        saveCookState();
    }

    function setCookStep(index, focusStep) {
        if (!cookMode || !cookStepRows.length) return;
        activeCookStep = Math.max(0, Math.min(cookStepRows.length - 1, index));
        const text = cookStepRows[activeCookStep].querySelector('.cook-step-full');
        if (cookActiveStep && text) {
            cookActiveStep.innerHTML = '';
            const stepText = document.createElement('div');
            stepText.className = 'cook-active-step-text';
            stepText.innerHTML = text.innerHTML;
            cookActiveStep.appendChild(stepText);
            refreshActiveStepIngredients();
            if (focusStep) cookActiveStep.focus({ preventScroll: true });
        }
        updateCookState();
    }

    function advanceCookStep(focusStep) {
        if (!cookMode || !cookStepRows.length) return;
        if (cookStepChecks[activeCookStep]) {
            cookStepChecks[activeCookStep].checked = true;
        }
        if (activeCookStep >= cookStepRows.length - 1) {
            showCookFinishPrompt(false);
            return;
        }
        setCookStep(activeCookStep + 1, focusStep);
    }

    async function requestWakeLock() {
        if (!('wakeLock' in navigator) || wakeLock) return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => {
                wakeLock = null;
            });
        } catch (e) {}
    }

    function releaseWakeLock() {
        if (!wakeLock) return;
        wakeLock.release().catch(() => {});
        wakeLock = null;
    }

    function openCookMode() {
        if (!cookMode) return;
        loadCookState();
        buildCookStepIngredientMatches();
        syncCookIngredientAmounts();
        cookMode.hidden = false;
        document.body.classList.add('cook-mode-active');
        setCookStep(activeCookStep, true);
        requestWakeLock();
    }

    function closeCookMode() {
        if (!cookMode) return;
        if (hasCookProgress() && cookFinish && cookFinish.hidden && showCookFinishPrompt(true)) return;
        cookMode.hidden = true;
        document.body.classList.remove('cook-mode-active');
        releaseWakeLock();
        if (cookOpen) cookOpen.focus();
    }

    function forceCloseCookMode() {
        if (!cookMode) return;
        closeAfterCookFinishDismiss = false;
        cookMode.hidden = true;
        document.body.classList.remove('cook-mode-active');
        releaseWakeLock();
        if (cookOpen) cookOpen.focus();
    }

    if (servingsInput) servingsInput.addEventListener('input', rerender);
    unitButtons.forEach(btn => btn.addEventListener('click', () => {
        preference = btn.dataset.units;
        unitButtons.forEach(b => b.classList.toggle('active', b === btn));
        rerender();
    }));

    if (cookOpen) cookOpen.addEventListener('click', openCookMode);
    if (cookClose) cookClose.addEventListener('click', closeCookMode);
    if (cookPrev) cookPrev.addEventListener('click', () => setCookStep(activeCookStep - 1, true));
    if (cookNext) cookNext.addEventListener('click', () => advanceCookStep(true));
    if (cookReset) {
        cookReset.addEventListener('click', () => {
            cookStepChecks.forEach(check => { check.checked = false; });
            cookIngredientChecks.forEach(check => { check.checked = false; });
            cookFinishDismissed = false;
            cookFinishPrompted = false;
            closeAfterCookFinishDismiss = false;
            setCookStep(0, true);
        });
    }
    if (cookFinishDismiss) {
        cookFinishDismiss.addEventListener('click', () => {
            cookFinishDismissed = true;
            updateCookState();
            if (closeAfterCookFinishDismiss) {
                forceCloseCookMode();
            }
        });
    }
    if (cookFinishForm) {
        cookFinishForm.addEventListener('submit', () => {
            try {
                window.localStorage.removeItem(cookStateKey);
            } catch (e) {}
        });
    }
    if (cookActiveCheck) {
        cookActiveCheck.addEventListener('change', () => {
            if (cookStepChecks[activeCookStep]) {
                cookStepChecks[activeCookStep].checked = cookActiveCheck.checked;
            }
            updateCookState();
        });
    }
    cookStepChecks.forEach((check, index) => {
        check.addEventListener('change', () => {
            if (index === activeCookStep && cookActiveCheck) {
                cookActiveCheck.checked = check.checked;
            }
            updateCookState();
        });
    });
    cookIngredientChecks.forEach(check => {
        check.addEventListener('change', updateCookState);
    });
    if (cookMode) {
        cookMode.querySelectorAll('[data-cook-step-jump]').forEach(button => {
            button.addEventListener('click', () => {
                setCookStep(parseInt(button.dataset.cookStepJump, 10) || 0, true);
            });
        });
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && isCookModeOpen()) requestWakeLock();
    });

    rerender();
})();
