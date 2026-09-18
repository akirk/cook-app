(function () {
    const ingredientRoot = document.getElementById('ingredient-sections');
    const instructionRoot = document.getElementById('instruction-sections');
    const config = document.getElementById('cookbook-form-config');
    const strings = JSON.parse(config ? config.getAttribute('data-strings') || '{}' : '{}');
    const variationSelect = document.getElementById('parent_id');
    const loadVariation = document.getElementById('load-variation');
    if (variationSelect && loadVariation) {
        const updateLoadButton = () => { loadVariation.disabled = !variationSelect.value || variationSelect.value === '0'; };
        variationSelect.addEventListener('change', updateLoadButton);
        updateLoadButton();
    }

    function initSectionCounters(root, sectionSelector) {
        if (!root) return;
        let next = 0;
        root.querySelectorAll(sectionSelector).forEach(section => {
            const index = parseInt(section.dataset.sectionIndex, 10);
            if (!isNaN(index)) next = Math.max(next, index + 1);
            const ingredientRows = section.querySelector('[data-ingredient-rows]');
            if (ingredientRows) {
                section.dataset.nextRowIndex = String(ingredientRows.querySelectorAll('.row').length);
            }
            updateSectionNames(section);
        });
        root.dataset.nextSectionIndex = String(next);
    }

    function nextSectionIndex(root) {
        const next = parseInt(root.dataset.nextSectionIndex || '0', 10) || 0;
        root.dataset.nextSectionIndex = String(next + 1);
        return next;
    }

    function nextIngredientRowIndex(section) {
        const next = parseInt(section.dataset.nextRowIndex || '0', 10) || 0;
        section.dataset.nextRowIndex = String(next + 1);
        return next;
    }

    function addIngredientRow(section, focus) {
        const rows = section.querySelector('[data-ingredient-rows]');
        if (!rows) return;
        const sectionIndex = section.dataset.sectionIndex;
        const rowIndex = nextIngredientRowIndex(section);
        const row = document.createElement('div');
        row.className = 'row';
        row.innerHTML = `
            <input type="text" name="ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][amount]" placeholder="${strings.two}">
            <input type="text" name="ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][unit]" placeholder="${strings.gram}" list="recipe-units">
            <input type="text" name="ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][name]" placeholder="${strings.ingredient}" required>
            <input type="text" name="ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][notes]" placeholder="${strings.chopped}">
            <button type="button" class="remove" aria-label="${strings.remove}">×</button>
        `;
        rows.appendChild(row);
        updateSectionNames(section);
        if (focus) row.querySelector('input').focus();
    }

    function addInstructionRow(section, focus) {
        const rows = section.querySelector('[data-instruction-rows]');
        if (!rows) return;
        const sectionIndex = section.dataset.sectionIndex;
        const row = document.createElement('div');
        row.className = 'row';
        row.style.gridTemplateColumns = '1fr auto';
        row.style.alignItems = 'flex-start';
        const num = rows.querySelectorAll('.row').length + 1;
        row.innerHTML = `
            <textarea name="instruction_parts[${sectionIndex}][instructions][]" placeholder="${strings.step} ${num}"></textarea>
            <button type="button" class="remove" aria-label="${strings.remove}">×</button>
        `;
        rows.appendChild(row);
        updateSectionNames(section);
        if (focus) row.querySelector('textarea').focus();
    }

    function rowElements(container) {
        return Array.from(container.children).filter(child => child.classList && child.classList.contains('row'));
    }

    function updateSectionNames(section) {
        const sectionIndex = section.dataset.sectionIndex;
        const title = section.querySelector('.recipe-form-section-header input');
        const rows = section.querySelector('.recipe-form-section-rows');
        if (!rows) return;

        if (section.matches('[data-ingredient-section]')) {
            if (title) title.name = `ingredient_parts[${sectionIndex}][title]`;
            rowElements(rows).forEach((row, rowIndex) => {
                const inputs = row.querySelectorAll('input');
                if (inputs[0]) inputs[0].name = `ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][amount]`;
                if (inputs[1]) inputs[1].name = `ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][unit]`;
                if (inputs[2]) inputs[2].name = `ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][name]`;
                if (inputs[3]) inputs[3].name = `ingredient_parts[${sectionIndex}][ingredients][${rowIndex}][notes]`;
            });
            section.dataset.nextRowIndex = String(rowElements(rows).length);
            refreshIngredientInserters(section);
            return;
        }

        if (title) title.name = `instruction_parts[${sectionIndex}][title]`;
        rowElements(rows).forEach(row => {
            const textarea = row.querySelector('textarea');
            if (textarea) textarea.name = `instruction_parts[${sectionIndex}][instructions][]`;
        });
        refreshInstructionInserters(section);
    }

    function refreshIngredientInserters(section) {
        const rows = section.querySelector('[data-ingredient-rows]');
        if (!rows) return;
        rows.querySelectorAll('.recipe-row-inserter').forEach(inserter => inserter.remove());
        rowElements(rows).forEach(row => {
            const inserter = document.createElement('div');
            inserter.className = 'recipe-row-inserter';
            inserter.innerHTML = `
                <span class="recipe-row-inserter-line"></span>
                <span class="recipe-row-inserter-actions">
                    <button type="button" class="insert-ingredient-here">+ ${strings.ingredientShort}</button>
                    <button type="button" class="insert-section-here">+ ${strings.sectionShort}</button>
                </span>
            `;
            rows.insertBefore(inserter, row.nextSibling);
        });
    }

    function refreshInstructionInserters(section) {
        const rows = section.querySelector('[data-instruction-rows]');
        if (!rows) return;
        rows.querySelectorAll('.recipe-row-inserter').forEach(inserter => inserter.remove());
        rowElements(rows).forEach(row => {
            const inserter = document.createElement('div');
            inserter.className = 'recipe-row-inserter';
            inserter.innerHTML = `
                <span class="recipe-row-inserter-line"></span>
                <span class="recipe-row-inserter-actions">
                    <button type="button" class="insert-instruction-here">+ ${strings.step}</button>
                    <button type="button" class="insert-instruction-section-here">+ ${strings.sectionShort}</button>
                </span>
            `;
            rows.insertBefore(inserter, row.nextSibling);
        });
    }

    function syncIngredientSectionState() {
        if (!ingredientRoot) return;
        const sections = Array.from(ingredientRoot.querySelectorAll('[data-ingredient-section]'));
        const hasSections = sections.length > 1 || sections.some(section => {
            const title = section.querySelector('.recipe-form-section-header input');
            return title && (title.value.trim() !== '' || title === document.activeElement);
        });
        ingredientRoot.classList.toggle('has-recipe-sections', hasSections);

        sections.forEach((section, index) => {
            const title = section.querySelector('.recipe-form-section-header input');
            const hasTitle = title && title.value.trim() !== '';
            const hasFocusedTitle = title && title === document.activeElement;
            section.classList.toggle('has-section-title', Boolean(hasTitle));
            section.classList.toggle('has-section-boundary', index > 0 || Boolean(hasTitle) || Boolean(hasFocusedTitle));
        });
    }

    function clearSection(section) {
        const root = section.parentElement;
        const rows = section.querySelector('.recipe-form-section-rows');
        const title = section.querySelector('.recipe-form-section-header input');
        if (title) title.value = '';
        if (!rows) return;
        Array.from(rows.querySelectorAll('.row')).slice(1).forEach(row => row.remove());
        let first = rows.querySelector('.row');
        if (!first && section.matches('[data-ingredient-section]')) {
            addIngredientRow(section, false);
            first = rows.querySelector('.row');
        } else if (!first && section.matches('[data-instruction-section]')) {
            addInstructionRow(section, false);
            first = rows.querySelector('.row');
        }
        if (first) first.querySelectorAll('input, textarea').forEach(el => { el.value = ''; });
        if (root) {
            const input = section.querySelector('input, textarea');
            if (input) input.focus();
        }
        updateSectionNames(section);
        syncIngredientSectionState();
    }

    function insertIngredientAtBoundary(inserter) {
        const section = inserter.closest('[data-ingredient-section]');
        const rows = section ? section.querySelector('[data-ingredient-rows]') : null;
        if (!section || !rows) return;

        const row = document.createElement('div');
        row.className = 'row';
        row.innerHTML = `
            <input type="text" placeholder="${strings.two}">
            <input type="text" placeholder="${strings.gram}" list="recipe-units">
            <input type="text" placeholder="${strings.ingredient}" required>
            <input type="text" placeholder="${strings.chopped}">
            <button type="button" class="remove" aria-label="${strings.remove}">×</button>
        `;
        rows.insertBefore(row, inserter.nextSibling);
        updateSectionNames(section);
        row.querySelector('input').focus();
    }

    function insertIngredientSectionAtBoundary(inserter) {
        const section = inserter.closest('[data-ingredient-section]');
        const root = section ? section.parentElement : null;
        const rows = section ? section.querySelector('[data-ingredient-rows]') : null;
        if (!section || !root || !rows) return;

        const index = nextSectionIndex(root);
        const newSection = document.createElement('section');
        newSection.className = 'recipe-form-section';
        newSection.dataset.sectionIndex = String(index);
        newSection.dataset.nextRowIndex = '0';
        newSection.setAttribute('data-ingredient-section', '');
        newSection.innerHTML = `
            <div class="recipe-form-section-header">
                <input type="text" name="ingredient_parts[${index}][title]" placeholder="${strings.ingredientSectionTitle}">
                <button type="button" class="btn secondary remove-section recipe-section-remove" aria-label="${strings.mergeIngredientSection}" title="${strings.mergeIngredientSection}">×</button>
            </div>
            <div class="recipe-form-section-rows" data-ingredient-rows></div>
        `;
        if (section.nextSibling) {
            root.insertBefore(newSection, section.nextSibling);
        } else {
            root.appendChild(newSection);
        }

        const targetRows = newSection.querySelector('[data-ingredient-rows]');
        let node = inserter.nextElementSibling;
        while (node) {
            const next = node.nextElementSibling;
            if (node.classList.contains('row')) {
                targetRows.appendChild(node);
            }
            node = next;
        }
        if (!rowElements(targetRows).length) {
            addIngredientRow(newSection, false);
        }
        updateSectionNames(section);
        updateSectionNames(newSection);
        syncIngredientSectionState();
        newSection.querySelector('.recipe-form-section-header input').focus();
    }

    function insertInstructionAtBoundary(inserter) {
        const section = inserter.closest('[data-instruction-section]');
        const rows = section ? section.querySelector('[data-instruction-rows]') : null;
        if (!section || !rows) return;

        const row = document.createElement('div');
        row.className = 'row';
        row.style.gridTemplateColumns = '1fr auto';
        row.style.alignItems = 'flex-start';
        row.innerHTML = `
            <textarea placeholder="${strings.step}"></textarea>
            <button type="button" class="remove" aria-label="${strings.remove}">×</button>
        `;
        rows.insertBefore(row, inserter.nextSibling);
        updateSectionNames(section);
        row.querySelector('textarea').focus();
    }

    function insertInstructionSectionAtBoundary(inserter) {
        const section = inserter.closest('[data-instruction-section]');
        const root = section ? section.parentElement : null;
        const rows = section ? section.querySelector('[data-instruction-rows]') : null;
        if (!section || !root || !rows) return;

        const index = nextSectionIndex(root);
        const newSection = document.createElement('section');
        newSection.className = 'recipe-form-section';
        newSection.dataset.sectionIndex = String(index);
        newSection.setAttribute('data-instruction-section', '');
        newSection.innerHTML = `
            <div class="recipe-form-section-header">
                <input type="text" name="instruction_parts[${index}][title]" placeholder="${strings.sectionTitle}">
                <button type="button" class="btn secondary remove-section recipe-section-remove" aria-label="${strings.mergeInstructionSection}" title="${strings.mergeInstructionSection}">×</button>
            </div>
            <div class="recipe-form-section-rows" data-instruction-rows></div>
        `;
        if (section.nextSibling) {
            root.insertBefore(newSection, section.nextSibling);
        } else {
            root.appendChild(newSection);
        }

        const targetRows = newSection.querySelector('[data-instruction-rows]');
        let node = inserter.nextElementSibling;
        while (node) {
            const next = node.nextElementSibling;
            if (node.classList.contains('row')) {
                targetRows.appendChild(node);
            }
            node = next;
        }
        if (!rowElements(targetRows).length) {
            addInstructionRow(newSection, false);
        }
        updateSectionNames(section);
        updateSectionNames(newSection);
        newSection.querySelector('.recipe-form-section-header input').focus();
    }

    function removeIngredientSectionHeader(section) {
        const root = section ? section.parentElement : null;
        if (!section || !root) return;

        const sections = Array.from(root.querySelectorAll('[data-ingredient-section]'));
        const sectionIndex = sections.indexOf(section);
        const title = section.querySelector('.recipe-form-section-header input');

        if (sectionIndex <= 0) {
            if (title) title.value = '';
            updateSectionNames(section);
            syncIngredientSectionState();
            const firstInput = section.querySelector('[data-ingredient-rows] input');
            if (firstInput) firstInput.focus();
            return;
        }

        const previous = sections[sectionIndex - 1];
        const previousRows = previous ? previous.querySelector('[data-ingredient-rows]') : null;
        const rows = section.querySelector('[data-ingredient-rows]');
        if (!previousRows || !rows) return;

        rowElements(rows).forEach(row => previousRows.appendChild(row));
        section.remove();
        updateSectionNames(previous);
        syncIngredientSectionState();
    }

    function removeInstructionSectionHeader(section) {
        const root = section ? section.parentElement : null;
        if (!section || !root) return;

        const sections = Array.from(root.querySelectorAll('[data-instruction-section]'));
        const sectionIndex = sections.indexOf(section);
        const title = section.querySelector('.recipe-form-section-header input');

        if (sectionIndex <= 0) {
            if (title) title.value = '';
            updateSectionNames(section);
            const firstInput = section.querySelector('[data-instruction-rows] textarea');
            if (firstInput) firstInput.focus();
            return;
        }

        const previous = sections[sectionIndex - 1];
        const previousRows = previous ? previous.querySelector('[data-instruction-rows]') : null;
        const rows = section.querySelector('[data-instruction-rows]');
        if (!previousRows || !rows) return;

        rowElements(rows).forEach(row => previousRows.appendChild(row));
        section.remove();
        updateSectionNames(previous);
    }

    initSectionCounters(ingredientRoot, '[data-ingredient-section]');
    initSectionCounters(instructionRoot, '[data-instruction-section]');

    if (variationSelect && loadVariation) {
        const recipeForm = document.getElementById('recipe-form');
        const titleInput = document.getElementById('title');
        const initialTitle = titleInput.value;
        const formSnapshot = () => JSON.stringify(Array.from(new FormData(recipeForm).entries())
            .filter(([name]) => name !== 'parent_id' && name !== 'title')
            .map(([name, value]) => [name, typeof value === 'string' ? value : (value.name ? [value.name, value.size, value.lastModified] : '')]));
        const initialForm = formSnapshot();
        loadVariation.addEventListener('click', () => {
            if (loadVariation.disabled) return;
            if (formSnapshot() !== initialForm && !window.confirm(strings.replaceWarning)) return;
            const url = new URL(loadVariation.dataset.loadUrl, window.location.href);
            url.searchParams.set('variation_of', variationSelect.value);
            const title = titleInput.value.trim();
            if (title && (titleInput.dataset.generatedTitle !== '1' || title !== initialTitle)) {
                url.searchParams.set('title', title);
            }
            window.location.assign(url.toString());
        });
    }
    syncIngredientSectionState();

    document.addEventListener('click', (e) => {
        if (e.target.classList && e.target.classList.contains('remove-section')) {
            const section = e.target.closest('.recipe-form-section');
            const root = section ? section.parentElement : null;
            if (!section || !root) return;
            if (section.matches('[data-ingredient-section]')) {
                removeIngredientSectionHeader(section);
                return;
            }
            if (section.matches('[data-instruction-section]')) {
                removeInstructionSectionHeader(section);
                return;
            }
            if (root.querySelectorAll('.recipe-form-section').length > 1) {
                section.remove();
            } else {
                clearSection(section);
            }
            return;
        }
        if (e.target.classList && e.target.classList.contains('insert-ingredient-here')) {
            const inserter = e.target.closest('.recipe-row-inserter');
            if (inserter) insertIngredientAtBoundary(inserter);
            return;
        }
        if (e.target.classList && e.target.classList.contains('insert-section-here')) {
            const inserter = e.target.closest('.recipe-row-inserter');
            if (inserter) insertIngredientSectionAtBoundary(inserter);
            return;
        }
        if (e.target.classList && e.target.classList.contains('insert-instruction-here')) {
            const inserter = e.target.closest('.recipe-row-inserter');
            if (inserter) insertInstructionAtBoundary(inserter);
            return;
        }
        if (e.target.classList && e.target.classList.contains('insert-instruction-section-here')) {
            const inserter = e.target.closest('.recipe-row-inserter');
            if (inserter) insertInstructionSectionAtBoundary(inserter);
            return;
        }
        if (e.target.classList && e.target.classList.contains('remove')) {
            const row = e.target.closest('.row');
            const root = row.parentElement;
            if (root.querySelectorAll('.row').length > 1) {
                row.remove();
                const section = root.closest('.recipe-form-section');
                if (section) updateSectionNames(section);
            } else {
                row.querySelectorAll('input, textarea').forEach(el => el.value = '');
            }
        }
    });

    if (ingredientRoot) {
        ingredientRoot.addEventListener('input', (e) => {
            if (e.target.matches('.recipe-form-section-header input')) {
                syncIngredientSectionState();
            }
        });
        ingredientRoot.addEventListener('focusin', (e) => {
            if (e.target.matches('.recipe-form-section-header input')) {
                syncIngredientSectionState();
            }
        });
        ingredientRoot.addEventListener('focusout', (e) => {
            if (e.target.matches('.recipe-form-section-header input')) {
                window.setTimeout(syncIngredientSectionState, 0);
            }
        });
    }

    const imageUrl = document.getElementById('image_url');
    const imagePreview = document.getElementById('image-url-preview');
    if (imageUrl && imagePreview) {
        const image = imagePreview.querySelector('img');
        imageUrl.addEventListener('input', () => {
            const url = imageUrl.value.trim();
            if (!url) {
                imagePreview.hidden = true;
                image.removeAttribute('src');
                return;
            }
            image.src = url;
            imagePreview.hidden = false;
        });
        image.addEventListener('error', () => {
            imagePreview.hidden = true;
        });
    }
})();
