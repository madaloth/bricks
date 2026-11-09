/**
 * Woo Setup Wizard admin page
 *
 * @since 2.4
 */
;(function () {
	const data = window.bricksWooSetupWizard || {}
	const i18n = (window.bricksData && window.bricksData.i18n) || {}
	const nonce = data.nonce || ''
	const loadingEl = document.getElementById('bricks-woo-loading')
	const areasEl = document.getElementById('bricks-woo-areas')
	const progressEl = document.getElementById('bricks-woo-progress')
	const selectedPresets = {}
	let latestStatuses = {}

	if (!loadingEl || !areasEl) {
		return
	}

	const areaLabels = {
		cart: i18n.wooCart,
		checkout: i18n.wooCheckout,
		my_account: i18n.wooMyAccount,
		order_confirmation: i18n.wooOrderConfirmation,
		shop: i18n.wooShop,
		single_product: i18n.wooSingleProduct
	}

	const areaDescriptions = {
		cart: i18n.wooCartDescription,
		checkout: i18n.wooCheckoutDescription,
		my_account: i18n.wooMyAccountDescription,
		order_confirmation: i18n.wooOrderConfirmationDescription,
		shop: i18n.wooShopDescription,
		single_product: i18n.wooSingleProductDescription
	}

	const areaIcons = {
		cart: 'dashicons-cart',
		checkout: 'dashicons-money-alt',
		my_account: 'dashicons-admin-users',
		order_confirmation: 'dashicons-media-document',
		shop: 'dashicons-store',
		single_product: 'dashicons-products'
	}

	const areaGroups = [
		{
			id: 'catalog',
			label: i18n.wooCatalog,
			areas: ['shop', 'single_product']
		},
		{
			id: 'checkout',
			label: i18n.wooCheckout,
			areas: ['cart', 'checkout']
		},
		{
			id: 'customer',
			label: i18n.wooCustomer,
			areas: ['my_account', 'order_confirmation']
		}
	]

	const statusMeta = {
		ready: {
			label: i18n.wooReady,
			className: 'ready'
		},
		partial: {
			label: i18n.wooNeedsSetup,
			className: 'partial'
		},
		not_configured: {
			label: i18n.wooNotStarted,
			className: 'not-configured'
		}
	}

	const linkIcons = {
		edit: '<span class="dashicons dashicons-edit" aria-hidden="true"></span>',
		remove: '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>',
		view: '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>'
	}

	// Fetch

	function fetchStatus() {
		setLoading(true)

		fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'bricks_woo_setup_wizard_get_status',
				nonce,
				presets: JSON.stringify(selectedPresets)
			})
		})
			.then((r) => r.json())
			.then((response) => {
				setLoading(false)
				if (response.success && response.data) {
					latestStatuses = response.data
					renderDashboard(response.data)
					areasEl.classList.remove('is-hidden')
				} else {
					showError(i18n.wooErrorLoading)
				}
			})
			.catch((err) => {
				setLoading(false)
				showError(i18n.error + ': ' + err.message)
			})
	}

	function setLoading(active) {
		if (active) {
			areasEl.classList.add('is-hidden')
			loadingEl.classList.remove('is-hidden')
		} else {
			loadingEl.classList.add('is-hidden')
		}
	}

	function showError(msg) {
		loadingEl.innerHTML = '<p style="color:var(--bricks-text-danger);">' + escHtml(msg) + '</p>'
		loadingEl.classList.remove('is-hidden')
	}

	function renderDashboard(statuses) {
		const renderedAreas = new Set()
		let expandedArea = ''
		areasEl.innerHTML = ''

		Object.values(statuses).forEach((s) => {
			if (s.area && s.selected_preset_key) {
				selectedPresets[s.area] = s.selected_preset_key
			}

			if (!expandedArea && s.status !== 'ready') {
				expandedArea = s.area
			}
		})

		areaGroups.forEach((group) => {
			const groupStatuses = group.areas.map((area) => statuses[area]).filter(Boolean)

			if (!groupStatuses.length) {
				return
			}

			const section = document.createElement('section')
			section.className = 'area-section area-section--' + group.id

			section.innerHTML =
				'<div class="area-section-header">' + '<h2>' + escHtml(group.label) + '</h2>' + '</div>'

			groupStatuses.forEach((s) => {
				renderedAreas.add(s.area)
				section.appendChild(createCard(s, s.area === expandedArea))
			})

			areasEl.appendChild(section)
		})

		Object.values(statuses).forEach((s) => {
			if (!s.area || renderedAreas.has(s.area)) {
				return
			}

			const section = document.createElement('section')
			section.className = 'area-section area-section--other'
			section.innerHTML =
				'<div class="area-section-header"><h2>' + escHtml(i18n.wooOther) + '</h2></div>'
			section.appendChild(createCard(s, s.area === expandedArea))
			areasEl.appendChild(section)
		})

		updateProgress(statuses)
	}

	function createCard(s, expanded) {
		const card = document.createElement('div')
		card.className = 'area-card' + (expanded ? ' is-expanded' : '')
		card.dataset.area = s.area || ''
		card.innerHTML = renderCard(s)

		return card
	}

	function updateProgress(statuses) {
		if (!progressEl) {
			return
		}

		const statusList = Object.values(statuses)
		const total = statusList.length
		const ready = statusList.filter((s) => s.status === 'ready').length
		const percent = total ? Math.round((ready / total) * 100) : 0
		const readyEl = progressEl.querySelector('.setup-progress-ready')
		const totalEl = progressEl.querySelector('.setup-progress-total')

		progressEl.style.setProperty('--ready-percent', percent + '%')

		if (readyEl) readyEl.textContent = ready
		if (totalEl) totalEl.textContent = total
	}

	// Card renderer
	function renderCard(s) {
		const label = areaLabels[s.area] || s.area
		const description = areaDescriptions[s.area] || ''
		const icon = areaIcons[s.area] || 'dashicons-admin-page'
		const statusCls = s.status.replace(/_/g, '-')
		const meta = statusMeta[s.status] || { label: s.status, className: statusCls }
		const statusText = meta.label || s.status

		let html = '<div class="card-header">'
		html +=
			'<div class="card-toggle">' +
			'<span class="card-toggle-left">' +
			'<span class="area-icon ' +
			escHtml(statusCls) +
			'"><span class="dashicons ' +
			escHtml(icon) +
			'" aria-hidden="true"></span></span>' +
			'<span class="area-title-group">' +
			'<h3>' +
			escHtml(label) +
			'</h3>' +
			(description ? '<span class="area-description">' + escHtml(description) + '</span>' : '') +
			'</span>' +
			'</span>' +
			'<span class="card-toggle-right">' +
			'<span class="status-badge ' +
			escHtml(meta.className || statusCls) +
			'">' +
			escHtml(statusText) +
			'</span>' +
			'<span class="card-toggle-chevron" aria-hidden="true"></span>' +
			'</span>' +
			'</div>'
		html += '</div>' // .card-header
		html += '<div class="card-body">'

		const showSetupNoticeBeforePresetSelector = shouldShowSetupNoticeBeforePresetSelector(s)
		if (showSetupNoticeBeforePresetSelector) {
			html += renderSetupNotice(s.setup_notice, 'setup-notice--preset')
		}

		html += renderPresetSelector(s)

		// WooCommerce page section
		if (s.page_id) {
			html += '<div class="card-section">'
			html += '<div class="section-label">' + escHtml(i18n.wooWooCommercePage) + '</div>'
			if (s.page_warnings && s.page_warnings.length) {
				html += renderSectionWarnings(s.page_warnings, true)
			}

			html += '<div class="page-info">'
			html += '<div class="page-info-main">'
			html += '<div class="page-name">' + escHtml(s.page_title) + '</div>'
			html += '<div class="page-meta">' + escHtml(s.page_url) + '</div>'

			html += '<div class="page-links">'
			if (s.page_bricks_edit_link) {
				html +=
					'<a href="' +
					escHtml(s.page_bricks_edit_link) +
					'" class="button button-small" target="_blank" rel="noopener">' +
					linkIcons.edit +
					escHtml(i18n.editWithBricks) +
					'</a>'
			}
			if (s.page_admin_edit_link) {
				html +=
					'<a href="' +
					escHtml(s.page_admin_edit_link) +
					'" class="button button-small" target="_blank" rel="noopener">' +
					linkIcons.edit +
					escHtml(i18n.wooEditWithWordPress) +
					'</a>'
			}
			if (s.page_url) {
				html +=
					'<a href="' +
					escHtml(s.page_url) +
					'" class="button button-small" target="_blank" rel="noopener">' +
					linkIcons.view +
					escHtml(i18n.wooViewFrontend) +
					'</a>'
			}
			html += '</div>'
			html += '</div>'

			if (
				s.preset_key &&
				(s.requires_page_edit || (s.page_warnings && s.page_warnings.length > 0))
			) {
				html +=
					'<div class="page-setup-actions"><button class="btn-setup-page" data-area="' +
					escHtml(s.area) +
					'" data-preset="' +
					escHtml(s.selected_preset_key || s.preset_key) +
					'" data-page-setup-confirm="' +
					escHtml(s.page_setup_confirm || '') +
					'">' +
					escHtml(i18n.wooSetup) +
					'</button></div>'
			}

			if (s.page_url) {
				html +=
					'<a class="page-open-button" href="' +
					escHtml(s.page_url) +
					'" target="_blank" rel="noopener">' +
					escHtml(i18n.open) +
					'</a>'
			}

			html += '</div>' // .page-info
			html += '</div>' // .card-section
		} else if (s.area !== 'single_product') {
			// No page assigned notice (Exclude single_product which doesn't require a page)
			html += '<div class="no-page-notice">'
			html += escHtml(i18n.wooPageNotAssigned) + ' '
			const settingLink =
				s.area === 'shop' ? data.wooSettingsProductsUrl : data.wooSettingsAdvancedUrl
			html +=
				'<a href="' +
				escHtml(settingLink || '#') +
				'" target="_blank" rel="noopener">' +
				escHtml(i18n.wooGoToSettings) +
				'</a>'
			html += '</div>'
		}

		// Templates section - always show all expected slots
		if (s.templates && s.templates.length) {
			html += '<div class="card-section">'
			html += '<div class="section-label">' + escHtml(i18n.wooBricksTemplates) + '</div>'
			if (s.template_warnings && s.template_warnings.length) {
				html += renderSectionWarnings(s.template_warnings)
			}
			html += '<div class="template-slots">'
			s.templates.forEach((slot) => {
				html += renderTemplateSlot(
					slot,
					s.area,
					s.selected_preset_key || s.preset_key,
					!!s.should_draft_existing
				)
			})
			html += '</div>'
			html += '</div>'
		}

		// Action buttons - cart, checkout, my_account, single_product, shop
		// Hide the setup button and notice when the WooCommerce page is missing or trashed.
		// single_product has no associated page so always show.
		if (s.area === 'single_product' || s.page_id) {
			if (s.setup_notice && !showSetupNoticeBeforePresetSelector) {
				html += renderSetupNotice(s.setup_notice)
			}
			html += '<div class="action-buttons">'
			html +=
				'<button class="btn primary btn-setup" data-area="' +
				escHtml(s.area) +
				'" data-preset="' +
				escHtml(s.selected_preset_key || s.preset_key || '') +
				'" data-confirm-description="' +
				escHtml(s.confirm_description || '') +
				'">' +
				escHtml(i18n.wooOneClickSetup) +
				'</button>'
			html += '</div>'
		}

		html += '</div>' // .card-body
		return html
	}

	function shouldShowSetupNoticeBeforePresetSelector(s) {
		if (!s.setup_notice || !s.presets || !s.presets.length) {
			return false
		}

		const selectedPresetId = s.selected_preset_key || s.preset_key
		const selectedPreset = s.presets.find((preset) => preset.id === selectedPresetId)

		return !!selectedPreset && selectedPreset.mode === 'advanced'
	}

	function renderSetupNotice(notice, extraClass = '') {
		const classes = 'setup-notice' + (extraClass ? ' ' + extraClass : '')

		return '<p class="' + classes + '">' + escHtml(notice) + '</p>'
	}

	function renderPresetSelector(s) {
		if (!s.allow_preset_selection || !s.presets || s.presets.length < 2) {
			return ''
		}

		let html = '<div class="preset-selector">'
		html += '<label>' + escHtml(i18n.wooSetupType) + '</label>'
		html +=
			'<div class="preset-options" role="group" aria-label="' + escHtml(i18n.wooSetupType) + '">'

		s.presets.forEach((preset) => {
			const selected = preset.id === (s.selected_preset_key || s.preset_key)
			const disabled = preset.disabled ? ' disabled' : ''
			const title = preset.disabled_reason ? ' title="' + escHtml(preset.disabled_reason) + '"' : ''

			html +=
				'<button type="button" class="preset-option' +
				(selected ? ' is-selected' : '') +
				'" data-area="' +
				escHtml(s.area) +
				'" data-preset="' +
				escHtml(preset.id) +
				'"' +
				' aria-pressed="' +
				(selected ? 'true' : 'false') +
				'"' +
				disabled +
				title +
				'>' +
				escHtml(preset.label) +
				'</button>'
		})

		html += '</div>'
		html += '</div>'

		if (s.advanced_modular_notice) {
			const settingsUrl = s.advanced_modular_settings_url || data.bricksWooSettingsUrl || ''
			html += '<p class="setup-notice">' + escHtml(s.advanced_modular_notice)

			if (settingsUrl && i18n.wooEnableAdvancedModularElements) {
				html +=
					' <a href="' +
					escHtml(settingsUrl) +
					'" target="_blank" rel="noopener noreferrer">' +
					escHtml(i18n.wooEnableAdvancedModularElements) +
					'</a>'
			}

			html += '</p>'
		}

		return html
	}

	function renderTemplateSlot(slot, area, presetKey, shouldDraftExisting) {
		const isConditionalTemplate =
			(area === 'shop' && slot.type === 'archive') ||
			(area === 'single_product' && slot.type === 'single')

		// These slots identify existing alternatives, not templates required by setup.
		if (isConditionalTemplate && !slot.templates.length) {
			return ''
		}

		// Matching conditions can target different products or archives without conflicting.
		const isDuplicate = !isConditionalTemplate && slot.found && slot.templates.length > 1
		const cls = slot.found ? (isDuplicate ? 'found duplicate' : 'found') : 'not-found'
		let html = '<div class="template-slot ' + cls + '">'

		// Slot header: type label + duplicate badge + per-slot setup button
		html += '<div class="slot-header">'
		html += '<div class="slot-type-label">' + escHtml(slot.type_label) + '</div>'
		if (isDuplicate) {
			html +=
				'<span class="slot-duplicate-badge" title="' +
				escHtml(i18n.wooTemplateDuplicate) +
				'">⚠ ' +
				escHtml(i18n.wooTemplateDuplicate) +
				'</span>'
		}
		if (slot.has_preset_def && area && presetKey) {
			const btnLabel = slot.found ? i18n.wooResetupTemplate : i18n.wooSetup
			html +=
				'<button class="btn-setup-template" data-area="' +
				escHtml(area) +
				'" data-preset="' +
				escHtml(presetKey) +
				'" data-template-type="' +
				escHtml(slot.type) +
				'" data-should-draft-existing="' +
				(shouldDraftExisting ? '1' : '0') +
				'">' +
				escHtml(btnLabel) +
				'</button>'
		}
		html += '</div>'

		if (isConditionalTemplate) {
			html += '<p class="description">' + escHtml(i18n.wooConditionalTemplatesDescription) + '</p>'
		}

		if (slot.found && slot.templates.length) {
			// Render every template - all are shown when duplicates exist
			slot.templates.forEach((tpl, idx) => {
				const statusCls = 'status-' + String(tpl.status).replace(/[^a-z0-9]/g, '-')
				const itemCls = isDuplicate
					? 'slot-template-item slot-template-item--duplicate'
					: 'slot-template-item'
				html += '<div class="' + itemCls + '">'
				if (isDuplicate) {
					html += '<span class="slot-template-index">' + (idx + 1) + '</span>'
					html += '<div class="slot-template-body">'
				}
				html += '<div class="slot-template-title">' + escHtml(tpl.title) + '</div>'
				html +=
					'<span class="slot-template-status ' + statusCls + '">' + escHtml(tpl.status) + '</span>'
				html += '<div class="slot-template-actions">'
				html +=
					'<a href="' +
					escHtml(tpl.edit_link) +
					'" class="button button-small slot-edit-link" target="_blank" rel="noopener">' +
					linkIcons.edit +
					escHtml(i18n.editWithBricks) +
					'</a>'
				if (tpl.admin_edit_link) {
					html +=
						'<a href="' +
						escHtml(tpl.admin_edit_link) +
						'" class="button button-small slot-edit-link" target="_blank" rel="noopener">' +
						linkIcons.edit +
						escHtml(i18n.wooEditWithWordPress) +
						'</a>'
				}
				if (tpl.preview_link) {
					html +=
						'<a href="' +
						escHtml(tpl.preview_link + '?bricks_preview=' + Date.now()) +
						'" class="button button-small slot-edit-link" target="_blank" rel="noopener">' +
						linkIcons.view +
						escHtml(i18n.wooPreviewTemplate) +
						'</a>'
				}
				html +=
					'<a href="#" class="button button-small btn-trash-template slot-edit-link" data-template-id="' +
					escHtml(tpl.id) +
					'">' +
					linkIcons.remove +
					escHtml(i18n.wooTrashTemplate) +
					'</a>'
				html += '</div>'
				if (isDuplicate) {
					html += '</div>'
				}
				html += '</div>'
			})
		} else {
			html += '<div class="slot-not-found-label">' + escHtml(i18n.wooTemplateNotFound) + '</div>'
		}

		html += '</div>'
		return html
	}

	function renderSectionWarnings(warnings, withAdvice = false) {
		let html = '<div class="section-warnings">'
		warnings.forEach((w) => {
			html +=
				'<div class="warning-item"><span class="warning-icon">⚠</span><span>' +
				escHtml(w) +
				'</span></div>'
		})
		if (withAdvice) {
			html +=
				'<div class="warning-item"><span class="warning-icon">✓</span><span>' +
				escHtml(i18n.wooPageAdvice) +
				'</span></div>'
		}
		html += '</div>'
		return html
	}

	// 1-Click Setup

	/**
	 * Show an inline confirmation panel below the action-buttons bar.
	 * Clicking "Confirm Setup" triggers runSetup(); clicking "Cancel" removes the panel.
	 * Calling again while the panel is open toggles it closed.
	 */
	function showConfirmPanel(area, btn) {
		const card = btn.closest('.area-card')
		if (!card) return

		// Toggle: if already open, close and re-enable the button
		const existing = card.querySelector('.setup-confirm-panel')
		if (existing) {
			existing.remove()
			btn.disabled = false
			card.querySelectorAll('.btn-setup-page, .btn-setup-template').forEach((b) => {
				b.disabled = false
			})
			return
		}

		btn.disabled = true
		card.querySelectorAll('.btn-setup-page, .btn-setup-template').forEach((b) => {
			b.disabled = true
		})

		const desc = btn.dataset.confirmDescription

		const panel = document.createElement('div')
		panel.className = 'setup-confirm-panel'
		panel.dataset.area = area
		panel.dataset.preset = btn.dataset.preset || selectedPresets[area] || ''
		panel.innerHTML =
			'<p class="confirm-description">' +
			escHtml(desc) +
			'</p>' +
			'<div class="confirm-actions">' +
			'<button class="btn primary btn-confirm-setup">' +
			escHtml(i18n.wooConfirmSetup) +
			'</button>' +
			'<button class="btn btn-cancel-setup">' +
			escHtml(i18n.cancel) +
			'</button>' +
			'</div>'

		const actionButtons = card.querySelector('.action-buttons')
		if (actionButtons) {
			actionButtons.after(panel)
		} else {
			card.appendChild(panel)
		}
	}

	/**
	 * Show a scoped confirmation panel for per-page or per-template setup.
	 * The panel is inserted after the triggering element's nearest .card-section or .template-slot.
	 * Only one scoped panel can be open at a time per card.
	 */
	function showScopedConfirmPanel(scope, area, templateType, triggerBtn) {
		const card = triggerBtn.closest('.area-card')
		if (!card) return

		// Toggle: if already open, close and re-enable all scoped buttons
		const existing = card.querySelector('.setup-scoped-panel')
		if (existing) {
			existing.remove()
			card.querySelectorAll('.btn-setup-page, .btn-setup-template').forEach((b) => {
				b.disabled = false
			})
			const mainSetupBtn = card.querySelector('.btn-setup')
			if (mainSetupBtn) mainSetupBtn.disabled = false
			return
		}

		// Disable all scoped setup buttons and the main 1-click button while the panel is open
		card.querySelectorAll('.btn-setup-page, .btn-setup-template').forEach((b) => {
			b.disabled = true
		})
		const mainSetupBtn = card.querySelector('.btn-setup')
		if (mainSetupBtn) mainSetupBtn.disabled = true

		const shouldDraft = triggerBtn.dataset.shouldDraftExisting !== '0'
		let desc = ''
		if (scope === 'page') {
			desc = triggerBtn.dataset.pageSetupConfirm
		} else {
			desc = shouldDraft ? i18n.wooSetupTemplateConfirm : i18n.wooSetupTemplateNoDraftConfirm
		}

		const panel = document.createElement('div')
		panel.className = 'setup-scoped-panel'
		panel.dataset.scope = scope
		panel.dataset.area = area
		panel.dataset.preset = triggerBtn.dataset.preset || selectedPresets[area] || ''
		panel.dataset.templateType = templateType || ''
		panel.innerHTML =
			'<p class="confirm-description">' +
			escHtml(desc) +
			'</p>' +
			'<div class="confirm-actions">' +
			'<button class="btn primary btn-confirm-scoped-setup">' +
			escHtml(i18n.wooConfirmSetup) +
			'</button>' +
			'<button class="btn btn-cancel-scoped-setup">' +
			escHtml(i18n.cancel) +
			'</button>' +
			'</div>'

		// Insert contextually: after the parent template-slot, or after the parent card-section
		const parentSlot = triggerBtn.closest('.template-slot')
		const parentSection = triggerBtn.closest('.card-section')
		if (parentSlot) {
			parentSlot.after(panel)
		} else if (parentSection) {
			parentSection.after(panel)
		} else {
			card.appendChild(panel)
		}
	}

	/**
	 * Run the setup AJAX request after the user confirms.
	 * btn is the "Confirm Setup" button inside .setup-confirm-panel (or the "Replace Layout"
	 * button inside .setup-force-confirm when force === true).
	 */
	function runSetup(area, btn, force, scope, templateType, presetKey) {
		scope = scope || 'all'
		templateType = templateType || ''
		const panel = btn.closest('.setup-confirm-panel, .setup-scoped-panel')
		const card = btn.closest('.area-card')
		presetKey = presetKey || (panel ? panel.dataset.preset : '') || selectedPresets[area] || ''

		btn.disabled = true
		const cancelBtn = panel
			? panel.querySelector('.btn-cancel-setup, .btn-cancel-scoped-setup')
			: null
		if (cancelBtn) cancelBtn.disabled = true

		// Show running status text below the action buttons
		let statusEl = panel ? panel.querySelector('.confirm-status') : null
		if (!statusEl && panel) {
			statusEl = document.createElement('p')
			statusEl.className = 'confirm-status'
			panel.appendChild(statusEl)
		}
		if (statusEl) statusEl.textContent = i18n.wooSetupRunning

		fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'bricks_woo_setup_wizard_run_setup',
				nonce,
				area,
				...(presetKey ? { preset: presetKey } : {}),
				...(force ? { force: 'true' } : {}),
				...(scope !== 'all' ? { scope } : {}),
				...(templateType ? { template_type: templateType } : {})
			})
		})
			.then((r) => r.json())
			.then((response) => {
				if (response.success && response.data && response.data.area_status) {
					if (card) {
						if (response.data.area_status.selected_preset_key) {
							selectedPresets[area] = response.data.area_status.selected_preset_key
						}

						latestStatuses[area] = response.data.area_status
						updateProgress(latestStatuses)
						card.innerHTML = renderCard(response.data.area_status)
						card.classList.add('is-expanded')
						if (response.data.message) {
							const notice = document.createElement('div')
							notice.className = 'setup-success-notice'
							const msg = document.createElement('span')
							msg.textContent = response.data.message
							const dismissBtn = document.createElement('button')
							dismissBtn.className = 'setup-success-dismiss'
							dismissBtn.type = 'button'
							dismissBtn.setAttribute('aria-label', 'Dismiss')
							dismissBtn.textContent = '✕'
							dismissBtn.addEventListener('click', () => notice.remove())
							notice.appendChild(msg)
							notice.appendChild(dismissBtn)
							// Add notice as the last child of the card, so it doesn't interfere with the confirm panel if the user goes back to setup for another area
							card.appendChild(notice)
						}
					}
				} else {
					if (statusEl) statusEl.textContent = ''
					btn.disabled = false
					if (cancelBtn) cancelBtn.disabled = false
					const isObj = response.data && typeof response.data === 'object'
					const code = isObj ? response.data.code : null
					const msg = isObj
						? response.data.message
						: typeof response.data === 'string'
							? response.data
							: ''
					if (code === 'page_has_bricks_data') {
						showForceConfirmPanel(panel, area, msg, scope, templateType, presetKey)
					} else {
						showPanelError(panel, i18n.wooSetupError + (msg ? ': ' + msg : ''))
					}
				}
			})
			.catch((err) => {
				if (statusEl) statusEl.textContent = ''
				btn.disabled = false
				if (cancelBtn) cancelBtn.disabled = false
				showPanelError(panel, i18n.error + ': ' + err.message)
			})
	}

	function showPanelError(panel, msg) {
		if (!panel) return
		const existing = panel.querySelector('.confirm-error')
		if (existing) existing.remove()
		const el = document.createElement('p')
		el.className = 'confirm-error'
		el.textContent = msg
		panel.appendChild(el)
	}

	/**
	 * Show an inline "Replace Layout" confirmation sub-panel when the server returns
	 * page_has_bricks_data. Clicking "Replace Layout" re-fires runSetup with force=true.
	 * Clicking "Cancel" dismisses the sub-panel and re-enables the original confirm buttons.
	 */
	function showForceConfirmPanel(panel, area, msg, scope, templateType, presetKey) {
		if (!panel) return
		const existing = panel.querySelector('.setup-force-confirm')
		if (existing) existing.remove()

		// Lock the original confirm/cancel buttons while the force sub-panel is open
		const originalConfirmBtn = panel.querySelector('.btn-confirm-setup')
		const originalCancelBtn = panel.querySelector('.btn-cancel-setup')
		if (originalConfirmBtn) originalConfirmBtn.disabled = true
		if (originalCancelBtn) originalCancelBtn.disabled = true

		const forceEl = document.createElement('div')
		forceEl.className = 'setup-force-confirm'
		forceEl.innerHTML =
			'<p class="force-confirm-warning">⚠ ' +
			escHtml(msg || i18n.wooPageHasBricksData) +
			'</p>' +
			'<div class="confirm-actions">' +
			'<button class="btn danger btn-force-setup">' +
			escHtml(i18n.wooReplaceContent) +
			'</button>' +
			'<button class="btn btn-cancel-force">' +
			escHtml(i18n.cancel) +
			'</button>' +
			'</div>'

		panel.appendChild(forceEl)

		forceEl.querySelector('.btn-force-setup').addEventListener('click', (e) => {
			runSetup(area, e.currentTarget, true, scope, templateType, presetKey)
		})

		forceEl.querySelector('.btn-cancel-force').addEventListener('click', () => {
			forceEl.remove()
			const confirmBtn = panel.querySelector('.btn-confirm-setup')
			const cancelBtn = panel.querySelector('.btn-cancel-setup')
			if (confirmBtn) confirmBtn.disabled = false
			if (cancelBtn) cancelBtn.disabled = false
		})
	}

	/**
	 * Show an inline confirm panel for trashing a template.
	 * Inserted after the triggering .slot-template-item.
	 * Calling again while the same panel is open toggles it closed.
	 */
	function showTrashConfirmPanel(templateId, triggerBtn) {
		const card = triggerBtn.closest('.area-card')
		const item = triggerBtn.closest('.slot-template-item')
		if (!card || !item) return

		// Close any open trash panel in this card
		const existing = card.querySelector('.trash-confirm-panel')
		if (existing) {
			const prevItem = existing.previousElementSibling
			existing.remove()
			if (prevItem === item) return // same template toggled closed
		}

		const panel = document.createElement('div')
		panel.className = 'trash-confirm-panel'
		panel.innerHTML =
			'<p class="confirm-description">' +
			escHtml(i18n.wooTrashTemplateConfirm) +
			'</p>' +
			'<div class="confirm-actions">' +
			'<button class="btn btn-confirm-trash-template" data-template-id="' +
			escHtml(templateId) +
			'">' +
			escHtml(i18n.wooTrashTemplate) +
			'</button>' +
			'<button class="btn btn-cancel-trash-template">' +
			escHtml(i18n.cancel) +
			'</button>' +
			'</div>'

		item.after(panel)
	}

	/**
	 * Run the trash AJAX request after the user confirms.
	 */
	function runTrashTemplate(templateId, btn) {
		const panel = btn.closest('.trash-confirm-panel')
		const card = btn.closest('.area-card')

		btn.disabled = true
		const cancelBtn = panel ? panel.querySelector('.btn-cancel-trash-template') : null
		if (cancelBtn) cancelBtn.disabled = true

		let statusEl = panel ? panel.querySelector('.confirm-status') : null
		if (!statusEl && panel) {
			statusEl = document.createElement('p')
			statusEl.className = 'confirm-status'
			panel.appendChild(statusEl)
		}
		if (statusEl) statusEl.textContent = i18n.wooTrashTemplateDeleting

		const areaEl = card ? card.querySelector('[data-area]') : null
		const area = areaEl ? areaEl.dataset.area : null

		fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'bricks_woo_setup_wizard_trash_template',
				nonce,
				template_id: templateId
			})
		})
			.then((r) => r.json())
			.then((response) => {
				if (response.success) {
					if (card && area) {
						refreshCard(card, area)
					}
				} else {
					if (statusEl) statusEl.textContent = ''
					btn.disabled = false
					if (cancelBtn) cancelBtn.disabled = false
					const msg =
						response.data && typeof response.data === 'object'
							? response.data.message
							: typeof response.data === 'string'
								? response.data
								: ''
					showPanelError(panel, i18n.wooTrashTemplateError + (msg ? ': ' + msg : ''))
				}
			})
			.catch((err) => {
				if (statusEl) statusEl.textContent = ''
				btn.disabled = false
				if (cancelBtn) cancelBtn.disabled = false
				showPanelError(panel, i18n.error + ': ' + err.message)
			})
	}

	/**
	 * Re-fetch status for one area and re-render its card in place.
	 */
	function refreshCard(card, area) {
		fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'bricks_woo_setup_wizard_get_status',
				nonce,
				presets: JSON.stringify(selectedPresets)
			})
		})
			.then((r) => r.json())
			.then((response) => {
				if (response.success && response.data && response.data[area]) {
					if (response.data[area].selected_preset_key) {
						selectedPresets[area] = response.data[area].selected_preset_key
					}

					latestStatuses = response.data
					updateProgress(response.data)
					card.innerHTML = renderCard(response.data[area])
					card.classList.add('is-expanded')
				}
			})
	}
	// Utilities

	function escHtml(str) {
		if (str == null) return ''
		return String(str).replace(
			/[&<>"']/g,
			(c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]
		)
	}

	// Init

	// Event delegation for all action buttons inside the areas grid
	areasEl.addEventListener('click', (e) => {
		const presetOption = e.target.closest('.preset-option')
		if (presetOption && !presetOption.disabled) {
			const area = presetOption.dataset.area
			const card = presetOption.closest('.area-card')

			if (area && card) {
				selectedPresets[area] = presetOption.dataset.preset
				refreshCard(card, area)
			}

			return
		}

		// Toggle accordion: click anywhere on .card-header (except interactive elements)
		const cardHeader = e.target.closest('.card-header')
		if (cardHeader && !e.target.closest('a, button, input, select, textarea')) {
			const card = cardHeader.closest('.area-card')
			if (card) card.classList.toggle('is-expanded')
			return
		}

		// 1-Click Setup > show inline confirm panel
		const setupBtn = e.target.closest('.btn-setup')
		if (setupBtn && !setupBtn.disabled) {
			const area = setupBtn.dataset.area
			if (area) showConfirmPanel(area, setupBtn)
			return
		}

		// Per-page or per-template scoped setup > show scoped confirm panel
		const scopedSetupBtn = e.target.closest('.btn-setup-page, .btn-setup-template')
		if (scopedSetupBtn && !scopedSetupBtn.disabled) {
			const scope = scopedSetupBtn.classList.contains('btn-setup-page') ? 'page' : 'template'
			const area = scopedSetupBtn.dataset.area
			const templateType = scopedSetupBtn.dataset.templateType || ''
			if (area) showScopedConfirmPanel(scope, area, templateType, scopedSetupBtn)
			return
		}

		// Confirm full Setup > run AJAX
		const confirmBtn = e.target.closest('.btn-confirm-setup')
		if (confirmBtn && !confirmBtn.disabled) {
			const panel = confirmBtn.closest('.setup-confirm-panel')
			const area = panel ? panel.dataset.area : null
			if (area) runSetup(area, confirmBtn, false, 'all', '', panel.dataset.preset || '')
			return
		}

		// Confirm scoped setup > run AJAX with scope
		const confirmScopedBtn = e.target.closest('.btn-confirm-scoped-setup')
		if (confirmScopedBtn && !confirmScopedBtn.disabled) {
			const panel = confirmScopedBtn.closest('.setup-scoped-panel')
			if (!panel) return
			const scope = panel.dataset.scope
			const area = panel.dataset.area
			const templateType = panel.dataset.templateType || ''
			if (area) {
				runSetup(area, confirmScopedBtn, false, scope, templateType, panel.dataset.preset || '')
			}
			return
		}

		// Cancel full setup > remove panel and re-enable setup button
		const cancelBtn = e.target.closest('.btn-cancel-setup')
		if (cancelBtn) {
			const panel = cancelBtn.closest('.setup-confirm-panel')
			if (panel) {
				const card = panel.closest('.area-card')
				panel.remove()
				if (card) {
					const setupBtn = card.querySelector('.btn-setup')
					if (setupBtn) setupBtn.disabled = false
					card.querySelectorAll('.btn-setup-page, .btn-setup-template').forEach((b) => {
						b.disabled = false
					})
				}
			}
			return
		}

		// Cancel scoped setup > remove panel and re-enable all scoped buttons
		const cancelScopedBtn = e.target.closest('.btn-cancel-scoped-setup')
		if (cancelScopedBtn) {
			const panel = cancelScopedBtn.closest('.setup-scoped-panel')
			if (panel) {
				const card = panel.closest('.area-card')
				panel.remove()
				if (card) {
					card.querySelectorAll('.btn-setup-page, .btn-setup-template').forEach((b) => {
						b.disabled = false
					})
					const setupBtn = card.querySelector('.btn-setup')
					if (setupBtn) setupBtn.disabled = false
				}
			}
		}

		// Trash template > show inline confirm panel
		const trashTemplateBtn = e.target.closest('.btn-trash-template')
		if (trashTemplateBtn) {
			e.preventDefault()
			showTrashConfirmPanel(trashTemplateBtn.dataset.templateId, trashTemplateBtn)
			return
		}

		// Confirm trash template > run AJAX
		const confirmTrashBtn = e.target.closest('.btn-confirm-trash-template')
		if (confirmTrashBtn && !confirmTrashBtn.disabled) {
			runTrashTemplate(confirmTrashBtn.dataset.templateId, confirmTrashBtn)
			return
		}

		// Cancel trash template > remove panel
		const cancelTrashBtn = e.target.closest('.btn-cancel-trash-template')
		if (cancelTrashBtn) {
			const panel = cancelTrashBtn.closest('.trash-confirm-panel')
			if (panel) panel.remove()
		}
	})

	document.addEventListener('DOMContentLoaded', fetchStatus)
})()
