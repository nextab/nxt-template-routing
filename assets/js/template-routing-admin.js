/**
 * Template Routing Admin JavaScript
 * 
 * Handles the admin UI for mapping taxonomies to templates.
 */
(function() {
	'use strict';

	const config = window.nxtTemplateRouting || {};
	const { ajaxUrl, nonce, taxonomies, templates, languages, i18n } = config;
	let mappings = config.mappings || [];

	/**
	 * Generate a unique ID for new mappings
	 */
	function generateId() {
		return 'mapping_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
	}

	/**
	 * Render all mappings
	 */
	function renderMappings() {
		const container = document.getElementById('nxt-mappings-list');
		if (!container) return;

		if (mappings.length === 0) {
			container.innerHTML = `
				<div class="nxt-mapping-empty">
					<p>${i18n.select_taxonomy || 'No mappings yet. Click "Add Mapping" to create one.'}</p>
				</div>
			`;
			return;
		}

		container.innerHTML = mappings.map((mapping, index) => renderMappingItem(mapping, index)).join('');
		attachMappingEventListeners();
	}

	/**
	 * Render template-per-language overrides (Polylang)
	 */
	function renderTemplatePerLang(mapping) {
		if (!languages || Object.keys(languages).length === 0) {
			return '';
		}
		const tplPerLang = mapping.template_per_lang || {};
		const langRows = Object.entries(languages).map(([slug, name]) => {
			const selected = tplPerLang[slug] || '';
			const opts = [
				`<option value="">— ${i18n.select_template || 'Default'} —</option>`,
				...Object.values(templates).map(tpl =>
					`<option value="${tpl.slug}"${tpl.slug === selected ? ' selected' : ''}>${tpl.title}</option>`
				)
			].join('');
			return `
				<div class="nxt-tpl-lang-row">
					<label class="nxt-tpl-lang-label">${name}</label>
					<select class="mapping-template-lang" data-lang="${slug}">${opts}</select>
				</div>
			`;
		}).join('');
		return `
			<div class="nxt-mapping-field nxt-template-per-lang">
				<details class="nxt-tpl-details">
					<summary>${i18n.template_per_lang || 'Template per language'}</summary>
					<p class="description">${i18n.template_per_lang_desc || ''}</p>
					<div class="nxt-tpl-lang-grid">${langRows}</div>
				</details>
			</div>
		`;
	}

	/**
	 * Render a single mapping item
	 */
	function renderMappingItem(mapping, index) {
		const taxonomyOptions = Object.values(taxonomies).map(tax => 
			`<option value="${tax.name}" ${mapping.taxonomy === tax.name ? 'selected' : ''}>${tax.label}</option>`
		).join('');

		const templateOptions = Object.values(templates).map(tpl =>
			`<option value="${tpl.slug}" ${mapping.template === tpl.slug ? 'selected' : ''}>${tpl.title}</option>`
		).join('');

		const selectedTerms = Array.isArray(mapping.terms) ? mapping.terms : [];
		const isAnyTerm = mapping.terms === 'any' || (selectedTerms.length === 1 && selectedTerms[0] === 'any') || selectedTerms.length === 0;

		return `
			<div class="nxt-mapping-item ${mapping.enabled ? '' : 'disabled'}" data-index="${index}" data-id="${mapping.id || ''}">
				<div class="nxt-mapping-toggle">
					<input type="checkbox" 
						class="mapping-enabled" 
						${mapping.enabled ? 'checked' : ''} 
						title="Enable/Disable this mapping">
				</div>

				<div class="nxt-mapping-field">
					<label>Taxonomy</label>
					<select class="mapping-taxonomy">
						<option value="">${i18n.select_taxonomy}</option>
						${taxonomyOptions}
					</select>
				</div>

				<div class="nxt-mapping-field terms-field">
					<label>Terms</label>
					<select class="mapping-terms" multiple data-taxonomy="${mapping.taxonomy || ''}">
						<option value="any" ${isAnyTerm ? 'selected' : ''}>${i18n.any_term}</option>
					</select>
				</div>

				<div class="nxt-mapping-field">
					<label>Template</label>
					<select class="mapping-template">
						<option value="">${i18n.select_template}</option>
						${templateOptions}
					</select>
				</div>

				<div class="nxt-mapping-field nxt-mapping-priority">
					<label>Priority</label>
					<input type="number" class="mapping-priority" value="${mapping.priority ?? 10}" min="0" max="100">
				</div>

				<div class="nxt-mapping-delete">
					<button type="button" class="delete-mapping" title="Delete mapping">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
				${renderTemplatePerLang(mapping)}
			</div>
		`;
	}

	/**
	 * Attach event listeners to mapping items
	 */
	function attachMappingEventListeners() {
		document.querySelectorAll('.nxt-mapping-item').forEach(item => {
			const index = parseInt(item.dataset.index, 10);

			// Enabled checkbox
			item.querySelector('.mapping-enabled')?.addEventListener('change', function() {
				mappings[index].enabled = this.checked;
				item.classList.toggle('disabled', !this.checked);
			});

			// Taxonomy select
			item.querySelector('.mapping-taxonomy')?.addEventListener('change', function() {
				mappings[index].taxonomy = this.value;
				mappings[index].terms = 'any';
				loadTermsForTaxonomy(item, this.value);
			});

			// Terms select
			item.querySelector('.mapping-terms')?.addEventListener('change', function() {
				const selected = Array.from(this.selectedOptions).map(opt => opt.value);
				if (selected.includes('any') && selected.length > 1) {
					// If "any" is selected along with specific terms, prioritize specific terms
					mappings[index].terms = selected.filter(t => t !== 'any');
				} else if (selected.includes('any') || selected.length === 0) {
					mappings[index].terms = 'any';
				} else {
					mappings[index].terms = selected;
				}
			});

			// Template select
			item.querySelector('.mapping-template')?.addEventListener('change', function() {
				mappings[index].template = this.value;
			});

			// Template per language
			item.querySelectorAll('.mapping-template-lang').forEach(sel => {
				sel.addEventListener('change', function() {
					const lang = this.dataset.lang;
					if (!mappings[index].template_per_lang) {
						mappings[index].template_per_lang = {};
					}
					if (this.value) {
						mappings[index].template_per_lang[lang] = this.value;
					} else {
						delete mappings[index].template_per_lang[lang];
					}
				});
			});

			// Priority input
			item.querySelector('.mapping-priority')?.addEventListener('change', function() {
				mappings[index].priority = parseInt(this.value, 10) || 10;
			});

			// Delete button
			item.querySelector('.delete-mapping')?.addEventListener('click', function() {
				if (confirm(i18n.confirm_delete)) {
					mappings.splice(index, 1);
					renderMappings();
				}
			});

			// Load terms if taxonomy is already selected
			const taxonomySelect = item.querySelector('.mapping-taxonomy');
			if (taxonomySelect && taxonomySelect.value) {
				loadTermsForTaxonomy(item, taxonomySelect.value, mappings[index].terms);
			}
		});
	}

	/**
	 * Load terms for a taxonomy via AJAX
	 */
	function loadTermsForTaxonomy(item, taxonomy, selectedTerms = []) {
		const termsSelect = item.querySelector('.mapping-terms');
		if (!termsSelect || !taxonomy) {
			termsSelect.innerHTML = `<option value="any" selected>${i18n.any_term}</option>`;
			return;
		}

		termsSelect.disabled = true;
		termsSelect.innerHTML = '<option>Loading...</option>';

		fetch(`${ajaxUrl}?action=nxt_get_taxonomy_terms&taxonomy=${taxonomy}&nonce=${nonce}`)
			.then(response => response.json())
			.then(data => {
				if (data.success && data.data.terms) {
					const isAnySelected = selectedTerms === 'any' || 
						(Array.isArray(selectedTerms) && (selectedTerms.length === 0 || selectedTerms.includes('any')));

					let options = `<option value="any" ${isAnySelected ? 'selected' : ''}>${i18n.any_term}</option>`;
					
					data.data.terms.forEach(term => {
						const isSelected = Array.isArray(selectedTerms) && selectedTerms.includes(term.slug);
						options += `<option value="${term.slug}" ${isSelected ? 'selected' : ''}>${term.name}</option>`;
					});

					termsSelect.innerHTML = options;
				}
				termsSelect.disabled = false;
			})
			.catch(() => {
				termsSelect.innerHTML = `<option value="any" selected>${i18n.any_term}</option>`;
				termsSelect.disabled = false;
			});
	}

	/**
	 * Add a new mapping
	 */
	function addMapping() {
		mappings.push({
			id: generateId(),
			taxonomy: '',
			terms: 'any',
			template: '',
			template_per_lang: {},
			post_types: [],
			priority: 10,
			enabled: true,
		});
		renderMappings();

		// Scroll to the new item
		const container = document.getElementById('nxt-mappings-list');
		if (container) {
			container.scrollTop = container.scrollHeight;
		}
	}

	/**
	 * Save all mappings
	 */
	function saveMappings() {
		const statusEl = document.getElementById('nxt-save-status');
		const saveBtn = document.getElementById('nxt-save-mappings');
		
		if (saveBtn) {
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving...';
		}

		if (statusEl) {
			statusEl.textContent = '';
			statusEl.className = 'nxt-save-status';
		}

		const formData = new FormData();
		formData.append('action', 'nxt_save_template_mapping');
		formData.append('nonce', nonce);
		formData.append('mappings', JSON.stringify(mappings));

		fetch(ajaxUrl, {
			method: 'POST',
			body: formData,
		})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					mappings = data.data.mappings || mappings;
					if (statusEl) {
						statusEl.textContent = i18n.save_success;
						statusEl.className = 'nxt-save-status success';
					}
				} else {
					if (statusEl) {
						statusEl.textContent = data.data?.message || i18n.save_error;
						statusEl.className = 'nxt-save-status error';
					}
				}
			})
			.catch(() => {
				if (statusEl) {
					statusEl.textContent = i18n.save_error;
					statusEl.className = 'nxt-save-status error';
				}
			})
			.finally(() => {
				if (saveBtn) {
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save All Mappings';
				}
			});
	}

	/**
	 * Initialize
	 */
	function init() {
		renderMappings();

		document.getElementById('nxt-add-mapping')?.addEventListener('click', addMapping);
		document.getElementById('nxt-save-mappings')?.addEventListener('click', saveMappings);
	}

	// Wait for DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

