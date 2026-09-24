(function () {
    var form = document.getElementById('import-form');
    var paste = document.getElementById('paste');
    if (!form || !paste || !window.fetch || !window.URLSearchParams) return;

    var endpoint = form.getAttribute('data-preview-endpoint') || '';
    var nonceField = form.querySelector('input[name="_wpnonce"]');
    var nonce = nonceField ? nonceField.value : '';
    var timer = null;
    var sequence = 0;
    var sourceUrl = document.getElementById('source_url');
    var sourceLookupTimer = null;
    var sourceLookupSequence = 0;
    var existingSource = {
        notice: document.querySelector('[data-source-url-existing]'),
        link: document.querySelector('[data-source-url-existing-link]')
    };
    var message = document.querySelector('[data-preview-message]');
    var error = document.querySelector('[data-preview-error]');
    var title = {
        section: document.querySelector('[data-preview-section="title"]'),
        status: document.querySelector('[data-preview-title-status]'),
        value: document.querySelector('[data-preview-title]')
    };
    var ingredients = {
        section: document.querySelector('[data-preview-section="ingredients"]'),
        status: document.querySelector('[data-preview-ingredients-status]'),
        rows: document.querySelector('[data-preview-ingredients]')
    };
    var instructions = {
        section: document.querySelector('[data-preview-section="instructions"]'),
        status: document.querySelector('[data-preview-instructions-status]'),
        rows: document.querySelector('[data-preview-instructions]')
    };
    var strings = JSON.parse(form.getAttribute('data-preview-strings') || '{}');

    function countLabel(count, one, many) {
        return count + ' ' + (count === 1 ? one : many);
    }

    function sectionParts(section) {
        return {
            button: section.section.querySelector('.preview-section-head'),
            body: section.section.querySelector('.preview-section-body')
        };
    }

    function setSectionExpanded(section, expanded) {
        var parts = sectionParts(section);
        parts.body.hidden = !expanded;
        parts.button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function setSection(section, present, statusText) {
        var wasPresent = !!section.present;
        section.section.dataset.state = present ? 'ok' : 'missing';
        section.status.textContent = statusText;
        section.present = !!present;
        if (!present) {
            setSectionExpanded(section, false);
        } else if (section.userToggled) {
            setSectionExpanded(section, !!section.userExpanded);
        } else if (!wasPresent) {
            setSectionExpanded(section, true);
        }
    }

    function resetSections(resetUserToggles) {
        if (resetUserToggles) {
            [title, ingredients, instructions].forEach(function (section) {
                section.userToggled = false;
                section.userExpanded = false;
                section.present = false;
            });
        }
        title.value.textContent = '';
        clearChildren(ingredients.rows);
        clearChildren(instructions.rows);
        setSection(title, false, strings.missing);
        setSection(ingredients, false, strings.missing);
        setSection(instructions, false, strings.missing);
    }

    function setMessage(text) {
        message.textContent = text;
        message.hidden = false;
        error.hidden = true;
    }

    function setError(text) {
        resetSections(true);
        error.textContent = text;
        error.hidden = false;
        message.hidden = true;
    }

    function clearExistingSourceRecipe() {
        if (!existingSource.notice || !existingSource.link) return;
        existingSource.notice.hidden = true;
        existingSource.link.removeAttribute('href');
        existingSource.link.textContent = '';
    }

    function setExistingSourceRecipe(recipe) {
        if (!existingSource.notice || !existingSource.link || !recipe || !recipe.view_url) return;
        existingSource.link.href = recipe.view_url;
        existingSource.link.textContent = recipe.title || recipe.view_url;
        existingSource.notice.hidden = false;
    }

    function requestSourceLookup() {
        if (!sourceUrl || !existingSource.notice || !existingSource.link) return;
        var url = sourceUrl.value.trim();
        sourceLookupSequence++;
        if (!url || !/^https?:\/\/\S+$/i.test(url)) {
            clearExistingSourceRecipe();
            return;
        }

        var requestId = sourceLookupSequence;
        var body = new URLSearchParams();
        body.set('action', 'cookbook_lookup_source_url');
        body.set('_ajax_nonce', nonce);
        body.set('source_url', url);

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (requestId !== sourceLookupSequence) return;
            if (json && json.success && json.data && json.data.exists && json.data.recipe) {
                setExistingSourceRecipe(json.data.recipe);
            } else {
                clearExistingSourceRecipe();
            }
        }).catch(function () {
            if (requestId === sourceLookupSequence) clearExistingSourceRecipe();
        });
    }

    function clearChildren(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function cell(text, className) {
        var td = document.createElement('td');
        td.textContent = text || strings.blank;
        if (!text) td.className = className || 'preview-muted';
        return td;
    }

    function appendIngredientRows(rows) {
        clearChildren(ingredients.rows);
        if (!rows.length) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 4;
            emptyCell.className = 'preview-muted';
            emptyCell.textContent = strings.noIngredients;
            emptyRow.appendChild(emptyCell);
            ingredients.rows.appendChild(emptyRow);
            return;
        }
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.appendChild(cell(row.amount || ''));
            tr.appendChild(cell(row.unit || ''));
            tr.appendChild(cell(row.name || ''));
            tr.appendChild(cell(row.notes || ''));
            ingredients.rows.appendChild(tr);
        });
    }

    function appendInstructionRows(rows) {
        clearChildren(instructions.rows);
        if (!rows.length) {
            var emptyRow = document.createElement('li');
            emptyRow.className = 'preview-muted';
            emptyRow.textContent = strings.noInstructions;
            instructions.rows.appendChild(emptyRow);
            return;
        }
        rows.forEach(function (row) {
            var item = document.createElement('li');
            item.textContent = row;
            instructions.rows.appendChild(item);
        });
    }

    function renderPreview(parsed) {
        var ingredientRows = Array.isArray(parsed.ingredients) ? parsed.ingredients : [];
        var instructionRows = Array.isArray(parsed.instructions) ? parsed.instructions : [];
        var hasTitle = !!(parsed.title || '').trim();

        title.value.textContent = parsed.title || '';
        appendIngredientRows(ingredientRows);
        appendInstructionRows(instructionRows);

        setSection(title, hasTitle, hasTitle ? strings.detected : strings.missing);
        setSection(ingredients, ingredientRows.length > 0, ingredientRows.length > 0 ? countLabel(ingredientRows.length, 'ingredient', 'ingredients') : strings.missing);
        setSection(instructions, instructionRows.length > 0, instructionRows.length > 0 ? countLabel(instructionRows.length, 'step', 'steps') : strings.missing);

        setMessage(strings.review);
        error.hidden = true;
    }

    [title, ingredients, instructions].forEach(function (section) {
        var parts = sectionParts(section);
        parts.button.addEventListener('click', function () {
            var shouldExpand = parts.body.hidden;
            section.userToggled = true;
            section.userExpanded = shouldExpand;
            setSectionExpanded(section, shouldExpand);
        });
    });

    resetSections(true);

    function requestPreview() {
        var text = paste.value.trim();
        sequence++;
        if (!text) {
            resetSections(true);
            setMessage(strings.empty);
            return;
        }

        var requestId = sequence;
        setMessage(strings.parsing);

        var body = new URLSearchParams();
        body.set('action', 'cookbook_parse_text');
        body.set('_ajax_nonce', nonce);
        body.set('paste', paste.value);

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (requestId !== sequence) return;
            if (json && json.success) {
                renderPreview(json.data || {});
            } else {
                setError(json && json.data && json.data.message ? json.data.message : strings.error);
            }
        }).catch(function () {
            if (requestId === sequence) setError(strings.error);
        });
    }

    paste.addEventListener('input', function () {
        resizePaste(true);
        window.clearTimeout(timer);
        timer = window.setTimeout(requestPreview, 300);
    });

    if (sourceUrl) {
        sourceUrl.addEventListener('input', function () {
            window.clearTimeout(sourceLookupTimer);
            sourceLookupTimer = window.setTimeout(requestSourceLookup, 300);
        });
    }

    function keepWindowScroll(x, y) {
        window.scrollTo(x, y);
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () {
                window.scrollTo(x, y);
            });
        }
    }

    function resizePaste(preserveScroll) {
        var x = window.pageXOffset || document.documentElement.scrollLeft || 0;
        var y = window.pageYOffset || document.documentElement.scrollTop || 0;

        paste.style.height = 'auto';
        paste.style.height = paste.scrollHeight + 'px';

        if (preserveScroll && document.activeElement === paste) {
            keepWindowScroll(x, y);
        }
    }

    var imageUrl = document.getElementById('image_url');
    var imagePreview = document.getElementById('image-url-preview');
    if (imageUrl && imagePreview) {
        var image = imagePreview.querySelector('img');
        imageUrl.addEventListener('input', function () {
            var url = imageUrl.value.trim();
            if (!url) {
                imagePreview.hidden = true;
                image.removeAttribute('src');
                return;
            }
            image.src = url;
            imagePreview.hidden = false;
        });
        image.addEventListener('error', function () {
            imagePreview.hidden = true;
        });
    }

    resizePaste(false);
    requestPreview();
})();