(function() {
	'use strict';

	const config = window.nxtTemplateRouting || {};

	function initDebugLog() {
		document.getElementById('nxt-clear-debug-log')?.addEventListener('click', function() {
			if (!confirm('Clear the debug log?')) return;

			fetch(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=nxt_clear_debug_log&nonce=' + config.nonce
			})
			.then(r => r.json())
			.then(data => {
				if (data.success) { location.reload(); }
			});
		});
	}

	function syncTemplate(btn) {
		const slug     = btn.dataset.slug;
		const statusEl = btn.parentNode.querySelector('.sync-status');

		btn.disabled         = true;
		btn.textContent      = 'Syncing...';
		statusEl.textContent = '';

		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=nxt_sync_template_to_file&nonce=' + config.nonce + '&template_slug=' + encodeURIComponent(slug)
		})
		.then(r => r.json())
		.then(data => {
			if (data.success) {
				statusEl.innerHTML = '<span style="color: #00a32a;">Synced!</span>';
				btn.textContent    = 'Synced';
				const statusCell   = btn.closest('tr')?.querySelectorAll('td')[2];
				if (statusCell) {
					statusCell.innerHTML = '<span style="color: #00a32a;">In Sync</span>';
				}
			} else {
				statusEl.innerHTML = '<span style="color: #d63638;">' + (data.data?.message ?? 'Error') + '</span>';
				btn.disabled       = false;
				btn.textContent    = 'Retry';
			}
		})
		.catch(function() {
			statusEl.innerHTML = '<span style="color: #d63638;">Network error</span>';
			btn.disabled       = false;
			btn.textContent    = 'Retry';
		});
	}

	function initTemplateSync() {
		document.querySelectorAll('.sync-template-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				syncTemplate(this);
			});
		});

		document.getElementById('nxt-sync-all-templates')?.addEventListener('click', function() {
			const buttons  = document.querySelectorAll('.sync-template-btn:not([disabled])');
			const statusEl = document.getElementById('nxt-sync-all-status');
			const syncBtn  = this;

			if (buttons.length === 0) {
				statusEl.innerHTML = '<span style="color: #646970;">No templates need syncing.</span>';
				return;
			}

			syncBtn.disabled         = true;
			syncBtn.textContent      = 'Syncing...';
			statusEl.textContent     = 'Syncing ' + buttons.length + ' template(s)...';

			buttons.forEach(function(btn) { btn.click(); });

			setTimeout(function() {
				const remainingButtons = document.querySelectorAll('.sync-template-btn:not([disabled])');
				const syncedCount      = buttons.length - remainingButtons.length;
				statusEl.innerHTML     = '<span style="color: #00a32a;">Synced ' + syncedCount + ' template(s)</span>';
				syncBtn.disabled       = false;
				syncBtn.textContent    = 'Sync All Modified Templates';
			}, 2000);
		});
	}

	function initPatternTranslationToggle() {
		const toggle    = document.getElementById('nxt-pattern-translation-toggle');
		const statusEl  = document.getElementById('nxt-pattern-translation-status');
		if (!toggle) return;

		toggle.addEventListener('change', function() {
			const enabled = this.checked;
			if (statusEl) statusEl.textContent = 'Saving...';

			fetch(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=nxt_toggle_pattern_translation&nonce=' + config.nonce + '&enabled=' + (enabled ? '1' : '0')
			})
			.then(r => r.json())
			.then(data => {
				if (statusEl) {
					statusEl.textContent = data.success ? 'Saved.' : 'Error.';
					setTimeout(() => { statusEl.textContent = ''; }, 2000);
				}
			})
			.catch(function() {
				if (statusEl) statusEl.textContent = 'Error.';
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			initDebugLog();
			initTemplateSync();
			initPatternTranslationToggle();
		});
	} else {
		initDebugLog();
		initTemplateSync();
		initPatternTranslationToggle();
	}
})();
