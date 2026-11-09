/**
 * Deliver one notification per server-confirmed mutation after the Woo UI work settles.
 *
 * Requests and scheduled refreshes share a barrier. Refreshes never create notifications;
 * their only role is to finish (or invalidate) the UI update for confirmed mutations.
 * This also covers native Woo requests and pages without Dynamic Fragments.
 *
 * A completed request contributes a notification only when PHP confirms changed item keys
 * or quantities. Read-only requests can hold delivery while they update the UI, but cannot
 * create notifications. Each confirmed request retains its own entry when refreshes coalesce.
 *
 * @since 2.4 #86cbf8r7t
 */
const bricksWooCartContentsChanges = (() => {
	/** @type {{requestId: string, sourceEvent: string}[]} Confirmed requests awaiting UI completion. */
	const pending = []
	/** @type {Set<Object|string>} In-flight jqXHRs and named, coalesced refresh timers. */
	const holds = new Set()
	let failedRefresh = false
	let initialized = false

	/**
	 * Deliver completed changes once all tracked UI work has settled.
	 *
	 * A failed refresh invalidates this batch: retaining it would let an unrelated later
	 * refresh show a success message for an operation whose UI never finished updating.
	 *
	 * @returns {void}
	 */
	const flush = () => {
		if (holds.size) {
			return
		}

		const changes = pending.splice(0)
		const failed = failedRefresh
		failedRefresh = false

		if (!failed) {
			changes.forEach((detail) => {
				document.body.dispatchEvent(
					new CustomEvent('bricks/woocommerce/cart-contents-changed', { detail })
				)
			})
		}
	}

	return {
		/**
		 * Keep notifications pending while a request or scheduled refresh owns the UI update.
		 * Named timers reuse their key so resetting a debounce does not leave extra holds.
		 *
		 * @param {Object|string} key jqXHR identity or the name of a coalesced refresh timer.
		 * @returns {void}
		 */
		hold(key) {
			holds.add(key)
		},
		/**
		 * Release one owner after completion, cancellation, or handoff to another request.
		 * The microtask lets synchronous Woo callbacks acquire the next hold before delivery.
		 *
		 * @param {Object|string} key Previously held request or timer.
		 * @param {boolean} failed Whether the UI refresh failed and invalidates pending messages.
		 * @returns {void}
		 */
		release(key, failed = false) {
			failedRefresh = failedRefresh || failed
			holds.delete(key)
			// Let all synchronous Woo handlers finish replacing and initializing markup first.
			Promise.resolve().then(flush)
		},
		/**
		 * Register once after frontend.js initializes bricksIsFrontend on DOMContentLoaded.
		 *
		 * @returns {void}
		 */
		init() {
			if (initialized || !bricksIsFrontend || typeof jQuery === 'undefined') {
				return
			}

			initialized = true

			jQuery(document.body).on('update_checkout', () => this.hold('checkout'))
			jQuery(document.body).on('updated_checkout checkout_error', (event) => {
				this.release('checkout', event.type === 'checkout_error')
			})

			jQuery.ajaxPrefilter((options, originalOptions, xhr) => {
				const url = new URL(options.url, window.location.href)
				if (url.origin !== window.location.origin) {
					return
				}

				const endpoint = url.searchParams.get('wc-ajax') || ''
				const endpoints = [
					'add_to_cart',
					'bricks_add_to_cart',
					'remove_from_cart',
					'bricks_update_cart_item_quantity',
					'update_order_review',
					'get_refreshed_fragments',
					'bricks_get_woo_dynamic_fragments'
				]
				const cartUrl =
					document.querySelector('.woocommerce-cart-form')?.action ||
					window.wc_cart_params?.cart_url
				const isCartRequest =
					!endpoint && cartUrl && url.pathname === new URL(cartUrl, window.location.href).pathname

				if (!endpoints.includes(endpoint) && !isCartRequest) {
					return
				}

				const isRefresh = ['get_refreshed_fragments', 'bricks_get_woo_dynamic_fragments'].includes(
					endpoint
				)
				const data = new URLSearchParams(options.data || '')
				const checkoutData = new URLSearchParams(data.get('post_data') || '')
				let expectedFragmentCount = 1

				if (endpoint === 'bricks_get_woo_dynamic_fragments') {
					try {
						expectedFragmentCount = Math.max(1, JSON.parse(data.get('fragments') || '[]').length)
					} catch (error) {
						// A malformed request cannot certify that all requested regions were updated.
						expectedFragmentCount = Infinity
					}
				}

				// A checkout/cart refresh can alter rendered totals without a customer item change.
				// Only explicit mutation requests ask PHP for a before/after confirmation.
				const isMutation =
					!isRefresh &&
					(endpoint === 'update_order_review'
						? checkoutData.get('bricks_update_checkout_cart_quantities') === '1'
						: isCartRequest
							? data.has('update_cart') ||
								url.searchParams.has('remove_item') ||
								url.searchParams.has('undo_item')
							: true)

				const requestId = Array.from(window.crypto.getRandomValues(new Uint32Array(4)), (value) =>
					value.toString(16).padStart(8, '0')
				).join('-')
				this.hold(xhr)

				if (isMutation) {
					xhr.setRequestHeader('X-Bricks-Cart-Request', requestId)
				}

				// Prefilter callbacks register before the caller's success/complete handlers. Defer
				// inspection until those handlers have installed markup and scheduled follow-up work.
				xhr.always((result, textStatus) => {
					Promise.resolve().then(() => {
						const response = xhr.responseJSON
						const fragments =
							endpoint === 'bricks_get_woo_dynamic_fragments'
								? response?.data?.fragments
								: response?.fragments
						const hasRefreshPayload =
							!isRefresh ||
							(fragments &&
								typeof fragments === 'object' &&
								Object.keys(fragments).length >= expectedFragmentCount)
						const succeeded =
							hasRefreshPayload &&
							['success', 'nocontent'].includes(textStatus) &&
							xhr.status >= 200 &&
							xhr.status < 300 &&
							response?.success !== false &&
							!response?.error

						if (
							isMutation &&
							succeeded &&
							xhr.getResponseHeader('X-Bricks-Cart-Contents-Changed') === '1'
						) {
							pending.push({ requestId, sourceEvent: endpoint || 'update_cart' })
						}

						// Woo does not emit checkout_error for transport failures or reload responses.
						if (endpoint === 'update_order_review') {
							this.release('checkout', !succeeded && xhr.statusText !== 'abort')
						}

						this.release(xhr, (isRefresh || isCartRequest) && !succeeded)
					})
				})
			})
		}
	}
})()

// frontend.js sets bricksIsFrontend in its earlier DOMContentLoaded listener.
document.addEventListener('DOMContentLoaded', () => bricksWooCartContentsChanges.init())

function bricksShowNotice(message) {
	// Find the notice wrapper .brxe-woocommerce-notice
	const $noticeWrapper = jQuery('.brxe-woocommerce-notice')

	if ($noticeWrapper.length > 0) {
		// Found Bricks WC notice wrapper, use it to display the error message
		$noticeWrapper.html(message)
	} else {
		// Use the default WooCommerce notice wrapper
		jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove()
		jQuery('form.woocommerce-checkout ').prepend(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + message + '</div>'
		)
	}

	bricksEnhanceCheckoutNotices(document)
}

function bricksScrollToNotices() {
	// Include Bricks WC notice wrapper
	let scrollElement = jQuery(
		'.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout, .brxe-woocommerce-notice'
	)

	if (!scrollElement.length) {
		scrollElement = jQuery('form.checkout')
	}

	jQuery.scroll_to_notices(scrollElement)
}

function bricksNormalizeWooNoticeFieldId(fieldId = '') {
	return `${fieldId || ''}`.replace(/^#/, '').trim()
}

function bricksGetWooEscapedSelector(value = '') {
	if (!value) {
		return ''
	}

	return window.CSS?.escape ? CSS.escape(value) : value.replace(/([^a-zA-Z0-9_-])/g, '\\$1')
}

function bricksGetWooNoticeFieldId(node) {
	const noticeNode = node?.closest?.('[data-brx-notice-field-id], [data-id]')

	if (noticeNode?.dataset?.brxNoticeFieldId) {
		return bricksNormalizeWooNoticeFieldId(noticeNode.dataset.brxNoticeFieldId)
	}

	if (noticeNode?.dataset?.id) {
		return bricksNormalizeWooNoticeFieldId(noticeNode.dataset.id)
	}

	const link = node?.closest?.('a[href^="#"]') || node?.querySelector?.('a[href^="#"]')

	if (link?.getAttribute('href')) {
		return bricksNormalizeWooNoticeFieldId(link.getAttribute('href'))
	}

	return ''
}

function bricksFindWooFieldTarget(rootNode, fieldId, includeDocument = true) {
	const normalizedFieldId = bricksNormalizeWooNoticeFieldId(fieldId)
	const escapedFieldId = bricksGetWooEscapedSelector(normalizedFieldId)

	if (!normalizedFieldId || !escapedFieldId) {
		return null
	}

	const searchContexts = []
	const addContext = (context) => {
		if (context?.querySelector && !searchContexts.includes(context)) {
			searchContexts.push(context)
		}
	}

	// Prefer the local form, but allow a document fallback because Bricks notice elements can live outside the form.
	addContext(rootNode?.closest?.('form'))
	addContext(rootNode?.querySelector?.('form'))
	if (includeDocument) {
		addContext(document)
	}

	for (const context of searchContexts) {
		const wrapper = context.querySelector(`#${escapedFieldId}_field`)

		if (wrapper) {
			return wrapper
		}

		const field = Array.from(context.querySelectorAll('input, select, textarea')).find(
			(fieldNode) => fieldNode.id === normalizedFieldId || fieldNode.name === normalizedFieldId
		)

		if (field) {
			return field.closest('.form-row, .brxe-woocommerce-form-field') || field
		}
	}

	return null
}

function bricksMarkWooFieldInvalid(fieldTarget) {
	if (!fieldTarget?.classList) {
		return false
	}

	const wrapper = fieldTarget.matches?.('.form-row, .brxe-woocommerce-form-field')
		? fieldTarget
		: fieldTarget.closest?.('.form-row, .brxe-woocommerce-form-field')
	const field = fieldTarget.matches?.('input, select, textarea')
		? fieldTarget
		: fieldTarget.querySelector?.('input, select, textarea')

	if (!wrapper) {
		return false
	}

	// Reuse Woo's invalid classes so existing Woo/Bricks invalid-state styles continue to apply.
	wrapper.classList.add('woocommerce-invalid', 'brx-woo-field-error')

	if (
		field?.required ||
		field?.getAttribute?.('aria-required') === 'true' ||
		wrapper.classList.contains('validate-required')
	) {
		wrapper.classList.add('validate-required', 'woocommerce-invalid-required-field')
	}

	if (field) {
		field.setAttribute('aria-invalid', 'true')
	}

	return true
}

function bricksFocusWooFieldTarget(fieldTarget) {
	const focusTarget = fieldTarget?.matches?.('input, select, textarea, button')
		? fieldTarget
		: fieldTarget?.querySelector?.('input, select, textarea, button')

	if (fieldTarget?.scrollIntoView) {
		fieldTarget.scrollIntoView({ behavior: 'smooth', block: 'center' })
	}

	if (focusTarget?.focus) {
		focusTarget.focus({ preventScroll: true })
	}
}

function bricksEnhanceWooFieldErrorNotices(rootNode = document) {
	// Builder notices can be dummy data, so only frontend renders should mark real form fields invalid.
	if (!bricksIsFrontend) {
		return
	}

	// Notice metadata is the source of truth for both server-rendered and fragment-injected validation errors.
	rootNode = rootNode?.querySelectorAll ? rootNode : document

	const noticeItems = rootNode.querySelectorAll('.woocommerce-error li')

	noticeItems.forEach((item) => {
		const fieldId = bricksGetWooNoticeFieldId(item)

		if (!fieldId) {
			return
		}

		item.dataset.brxNoticeFieldId = fieldId

		if (!item.dataset.id) {
			item.dataset.id = fieldId
		}

		const fieldTarget = bricksFindWooFieldTarget(item, fieldId)

		if (fieldTarget) {
			bricksMarkWooFieldInvalid(fieldTarget)
		}
	})
}

function bricksBindWooNoticeFieldErrorEvents() {
	if (!bricksIsFrontend) {
		return
	}

	if (bricksBindWooNoticeFieldErrorEvents.bound) {
		return
	}

	bricksBindWooNoticeFieldErrorEvents.bound = true

	document.addEventListener('click', (event) => {
		if (event.defaultPrevented) {
			return
		}

		const noticeItem = event.target.closest('.woocommerce-error li')

		if (!noticeItem) {
			return
		}

		const fieldId = bricksGetWooNoticeFieldId(noticeItem)

		if (!fieldId) {
			return
		}

		const checkoutForm =
			noticeItem.closest('form.checkout, form.woocommerce-checkout') ||
			document.querySelector('form.checkout, form.woocommerce-checkout')

		const checkoutFieldTarget = checkoutForm
			? bricksFindWooFieldTarget(checkoutForm, fieldId, false)
			: null

		// Checkout gets first chance so hidden multistep fields can route through the step manager.
		if (checkoutFieldTarget && window.bricksUtils?.goToWooCheckoutField) {
			event.preventDefault()
			window.bricksUtils.goToWooCheckoutField(checkoutForm, fieldId, { force: true })
			return
		}

		const fieldTarget = bricksFindWooFieldTarget(noticeItem, fieldId)

		if (!fieldTarget) {
			return
		}

		if (event.target.closest('a[href^="#"]')) {
			event.preventDefault()
		}

		bricksFocusWooFieldTarget(fieldTarget)
	})

	if (typeof jQuery !== 'undefined') {
		jQuery(document.body).on(
			'checkout_error updated_checkout updated_cart_totals wc_fragments_refreshed',
			function () {
				setTimeout(() => {
					bricksEnhanceWooFieldErrorNotices(document)
				}, 40)
			}
		)
	}
}

function bricksSyncWooNoticeFieldErrors(rootNode = document) {
	if (!bricksIsFrontend) {
		return
	}

	bricksEnhanceWooFieldErrorNotices(rootNode)
	bricksBindWooNoticeFieldErrorEvents()
}

// Copy field ids from checkout notice links into data attributes so step routing
// can reopen the right step after WooCommerce reports an error, then run the
// generic field highlighter for non-checkout Woo forms.
function bricksEnhanceCheckoutNotices(rootNode = document) {
	rootNode = rootNode?.querySelectorAll ? rootNode : document

	const noticeItems = rootNode.querySelectorAll(
		'.woocommerce-error li, .woocommerce-NoticeGroup-checkout .woocommerce-error li'
	)

	noticeItems.forEach((item) => {
		if (item.dataset?.brxNoticeFieldId) {
			return
		}

		const noticeLink = item.querySelector('a[href^="#"]')
		if (!noticeLink) {
			return
		}

		item.dataset.brxNoticeFieldId = noticeLink.getAttribute('href').replace(/^#/, '')
	})

	bricksEnhanceWooFieldErrorNotices(rootNode)
}

// Manage multistep checkout state on top of WooCommerce's classic checkout form
// without removing fields from the native form submission flow.
function bricksCreateWooCheckoutStepsManager() {
	const selectors = {
		form: 'form.checkout, form.woocommerce-checkout',
		step: '[data-brx-checkout-step]',
		nav: '[data-brx-checkout-steps-nav]',
		navItem: '[data-brx-checkout-step-nav-item]',
		stepNumber: '[data-brx-woo-checkout-current-step-number]',
		legacyNavButton: '[data-brx-checkout-steps-nav] button[data-step-id]',
		noticeLink:
			'.woocommerce-notices-wrapper a[href^="#"], .woocommerce-NoticeGroup-checkout a[href^="#"]',
		noticeTarget:
			'.woocommerce-notices-wrapper [data-brx-notice-field-id], .woocommerce-NoticeGroup-checkout [data-brx-notice-field-id]'
	}

	// A page may contain multiple checkout forms, especially in previews.
	const getForms = () => Array.from(document.querySelectorAll(selectors.form))

	// Step order is based on DOM order so users can add, remove, or rearrange steps freely.
	const getSteps = (form) => Array.from(form.querySelectorAll(selectors.step))

	// A checkout can expose one or more nav instances that all mirror the same step state.
	const getNavs = (form) => Array.from(form.querySelectorAll(selectors.nav))

	// Normalize ids coming from links, notices, and field wrappers into the same shape.
	const normalizeFieldId = (fieldId = '') => fieldId.replace(/^#/, '').trim()

	// Use CSS.escape when available, with a fallback for older environments.
	const getEscapedSelector = (value = '') => {
		if (!value) {
			return ''
		}

		return window.CSS?.escape ? CSS.escape(value) : value.replace(/([^a-zA-Z0-9_-])/g, '\\$1')
	}

	// Step order is the source of truth: the first step is always the initial step.
	const getInitialStepId = (steps = []) => steps[0]?.dataset?.stepId || ''

	// Woo keeps its country/state SelectWoo initializer private, so we mirror the same
	// setup here when a later step becomes visible after the initial page load.
	const getWooCountrySelectFormatStrings = () => {
		if (typeof wc_country_select_params === 'undefined') {
			return {}
		}

		return {
			language: {
				errorLoading() {
					return wc_country_select_params.i18n_searching
				},
				inputTooLong(args) {
					const overChars = args.input.length - args.maximum

					if (overChars === 1) {
						return wc_country_select_params.i18n_input_too_long_1
					}

					return wc_country_select_params.i18n_input_too_long_n.replace('%qty%', overChars)
				},
				inputTooShort(args) {
					const remainingChars = args.minimum - args.input.length

					if (remainingChars === 1) {
						return wc_country_select_params.i18n_input_too_short_1
					}

					return wc_country_select_params.i18n_input_too_short_n.replace('%qty%', remainingChars)
				},
				loadingMore() {
					return wc_country_select_params.i18n_load_more
				},
				maximumSelected(args) {
					if (args.maximum === 1) {
						return wc_country_select_params.i18n_selection_too_long_1
					}

					return wc_country_select_params.i18n_selection_too_long_n.replace('%qty%', args.maximum)
				},
				noResults() {
					return wc_country_select_params.i18n_no_matches
				},
				searching() {
					return wc_country_select_params.i18n_searching
				}
			}
		}
	}

	// Initialize Woo's country/state SelectWoo controls once the step becomes visible.
	const initVisibleWooCountrySelects = (context = document) => {
		bricksBindWooSelect2PreInitShells(context)

		if (typeof jQuery === 'undefined' || typeof jQuery.fn.selectWoo === 'undefined') {
			return
		}

		const $context = jQuery(context)
		const select2Language = getWooCountrySelectFormatStrings()

		$context.find('select.country_select:visible, select.state_select:visible').each(function () {
			const $select = jQuery(this)

			if ($select.data('select2') || $select.next('.select2').length) {
				return
			}

			const select2Args = jQuery.extend(
				{
					placeholder: $select.attr('data-placeholder') || $select.attr('placeholder') || '',
					label: $select.attr('data-label') || null,
					required: $select.attr('aria-required') === 'true' || null,
					width: '100%'
				},
				select2Language
			)

			$select
				.on('select2:select.bricksCheckoutSteps', function () {
					jQuery(this).trigger('focus')
				})
				.selectWoo(select2Args)

			bricksRemoveWooSelect2PreInitShell(this)
		})
	}

	// Convert a step id back into its current DOM position.
	const getStepIndex = (steps, stepId) => steps.findIndex((step) => step.dataset?.stepId === stepId)

	// When we need to focus a field, prefer a real interactive control inside the wrapper.
	const getFocusableField = (container) => {
		if (!container) {
			return null
		}

		return container.querySelector(
			'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])'
		)
	}

	// For generic "go to step" navigation, place focus on the first usable field in the
	// destination step so keyboard users can continue immediately.
	const getStepDefaultFocusTarget = (step) => {
		if (!step) {
			return null
		}

		const focusableField = getFocusableField(step)

		if (focusableField && !isFieldIgnoredForValidation(focusableField)) {
			return focusableField
		}

		return focusableField || null
	}

	// Step-level opt-in for auto focusing the first field when the step becomes active.
	const shouldAutoFocusStep = (step) => step?.dataset?.autoFocusFirstField === 'true'

	// Validation is controlled on the step element itself. If the checkbox is not enabled,
	// forward navigation should be allowed without validating the current step.
	const shouldValidateBeforeLeave = (step) => step?.dataset?.validateBeforeLeave === 'true'

	// Hidden conditional UI should not block step progression, even if Woo or a plugin
	// leaves the fields in the DOM.
	const isElementHidden = (element) => {
		if (!element || element.nodeType !== 1) {
			return true
		}

		if (element.hidden || element.getAttribute('aria-hidden') === 'true') {
			return true
		}

		if (element.closest('[hidden], [aria-hidden="true"]')) {
			return true
		}

		const styles = window.getComputedStyle(element)

		if (
			styles.display === 'none' ||
			styles.visibility === 'hidden' ||
			styles.visibility === 'collapse'
		) {
			return true
		}

		// Detached from layout flow, but keep fixed-position elements valid.
		if (element.offsetParent === null && styles.position !== 'fixed') {
			return true
		}

		return false
	}

	// Ignore values Woo will not submit or that belong to UI the shopper cannot currently use.
	const isFieldIgnoredForValidation = (field) => {
		if (!field) {
			return true
		}

		// Checkout login is optional during the multistep flow, so its fields should
		// never block a shopper from skipping ahead to the actual checkout fields.
		if (field.closest('.brxe-woocommerce-checkout-login')) {
			return true
		}

		if (
			field.disabled ||
			field.type === 'hidden' ||
			field.type === 'button' ||
			field.type === 'submit' ||
			field.type === 'reset'
		) {
			return true
		}

		return isElementHidden(field)
	}

	// Keep step navigation and notice scrolling centered on the relevant target.
	const scrollToElement = (element, offset = 24) => {
		if (!element) {
			return
		}

		const rect = element.getBoundingClientRect()
		window.scrollBy({
			top: rect.top - offset,
			left: 0,
			behavior: 'smooth'
		})
	}

	// Reuse the same invalid-step handling regardless of whether the shopper clicked
	// a navigation button or a step nav item.
	const handleStepValidationFailure = (validation, fallbackTarget) => {
		scrollToElement(validation.scrollTarget || fallbackTarget)
		focusField(validation.focusTarget || getFocusableField(validation.scrollTarget))
		return false
	}

	// Fire a public event when the visible step changes so third-party scripts can
	// initialize or refresh step-specific UI as soon as the target step is active.
	const dispatchStepChangeEvent = (
		form,
		{
			stepId,
			stepIndex,
			stepElement,
			previousStepId = '',
			previousStepIndex = -1,
			previousStepElement = null,
			maxReachedIndex = 0
		} = {}
	) => {
		if (!form || !stepElement) {
			return
		}

		const detail = {
			form,
			stepId,
			stepIndex,
			stepElement,
			previousStepId,
			previousStepIndex,
			previousStepElement,
			maxReachedIndex,
			steps: getSteps(form)
		}

		// Dispatch custom event (@since 2.4)
		document.body.dispatchEvent(
			new CustomEvent('bricks/woocommerce/checkout-step-changed', {
				detail
			})
		)
	}

	// Submit the native Woo checkout form from the final step while keeping Woo's
	// submit handlers, selected gateway hooks, and submitter context intact.
	const submitCheckoutForm = (form) => {
		if (!form) {
			return false
		}

		const submitButton = form.querySelector(
			'#place_order, button[name="woocommerce_checkout_place_order"], button[type="submit"], input[type="submit"]'
		)

		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit(submitButton || undefined)
			return true
		}

		if (submitButton && typeof submitButton.click === 'function') {
			submitButton.click()
			return true
		}

		jQuery(form).trigger('submit')
		return true
	}

	// Focus is deferred slightly so it still works after a step switch or Woo DOM refresh.
	const focusField = (field) => {
		if (!field || typeof field.focus !== 'function') {
			return
		}

		setTimeout(() => {
			field.focus({ preventScroll: true })
		}, 30)
	}

	// Look up a field by id, wrapper id, or field name so notices and interaction targets
	// can resolve to the correct checkout control.
	const findFieldTarget = (form, fieldId) => {
		const normalizedId = normalizeFieldId(fieldId)

		if (!form || !normalizedId) {
			return null
		}

		const escapedId = getEscapedSelector(normalizedId)
		const exactTarget =
			form.querySelector(`#${escapedId}`) ||
			form.querySelector(`#${escapedId}_field`) ||
			form.querySelector(`[name="${escapedId}"]`) ||
			form.querySelector(`[name="${escapedId}\\[\\]"]`)

		if (exactTarget) {
			return exactTarget
		}

		return form.querySelector(`[id$="${escapedId}_field"], [id$="${escapedId}"]`)
	}

	// Read the custom nav item wrappers inside one nav instance.
	const getCustomNavItems = (nav) =>
		Array.from(nav.querySelectorAll(selectors.navItem)).filter(
			(item) => item.closest(selectors.nav) === nav
		)

	const syncStepNumberNodes = (form, steps) => {
		form.querySelectorAll(selectors.stepNumber).forEach((numberNode) => {
			let stepIndex = -1

			const navItem = numberNode.closest(selectors.navItem)
			if (navItem && navItem.closest(selectors.form) === form) {
				stepIndex = getStepIndex(steps, navItem.dataset?.stepId || '')
			}

			if (stepIndex === -1) {
				const step = numberNode.closest(selectors.step)
				if (step && step.closest(selectors.form) === form) {
					stepIndex = getStepIndex(steps, step.dataset?.stepId || '')
				}
			}

			numberNode.textContent = stepIndex > -1 ? String(stepIndex + 1) : '#'
		})
	}

	// Keep slot-based label/index placeholders in custom nav items in sync with the step they represent.
	const syncCustomNavItems = (form, steps) => {
		getNavs(form).forEach((nav) => {
			const customItems = getCustomNavItems(nav)

			customItems.forEach((item, index) => {
				const step = steps[index] || null
				const stepIndex = step ? getStepIndex(steps, step.dataset?.stepId || '') : -1
				const stepLabel = step?.dataset?.stepLabel || ''

				if (step) {
					item.dataset.stepId = step.dataset?.stepId || ''
					item.dataset.stepIndex = String(stepIndex)
					item.dataset.stepLabel = stepLabel
					item.dataset.allowDirectEntry = step.dataset?.allowDirectEntry || 'false'
				} else {
					delete item.dataset.stepId
					delete item.dataset.stepIndex
					delete item.dataset.stepLabel
					item.dataset.allowDirectEntry = 'false'
				}
			})
		})

		syncStepNumberNodes(form, steps)
	}

	// Both the new custom nav items and the legacy auto-rendered buttons should respond to state updates.
	const getNavActionItems = (form) => {
		const items = []

		getNavs(form).forEach((nav) => {
			items.push(...getCustomNavItems(nav))
		})

		items.push(...Array.from(form.querySelectorAll(selectors.legacyNavButton)))

		return items
	}

	// Update the optional step navigation UI to reflect active, completed, and blocked steps.
	const setNavState = (form, steps, activeIndex, maxReachedIndex) => {
		getNavActionItems(form).forEach((item) => {
			const stepIndex = getStepIndex(steps, item.dataset?.stepId || '')
			const allowDirectEntry = item.dataset?.allowDirectEntry === 'true'
			const isActive = stepIndex === activeIndex
			const isCompleted = stepIndex > -1 && stepIndex < maxReachedIndex && stepIndex !== activeIndex
			const isDisabled = stepIndex === -1 ? true : stepIndex > maxReachedIndex && !allowDirectEntry

			item.classList.toggle('is-active', isActive)
			item.classList.toggle('is-completed', isCompleted)
			item.classList.toggle('is-disabled', isDisabled)
			item.setAttribute('aria-disabled', isDisabled ? 'true' : 'false')
			item.setAttribute('aria-current', isActive ? 'step' : 'false')

			if ('disabled' in item) {
				item.disabled = isDisabled
			}

			if (item.matches(selectors.navItem)) {
				item.setAttribute('tabindex', isDisabled ? '-1' : '0')
			}
		})
	}

	// Switch the visible step and persist both the active step and the furthest step reached.
	const activateStep = (
		form,
		stepId,
		{
			scrollIntoView = false,
			focusTarget = null,
			force = false,
			allowForward = false,
			autoFocus = false
		} = {}
	) => {
		const steps = getSteps(form)

		if (!steps.length) {
			return false
		}

		const currentStepId = form.dataset?.brxCheckoutActiveStep || getInitialStepId(steps)
		const nextIndex = getStepIndex(steps, stepId)
		const reachedIndex =
			parseInt(form.dataset?.brxCheckoutMaxStep || getStepIndex(steps, currentStepId), 10) || 0
		const maxReachedIndex = Math.max(reachedIndex, nextIndex)
		const previousStepIndex = getStepIndex(steps, currentStepId)

		if (nextIndex === -1) {
			return false
		}

		const nextStep = steps[nextIndex]
		const allowDirectEntry = nextStep.dataset?.allowDirectEntry === 'true'

		if (!force && !allowForward && nextIndex > reachedIndex && !allowDirectEntry) {
			return false
		}

		form.dataset.brxCheckoutActiveStep = stepId
		form.dataset.brxCheckoutMaxStep = String(maxReachedIndex)

		steps.forEach((step, index) => {
			const isActive = index === nextIndex
			const isCompleted = index < maxReachedIndex && !isActive

			step.classList.toggle('brx-checkout-step--active', isActive)
			step.classList.toggle('brx-checkout-step--inactive', !isActive)
			step.classList.toggle('brx-checkout-step--completed', isCompleted)
			step.setAttribute('aria-hidden', isActive ? 'false' : 'true')
		})

		initVisibleWooCountrySelects(steps[nextIndex])
		setNavState(form, steps, nextIndex, maxReachedIndex)

		const activeStep = steps[nextIndex]
		if (scrollIntoView) {
			scrollToElement(focusTarget || activeStep)
		}

		const resolvedFocusTarget =
			focusTarget || (autoFocus ? getStepDefaultFocusTarget(activeStep) : null)

		if (resolvedFocusTarget) {
			focusField(resolvedFocusTarget)
		}

		dispatchStepChangeEvent(form, {
			stepId,
			stepIndex: nextIndex,
			stepElement: activeStep,
			previousStepId: currentStepId,
			previousStepIndex,
			previousStepElement: steps[previousStepIndex] || null,
			maxReachedIndex
		})

		return true
	}

	// Validate only the current step before moving forward, using Woo's own validation
	// classes plus native browser validity as a fallback.
	const getStepValidationError = (step) => {
		if (!step) {
			return { valid: true }
		}

		jQuery(step)
			.find('.input-text, select, input:checkbox, input:radio, textarea')
			.filter(function () {
				return !isFieldIgnoredForValidation(this)
			})
			.trigger('validate')
			.trigger('blur')

		const invalidCandidates = step.querySelectorAll(
			'.woocommerce-invalid, .form-row input:invalid, .form-row select:invalid, .form-row textarea:invalid'
		)

		for (const invalidCandidate of invalidCandidates) {
			const invalidField = invalidCandidate.matches('input, select, textarea')
				? invalidCandidate
				: invalidCandidate.querySelector('input, select, textarea')

			if (invalidField && isFieldIgnoredForValidation(invalidField)) {
				continue
			}

			if (isElementHidden(invalidCandidate)) {
				continue
			}

			return {
				valid: false,
				scrollTarget:
					invalidCandidate.closest('.form-row, .payment_box, .wc_payment_method') ||
					invalidCandidate,
				focusTarget: getFocusableField(invalidCandidate) || invalidField || invalidCandidate
			}
		}

		const fields = step.querySelectorAll('input, select, textarea')
		for (const field of fields) {
			if (isFieldIgnoredForValidation(field)) {
				continue
			}

			if (typeof field.checkValidity === 'function' && !field.checkValidity()) {
				return {
					valid: false,
					scrollTarget: field.closest('.form-row, .payment_box, .wc_payment_method') || field,
					focusTarget: field
				}
			}
		}

		// The empty zero-total fragment stays in the DOM so Woo can restore payment methods later.
		const paymentContainer = step.querySelector(
			'.payment_methods, .brxe-woocommerce-payment-options:not([data-brx-needs-payment="false"])'
		)

		if (paymentContainer) {
			const selectedGateway = step.querySelector('input[name="payment_method"]:checked')

			if (!selectedGateway) {
				return {
					valid: false,
					scrollTarget: paymentContainer,
					focusTarget:
						paymentContainer.querySelector('input[name="payment_method"]') || paymentContainer
				}
			}
		}

		return { valid: true }
	}

	// Scan the rendered checkout in DOM order so Bricks follows the shopper's visual
	// layout, even when Woo notice ordering still reflects the original field priority.
	const getFirstInvalidStepValidation = (form) => {
		const steps = getSteps(form)

		for (const step of steps) {
			const validation = getStepValidationError(step)

			if (!validation.valid) {
				return {
					step,
					validation
				}
			}
		}

		return null
	}

	// Open the step that owns a field, then scroll and focus that field for the shopper.
	const goToField = (
		form,
		fieldId,
		{ scrollIntoView = true, focusTarget = true, force = true } = {}
	) => {
		const fieldTarget = findFieldTarget(form, fieldId)

		if (!fieldTarget) {
			return false
		}

		const step = fieldTarget.closest(selectors.step)
		const focusableTarget = fieldTarget.matches?.('input, select, textarea, button')
			? fieldTarget
			: getFocusableField(fieldTarget)

		if (step?.dataset?.stepId) {
			activateStep(form, step.dataset.stepId, {
				scrollIntoView,
				focusTarget: focusTarget ? focusableTarget || fieldTarget : null,
				force
			})
		} else if (scrollIntoView) {
			scrollToElement(fieldTarget)
			if (focusTarget) {
				focusField(focusableTarget || fieldTarget)
			}
		}

		return true
	}

	// Shared next/previous step navigation used by both buttons and step navigation items.
	const moveStep = (form, direction, config = {}) => {
		const steps = getSteps(form)
		const currentStepId = form.dataset?.brxCheckoutActiveStep || getInitialStepId(steps)
		const currentIndex = Math.max(0, getStepIndex(steps, currentStepId))
		const currentStep = steps[currentIndex]
		const nextIndex = currentIndex + direction

		if (!currentStep || nextIndex < 0 || nextIndex >= steps.length) {
			return false
		}

		if (direction > 0 && shouldValidateBeforeLeave(currentStep)) {
			const validation = getStepValidationError(currentStep)

			if (!validation.valid) {
				return handleStepValidationFailure(validation, currentStep)
			}
		}

		return activateStep(form, steps[nextIndex].dataset.stepId, {
			scrollIntoView: config?.checkoutStepScrollIntoView !== false,
			allowForward: direction > 0
		})
	}

	// Direct step jumps should still respect the current step's validation rules when
	// moving forward, so nav-only setups cannot bypass required checkout fields.
	const jumpToStep = (form, targetStepId, config = {}) => {
		const steps = getSteps(form)
		const currentStepId = form.dataset?.brxCheckoutActiveStep || getInitialStepId(steps)
		const currentIndex = Math.max(0, getStepIndex(steps, currentStepId))
		const targetIndex = getStepIndex(steps, targetStepId)
		const currentStep = steps[currentIndex]

		if (!currentStep || targetIndex === -1 || targetIndex === currentIndex) {
			return false
		}

		if (targetIndex > currentIndex && shouldValidateBeforeLeave(currentStep)) {
			const validation = getStepValidationError(currentStep)

			if (!validation.valid) {
				return handleStepValidationFailure(validation, currentStep)
			}
		}

		return activateStep(form, targetStepId, {
			scrollIntoView: config?.checkoutStepScrollIntoView !== false,
			force: false,
			autoFocus: false
		})
	}

	// Treat Enter on text-like fields as "continue" for intermediate steps, and as
	// "submit order" on the final step, while avoiding special checkout flows such
	// as coupon, login, payment method rows, and textarea input.
	const maybeAdvanceStepOnEnter = (event) => {
		if (event.key !== 'Enter' || event.defaultPrevented) {
			return false
		}

		const target = event.target
		if (!(target instanceof HTMLElement) || !target.closest(selectors.form)) {
			return false
		}

		if (
			target.closest(
				'.brxe-woocommerce-checkout-coupon, .brxe-woocommerce-checkout-login, .woocommerce-form-login, .payment_box, .wc_payment_method'
			)
		) {
			return false
		}

		if (
			target.matches(
				'textarea, select, button, [role="button"], input[type="checkbox"], input[type="radio"], input[type="submit"], input[type="button"], input[type="reset"], input[type="file"]'
			)
		) {
			return false
		}

		if (target.isContentEditable || target.closest('[contenteditable="true"]')) {
			return false
		}

		const form = target.closest(selectors.form)
		const steps = getSteps(form)
		const currentStepId = form.dataset?.brxCheckoutActiveStep || getInitialStepId(steps)
		const currentIndex = Math.max(0, getStepIndex(steps, currentStepId))
		const currentStep = steps[currentIndex]

		if (!currentStep || !currentStep.contains(target)) {
			return false
		}

		event.preventDefault()

		// If we're on the last step, submit the form. Otherwise, move to the next step.
		if (currentIndex >= steps.length - 1) {
			return submitCheckoutForm(form)
		}

		return moveStep(form, 1, {
			checkoutStepScrollIntoView: true
		})
	}

	// Woo notices may point to a field through custom data or a hash link.
	const getNoticeFieldId = (node) => {
		const noticeNode = node?.closest?.('[data-brx-notice-field-id]')
		if (noticeNode?.dataset?.brxNoticeFieldId) {
			return noticeNode.dataset.brxNoticeFieldId
		}

		const link = node?.closest?.('a[href^="#"]')
		if (link?.getAttribute('href')) {
			return normalizeFieldId(link.getAttribute('href'))
		}

		return ''
	}

	return {
		selectors,

		// Rebuild step state after initial load and after Woo replaces checkout fragments.
		init(form = null) {
			bricksEnhanceCheckoutNotices(document)

			const forms = form ? [form] : getForms()

			forms.forEach((checkoutForm) => {
				const steps = getSteps(checkoutForm)

				if (!steps.length) {
					return
				}

				syncCustomNavItems(checkoutForm, steps)
				initVisibleWooCountrySelects(checkoutForm)

				const activeStepId =
					checkoutForm.dataset?.brxCheckoutActiveStep &&
					getStepIndex(steps, checkoutForm.dataset.brxCheckoutActiveStep) > -1
						? checkoutForm.dataset.brxCheckoutActiveStep
						: getInitialStepId(steps)

				const activeStep = steps[getStepIndex(steps, activeStepId)] || steps[0]

				activateStep(checkoutForm, activeStepId, {
					force: true,
					autoFocus: shouldAutoFocusStep(activeStep)
				})
			})
		},

		// Public entry point used by the generic interactions system.
		runAction(sourceEl, config = {}) {
			const form = sourceEl?.closest?.(selectors.form) || document.querySelector(selectors.form)

			if (!form) {
				return false
			}

			const scrollIntoView = config?.checkoutStepScrollIntoView !== false
			switch (config?.checkoutStepMode) {
				case 'prev':
					return moveStep(form, -1, config)

				case 'goToStep':
					return jumpToStep(form, config?.checkoutStepId, config)

				case 'goToField':
					return goToField(form, config?.checkoutFieldId, {
						scrollIntoView,
						focusTarget: true,
						force: true
					})

				case 'next':
				default:
					return moveStep(form, 1, config)
			}
		},

		goToField,

		handleEnterKey(event) {
			return maybeAdvanceStepOnEnter(event)
		},

		// When Woo returns an error after submit, reveal the matching step before focusing it.
		handleCheckoutError(form) {
			if (!form) {
				return
			}

			const invalidStepValidation = getFirstInvalidStepValidation(form)

			if (invalidStepValidation) {
				const { step, validation } = invalidStepValidation

				if (step?.dataset?.stepId) {
					activateStep(form, step.dataset.stepId, {
						force: true
					})
				}

				handleStepValidationFailure(validation, step)
				return
			}

			const firstNoticeTarget = form.querySelector(selectors.noticeTarget)
			const noticeFieldId =
				getNoticeFieldId(firstNoticeTarget) ||
				getNoticeFieldId(form.querySelector(selectors.noticeLink))

			if (noticeFieldId && goToField(form, noticeFieldId, { force: true })) {
				return
			}

			const activeStepId = form.dataset?.brxCheckoutActiveStep || getInitialStepId(getSteps(form))
			const activeStep =
				form.querySelector(
					`${selectors.step}[data-step-id="${getEscapedSelector(activeStepId)}"]`
				) || getSteps(form)[0]
			const validation = getStepValidationError(activeStep)

			if (!validation.valid) {
				handleStepValidationFailure(validation, activeStep)
			}
		}
	}
}

// Ensure only one instance of the checkout steps manager is created, even if multiple forms or builders are present. (@since 2.4)
let bricksWooCheckoutStepsManager = null

const getWooCheckoutStepsManager = () => {
	if (bricksWooCheckoutStepsManager === null) {
		bricksWooCheckoutStepsManager = bricksCreateWooCheckoutStepsManager()
	}

	return bricksWooCheckoutStepsManager
}

// Extend bricksUtils const in frontend.js
Object.assign(window.bricksUtils, {
	getWooCheckoutStepsManager,

	syncWooNoticeFieldErrors(rootNode = document) {
		return bricksSyncWooNoticeFieldErrors(rootNode)
	},

	initWooCheckoutSteps(form = null) {
		return getWooCheckoutStepsManager().init(form)
	},

	runWooCheckoutStepAction(sourceEl, config = {}) {
		return getWooCheckoutStepsManager().runAction(sourceEl, config)
	},

	goToWooCheckoutField(form, fieldId, options = {}) {
		return getWooCheckoutStepsManager().goToField(form, fieldId, options)
	},

	handleWooCheckoutError(form) {
		return getWooCheckoutStepsManager().handleCheckoutError(form)
	}
})

/**
 * Expose a BricksFunction wrapper so step markup can trigger setup whenever Woo or Bricks refreshes the checkout DOM.
 *
 * @since 2.4
 */
const bricksWooCheckoutStepsFn = new BricksFunction({
	parentNode: document,
	selector:
		'form.checkout [data-brx-checkout-step], form.checkout [data-brx-checkout-steps-nav], form.woocommerce-checkout [data-brx-checkout-step], form.woocommerce-checkout [data-brx-checkout-steps-nav]',
	frontEndOnly: true,
	windowVariableCheck: ['jQuery', 'wc_checkout_params.is_checkout'],
	subscribejQueryEvents: ['init_checkout', 'updated_checkout'],
	forceReinit: true,
	run() {
		window.bricksUtils.initWooCheckoutSteps()
	},
	additionalActions: [
		function () {
			document.addEventListener('click', (event) => {
				const navButton = event.target.closest(
					'[data-brx-checkout-steps-nav] [data-brx-checkout-step-nav-item], [data-brx-checkout-steps-nav] button[data-step-id]'
				)

				if (navButton) {
					event.preventDefault()

					const form =
						navButton.closest('form.checkout, form.woocommerce-checkout') ||
						document.querySelector('form.checkout, form.woocommerce-checkout')

					if (!form || navButton.disabled || navButton.getAttribute('aria-disabled') === 'true') {
						return
					}

					window.bricksUtils.runWooCheckoutStepAction(navButton, {
						action: 'checkoutStep',
						checkoutStepMode: 'goToStep',
						checkoutStepId: navButton.dataset?.stepId,
						checkoutStepScrollIntoView: true
					})

					return
				}

				if (event.defaultPrevented) {
					return
				}

				const noticeLink = event.target.closest(
					'.woocommerce-notices-wrapper a[href^="#"], .woocommerce-NoticeGroup-checkout a[href^="#"]'
				)

				if (!noticeLink) {
					return
				}

				const form =
					noticeLink.closest('form.checkout, form.woocommerce-checkout') ||
					document.querySelector('form.checkout, form.woocommerce-checkout')
				const noticeFieldId =
					noticeLink.closest('[data-brx-notice-field-id]')?.dataset?.brxNoticeFieldId ||
					noticeLink.getAttribute('href')?.replace(/^#/, '')

				if (form && noticeFieldId) {
					event.preventDefault()
					window.bricksUtils.goToWooCheckoutField(form, noticeFieldId, { force: true })
				}
			})

			document.addEventListener('keydown', (event) => {
				const navItem = event.target.closest(
					'[data-brx-checkout-steps-nav] [data-brx-checkout-step-nav-item]'
				)

				if (
					!navItem ||
					!['Enter', ' '].includes(event.key) ||
					navItem.getAttribute('aria-disabled') === 'true'
				) {
					return
				}

				event.preventDefault()
				navItem.click()
			})

			document.addEventListener('keydown', (event) => {
				window.bricksUtils.getWooCheckoutStepsManager().handleEnterKey(event)
			})

			jQuery(document.body).on('checkout_error', function () {
				const form = document.querySelector('form.checkout, form.woocommerce-checkout')

				if (!form) {
					return
				}

				setTimeout(() => {
					bricksSyncWooNoticeFieldErrors(document)
					window.bricksUtils.handleWooCheckoutError(form)
				}, 40)
			})
		}
	]
})

// Builder/init hook used by the checkout step elements.
function bricksWooInitCheckoutSteps() {
	bricksWooCheckoutStepsFn.run()
}

/**
 * Mini cart: Refresh cart fragments.
 *
 * This helper also supports the recovery path for failed cart mutations. In that case it is
 * queued behind any earlier mutations and asks Woo to render mounted Dynamic Fragments in the
 * same authoritative response.
 *
 * @param {Object} options Refresh behavior.
 * @param {boolean} options.queue Serialize this request with Bricks cart mutations.
 * @param {boolean} options.refreshDynamicFragments Include mounted Dynamic Fragments.
 * @param {boolean} options.skipCheckoutUpdate Do not schedule a dependent checkout refresh.
 * @param {boolean} options.skipDynamicRefresh Do not schedule another Dynamic Fragment request.
 * @param {boolean} options.triggerEvent Emit Woo's `wc_fragments_refreshed` event.
 * @param {string} options.sourceEvent Woo event represented by the refresh.
 * @param {WeakMap<Element, Object[]>} options.scrollStates Positions captured before the refresh.
 * @param {Function} options.onSuccess Successful refresh callback.
 * @param {Function} options.onError Failed or incomplete refresh callback.
 * @returns {Object|undefined} A jqXHR for immediate requests; queued requests start later.
 */
function bricksWooRefreshCartFragments(options = {}) {
	options = options || {}

	const wcAjaxUrl =
		window.wc_cart_params?.wc_ajax_url ||
		window.wc_add_to_cart_params?.wc_ajax_url ||
		window.wc_checkout_params?.wc_ajax_url ||
		window.woocommerce_params?.wc_ajax_url ||
		''

	if (!wcAjaxUrl) {
		if (typeof options.onError === 'function') {
			options.onError()
		}

		return
	}

	// TODO: PayPal SDK generates console error in builder when mini cart is used in header

	var url = wcAjaxUrl
	url = url.replace('%%endpoint%%', 'get_refreshed_fragments')

	// Resolve targets at enqueue time so a preceding mutation cannot replace the DOM metadata
	// before this recovery request starts.
	const dynamicFragmentTargets = options.refreshDynamicFragments
		? bricksWooGetDynamicFragmentTargets()
		: []
	const request = {
		type: 'POST',
		url,
		dataType: 'json',
		data: {
			bricks_woo_current_url: window.location.href.split('#')[0],
			bricks_woo_refresh_dynamic_fragments: dynamicFragmentTargets.length ? '1' : '',
			fragments: dynamicFragmentTargets.length ? JSON.stringify(dynamicFragmentTargets) : ''
		},
		success(data) {
			if (!data?.fragments) {
				if (typeof options.onError === 'function') {
					options.onError()
				}

				return
			}

			const { cartFragments, dynamicFragments } = bricksWooSplitCartFragments(data.fragments)
			const allDynamicFragmentsUpdated = bricksWooInstallDynamicFragments(
				dynamicFragments,
				dynamicFragmentTargets,
				options.sourceEvent || 'wc_fragments_refreshed',
				options.scrollStates
			)

			bricksWooReplaceFragments(cartFragments)

			if (options.triggerEvent !== false) {
				jQuery('body').trigger('wc_fragments_refreshed', [
					{
						skipCheckoutUpdate: !!options.skipCheckoutUpdate,
						skipDynamicRefresh: !!options.skipDynamicRefresh || allDynamicFragmentsUpdated
					}
				])
			}

			if (typeof options.onSuccess === 'function') {
				options.onSuccess(data)
			}
		},
		error() {
			if (typeof options.onError === 'function') {
				options.onError()
			}
		}
	}

	if (options.queue) {
		bricksWooQueueCartMutation(request)
		return
	}

	return jQuery.ajax(request)
}

/**
 * Preserve non-zero vertical scroll positions while a Woo Dynamic Fragment is replaced.
 *
 * The fragment root and nested layout elements are destroyed during a refresh, while scrollable
 * Offcanvas and popup containers outside the fragment survive. Stable DOM IDs let the new subtree
 * inherit positions without guessing which repeated cart-loop row corresponds to an old node.
 *
 * @since 2.4 #86cbbtgg2
 */
const bricksWooDynamicFragmentScrollState = (() => {
	const fragmentSelector = '[data-brx-woo-fragment="true"]'

	/**
	 * Capture positions that can be restored without depending on the fragment's child order.
	 *
	 * @param {Element} fragment Mounted Dynamic Fragment root.
	 * @returns {Object[]} Scroll positions and their replacement lookup data.
	 */
	function capture(fragment) {
		const positions = []
		const remember = (element, replacementId = '') => {
			if (element.scrollTop > 0) {
				positions.push({ element, replacementId, scrollTop: element.scrollTop })
			}
		}

		remember(fragment, fragment.id)

		// Structural Bricks elements retain their IDs after server rendering. DOM paths are unsafe
		// here because removing or reordering a repeated cart-loop row changes every later path.
		bricksQuerySelectorAll(fragment, '[id]').forEach((element) => {
			remember(element, element.id)
		})

		let ancestor = fragment.parentElement

		// Scroll shells such as an Offcanvas or popup can live outside the replaced fragment. Their
		// nodes survive the refresh, so object identity is more reliable than requiring a custom ID.
		while (ancestor && ancestor !== document.body && ancestor !== document.documentElement) {
			remember(ancestor)
			ancestor = ancestor.parentElement
		}

		return positions
	}

	/**
	 * Restore positions to surviving ancestors or their server-rendered replacements.
	 *
	 * @param {Element} fragment Newly installed Dynamic Fragment root.
	 * @param {Object[]} positions Previously captured scroll positions.
	 * @returns {void}
	 */
	function restore(fragment, positions) {
		positions.forEach(({ element, replacementId, scrollTop }) => {
			// Surviving ancestors use their live references. Destroyed fragment descendants must be
			// matched by stable ID; deliberately skip ambiguous descendants without an ID.
			const replacement = element.isConnected
				? element
				: replacementId === fragment.id
					? fragment
					: bricksQuerySelectorAll(fragment, '[id]').find(
							(candidate) => candidate.id === replacementId
						)

			if (replacement) {
				replacement.scrollTop = scrollTop
			}
		})
	}

	/**
	 * Snapshot every mounted fragment before a cart mutation changes its surrounding layout.
	 *
	 * The WeakMap ties each snapshot to the exact old root. A queued response therefore cannot
	 * accidentally consume state belonging to a fragment already replaced by an earlier response.
	 *
	 * @returns {WeakMap<Element, Object[]>} Positions keyed by mounted fragment roots.
	 */
	function captureMounted() {
		const states = new WeakMap()

		bricksQuerySelectorAll(document, fragmentSelector).forEach((fragment) => {
			states.set(fragment, capture(fragment))
		})

		return states
	}

	return { capture, captureMounted, restore }
})()

/**
 * Preserve Progress Bar positions while Woo Dynamic Fragments replace their DOM.
 *
 * Keeps the implementation details scoped and exposes only the operations used by the Woo flows.
 * (#86carzqa4; @since 2.4)
 */
const bricksWooProgressBarState = (() => {
	const dynamicFragmentSelector = '[data-brx-woo-fragment="true"]'
	const progressBarSelector = '.brxe-progress-bar[data-script-id]'
	const rememberedState = new Map()

	function capture(fragment) {
		const state = new Map()

		bricksQuerySelectorAll(fragment, progressBarSelector).forEach((progressBar) => {
			const scriptId = progressBar.dataset.scriptId

			if (!scriptId) {
				return
			}

			const widths = bricksQuerySelectorAll(progressBar, '.bar span').map((bar) => {
				const appliedWidth = bar.style.width || bar.dataset.bricksProgressBarWidth || ''

				if (!appliedWidth && bar.dataset.width) {
					// Closed mini carts can be measurable while their uninitialized fill remains at zero.
					return bar.dataset.width
				}

				const trackWidth = bar.parentElement?.getBoundingClientRect().width || 0

				if (trackWidth > 0) {
					const width = (bar.getBoundingClientRect().width / trackWidth) * 100

					return `${Math.min(100, Math.max(0, width))}%`
				}

				// Hidden bars have no measurable width, so retain the last applied value as a fallback.
				return appliedWidth || bar.dataset.width || ''
			})

			state.set(scriptId, widths)
		})

		return state
	}

	function restore(fragment, state) {
		let hasRestoredState = false
		const restoredBars = []

		bricksQuerySelectorAll(fragment, progressBarSelector).forEach((progressBar) => {
			const widths = state.get(progressBar.dataset.scriptId)

			if (!widths) {
				return
			}

			bricksQuerySelectorAll(progressBar, '.bar span').forEach((bar, index) => {
				if (!widths[index]) {
					return
				}

				bar.style.transition = 'none'
				bar.style.width = widths[index]
				restoredBars.push(bar)
				hasRestoredState = true
			})
		})

		if (hasRestoredState) {
			// Flush the restored width so the next width change triggers the existing CSS transition.
			fragment.getBoundingClientRect()

			restoredBars.forEach((bar) => {
				bar.style.removeProperty('transition')
			})
		}

		return hasRestoredState
	}

	return {
		prime() {
			bricksQuerySelectorAll(document, dynamicFragmentSelector).forEach((fragment) => {
				restore(fragment, capture(fragment))
			})
		},

		remember() {
			rememberedState.clear()

			bricksQuerySelectorAll(document, dynamicFragmentSelector).forEach((fragment) => {
				capture(fragment).forEach((widths, scriptId) => {
					rememberedState.set(scriptId, widths)
				})
			})
		},

		forgetRemembered() {
			rememberedState.clear()
		},

		restoreRemembered() {
			let hasRestoredState = false

			bricksQuerySelectorAll(document, dynamicFragmentSelector).forEach((fragment) => {
				hasRestoredState = restore(fragment, rememberedState) || hasRestoredState
			})

			rememberedState.clear()

			return hasRestoredState
		},

		replace(selector, value) {
			const $fragments = jQuery(selector)
			const states = $fragments.toArray().map((fragmentElement) => capture(fragmentElement))

			$fragments.replaceWith(value)

			jQuery(selector).each((index, fragmentElement) => {
				if (states[index]) {
					restore(fragmentElement, states[index])
				}
			})
		}
	}
})()

let bricksWooSkipNextDynamicCartRefresh = false

/**
 * Use a full cart-page response to update its mounted Dynamic Fragments.
 *
 * @param {string} html Full cart-page response HTML.
 * @returns {Object} Replaced fragments, targets, and whether every mounted target was found.
 */
function bricksWooReplaceDynamicFragmentsFromHTML(html) {
	const $html = jQuery(html)
	const fragments = {}
	const targets = []
	let targetCount = 0

	bricksQuerySelectorAll(document, '[data-brx-woo-fragment="true"]').forEach((fragment) => {
		if (fragment.parentElement?.closest('[data-brx-woo-fragment="true"]')) {
			return
		}

		targetCount++

		const id = fragment.dataset.brxWooFragmentId || ''
		const rootId = fragment.dataset.brxWooFragmentRootId || id
		const source = fragment.dataset.brxWooFragmentSource || ''
		const area = fragment.dataset.brxWooFragmentArea || 'content'
		const token = fragment.dataset.brxWooFragmentToken || ''

		if (!id || !source || !token) {
			return
		}

		const selector = `[data-brx-woo-fragment="true"][data-brx-woo-fragment-id="${id}"][data-brx-woo-fragment-source="${source}"][data-brx-woo-fragment-area="${area}"]`
		const $responseFragment = $html.filter(selector).add($html.find(selector)).first()

		if (!$responseFragment.length) {
			return
		}

		fragments[selector] = $responseFragment.prop('outerHTML')
		targets.push({ id, rootId, source, area, token })
	})

	if (targets.length) {
		bricksWooReplaceFragments(fragments, { preserveProgressBarState: true })
	}

	return {
		fragments,
		targets,
		allTargetsReplaced: targetCount > 0 && targets.length === targetCount
	}
}

/**
 * Replace WooCommerce fragments while retaining Dynamic Fragment visual state.
 *
 * @param {Object<string, string>} fragments Fragment selector map.
 * @param {Object} options Replacement behavior.
 * @param {boolean} options.preserveProgressBarState Retain Dynamic Fragment progress widths.
 * @param {WeakMap<Element, Object[]>} options.scrollStates Positions captured before replacement.
 * @returns {void}
 */
function bricksWooReplaceFragments(fragments, options = {}) {
	if (fragments) {
		jQuery.each(fragments, function (key, value) {
			const $fragments = jQuery(key)

			if (!$fragments.length) {
				return
			}

			// This opt-in is intentionally selector-based. Native WooCommerce and extension fragments
			// keep their established replacement behavior and cannot inherit Bricks layout state.
			const scrollStates = key.startsWith('[data-brx-woo-fragment="true"]')
				? $fragments.toArray().map(
						(fragmentElement) =>
							// Prefer the click-time snapshot taken before BlockUI changed the layout. The
							// fallback supports refreshes initiated by Woo events without a mutation hook.
							options.scrollStates?.get(fragmentElement) ||
							bricksWooDynamicFragmentScrollState.capture(fragmentElement)
					)
				: []

			if (options.preserveProgressBarState) {
				bricksWooProgressBarState.replace(key, value)
			} else {
				$fragments.replaceWith(value)
			}

			if (scrollStates.length) {
				jQuery(key).each((index, fragmentElement) => {
					if (scrollStates[index]) {
						// Restore immediately to avoid painting at the top, then repeat after layout so
						// browser scroll anchoring cannot revise the position for the new content height.
						bricksWooDynamicFragmentScrollState.restore(fragmentElement, scrollStates[index])

						requestAnimationFrame(() => {
							if (fragmentElement.isConnected) {
								bricksWooDynamicFragmentScrollState.restore(fragmentElement, scrollStates[index])
							}
						})
					}
				})
			}
		})
	}
}

/**
 * Separate Bricks Dynamic Fragments from native Woo and third-party cart fragments.
 *
 * Woo stores the fragments passed to `added_to_cart` in browser storage. Keeping Bricks-only
 * selectors out of that event prevents page-specific Dynamic Fragments from leaking to other URLs.
 *
 * @param {Object<string, string>} fragments Combined fragment response.
 * @returns {{cartFragments: Object<string, string>, dynamicFragments: Object<string, string>}}
 */
function bricksWooSplitCartFragments(fragments = {}) {
	const cartFragments = {}
	const dynamicFragments = {}

	if (!fragments || typeof fragments !== 'object') {
		return { cartFragments, dynamicFragments }
	}

	Object.entries(fragments).forEach(([selector, html]) => {
		if (selector.startsWith('[data-brx-woo-fragment="true"]')) {
			dynamicFragments[selector] = html
			return
		}

		cartFragments[selector] = html
	})

	return { cartFragments, dynamicFragments }
}

/**
 * Install a complete set of Dynamic Fragments returned with a Woo cart mutation.
 *
 * @param {Object<string, string>} fragments Dynamic Fragment selector map.
 * @param {Object[]} targets Mounted Dynamic Fragment targets requested by the client.
 * @param {string} sourceEvent Woo event represented by the response.
 * @param {WeakMap<Element, Object[]>} scrollStates Positions captured when a mutation began.
 * @returns {boolean} Whether every requested Dynamic Fragment was installed.
 */
function bricksWooInstallDynamicFragments(fragments, targets, sourceEvent, scrollStates = null) {
	const allFragmentsReturned =
		targets.length > 0 && Object.keys(fragments).length === targets.length

	// Avoid installing a partial cart snapshot. Returning false lets the regular Woo event
	// schedule a complete Dynamic Fragment refresh as the fallback. It also keeps the captured
	// scroll state available for that authoritative replacement instead of consuming it piecemeal.
	if (!allFragmentsReturned) {
		return false
	}

	bricksWooReplaceFragments(fragments, {
		preserveProgressBarState: true,
		scrollStates
	})

	if (typeof bricksRunAllFunctions === 'function') {
		bricksRunAllFunctions()
	}

	document.body.dispatchEvent(
		new CustomEvent('bricks/woocommerce/fragments/refreshed', {
			detail: {
				fragments,
				targets,
				sourceEvent
			}
		})
	)

	return true
}

/**
 * Serialize Bricks-owned cart mutations.
 *
 * Woo's session handler persists the complete session value. Letting cart mutations overlap can
 * therefore make a later request overwrite changes made by an earlier request. WooCommerce's
 * native add-to-cart script protects against this with its own queue; Bricks custom endpoints need
 * the same ordering guarantee.
 */
const bricksWooCartMutationQueue = (() => {
	const requests = []
	let running = false

	const runNext = () => {
		if (running || !requests.length) {
			return
		}

		running = true

		const request = { ...requests[0] }
		const originalComplete = request.complete

		// jQuery's complete callback runs for both success and failure. Advancing from `finally`
		// guarantees that a consumer callback cannot leave the cart queue permanently blocked.
		request.complete = function (...args) {
			try {
				if (typeof originalComplete === 'function') {
					originalComplete.apply(this, args)
				}
			} finally {
				requests.shift()
				running = false
				runNext()
			}
		}

		jQuery.ajax(request)
	}

	return {
		add(request) {
			requests.push(request)
			runNext()
		}
	}
})()

/**
 * Queue one Bricks-owned cart mutation.
 *
 * @param {Object} request jQuery AJAX request settings.
 */
function bricksWooQueueCartMutation(request) {
	bricksWooCartMutationQueue.add(request)
}

/**
 * Get the mounted, top-level Bricks regions that depend on Woo cart dynamic data.
 *
 * @returns {Object[]}
 */
function bricksWooGetDynamicFragmentTargets() {
	const targets = new Map()

	document.querySelectorAll('[data-brx-woo-fragment="true"]').forEach((node) => {
		if (node.parentElement?.closest('[data-brx-woo-fragment="true"]')) {
			return
		}

		const id = node.dataset?.brxWooFragmentId || ''
		const rootId = node.dataset?.brxWooFragmentRootId || id
		const source = node.dataset?.brxWooFragmentSource || ''
		const area = node.dataset?.brxWooFragmentArea || 'content'
		const token = node.dataset?.brxWooFragmentToken || ''

		if (!id || !source || !token) {
			return
		}

		const key = `${source}:${area}:${id}`

		targets.set(key, {
			id,
			rootId,
			source,
			area,
			token
		})
	})

	return Array.from(targets.values())
}

/**
 * Refresh opt-in Bricks regions that depend on Woo cart dynamic data.
 *
 * @since 2.4
 */
function bricksWooDynamicFragments() {
	if (!bricksIsFrontend || typeof jQuery === 'undefined') {
		return
	}

	if (bricksWooDynamicFragments.bound) {
		return
	}

	const endpoint =
		window.wc_cart_params?.wc_ajax_url ||
		window.wc_add_to_cart_params?.wc_ajax_url ||
		window.wc_checkout_params?.wc_ajax_url ||
		window.woocommerce_params?.wc_ajax_url ||
		''

	if (!endpoint) {
		return
	}

	bricksWooDynamicFragments.bound = true

	const refreshUrl = endpoint.toString().replace('%%endpoint%%', 'bricks_get_woo_dynamic_fragments')
	let refreshTimer = null
	let refreshXhr = null
	let eventGeneration = 0
	let inFlightGeneration = 0
	let pendingGeneration = 0
	let lastCartDomRefreshAt = 0
	let pendingSourceEvent = ''

	const cartUiSelector =
		'.woocommerce-cart-form, .brxe-woocommerce-cart-v2, .brxe-woocommerce-cart-v2-state-cart, .brxe-woocommerce-cart-v2-state-empty, body.woocommerce-cart'
	const checkoutUiSelector =
		'form.checkout, form.woocommerce-checkout, .woocommerce-checkout-review-order, #bricks-woo-checkout-order-summary'

	const isCheckoutContext = () =>
		!!window.wc_checkout_params?.is_checkout && !!document.querySelector(checkoutUiSelector)
	const isCartContext = () => !!document.querySelector(cartUiSelector) && !isCheckoutContext()
	const getCartHashCookie = () => {
		// Woo intentionally exposes this cookie to its own cart-fragments script, so it is the
		// browser-side authority for comparing cached server markup with the active cart session.
		const cookie = document.cookie
			.split('; ')
			.find((row) => row.startsWith('woocommerce_cart_hash='))

		if (!cookie) {
			return ''
		}

		try {
			return decodeURIComponent(cookie.slice(cookie.indexOf('=') + 1))
		} catch (error) {
			return cookie.slice(cookie.indexOf('=') + 1)
		}
	}
	const hasStaleInitialState = () => {
		const cartHash = getCartHashCookie()

		// Full-page caches can serve an empty or previous cart subtree. A matching hash avoids an
		// unconditional AJAX request on normal uncached page loads.
		return bricksQuerySelectorAll(document, '[data-brx-woo-fragment="true"]').some(
			(fragment) => (fragment.dataset.brxWooFragmentCartHash || '') !== cartHash
		)
	}

	const runRefresh = (generation = eventGeneration) => {
		// Hand ownership from the timer to the AJAX request below within the same callback.
		bricksWooCartContentsChanges.release('dynamicFragments')
		refreshTimer = null
		const sourceEvent = pendingSourceEvent

		pendingSourceEvent = ''

		const targets = bricksWooGetDynamicFragmentTargets()

		if (!targets.length) {
			return
		}

		if (refreshXhr) {
			pendingGeneration = Math.max(pendingGeneration, generation)
			pendingSourceEvent = pendingSourceEvent || sourceEvent

			return
		}

		inFlightGeneration = generation

		refreshXhr = jQuery.ajax({
			type: 'POST',
			url: refreshUrl,
			dataType: 'json',
			data: {
				postId: window.bricksData?.postId || 0,
				// Preserve page conditions even when Referrer-Policy hides the URL. Hashes are client-only. (@since 2.4 #86cbgg7yy)
				bricks_woo_current_url: window.location.href.split('#')[0],
				fragments: JSON.stringify(targets)
			},
			success(response) {
				if (generation !== eventGeneration) {
					return
				}

				const fragments = response?.data?.fragments || response?.fragments || {}

				if (fragments && Object.keys(fragments).length) {
					// Retain visual state instead of restarting it when the Dynamic Fragment DOM changes.
					// (#86carzqa4, #86cbbtgg2; @since 2.4)
					bricksWooReplaceFragments(fragments, { preserveProgressBarState: true })

					if (typeof bricksRunAllFunctions === 'function') {
						bricksRunAllFunctions()
					}

					document.body.dispatchEvent(
						new CustomEvent('bricks/woocommerce/fragments/refreshed', {
							detail: {
								fragments,
								targets,
								sourceEvent
							}
						})
					)
				}
			},
			complete() {
				const currentGeneration = inFlightGeneration

				refreshXhr = null
				inFlightGeneration = 0

				if (pendingGeneration > currentGeneration) {
					pendingGeneration = 0
					scheduleRefresh()
				}
			}
		})
	}

	// Coalesce paired Woo events without adding a noticeable wait before the fragment request. (#86carzqa4; @since 2.4)
	const scheduleRefresh = (delay = 20, eventType = '') => {
		bricksWooCartContentsChanges.hold('dynamicFragments')
		eventGeneration++

		if (eventType) {
			pendingSourceEvent = eventType
		}

		if (refreshTimer) {
			clearTimeout(refreshTimer)
		}

		const generation = eventGeneration

		refreshTimer = setTimeout(() => runRefresh(generation), delay)
	}

	const handleWooFragmentEvent = (event, ...eventArgs) => {
		const eventType = event?.type || ''
		const eventOptions = eventArgs[eventArgs.length - 1] || {}

		if (eventOptions.skipDynamicRefresh) {
			return
		}

		if (eventType === 'adding_to_cart') {
			bricksWooProgressBarState.prime()
			return
		}

		if (eventType === 'wc_fragments_loaded') {
			// Woo emits `loaded` instead of `refreshed` when it restores native fragments from
			// sessionStorage. Recheck the hash because the Dynamic Fragment is not stored there.
			if (hasStaleInitialState()) {
				scheduleRefresh(20, eventType)
			}

			return
		}

		if (isCheckoutContext()) {
			if (eventType === 'updated_checkout') {
				scheduleRefresh(20, eventType)
			}

			return
		}

		if (isCartContext()) {
			if (eventType === 'updated_wc_div') {
				lastCartDomRefreshAt = Date.now()

				// Native Woo cart removal replaces cart totals outside Bricks' cart updater. Restore
				// the captured fill before reinitializing so the new value animates from the live width.
				// (#86cb1pxd8; @since 2.4)
				const hasRestoredProgressBars = bricksWooProgressBarState.restoreRemembered()

				if (hasRestoredProgressBars && typeof bricksProgressBar === 'function') {
					bricksProgressBar()
				}

				if (bricksWooSkipNextDynamicCartRefresh) {
					bricksWooSkipNextDynamicCartRefresh = false
					bricksWooCartContentsChanges.release('dynamicFragments')
					eventGeneration++
					pendingGeneration = 0
					pendingSourceEvent = ''

					if (refreshTimer) {
						clearTimeout(refreshTimer)
						refreshTimer = null
					}

					return
				}

				scheduleRefresh(20, eventType)
			} else if (eventType === 'wc_cart_emptied') {
				scheduleRefresh(20, eventType)
			} else if (eventType === 'updated_cart_totals') {
				if (refreshTimer || refreshXhr || Date.now() - lastCartDomRefreshAt < 1000) {
					return
				}

				// Totals-only updates need a fallback, but a later full-cart update can replace
				// or cancel it. Keep this grace period separate from normal 20 ms event batching
				// to avoid starting an unnecessary fragment request before updated_wc_div arrives.
				// Introduced in 36e9216df; retained when 27fcee4e83 reduced normal refresh delays.
				scheduleRefresh(500, eventType)
			} else if (eventType === 'item_removed_from_classic_cart') {
				// Woo fires this from its complete callback even when the request fails. Discard any
				// state that was not consumed by a successful updated_wc_div event. (#86cb1pxd8)
				bricksWooProgressBarState.forgetRemembered()
			}

			return
		}

		if (
			[
				'added_to_cart',
				'removed_from_cart',
				'item_removed_from_classic_cart',
				'wc_fragments_refreshed',
				'wc_cart_emptied',
				'applied_coupon',
				'removed_coupon',
				'applied_coupon_in_checkout',
				'removed_coupon_in_checkout'
			].includes(eventType)
		) {
			scheduleRefresh(20, eventType)
		}
	}

	jQuery(document.body).on(
		'adding_to_cart added_to_cart removed_from_cart item_removed_from_classic_cart wc_cart_emptied updated_cart_totals updated_wc_div updated_checkout wc_fragments_loaded wc_fragments_refreshed applied_coupon removed_coupon applied_coupon_in_checkout removed_coupon_in_checkout',
		handleWooFragmentEvent
	)

	// Hydrate only stale cached markup. The signed target token is cache-stable, so this remains
	// valid even when the cached page outlives WordPress' normal nonce window.
	if (hasStaleInitialState()) {
		scheduleRefresh(0, 'initial_cart_state')
	}
}

/**
 * Hide mini cart on click outside of mini cart details
 *
 * @since 1.3.1
 */
function bricksWooMiniCartHideDetailsClickOutside() {
	// @since 1.7.1 - Close mini cart detail function
	const closeMiniCartDetail = (miniCartDetail) => {
		// Ensure this is a mini cart detail
		if (!miniCartDetail.classList.contains('cart-detail')) {
			return
		}

		miniCartDetail.classList.remove('active')
		const miniCartEl = miniCartDetail.closest('.brxe-woocommerce-mini-cart')

		if (miniCartEl) {
			miniCartEl.classList.toggle('show-cart-details')
		}
	}

	const miniCartDetails = bricksQuerySelectorAll(document, '.cart-detail')

	if (miniCartDetails) {
		miniCartDetails.forEach(function (element) {
			// skip click outside event if set by user (@since 1.9.4)
			if (element.dataset?.skipClickOutside) {
				return
			}

			document.addEventListener('click', function (event) {
				if (
					!event.target.closest('.mini-cart-link') &&
					element.classList.contains('active') &&
					!event.target.closest('.cart-detail')
				) {
					closeMiniCartDetail(element)
				}
			})
		})
	}

	const miniCartCloseButtons = bricksQuerySelectorAll(
		document,
		'.cart-detail .bricks-mini-cart-close'
	)

	if (miniCartCloseButtons) {
		miniCartCloseButtons.forEach(function (element) {
			element.addEventListener('click', function (event) {
				event.preventDefault()

				const miniCartDetail = event.target.closest('.cart-detail')

				if (miniCartDetail) {
					closeMiniCartDetail(miniCartDetail)
				}
			})
		})
	}
}

/**
 * Used to open/close mini cart (and account modal)
 */
function bricksWooMiniModalsToggle(event) {
	event.preventDefault()

	var target = event.currentTarget
	var modalString = target.getAttribute('data-toggle-target')

	if (!modalString) {
		return
	}

	// Remove class from other modals
	var toggles = document.querySelectorAll('.bricks-woo-toggle')

	toggles.forEach(function (toggle) {
		var thisModal = toggle.getAttribute('data-toggle-target')

		if (thisModal !== modalString) {
			var elModal = toggle.querySelector(thisModal)

			if (elModal !== null && elModal.classList.contains('active')) {
				elModal.classList.remove('active')

				var miniCartEl = toggle.closest('.brxe-woocommerce-mini-cart')

				if (miniCartEl) {
					miniCartEl.classList.remove('show-cart-details')
				}
			}
		}
	})

	// Toggle main modal
	var modalEl = document.querySelector(modalString)

	if (modalEl) {
		modalEl.classList.toggle('active')

		var miniCartEl = modalEl.closest('.brxe-woocommerce-mini-cart')

		if (miniCartEl) {
			miniCartEl.classList.toggle('show-cart-details')
		}
	}
}

/**
 * Re-init WooCommerce product gallery in builder
 */
function bricksWooProductGallery() {
	if (bricksIsFrontend || typeof jQuery(this).wc_product_gallery === 'undefined') {
		return
	}

	jQuery('.woocommerce-product-gallery').each(function () {
		jQuery(this).trigger('wc-product-gallery-before-init', [this, window.wc_single_product_params])
		jQuery(this).wc_product_gallery(window.wc_single_product_params)
		jQuery(this).trigger('wc-product-gallery-after-init', [this, window.wc_single_product_params])
	})
}

/**
 * Re-init WooCommerce product gallery if it's fetched via AJAX
 * No need to trigger on document ready, as it's already init by WooCommerce.
 *
 * @since 1.10.2
 */
const bricksWooProductGalleryFn = new BricksFunction({
	parentNode: document,
	selector: '.woocommerce-product-gallery',
	frontEndOnly: true,
	eachElement: (gallery) => {
		if (typeof jQuery(window).wc_product_gallery === 'undefined') {
			return
		}

		// AJAX events scan the whole document, including galleries WooCommerce already initialized on page load. (#86c5v3x78; @since 2.3.10)
		if (jQuery(gallery).data('product_gallery')) {
			return
		}

		jQuery(gallery).trigger('wc-product-gallery-before-init', [
			gallery,
			window.wc_single_product_params
		])
		jQuery(gallery).wc_product_gallery(window.wc_single_product_params)
		jQuery(gallery).trigger('wc-product-gallery-after-init', [
			gallery,
			window.wc_single_product_params
		])
	}
})

/**
 * Re-init WooCommerce variation form if Add To Cart button is fetched via AJAX (Product Quick View)
 * No need to trigger on document ready, as it's already init by WooCommerce.
 *
 * @since 1.10.2
 */
const bricksWooVariationFormFn = new BricksFunction({
	parentNode: document,
	selector: '.product form.variations_form',
	frontEndOnly: true,
	eachElement: (form) => {
		if (typeof jQuery(window).wc_variation_form === 'undefined') {
			return
		}

		jQuery(form).wc_variation_form()
	}
})

/**
 * Re-init WooCommerce product tabs, rating if fetched via AJAX
 * No need to trigger on document ready, as it's already init by WooCommerce.
 *
 * @since 1.10.2
 */
const bricksWooTabsRatingFn = new BricksFunction({
	parentNode: document,
	selector: '.wc-tabs-wrapper, .woocommerce-tabs, #rating',
	frontEndOnly: true,
	eachElement: (element) => {
		// Prevent duplicate Woo stars markup on already initialized rating fields. (#86c2b85ud; @since 2.3.2)
		if (element.id === 'rating' && jQuery(element).siblings('p.stars').length) {
			// This is a select rating hidden field by WooCommerce, and we already have the stars markup, so we can skip initialization to prevent duplicate stars.
			return
		}

		jQuery(element).trigger('init')
	}
})

/**
 * Initialize replacement Product Tabs markup in the Builder using WooCommerce's handlers.
 *
 * @since 2.4.4
 */
function bricksWooProductTabs(node) {
	if (bricksIsFrontend) {
		return
	}

	jQuery(node || '.brxe-product-tabs')
		.find('.wc-tabs-wrapper, .woocommerce-tabs, #rating')
		.each(function () {
			// WooCommerce's rating init always inserts stars, including on an already initialized field.
			if (this.id === 'rating' && jQuery(this).siblings('p.stars').length) {
				return
			}

			jQuery(this).trigger('init')
		})
}

/**
 * Re-init WooCommerce product reviews element star rating in builder
 *
 * @see /woocommerce/assets/js/frontend/single-product.js
 *
 * @since 1.9.2
 */
function bricksWooStarRating() {
	if (bricksIsFrontend) {
		return
	}

	jQuery('.brxe-product-reviews #rating').each(function () {
		// Hide the default select field
		jQuery(this).hide()

		// Add stars if not already added
		if (jQuery(this).closest('.brxe-product-reviews').find('p.stars').length === 0) {
			jQuery(this).before(
				'<p class="stars">\
						<span>\
							<a class="star-1" href="#">1</a>\
							<a class="star-2" href="#">2</a>\
							<a class="star-3" href="#">3</a>\
							<a class="star-4" href="#">4</a>\
							<a class="star-5" href="#">5</a>\
						</span>\
					</p>'
			)
		}
	})
}

/**
 * Product reviews: Manage star rating fill states
 *
 * @since 2.1
 */
const bricksWooStarRatingManageFillFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-product-reviews',
	eachElement: (reviewsContainer) => {
		const tryFindStars = (attempt = 1) => {
			const $starsContainer = jQuery(reviewsContainer).find('form .stars')

			if ($starsContainer.length === 0 && attempt < 5) {
				setTimeout(() => tryFindStars(attempt + 1), 500)
				return
			}

			$starsContainer.each(function () {
				const $stars = jQuery(this)
				const stars = $stars.find('a')

				const updateFilledStars = (activeIndex) => {
					stars.each(function (index) {
						if (index <= activeIndex) {
							jQuery(this).addClass('bricks-star-filled')
						} else {
							jQuery(this).removeClass('bricks-star-filled')
						}
					})
				}

				stars.on('click', function () {
					updateFilledStars(stars.index(this))
				})

				// Initialize
				const activeIndex = stars.index(stars.filter('.active'))
				if (activeIndex >= 0) {
					updateFilledStars(activeIndex)
				}
			})
		}

		// Start trying to find stars
		tryFindStars()
	}
})

/**
 * Product reviews: Manage star rating fill states
 *
 * @since 2.1
 */
function bricksWooStarRatingManageFill() {
	bricksWooStarRatingManageFillFn.run()
}

/**
 * WooCommerce product gallery: Thumbnail slider
 *
 * @since 1.9
 * @since 2.4.2 Rebuild thumbnails when WooCommerce replaces a variation gallery.
 */
function bricksWooProductGalleryEnhance() {
	// Return: Not the single product page or flexslider is not loaded
	if (
		typeof window.wc_single_product_params == 'undefined' ||
		typeof jQuery.fn.flexslider == 'undefined'
	) {
		return
	}

	const staticSideThumbnailGallerySelector =
		'.brxe-product-gallery:not(.thumbnail-slider)[data-pos="left"] > .woocommerce-product-gallery, .brxe-product-gallery:not(.thumbnail-slider)[data-pos="right"] > .woocommerce-product-gallery'

	/**
	 * Stabilize static side-thumbnail galleries in Firefox.
	 *
	 * FlexSlider's Firefox branch measures the outer gallery. In Bricks' side-by-side layout that
	 * includes the thumbnail navigation, creating a width feedback loop on variation changes
	 * (#86cbdkxe2).
	 *
	 * @since 2.3.13
	 *
	 * @param {HTMLElement} gallery Main WooCommerce gallery element.
	 */
	const stabilizeFirefoxSideThumbnailGallery = (gallery) => {
		if (!gallery?.matches(staticSideThumbnailGallerySelector)) {
			return
		}

		const flexData = jQuery(gallery).data('flexslider')

		if (!flexData?.isFirefox) {
			return
		}

		// FlexSlider otherwise measures the outer gallery instead of its viewport on Firefox.
		// https://github.com/woocommerce/FlexSlider/blob/master/jquery.flexslider.js#L914-L922
		flexData.isFirefox = false

		requestAnimationFrame(() => {
			if (flexData.animating || !flexData.viewport || !flexData.newSlides) {
				return
			}

			flexData.doMath()
			flexData.setProps(flexData.computedW, 'setTotal')
			flexData.newSlides.width(flexData.computedW)
		})
	}

	// A variation swaps the main gallery node but leaves Bricks' thumbnail slider in place.
	// Keep its original slides and product class on the surviving nodes. (#86cbj9ut2; @since 2.4.2)
	const thumbnailGalleryStates = new WeakMap()
	const galleryProductClasses = new WeakMap()
	const thumbnailRequests = new WeakMap()

	// Resolve custom sizes after WooCommerce selects a variation, including AJAX-loaded ones.
	// A per-gallery token prevents a late response from changing a newer selection. (#86cbj9ut2; @since 2.4.2)
	jQuery(document.body).on('found_variation reset_data', function (event, variation) {
		const form = event.target
		const request = {}
		const productId = Number(form.getAttribute('data-product_id'))
		document.querySelectorAll(`.bricks-product-gallery-for-${productId}`).forEach((gallery) => {
			thumbnailRequests.set(gallery, request)
			if (!variation?.gallery_images_html || !variation?.variation_id) {
				return
			}

			const element = gallery.closest('.brxe-product-gallery')
			const size = element?.getAttribute('data-variation-thumbnail-size')
			const url = element?.getAttribute('data-variation-thumbnail-url')
			if (!size || !url) {
				return
			}

			jQuery.post(url, { variation_id: variation.variation_id, size }).done((response) => {
				// A slow response must not overwrite a newer variation or a reset gallery.
				if (
					!response.success ||
					!gallery.isConnected ||
					thumbnailRequests.get(gallery) !== request
				) {
					return
				}

				// Cache the URL map for the next WooCommerce replacement; the current gallery has
				// already initialized, so update its slide and thumbnail sources directly. (#86cbj9ut2; @since 2.4.2)
				const thumbnails = response.data
				const sizes = jQuery(element).data('variation-thumbnail-sizes') || {}
				Object.assign(sizes, thumbnails)
				jQuery(element).data('variation-thumbnail-sizes', sizes)

				const slides = gallery.querySelectorAll(
					'.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image:not(.clone)'
				)
				const nativeThumbnails = gallery.querySelectorAll('.flex-control-thumbs li img')
				const sliderThumbnails = element.querySelectorAll(
					'.brx-thumbnail-slider-wrapper > :not(.clone) img'
				)

				slides.forEach((slide, index) => {
					const thumbnail = thumbnails[slide.getAttribute('data-thumb')]
					if (!thumbnail) {
						return
					}
					;['src', 'srcset', 'sizes'].forEach((attribute) => {
						slide.setAttribute(
							attribute === 'src' ? 'data-thumb' : `data-thumb-${attribute}`,
							thumbnail[attribute]
						)
						;[nativeThumbnails[index], sliderThumbnails[index]].forEach((image) => {
							if (image) {
								image.setAttribute(attribute, thumbnail[attribute])
								image.removeAttribute(`data-${attribute}`)
								image.classList.remove('bricks-lazy-hidden')
							}
						})
					})
				})
			})
		})
	})

	// WooCommerce's replacement markup omits the Bricks product class used by variation handlers.
	// Preserve it on the surviving Product Gallery element before the old node is removed. (#86cbj9ut2; @since 2.4.2)
	jQuery(document.body).on(
		'wc-product-gallery-before-destroy',
		'.brxe-product-gallery > .woocommerce-product-gallery',
		function () {
			const productClass = Array.from(this.classList).find((className) =>
				/^bricks-product-gallery-for-\d+$/.test(className)
			)

			if (productClass) {
				galleryProductClasses.set(this.parentElement, productClass)
			}
		}
	)

	jQuery('.brx-product-gallery-thumbnail-slider').each(function () {
		thumbnailGalleryStates.set(this, {
			gallery: jQuery(this).siblings('.woocommerce-product-gallery')[0],
			slides: jQuery(this).find('.brx-thumbnail-slider-wrapper').children().clone()
		})
	})

	// Delegate lifecycle handlers because WooCommerce replaces the gallery node for each variation.
	// The thumbnail slider and its FlexSlider instance remain in place. (#86cbj9ut2; @since 2.4.2)
	jQuery(document.body).on(
		'wc-product-gallery-before-init',
		'.woocommerce-product-gallery',
		function (event, gallery, wc_single_product_params) {
			// Variation/reset HTML is rendered outside this element's PHP image-size filters.
			// Apply its WordPress-resolved sources before either thumbnail navigation is built.
			const thumbnailSizes = jQuery(this)
				.parent('.brxe-product-gallery')
				.data('variation-thumbnail-sizes')
			if (thumbnailSizes) {
				jQuery(this)
					.find('.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image')
					.each(function () {
						const thumbnail = thumbnailSizes[this.getAttribute('data-thumb')]
						if (thumbnail) {
							this.setAttribute('data-thumb', thumbnail.src)
							this.setAttribute('data-thumb-srcset', thumbnail.srcset)
							this.setAttribute('data-thumb-sizes', thumbnail.sizes)
						}
					})

				// Custom thumbnail sizes make WooCommerce use its fallback image-update path.
				// Resolve lazy sources now so a direct switch cannot cache SVG placeholders as originals.
				jQuery(this)
					.find('.woocommerce-product-gallery__wrapper img.bricks-lazy-hidden')
					.each(function () {
						;['src', 'srcset', 'sizes'].forEach((attribute) => {
							const value = this.getAttribute(`data-${attribute}`)
							if (value) {
								this.setAttribute(attribute, value)
								this.removeAttribute(`data-${attribute}`)
							}
						})
						this.classList.remove('bricks-lazy-hidden')
					})
			}

			// Restore the class before WooCommerce initializes the replacement gallery so later
			// variation and reset handlers can still find this product's gallery.
			const productClass = galleryProductClasses.get(this.parentElement)
			if (productClass) {
				this.classList.add(productClass)
			}

			var bricksThumbnailSlider = jQuery(this).siblings('.brx-product-gallery-thumbnail-slider')

			// Only set sync if thumbnail slider exists
			if (!bricksThumbnailSlider.length) {
				return
			}

			let state = thumbnailGalleryStates.get(bricksThumbnailSlider[0])

			// AJAX-inserted Product Galleries miss the initial scan; snapshot their parent slides now.
			if (!state) {
				state = {
					gallery: this,
					slides: bricksThumbnailSlider.find('.brx-thumbnail-slider-wrapper').children().clone()
				}
				thumbnailGalleryStates.set(bricksThumbnailSlider[0], state)
			}

			const flexData = bricksThumbnailSlider.data('flexslider')
			const $slides = jQuery(this).find(
				'.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image'
			)
			// Match WooCommerce's one-image behavior, including a parent with no gallery and a
			// variation without its own gallery. Show before rebuilding so FlexSlider can measure it.
			bricksThumbnailSlider.toggle($slides.length > 1)

			if (state && state.gallery !== this && flexData && $slides.length) {
				// Reuse the existing instance and its native thumbnail navigation handlers. Reinitializing
				// FlexSlider would leave its window listeners and pending callbacks attached.
				const $slideTemplate = flexData.slides.first().clone(true, false)
				flexData.container.stop(true, true)
				clearTimeout(flexData.ensureAnimationEnd)
				flexData.animating = false
				flexData.currentSlide = flexData.currentItem = flexData.animatingTo = 0

				while (flexData.count > 1) {
					flexData.removeSlide(flexData.count - 1)
				}

				$slides.each(function (index) {
					const src = jQuery(this).find('img').attr('data-large_image')
					const $original = state.slides.filter(function () {
						return jQuery(this).find('img').attr('data-large_image') === src
					})
					const $content = ($original.length ? $original.first() : jQuery(this)).clone()

					// Keep the configured image size for parent images; new variation images use the
					// thumbnail supplied by WooCommerce instead of downloading the full gallery image.
					if (!$original.length && this.getAttribute('data-thumb')) {
						$content
							.find('img')
							.attr('src', this.getAttribute('data-thumb'))
							.attr('srcset', this.getAttribute('data-thumb-srcset') || '')
							.attr('sizes', this.getAttribute('data-thumb-sizes') || '')
							.removeAttr('data-src data-srcset data-sizes width height')
							.removeClass('bricks-lazy-hidden')
					}

					const $slide = index === 0 ? flexData.slides.first() : $slideTemplate.clone(true, false)
					$slide.empty().append($content.children())
					if (index > 0) {
						flexData.addSlide($slide)
					}
				})

				flexData.slides.removeClass('flex-active-slide').first().addClass('flex-active-slide')
				flexData.setProps(0, 'setTouch', 0)
				state.gallery = this
			}
			wc_single_product_params.flexslider.sync = bricksThumbnailSlider
			wc_single_product_params.flexslider.isBricksThumbnailSync = true
		}
	)

	jQuery(document.body).on(
		'wc-product-gallery-after-init',
		'.woocommerce-product-gallery',
		function (event, gallery, wc_single_product_params) {
			if (
				!wc_single_product_params.flexslider.isBricksThumbnailSync ||
				!wc_single_product_params.flexslider.sync
			) {
				return
			}
			// WooCommerce shares these FlexSlider options across galleries; do not leak this sync target.
			delete wc_single_product_params.flexslider.sync
			delete wc_single_product_params.flexslider.isBricksThumbnailSync
		}
	)

	jQuery('.woocommerce-product-gallery').each(function () {
		stabilizeFirefoxSideThumbnailGallery(this)
	})

	// Listen to wc-product-gallery-after-init event
	jQuery(document.body).on('wc-product-gallery-after-init', function (event) {
		stabilizeFirefoxSideThumbnailGallery(event.target)

		jQuery('.brx-product-gallery-thumbnail-slider').each(function () {
			let settings = jQuery(this).data('thumbnail-settings')
			if (settings) {
				jQuery(this).flexslider(settings)
				bricksWooProductGallerySetA11yLabels(this)
				// Set opacity to 1 after flexslider is loaded
				jQuery(this).css('opacity', 1)
			}

			// WooCommerce skips FlexSlider for one image, so hide our separate slider after it
			// initializes. A later variation replacement can reveal the same instance. (#86cbj9ut2; @since 2.4.2)
			const gallery = jQuery(this).siblings('.woocommerce-product-gallery')
			const slideCount = gallery.find(
				'.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image:not(.clone)'
			).length
			jQuery(this).toggle(slideCount > 1)
		})

		// Variation/reset markup can contain lazy main images even without a thumbnail slider.
		// Initialize after both galleries so new images and cached thumbnails load at their supplied sizes.
		bricksLazyLoad()
	})

	// This is to solve that sometimes the first image does not auto-navigate to the first slide when variation is changed
	jQuery(document.body).on('woocommerce_gallery_init_zoom', function (event) {
		jQuery('.brx-product-gallery-thumbnail-slider').each(function () {
			let flexData = jQuery(this).data('flexslider')
			if (flexData) {
				if (flexData.currentItem === 0 && flexData.currentSlide !== 0) {
					jQuery(this).flexslider(0)
				}
			}
		})
	})

	/**
	 * Update the main image on variation changes.
	 *
	 * WooCommerce normally matches its generated thumbnail URL. A custom Bricks thumbnail image
	 * size changes that URL, so dedicated slides must instead be matched by their full-size URL.
	 *
	 * @since 1.10.2
	 * @since 2.3.13 Match dedicated variation slides independently of the thumbnail image size.
	 */

	// List of attributes that we can update [originalAttribute, variantAttribute]
	const attributeList = [
		['width', 'thumb_src_w'],
		['height', 'thumb_src_h'],
		['src', 'thumb_src'],
		['alt', 'alt'],
		['title', 'title'],
		['data-caption', 'caption'],
		['data-large_image', 'full_src'],
		['data-large_image_width', 'full_src_w'],
		['data-large_image_height', 'full_src_h'],
		['sizes', 'sizes'],
		['srcset', 'srcset']
	]

	const mainSliderAttributeList = [
		['width', 'src_w'],
		['height', 'src_h'],
		['src', 'full_src'],
		['data-src', 'full_src'],
		['alt', 'alt'],
		['title', 'title'],
		['data-caption', 'caption'],
		['data-large_image', 'full_src'],
		['data-large_image_width', 'full_src_w'],
		['data-large_image_height', 'full_src_h'],
		['sizes', 'sizes'],
		['srcset', 'srcset']
	]

	// WooCommerce resets the gallery about 20ms after found_variation. Keep its intended destination
	// per gallery so that reset can be corrected in the same event turn without storing state in the DOM.
	const pendingVariationImageSlides = new WeakMap()

	/**
	 * Restore an attribute saved by WooCommerce's wc_set_variation_attr helper.
	 *
	 * @since 2.3.13
	 *
	 * @param {HTMLElement|null} element Target element.
	 * @param {string} attribute Attribute name without the data-o_ prefix.
	 */
	const restoreWooVariationAttribute = (element, attribute) => {
		const originalAttribute = 'data-o_' + attribute

		if (element?.hasAttribute(originalAttribute)) {
			element.setAttribute(attribute, element.getAttribute(originalAttribute))
		}
	}

	/**
	 * Find a dedicated variation slide by its full-size image URL.
	 *
	 * Start at index 1 because WooCommerce mutates the first slide as its fallback. Treating that
	 * mutation as a dedicated slide would keep every variation incorrectly focused on slide 0.
	 *
	 * @since 2.3.13
	 *
	 * @param {Object} flexData FlexSlider instance data.
	 * @param {string} variationImageSrc Variation full-size image URL.
	 * @return {number} Matching zero-based slide index, or -1 when there is no dedicated slide.
	 */
	const getVariationImageSlideIndex = (flexData, variationImageSrc) => {
		if (!flexData?.slides || !variationImageSrc) {
			return -1
		}

		for (let i = 1; i < flexData.slides.length; i++) {
			const slideImage = flexData.slides[i].querySelector('img')

			if (slideImage?.getAttribute('data-large_image') === variationImageSrc) {
				return i
			}
		}

		return -1
	}

	/**
	 * Restore the original first main slide after WooCommerce used it as a variation fallback.
	 *
	 * Restoring the image alone is insufficient: zoom, lightbox, and native thumbnail navigation
	 * also read attributes from the slide wrapper, link, and first navigation image.
	 *
	 * @since 2.3.13
	 *
	 * @param {jQuery} $mainSlider Main WooCommerce gallery.
	 */
	const restoreMainSliderFirstSlide = ($mainSlider) => {
		const mainFlexData = $mainSlider.data('flexslider')
		const mainFirstSlide = mainFlexData?.slides?.[0]

		if (!mainFirstSlide) {
			return
		}

		const mainFirstSlideImage = mainFirstSlide.querySelector('img')
		const mainFirstSlideLink = mainFirstSlide.querySelector('a')
		const mainFirstNavImage = $mainSlider[0].querySelector('.flex-control-nav li:first-child img')

		mainSliderAttributeList.forEach(([originalAttribute]) => {
			restoreWooVariationAttribute(mainFirstSlideImage, originalAttribute)
		})
		restoreWooVariationAttribute(mainFirstSlide, 'data-thumb')
		restoreWooVariationAttribute(mainFirstSlideLink, 'href')
		restoreWooVariationAttribute(mainFirstNavImage, 'src')
	}

	/**
	 * Restore the custom thumbnail slider's first slide after fallback variation updates.
	 *
	 * Bricks stores these originals without WooCommerce's data-o_ prefix because this is a separate
	 * FlexSlider instance maintained by the Product Gallery element.
	 *
	 * @since 2.3.13
	 *
	 * @param {Object} flexData FlexSlider instance data.
	 */
	const restoreThumbnailSliderFirstSlide = (flexData) => {
		const firstSlide = flexData?.slides?.[0]
		const firstSlideLink = firstSlide?.querySelector('a')
		const firstSlideImage = firstSlide?.querySelector('img')

		if (!firstSlideLink || !firstSlideImage) {
			return
		}

		if (firstSlideLink.hasAttribute('o_href')) {
			firstSlideLink.setAttribute('href', firstSlideLink.getAttribute('o_href'))
		}

		attributeList.forEach(([originalAttribute]) => {
			if (firstSlideImage.hasAttribute('o_' + originalAttribute)) {
				firstSlideImage.setAttribute(
					originalAttribute,
					firstSlideImage.getAttribute('o_' + originalAttribute)
				)
			}
		})
	}

	/**
	 * Navigate the main gallery and its optional custom thumbnail slider to a variation image.
	 *
	 * Trigger FlexSlider's own navigation event when a custom thumbnail slider exists. This keeps
	 * both instances synchronized and avoids two competing animations (#86cbdkxe2).
	 *
	 * @since 2.3.13
	 *
	 * @param {HTMLElement} gallery Main WooCommerce gallery element.
	 * @param {number} variationImageSlideIndex Matching slide index.
	 * @param {string} variationImageSrc Variation full-size image URL.
	 * @return {boolean} Whether the requested slide still matches and navigation was handled.
	 */
	const navigateProductGalleryToVariation = (
		gallery,
		variationImageSlideIndex,
		variationImageSrc
	) => {
		const $mainSlider = jQuery(gallery)
		const mainFlexData = $mainSlider.data('flexslider')
		const mainVariationImage =
			mainFlexData?.slides?.[variationImageSlideIndex]?.querySelector('img')

		if (mainVariationImage?.getAttribute('data-large_image') !== variationImageSrc) {
			return false
		}

		restoreMainSliderFirstSlide($mainSlider)

		const $thumbnailSlider = $mainSlider.siblings('.brx-product-gallery-thumbnail-slider').first()
		const thumbnailFlexData = $thumbnailSlider.data('flexslider')
		const thumbnailVariationImage =
			thumbnailFlexData?.slides?.[variationImageSlideIndex]?.querySelector('img')

		if (thumbnailVariationImage?.getAttribute('data-large_image') === variationImageSrc) {
			restoreThumbnailSliderFirstSlide(thumbnailFlexData)

			if (thumbnailFlexData.currentItem !== variationImageSlideIndex) {
				jQuery(thumbnailFlexData.slides[variationImageSlideIndex]).trigger('flexslider-click')
			} else if (mainFlexData.currentSlide !== variationImageSlideIndex) {
				$mainSlider.flexslider(variationImageSlideIndex)
			}

			return true
		}

		if (mainFlexData.currentSlide !== variationImageSlideIndex) {
			$mainSlider.flexslider(variationImageSlideIndex)
		}

		return true
	}

	// WooCommerce's delayed reset must finish first. Correcting it while this event bubbles prevents
	// a visible slide-0 animation before the gallery moves to the dedicated variation slide.
	jQuery(document.body).on('woocommerce_gallery_reset_slide_position', function (event) {
		const gallery = event.target
		const pendingVariationImageSlide = pendingVariationImageSlides.get(gallery)

		if (!pendingVariationImageSlide) {
			return
		}

		navigateProductGalleryToVariation(
			gallery,
			pendingVariationImageSlide.index,
			pendingVariationImageSlide.src
		)
		pendingVariationImageSlides.delete(gallery)
	})

	jQuery(document.body).on('show_variation', function (event, variation) {
		let event_variation_id = variation?.variation_id || 0
		if (!event_variation_id) {
			return
		}

		const variationForm = event.target.closest?.('.variations_form')
		const currentVariationId = Number(
			variationForm?.querySelector('input[name="variation_id"], input.variation_id')?.value || 0
		)

		// The event is delayed by 300ms. Its payload can be stale after a fast variation change or reset.
		if (variationForm && currentVariationId !== Number(event_variation_id)) {
			return
		}

		jQuery('.brx-product-gallery-thumbnail-slider').each(function () {
			let sliderVariationIds = jQuery(this).data('variation-ids') || []

			// If the variation ID is not in the slider variation IDs, return (@since 1.11)
			if (!sliderVariationIds.includes(event_variation_id)) {
				return
			}

			let flexData = jQuery(this).data('flexslider')

			if (flexData) {
				// Check if variation image already exists in slider
				const variationImageSrc = variation?.image?.full_src
				if (!variationImageSrc) {
					return
				}

				// Check if any slide (except first) already has this variation image
				const variationImageSlideIndex = getVariationImageSlideIndex(flexData, variationImageSrc)

				const firstSlide = flexData.slides[0]

				const firstSlideLink = firstSlide.querySelector('a')
				const firstSlideImage = firstSlide.querySelector('img')

				// If we don't have a link or image, return
				if (!firstSlideLink || !firstSlideImage) {
					return
				}

				// If variation has dedicated slide, restore first slide to original (#86c3r9jwy)
				if (variationImageSlideIndex !== -1) {
					const mainSlider = jQuery(this).siblings('.woocommerce-product-gallery').first()[0]

					if (mainSlider) {
						navigateProductGalleryToVariation(
							mainSlider,
							variationImageSlideIndex,
							variationImageSrc
						)
						pendingVariationImageSlides.delete(mainSlider)
					}

					// Variation has dedicated slide, don't modify first slide further
					return
				}

				// Replacement galleries already rebuilt this thumbnail at the element's selected size.
				// The legacy single-image payload uses WooCommerce's default thumbnail size instead.
				if (variation?.gallery_images_html) {
					return
				}

				// If we don't have an image, return
				// Should not happen, but just in case
				if (!variation?.image) {
					return
				}

				// Update link href and save original href
				if (!firstSlideLink.hasAttribute('o_href')) {
					firstSlideLink.setAttribute('o_href', firstSlideLink.href)
				}
				firstSlideLink.setAttribute('href', variation.image.full_src)

				// Update image attributes and save original attributes
				attributeList.forEach((attribute) => {
					const [originalAttribute, variantAttribute] = attribute

					// If we don't have the attribute, return
					if (!firstSlideImage.hasAttribute(originalAttribute)) {
						return
					}

					// Save atributte if not already saved
					if (!firstSlideImage.hasAttribute('o_' + originalAttribute)) {
						firstSlideImage.setAttribute(
							'o_' + originalAttribute,
							firstSlideImage.getAttribute(originalAttribute)
						)
					}

					// Get attribute from variant and update
					const variantValue = variation?.image[variantAttribute]

					if (variantValue !== undefined) {
						firstSlideImage.setAttribute(originalAttribute, variantValue)
					}
				})

				jQuery(this).flexslider(0)
			}
		})

		const productId = variationForm?.getAttribute('data-product_id')

		if (!productId) {
			return
		}

		document
			.querySelectorAll(
				'.woocommerce-product-gallery.images.bricks-product-gallery-for-' + productId
			)
			.forEach((gallery) => {
				if (gallery.parentElement?.classList.contains('thumbnail-slider')) {
					return
				}

				const $mainSlider = jQuery(gallery)
				const mainFlexData = $mainSlider.data('flexslider')
				const variationImageSlideIndex = getVariationImageSlideIndex(
					mainFlexData,
					variation?.image?.full_src
				)

				if (variationImageSlideIndex === -1) {
					return
				}

				navigateProductGalleryToVariation(
					gallery,
					variationImageSlideIndex,
					variation.image.full_src
				)
				pendingVariationImageSlides.delete(gallery)
			})
	})

	jQuery(document.body).on('reset_image', function () {
		jQuery('.brx-product-gallery-thumbnail-slider').each(function () {
			let flexData = jQuery(this).data('flexslider')
			if (flexData) {
				const firstSlide = flexData.slides[0]

				const firstSlideLink = firstSlide.querySelector('a')
				const firstSlideImage = firstSlide.querySelector('img')

				// If we don't have a link or image, return
				if (!firstSlideLink || !firstSlideImage) {
					return
				}

				// Reset link href
				if (firstSlideLink.hasAttribute('o_href')) {
					firstSlideLink.setAttribute('href', firstSlideLink.getAttribute('o_href'))
				}

				// Reset image attributes
				attributeList.forEach((attribute) => {
					const [originalAttribute] = attribute

					// If we don't have the attribute, return
					if (!firstSlideImage.hasAttribute('o_' + originalAttribute)) {
						return
					}

					// Reset attribute
					firstSlideImage.setAttribute(
						originalAttribute,
						firstSlideImage.getAttribute('o_' + originalAttribute)
					)
				})

				// Move to first slide
				jQuery(this).flexslider(0)
			}
		})
	})

	/**
	 * Observer, that will resize gallery when it's intersecting
	 *
	 * Fixes issue with gallery not resizing properly, if hidden by default.
	 *
	 * Example: Inside nested tabs, accordion, etc.
	 *
	 * @since 1.12.2
	 */
	const imageGalleryObserver = new IntersectionObserver((entries) => {
		entries.forEach((entry) => {
			// Skip, if not intersecting
			if (!entry.isIntersecting) return

			// Resize the gallery
			jQuery(entry.target).resize()

			// Unobserve, as we only need to resize once (performance)
			imageGalleryObserver.unobserve(entry.target)
		})
	})

	// Observe all galleries and thumbnail sliders (@since 1.12.2)
	jQuery('.woocommerce-product-gallery, .brx-product-gallery-thumbnail-slider').each(function () {
		imageGalleryObserver.observe(this)
	})

	/**
	 * Recalculate FlexSlider dimensions after responsive layout changes.
	 *
	 * FlexSlider persists pixel widths on its viewport and slides. Those values can feed back into
	 * Bricks grid/flex sizing, especially for horizontal thumbnails and Firefox side thumbnails.
	 *
	 * @since 2.3.13 Extended the side-thumbnail resize recovery from #86cbcaend to every position.
	 */
	const productGallerySelector = '.brxe-product-gallery'

	const getProductGalleryFlexSliders = (productGallery) => {
		return jQuery(productGallery).children(
			'.woocommerce-product-gallery, .brx-product-gallery-thumbnail-slider'
		)
	}

	const resizeProductGalleryFlexSlider = (slider) => {
		const $slider = jQuery(slider)
		const flexData = $slider.data('flexslider')

		if (!flexData || flexData.animating || !$slider.is(':visible')) {
			return
		}

		const isCarousel = flexData.vars.itemWidth > 0
		const isFade = flexData.vars.animation === 'fade'
		const isVertical = flexData.vars.direction === 'vertical'

		flexData.doMath()

		if (isFade) {
			return
		}

		if (isCarousel) {
			flexData.slides.width(flexData.computedW)
			flexData.update(flexData.pagingCount)
			flexData.setProps()

			return
		}

		if (isVertical) {
			flexData.viewport.height(flexData.h)
			flexData.setProps(flexData.h, 'setTotal')

			return
		}

		flexData.setProps(flexData.computedW, 'setTotal')
		flexData.newSlides.width(flexData.computedW)
	}

	const resetProductGalleryFlexSliderWidth = (slider) => {
		const $slider = jQuery(slider)
		const flexData = $slider.data('flexslider')

		if (!flexData) {
			return
		}

		if (!$slider.is(':visible')) {
			// Never clear widths while hidden: the visible pass cannot restore them. Re-arm the one-time
			// observer so a tab, accordion, or popup recalculates the slider when it becomes visible.
			imageGalleryObserver.observe(slider)
			return
		}

		if (
			flexData.animating ||
			flexData.vars.animation === 'fade' ||
			flexData.vars.direction === 'vertical' ||
			flexData.vars.itemWidth > 0 ||
			!flexData.newSlides
		) {
			return
		}

		if (flexData.viewport) {
			flexData.viewport.width('100%')
		}

		flexData.newSlides.width(0)
	}

	const resizeProductGalleryFlexSliders = () => {
		jQuery(productGallerySelector).each(function () {
			const $sliders = getProductGalleryFlexSliders(this)

			$sliders.each(function () {
				resetProductGalleryFlexSliderWidth(this)
			})

			$sliders.each(function () {
				resizeProductGalleryFlexSlider(this)
			})
		})
	}

	let productGalleryResizeFrame = null
	let productGalleryResizeTimers = []

	const scheduleProductGalleryResize = () => {
		if (productGalleryResizeFrame) {
			cancelAnimationFrame(productGalleryResizeFrame)
		}

		productGalleryResizeTimers.forEach((timer) => {
			clearTimeout(timer)
		})

		productGalleryResizeFrame = requestAnimationFrame(() => {
			resizeProductGalleryFlexSliders()

			// Bricks breakpoint styles and surrounding grid/flex layouts may settle after the native
			// resize event, so repeat twice without continuously observing every gallery dimension.
			productGalleryResizeTimers = [
				setTimeout(resizeProductGalleryFlexSliders, 250),
				setTimeout(resizeProductGalleryFlexSliders, 700)
			]
		})
	}

	window.addEventListener('resize', scheduleProductGalleryResize)
	window.addEventListener('orientationchange', scheduleProductGalleryResize)

	resizeProductGalleryFlexSliders()

	// Handle multiple product gallery elements on the same page (#86c4vhehz; @since 2.2)
	jQuery(document.body).on('found_variation', function (event, variation) {
		var form = event.target
		var productId = form.getAttribute('data-product_id')

		var linkedGalleries = document.querySelectorAll(
			'.woocommerce-product-gallery.images.bricks-product-gallery-for-' + productId
		)

		if (variation && variation.image && variation.image.src) {
			// Remember only dedicated-slide matches. WooCommerce handles fallback variations by updating
			// slide 0, while these targets must survive its delayed slide-position reset.
			linkedGalleries.forEach((gallery) => {
				const variationImageSlideIndex = getVariationImageSlideIndex(
					jQuery(gallery).data('flexslider'),
					variation.image.full_src
				)

				if (variationImageSlideIndex !== -1) {
					pendingVariationImageSlides.set(gallery, {
						index: variationImageSlideIndex,
						src: variation.image.full_src
					})
				} else {
					pendingVariationImageSlides.delete(gallery)
				}
			})

			// Loop through all galleries except the first one (It will be handled by WooCommerce itself)
			linkedGalleries.forEach(function (gallery, index) {
				if (index === 0) {
					return
				}

				// Check if variation image already exists in gallery slider
				const variationImageSrc = variation?.image?.full_src

				if (!variationImageSrc) {
					return
				}

				// Get gallery flexslider data
				const $gallery = jQuery(gallery)
				const flexData = $gallery.data('flexslider')

				// Check if variation image exists in any slide (except first)
				let hasVariationImageInSlider = false

				if (flexData && flexData.slides) {
					for (let i = 0; i < flexData.slides.length; i++) {
						const slideImage = flexData.slides[i].querySelector('img')

						if (slideImage && slideImage.getAttribute('data-large_image') === variationImageSrc) {
							hasVariationImageInSlider = true
							break
						}
					}
				}

				var firstSlideImage = gallery.querySelector(
					'.woocommerce-product-gallery__image img.wp-post-image'
				)

				if (!firstSlideImage) {
					return
				}

				// If variation has dedicated slide, restore first slide and navigate to variation slide
				if (hasVariationImageInSlider) {
					// Restore first slide to original state
					if (firstSlideImage.hasAttribute('o_src')) {
						mainSliderAttributeList.forEach((attribute) => {
							const [originalAttribute] = attribute

							if (firstSlideImage.hasAttribute('o_' + originalAttribute)) {
								firstSlideImage.setAttribute(
									originalAttribute,
									firstSlideImage.getAttribute('o_' + originalAttribute)
								)
							}
						})
					}

					return
				}

				// Update image attributes and save original attributes
				mainSliderAttributeList.forEach((attribute) => {
					const [originalAttribute, variantAttribute] = attribute

					// If we don't have the attribute, return
					if (!firstSlideImage.hasAttribute(originalAttribute)) {
						return
					}

					// Save atributte if not already saved
					if (!firstSlideImage.hasAttribute('o_' + originalAttribute)) {
						firstSlideImage.setAttribute(
							'o_' + originalAttribute,
							firstSlideImage.getAttribute(originalAttribute)
						)
					}

					// Get attribute from variant and update
					const variantValue = variation?.image[variantAttribute]

					if (variantValue !== undefined) {
						firstSlideImage.setAttribute(originalAttribute, variantValue)
					}
				})
			})
		}
	})

	// Reset on variation reset
	jQuery(document.body).on('reset_image', function (event) {
		var form = event.target
		var productId = form.getAttribute('data-product_id')

		var linkedGalleries = document.querySelectorAll(
			'.woocommerce-product-gallery.images.bricks-product-gallery-for-' + productId
		)

		// A queued target belongs to the previous selection and must not survive reset_image.
		linkedGalleries.forEach((gallery) => {
			pendingVariationImageSlides.delete(gallery)
		})

		// Loop through all galleries except the first one (It will be handled by WooCommerce itself)
		linkedGalleries.forEach(function (gallery, index) {
			if (index === 0) {
				return
			}
			var firstSlideImage = gallery.querySelector(
				'.woocommerce-product-gallery__image img.wp-post-image'
			)
			if (!firstSlideImage) {
				return
			}

			// Reset image attributes
			mainSliderAttributeList.forEach((attribute) => {
				const [originalAttribute] = attribute
				// If we don't have the attribute, return
				if (!firstSlideImage.hasAttribute('o_' + originalAttribute)) {
					return
				}
				// Reset attribute
				firstSlideImage.setAttribute(
					originalAttribute,
					firstSlideImage.getAttribute('o_' + originalAttribute)
				)
			})
		})
	})
}

/**
 * Step a WooCommerce quantity input.
 *
 * Native number inputs silently keep their value when stepUp() or stepDown()
 * reaches a min/max boundary. Report whether the value changed so callers do
 * not trigger a cart update for that no-op.
 *
 * @param {HTMLInputElement} quantityInput
 * @param {'up'|'down'} direction
 * @returns {boolean}
 */
function bricksWooStepQuantityInput(quantityInput, direction) {
	const previousValue = quantityInput.value

	try {
		if (direction === 'up') {
			quantityInput.stepUp()
		} else {
			quantityInput.stepDown()
		}
	} catch (error) {
		const currentValue = parseFloat(quantityInput.value) || 0
		const step = parseFloat(quantityInput.getAttribute('step')) || 1
		const min = parseFloat(quantityInput.getAttribute('min'))
		const max = parseFloat(quantityInput.getAttribute('max'))
		let nextValue = direction === 'up' ? currentValue + step : currentValue - step

		if (!isNaN(min)) {
			nextValue = Math.max(nextValue, min)
		}

		if (!isNaN(max)) {
			nextValue = Math.min(nextValue, max)
		}

		quantityInput.value = nextValue
	}

	bricksWooUpdateQuantityButtonStates(quantityInput)

	return quantityInput.value !== previousValue
}

const bricksWooQuantityStateInputs = new WeakSet()

/**
 * Synchronize quantity stepper controls with the input min/max state.
 * (#86cax1ekt; @since 2.4)
 *
 * @param {HTMLInputElement} quantityInput
 */
function bricksWooUpdateQuantityButtonStates(quantityInput) {
	const numberWrap = quantityInput.closest('.quantity, .brx-number-wrap')

	if (!numberWrap) {
		return
	}

	const currentValue = parseFloat(quantityInput.value)
	const min = parseFloat(quantityInput.getAttribute('min'))
	const max = parseFloat(quantityInput.getAttribute('max'))

	numberWrap
		.querySelectorAll('.action.minus, .action.plus, .brx-stepper-button')
		.forEach((button) => {
			const direction =
				button.dataset.stepperDirection || (button.classList.contains('minus') ? 'down' : 'up')
			const disabled =
				!isNaN(currentValue) &&
				((direction === 'up' && !isNaN(max) && currentValue >= max) ||
					(direction === 'down' && !isNaN(min) && currentValue <= min))

			button.classList.toggle('disabled', disabled)
			button.setAttribute('aria-disabled', disabled ? 'true' : 'false')

			if (button.tagName === 'BUTTON') {
				button.disabled = disabled
			}
		})
}

/**
 * Initialize quantity stepper state and keep it in sync with manual input changes.
 *
 * @param {HTMLInputElement} quantityInput
 */
function bricksWooInitQuantityInputState(quantityInput) {
	bricksWooUpdateQuantityButtonStates(quantityInput)

	if (bricksWooQuantityStateInputs.has(quantityInput)) {
		return
	}

	bricksWooQuantityStateInputs.add(quantityInput)
	const updateButtonStates = () => {
		bricksWooUpdateQuantityButtonStates(quantityInput)
	}
	quantityInput.addEventListener('input', updateButtonStates)

	// WooCommerce updates variation limits through jQuery change events, which native listeners miss.
	if (typeof jQuery !== 'undefined') {
		jQuery(quantityInput).on('change', updateButtonStates)
	} else {
		quantityInput.addEventListener('change', updateButtonStates)
	}
}

/**
 * Cart quantity up/down
 *
 * Use BricksFunction @since 1.9.2
 */
const bricksWooQuantityTriggersFn = new BricksFunction({
	parentNode: document,
	selector: 'form .quantity .action',
	subscribejQueryEvents: ['updated_cart_totals'],
	eachElement: (button) => {
		const quantityInput = button.closest('.quantity').querySelector('.qty:not([readonly])')

		if (quantityInput && !quantityInput.disabled) {
			bricksWooInitQuantityInputState(quantityInput)
		}

		button.addEventListener('click', function (e) {
			e.preventDefault()

			// Only update cart if quantity input is not readonly (@since 1.7)
			const quantityInput = button.closest('.quantity').querySelector('.qty:not([readonly])')

			if (!quantityInput || quantityInput.disabled) {
				return
			}

			if (button.classList.contains('disabled')) {
				return
			}

			const direction = button.classList.contains('plus') ? 'up' : 'down'

			if (!bricksWooStepQuantityInput(quantityInput, direction)) {
				return
			}

			var updateCartButton = quantityInput
				.closest('form')
				?.querySelector('button[name="update_cart"]')

			if (updateCartButton) {
				updateCartButton.removeAttribute('disabled')
				updateCartButton.setAttribute('aria-disabled', 'false')
			}

			// Trigger change event for product quantity input (@since 1.7)
			const quantityInputEvent = new Event('change', { bubbles: true })
			quantityInput.dispatchEvent(quantityInputEvent)
		})
	}
})

/**
 * WooCommerce cart quantity custom stepper buttons.
 *
 * @since 2.4
 */
const bricksWooCartQuantityStepperFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-woocommerce-cart-quantity .brx-stepper-button',
	subscribejQueryEvents: ['updated_cart_totals', 'updated_checkout'],
	eachElement: (button) => {
		const quantityInput = button.closest('.brx-number-wrap')?.querySelector('.qty:not([readonly])')

		if (quantityInput && !quantityInput.disabled) {
			bricksWooInitQuantityInputState(quantityInput)
		}

		const stepQuantity = (direction) => {
			const numberWrap = button.closest('.brx-number-wrap')
			const quantityInput = numberWrap?.querySelector('.qty:not([readonly])')

			if (
				!quantityInput ||
				quantityInput.disabled ||
				button.classList.contains('disabled') ||
				numberWrap.closest('.processing')
			) {
				return
			}

			if (!bricksWooStepQuantityInput(quantityInput, direction)) {
				return
			}

			const cartForm = quantityInput.closest('.woocommerce-cart-form')
			const updateCartButton = cartForm?.querySelector('button[name="update_cart"]')

			if (updateCartButton) {
				updateCartButton.removeAttribute('disabled')
				updateCartButton.setAttribute('aria-disabled', 'false')
			}

			quantityInput.dispatchEvent(new Event('change', { bubbles: true }))
		}

		button.addEventListener('click', (event) => {
			event.preventDefault()
			stepQuantity(button.dataset.stepperDirection === 'down' ? 'down' : 'up')
		})

		button.addEventListener('keydown', (event) => {
			if (event.key === 'ArrowUp') {
				event.preventDefault()
				stepQuantity('up')
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault()
				stepQuantity('down')
			}
		})
	}
})

/** @type {WeakMap<Element, number>} Per-wrapper debounce timers for the fallback quantity endpoint. */
const bricksWooDetachedCartQuantityTimers = new WeakMap()

/** @type {string} Form marker consumed by the Checkout V2 server-side quantity handler. */
const bricksWooCheckoutCartQuantityMarkerName = 'bricks_update_checkout_cart_quantities'

/** @type {string} Form field that distinguishes a native-style removal from quantity zero. */
const bricksWooCheckoutCartRemovalMarkerName = 'bricks_remove_checkout_cart_item'

/** @type {string} Nameless node Woo replaces with the final cart hash and removal result. */
const bricksWooCheckoutCartHashSelector = '[data-brx-woo-checkout-cart-hash]'

/** @type {number|null} Shared debounce timer so changes to multiple Checkout V2 rows are coalesced. */
let bricksWooCheckoutCartQuantityTimer = null

/**
 * The clicked link is retained only until the marked update_order_review request completes.
 *
 * Woo and third-party scripts conventionally receive this link with `removed_from_cart`.
 *
 * @type {{cartItemKey: string, trigger: HTMLElement}|null}
 */
let bricksWooPendingCheckoutRemoval = null

/**
 * Add the Checkout V2 cart-update marker and queue one native checkout refresh.
 *
 * @since 2.4
 *
 * @param {HTMLFormElement} checkoutForm Checkout form containing the cart fields.
 * @param {number} delay Debounce delay in milliseconds.
 * @return {void}
 */
function bricksWooRequestCheckoutCartUpdate(checkoutForm, delay = 200) {
	let updateMarker = checkoutForm.querySelector(
		`input[name="${bricksWooCheckoutCartQuantityMarkerName}"]`
	)

	if (!updateMarker) {
		// Woo serializes the form after the event, so keep the marker until `updated_checkout`.
		updateMarker = document.createElement('input')
		updateMarker.type = 'hidden'
		updateMarker.name = bricksWooCheckoutCartQuantityMarkerName
		updateMarker.value = '1'
		checkoutForm.append(updateMarker)
	}

	if (!checkoutForm.querySelector(bricksWooCheckoutCartHashSelector)) {
		/*
		 * This nameless node is safe to keep in the checkout form because Woo does not serialize
		 * it as form data. The response replaces it with the server-confirmed hash and removal
		 * result used by the compatibility event after `updated_checkout`.
		 */
		const cartHashMarker = document.createElement('input')
		cartHashMarker.type = 'hidden'
		cartHashMarker.setAttribute('data-brx-woo-checkout-cart-hash', '')
		cartHashMarker.setAttribute('data-cart-item-removed', '0')
		checkoutForm.append(cartHashMarker)
	}

	if (bricksWooCheckoutCartQuantityTimer) {
		clearTimeout(bricksWooCheckoutCartQuantityTimer)
	}

	bricksWooCheckoutCartQuantityTimer = setTimeout(() => {
		bricksWooCheckoutCartQuantityTimer = null
		jQuery(document.body).trigger('update_checkout', { update_shipping_method: false })
	}, delay)
}

/**
 * Set a cart item quantity in the serialized Checkout V2 form.
 *
 * @since 2.4
 *
 * @param {HTMLFormElement} checkoutForm Checkout form containing the cart fields.
 * @param {string} cartItemKey WooCommerce cart item key.
 * @param {number|string} quantity Quantity to submit.
 * @param {string} nonce Optional cart nonce from a remove URL.
 * @return {boolean} Whether the required form fields were prepared.
 */
function bricksWooSetCheckoutCartItemQuantity(checkoutForm, cartItemKey, quantity, nonce = '') {
	if (!checkoutForm || !cartItemKey) {
		return false
	}

	const inputName = `cart[${cartItemKey}][qty]`
	let quantityInput = Array.from(checkoutForm.elements).find(
		(field) => field.getAttribute?.('name') === inputName
	)

	if (!quantityInput) {
		quantityInput = document.createElement('input')
		quantityInput.type = 'hidden'
		quantityInput.name = inputName
		checkoutForm.append(quantityInput)
	}

	quantityInput.value = quantity

	if (nonce && !checkoutForm.querySelector('input[name="woocommerce-cart-nonce"]')) {
		const nonceInput = document.createElement('input')
		nonceInput.type = 'hidden'
		nonceInput.name = 'woocommerce-cart-nonce'
		nonceInput.value = nonce
		checkoutForm.append(nonceInput)
	}

	return !!checkoutForm.querySelector('input[name="woocommerce-cart-nonce"]')
}

/**
 * Mark one Checkout V2 cart item for Woo's native removal lifecycle.
 *
 * Quantity zero remains in the serialized row as an understandable fallback representation,
 * while the explicit removal field tells PHP to call `remove_cart_item()` and fire Woo's
 * user-requested removal hook instead of reporting a quantity update.
 *
 * @since 2.4
 *
 * @param {HTMLFormElement} checkoutForm Checkout form containing the cart fields.
 * @param {string} cartItemKey WooCommerce cart item key.
 * @param {HTMLElement} removeLink Clicked removal link passed to compatibility listeners.
 * @param {string} nonce Cart nonce parsed from the removal URL.
 * @return {boolean} Whether the required fields were prepared.
 */
function bricksWooPrepareCheckoutCartItemRemoval(
	checkoutForm,
	cartItemKey,
	removeLink,
	nonce = ''
) {
	if (!bricksWooSetCheckoutCartItemQuantity(checkoutForm, cartItemKey, 0, nonce)) {
		return false
	}

	let removalMarker = checkoutForm.querySelector(
		`input[name="${bricksWooCheckoutCartRemovalMarkerName}"]`
	)

	if (!removalMarker) {
		removalMarker = document.createElement('input')
		removalMarker.type = 'hidden'
		removalMarker.name = bricksWooCheckoutCartRemovalMarkerName
		checkoutForm.append(removalMarker)
	}

	removalMarker.value = cartItemKey

	// A previous successful removal may have left this reusable marker at "1".
	checkoutForm
		.querySelector(bricksWooCheckoutCartHashSelector)
		?.setAttribute('data-cart-item-removed', '0')

	bricksWooPendingCheckoutRemoval = {
		cartItemKey,
		trigger: removeLink
	}

	return true
}

/**
 * Route a detached cart quantity change through the appropriate WooCommerce lifecycle.
 *
 * Checkout V2 renders the Cart quantity element inside WooCommerce's native checkout form.
 * Woo serializes that form as `post_data`, so the quantity and Bricks-only marker can travel
 * in the existing `update_order_review` request. The PHP handler updates the cart first;
 * Woo then recalculates shipping, fees, totals, and payment methods in the same request.
 *
 * Cart and dynamic-fragment instances outside `.woocommerce-cart-form` keep using the
 * dedicated quantity endpoint. Checkout V1 never enters the one-request branch because
 * it has no `.brxe-woocommerce-checkout-v2.brx-wc-checkout-v2--checkout` ancestor.
 *
 * @since 2.4
 *
 * @param {HTMLInputElement} quantityInput Changed cart quantity input.
 * @return {void}
 */
function bricksWooDetachedCartQuantityAjaxUpdate(quantityInput) {
	if (typeof jQuery === 'undefined') {
		return
	}

	const quantityRoot = quantityInput.closest('.brx-woo-cart-quantity-update')
	const checkoutForm = quantityInput.closest('form.checkout, form.woocommerce-checkout')
	const checkoutV2Root = checkoutForm?.closest(
		'.brxe-woocommerce-checkout-v2.brx-wc-checkout-v2--checkout'
	)

	if (quantityRoot && checkoutForm && checkoutV2Root && window.wc_checkout_params?.is_checkout) {
		bricksWooRequestCheckoutCartUpdate(checkoutForm)

		return
	}

	const inputName = quantityInput.getAttribute('name') || ''
	const cartItemKey = inputName.match(/^cart\[(.+)]\[qty]$/)?.[1] || ''
	const nonce =
		quantityRoot?.querySelector('input[name="woocommerce-cart-nonce"]')?.value ||
		document.querySelector('input[name="woocommerce-cart-nonce"]')?.value ||
		''
	const wcAjaxUrl =
		window.wc_cart_params?.wc_ajax_url ||
		window.wc_add_to_cart_params?.wc_ajax_url ||
		window.wc_checkout_params?.wc_ajax_url ||
		window.woocommerce_params?.wc_ajax_url ||
		''
	const updateQuantityUrl = wcAjaxUrl
		? wcAjaxUrl.toString().replace('%%endpoint%%', 'bricks_update_cart_item_quantity')
		: ''

	if (!quantityRoot || !cartItemKey || !nonce || !updateQuantityUrl) {
		return
	}

	if (bricksWooDetachedCartQuantityTimers.has(quantityRoot)) {
		clearTimeout(bricksWooDetachedCartQuantityTimers.get(quantityRoot))
	}

	bricksWooDetachedCartQuantityTimers.set(
		quantityRoot,
		setTimeout(() => {
			bricksWooDetachedCartQuantityAjaxSubmit(quantityInput, {
				cartItemKey,
				nonce,
				quantityRoot,
				updateQuantityUrl
			})
		}, 200)
	)
}

/**
 * Submit the fallback quantity request used outside the Checkout V2 form.
 *
 * This path remains necessary for cart loops in headers, popups, dynamic fragments, and
 * other detached locations. If it runs on a checkout page, the native checkout refresh is
 * deliberately started only after this request releases its own blocker.
 *
 * @since 2.4
 *
 * @param {HTMLInputElement} quantityInput Changed cart quantity input.
 * @param {Object} config Request configuration.
 * @param {string} config.cartItemKey WooCommerce cart item key.
 * @param {string} config.nonce WooCommerce cart nonce.
 * @param {Element} config.quantityRoot Cart quantity element root.
 * @param {string} config.updateQuantityUrl Bricks quantity endpoint URL.
 * @return {void}
 */
function bricksWooDetachedCartQuantityAjaxSubmit(quantityInput, config) {
	const $wrapper = jQuery(
		quantityInput.closest(
			'.woocommerce-cart-form, .woocommerce-checkout-review-order, #bricks-woo-checkout-order-summary, form.checkout, form.woocommerce-checkout, [data-brx-woo-fragment="true"]'
		) || config.quantityRoot
	)
	const cartForm = document.querySelector('.woocommerce-cart-form')
	const refreshFragments = !cartForm
	const dynamicFragmentTargets = refreshFragments ? bricksWooGetDynamicFragmentTargets() : []
	// Capture before BlockUI inserts its overlay. That temporary DOM and the resulting overflow
	// changes can move a custom inner scroll container before the response replaces its fragment.
	const dynamicFragmentScrollStates = bricksWooDynamicFragmentScrollState.captureMounted()
	let shouldUpdateCheckout = false

	if ($wrapper.length && bricksWooCartIsBlocked($wrapper)) {
		return
	}

	if ($wrapper.length) {
		$wrapper.attr('aria-busy', 'true')
		bricksWooCartBlock($wrapper)
	}

	jQuery.ajax({
		type: 'POST',
		url: config.updateQuantityUrl,
		data: {
			security: config.nonce,
			cart_item_key: config.cartItemKey,
			quantity: quantityInput.value,
			postId: window.bricksData?.postId || 0,
			refresh_fragments: refreshFragments,
			bricks_woo_current_url: window.location.href.split('#')[0],
			fragments: JSON.stringify(dynamicFragmentTargets)
		},
		success: function (response) {
			if (response?.data?.quantity !== undefined) {
				quantityInput.value = response.data.quantity
				quantityInput.defaultValue = response.data.quantity
			}

			if (!response || response.success === false) {
				if (response?.data?.notices) {
					bricksShowNotice(response.data.notices)
					bricksScrollToNotices()
				}

				return
			}

			if (response?.data?.notices) {
				bricksShowNotice(response.data.notices)
			}

			if (cartForm) {
				const inputName = quantityInput.getAttribute('name') || ''

				if (inputName) {
					cartForm.querySelectorAll('.qty').forEach((cartFormInput) => {
						if (cartFormInput.getAttribute('name') === inputName) {
							cartFormInput.value = quantityInput.value
						}
					})
				}

				// Updating a quantity inside a detached layout starts a second, full cart-page request.
				// Carry the original click-time positions through that request instead of recapturing
				// after the first response has already altered the fragment layout.
				const cartUpdate = bricksWooCartAjaxUpdate(cartForm, {
					forceUpdateCart: true,
					noticesHTML: response?.data?.notices || '',
					preserveNotices: !response?.data?.notices,
					scrollToNotices: true
				})

				if (cartUpdate?.always) {
					cartUpdate.always(() => {
						bricksWooRefreshCartFragments({
							skipCheckoutUpdate: true,
							scrollStates: dynamicFragmentScrollStates
						})
					})
				} else {
					bricksWooRefreshCartFragments({
						skipCheckoutUpdate: true,
						scrollStates: dynamicFragmentScrollStates
					})
				}

				return
			}

			const fragments = response?.data?.fragments
			const dynamicFragments = response?.data?.dynamic_fragments

			if (!fragments || !dynamicFragments) {
				bricksWooRefreshCartFragments({
					skipCheckoutUpdate: true,
					scrollStates: dynamicFragmentScrollStates
				})
			} else {
				bricksWooReplaceFragments(fragments)

				if (Object.keys(dynamicFragments).length) {
					bricksWooReplaceFragments(dynamicFragments, {
						preserveProgressBarState: true,
						scrollStates: dynamicFragmentScrollStates
					})

					if (typeof bricksRunAllFunctions === 'function') {
						bricksRunAllFunctions()
					}

					document.body.dispatchEvent(
						new CustomEvent('bricks/woocommerce/fragments/refreshed', {
							detail: {
								fragments: dynamicFragments,
								targets: dynamicFragmentTargets,
								sourceEvent: 'wc_fragments_refreshed'
							}
						})
					)
				}

				jQuery(document.body).trigger('wc_fragments_refreshed', [
					{
						skipCheckoutUpdate: true,
						skipDynamicRefresh: true
					}
				])
			}

			if (window.wc_checkout_params?.is_checkout) {
				shouldUpdateCheckout = true
			}
		},
		complete: function () {
			if ($wrapper.length) {
				$wrapper.removeAttr('aria-busy')
				bricksWooCartUnblock($wrapper)
			}

			// Start the dependent checkout refresh only after releasing this request's blocker.
			// Its busy state remains owned by checkout until updated_checkout or checkout_error.
			if (shouldUpdateCheckout) {
				jQuery(document.body).trigger('update_checkout', { update_shipping_method: false })
			}
		}
	})
}

function bricksWooProductsFilter() {
	var filters = bricksQuerySelectorAll(document, '.brxe-woocommerce-products-filter .filter-item')

	filters.forEach(function (filter) {
		function triggerFormSubmit(event) {
			event.target.closest('form').submit()
		}

		function toggleFilter(event) {
			var parentEl = event.target.closest('.filter-item')
			parentEl.classList.toggle('open')
		}

		var dropdowns = bricksQuerySelectorAll(filter, '.dropdown')
		dropdowns.forEach(function (dropdown) {
			dropdown.addEventListener('change', triggerFormSubmit)
		})

		var inputs = bricksQuerySelectorAll(filter, 'input[type="radio"], input[type="checkbox"]')
		inputs.forEach(function (input) {
			input.addEventListener('change', triggerFormSubmit)
			input.addEventListener('click', triggerFormSubmit)
		})

		var sliders = bricksQuerySelectorAll(filter, '.double-slider-wrap')
		sliders.forEach(function (slider) {
			bricksWooProductsFilterInitSlider(slider)
		})

		var toggles = bricksQuerySelectorAll(filter, '.title')
		toggles.forEach(function (toggle) {
			toggle.onclick = toggleFilter
		})
	})
}

/**
 * Init any WooCommerce mini modals (mini-cart)
 */
function bricksWooMiniModals() {
	var toggles = document.querySelectorAll('.bricks-woo-toggle')
	toggles.forEach(function (toggle) {
		toggle.addEventListener('click', bricksWooMiniModalsToggle)

		// Open on woo added_to_cart
		if (toggle.hasAttribute('data-open-on-add-to-cart')) {
			jQuery(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
				toggle.click()
			})
		}
	})
}

/**
 * Double Range Slider (to set min & max values)
 */
function bricksWooProductsFilterInitSlider(slider) {
	var lowerSlider = slider.querySelector('input.lower')
	var upperSlider = slider.querySelector('input.upper')

	lowerSlider.oninput = bricksWooProductsFilterUpdateSliderValue
	upperSlider.oninput = bricksWooProductsFilterUpdateSliderValue

	var lowerVal = parseInt(lowerSlider.value)
	var upperVal = parseInt(upperSlider.value)

	bricksWooProductsFilterRenderSliderValues(lowerSlider.parentNode, lowerVal, upperVal)

	// Submit form after range input change (= mouseup)
	lowerSlider.addEventListener('change', function () {
		slider.closest('form').submit()
	})

	upperSlider.addEventListener('change', function () {
		slider.closest('form').submit()
	})
}

function bricksWooProductsFilterUpdateSliderValue(event) {
	var parentEl = event.target.parentNode
	var lowerSlider = parentEl.querySelector('input.lower')
	var upperSlider = parentEl.querySelector('input.upper')
	var lowerVal = parseInt(lowerSlider.value)
	var upperVal = parseInt(upperSlider.value)

	if (upperVal < lowerVal + 4) {
		lowerSlider.value = upperVal - 4
		upperSlider.value = lowerVal + 4

		if (lowerVal == lowerSlider.min) {
			upperSlider.value = 4
		}
		if (upperVal == upperSlider.max) {
			lowerSlider.value = parseInt(upperSlider.max) - 4
		}
	}

	bricksWooProductsFilterRenderSliderValues(parentEl, lowerVal, upperVal)
}

function bricksWooProductsFilterRenderSliderValues(parentEl, lowerVal, upperVal) {
	var currency = parentEl.getAttribute('data-currency')
	var labelLower = parentEl.querySelector('label.lower')
	var labelUpper = parentEl.querySelector('label.upper')
	var valueLower = parentEl.querySelector('.value.lower')
	var valueUpper = parentEl.querySelector('.value.upper')

	// Parse currency data from data-currency attribute (@since 1.10)
	const currencyData = JSON.parse(currency)

	// Properly format currency symbol
	let currencySymbolLower = currencyData.symbol
	let currencySymbolUpper = currencyData.symbol

	switch (currencyData.position) {
		case 'left':
			currencySymbolLower = currencyData.symbol + lowerVal
			currencySymbolUpper = currencyData.symbol + upperVal
			break
		case 'right':
			currencySymbolLower = lowerVal + currencyData.symbol
			currencySymbolUpper = upperVal + currencyData.symbol
			break
		case 'leftSpace':
			currencySymbolLower = currencyData.symbol + ' ' + lowerVal
			currencySymbolUpper = currencyData.symbol + ' ' + upperVal
			break
		case 'rightSpace':
			currencySymbolLower = lowerVal + ' ' + currencyData.symbol
			currencySymbolUpper = upperVal + ' ' + currencyData.symbol
			break
	}

	valueLower.innerText = labelLower.innerText + ': ' + currencySymbolLower
	valueUpper.innerText = labelUpper.innerText + ': ' + currencySymbolUpper
}

/**
 * AJAX add to cart click handler
 * - Add event listener for clicking add to cart
 * - Actual function refer to bricksWooAddToCart()
 *
 * Use BricksFunction class and separate from bricksWooAjaxAddToCartText() (@since 1.9.2)
 *
 * @since 1.9.2
 */
const bricksWooAjaxAddToCartFn = new BricksFunction({
	parentNode: document,
	selector: '.single_add_to_cart_button, .brx_ajax_add_to_cart',
	windowVariableCheck: ['bricksWooCommerce.ajaxAddToCartEnabled'],
	eachElement: (addToCartButton) => {
		// Add event listeners for clicking add to cart
		addToCartButton.addEventListener('click', function (event) {
			event.preventDefault()
			if (addToCartButton.classList.contains('disabled')) {
				return
			}

			// Get type of add to cart button (@since 1.9)
			const type = addToCartButton.classList.contains('single_add_to_cart_button')
				? 'single'
				: 'loop'

			const addToCartElement =
				type === 'single' ? addToCartButton.closest('form.cart') : addToCartButton

			if (type === 'single') {
				/**
				 * Follow external product link instead of AJAX add to cart
				 *
				 * External product use 'get' method instead of 'post'.
				 *
				 * @since 1.8.5
				 */
				const form = addToCartButton.closest('form.cart')
				const formMethod = form.getAttribute('method')

				if (formMethod === 'get') {
					form.submit()

					// Return: Don't perform AJAX add to cart
					return
				}
			}

			// AJAX add to cart
			bricksWooAddToCart(addToCartElement, type)
		})
	}
})

/**
 * Init AJAX add to cart logic
 *
 * @since 1.6.1
 */
function bricksWooAjaxAddToCartText() {
	if (!window.bricksWooCommerce.ajaxAddToCartEnabled) {
		return
	}

	const resetTimers = new WeakMap()
	const clearSuccessFeedback = (button) => {
		// A previous addition must not reset feedback for a newer request.
		clearTimeout(resetTimers.get(button))
		resetTimers.delete(button)
		button.classList.remove('bricks-cart-success')
	}

	// Function to get Ajax Button Settings, returns default setting if not set
	const getAjaxButtonSettings = function (button) {
		let ajaxButtonSettingsObj = {
			addingHTML: bricksWooCommerce.ajaxAddingText,
			addedHTML: bricksWooCommerce.ajaxAddedText,
			showNotice: bricksWooCommerce.showNotice,
			scrollToNotice: bricksWooCommerce.scrollToNotice,
			resetTextAfter: bricksWooCommerce.resetTextAfter,
			errorAction: bricksWooCommerce.errorAction,
			errorScrollToNotice: bricksWooCommerce.errorScrollToNotice
		}

		// Overwrite default settings with custom settings on the button if available
		if (button.closest('.brxe-product-add-to-cart')) {
			customAjaxButtonSettingsObj =
				button.closest('.brxe-product-add-to-cart')?.getAttribute('data-bricks-ajax-add-to-cart') ||
				false

			if (customAjaxButtonSettingsObj) {
				// Try to parse custom settings and overwrite default settings
				try {
					JSON.parse(customAjaxButtonSettingsObj, (key, value) => {
						ajaxButtonSettingsObj[key] = value
					})
				} catch (error) {
					console.error('Bricks WooCommerce: Invalid JSON format for data-bricks-ajax-add-to-cart')
				}
			}
		}

		return ajaxButtonSettingsObj
	}

	// Change button text on woo event adding_to_cart, included shop loop buttons
	jQuery('body').on('adding_to_cart', function (event, $button, data) {
		clearSuccessFeedback($button[0])
		$button[0].setAttribute('disabled', 'disabled')
		$button[0].classList.add('disabled', 'bricks-cart-adding')

		// Get Ajax Button Settings
		const ajaxButtonSettings = getAjaxButtonSettings($button[0])
		if (ajaxButtonSettings && ajaxButtonSettings.addingHTML) {
			// Store the original button text
			if (!$button[0].hasAttribute('data-original-text')) {
				$button[0].setAttribute('data-original-text', $button[0].innerHTML)
			}
			$button[0].innerHTML = ajaxButtonSettings.addingHTML
		}
	})

	/**
	 * Listen to added_to_cart
	 * - Change button text
	 * - Show notice
	 * - Scroll to notice
	 */
	jQuery('body').on('added_to_cart', function (event, fragments, cartHash, $button) {
		clearSuccessFeedback($button[0])
		$button[0].removeAttribute('disabled')
		$button[0].classList.add('bricks-cart-added')
		$button[0].classList.remove('disabled', 'bricks-cart-adding')

		// Get Ajax Button Settings
		const ajaxButtonSettings = getAjaxButtonSettings($button[0])
		if (ajaxButtonSettings && ajaxButtonSettings.addedHTML) {
			$button[0].innerHTML = ajaxButtonSettings.addedHTML
			$button[0].classList.add('bricks-cart-success')
			// Reset button text after N seconds
			resetTimers.set(
				$button[0],
				setTimeout(function () {
					$button[0].innerHTML = $button[0].getAttribute('data-original-text')
					clearSuccessFeedback($button[0])
				}, ajaxButtonSettings.resetTextAfter * 1000)
			)
		}

		// Show notice
		if (
			typeof window.bricksWooCommerce.addedToCartNotices === 'string' &&
			window.bricksWooCommerce.addedToCartNotices.length > 0 &&
			ajaxButtonSettings.showNotice === 'yes'
		) {
			// Show notice
			jQuery('.woocommerce-notices-wrapper').html(window.bricksWooCommerce.addedToCartNotices)
			// Reset notices
			window.bricksWooCommerce.addedToCartNotices = ''

			// Scroll to notice
			if (
				ajaxButtonSettings.scrollToNotice === 'yes' &&
				typeof jQuery.scroll_to_notices === 'function'
			) {
				jQuery.scroll_to_notices(jQuery('.woocommerce-notices-wrapper'))
			}
		}
	})

	/**
	 * Listen to custom bricks_add_to_cart_error
	 *
	 * - Show notice
	 * - Scroll to notice
	 * - Reset button text
	 *
	 * @since 1.11
	 */
	jQuery('body').on('bricks_add_to_cart_error', function (event, notices, $button) {
		clearSuccessFeedback($button[0])
		$button[0].removeAttribute('disabled')
		$button[0].classList.remove('disabled', 'bricks-cart-adding')
		const ajaxButtonSettings = getAjaxButtonSettings($button[0])

		// Reset button text
		if ($button[0].hasAttribute('data-original-text')) {
			$button[0].innerHTML = $button[0].getAttribute('data-original-text')
		}

		// Show notice
		if (
			typeof notices === 'string' &&
			notices.length > 0 &&
			ajaxButtonSettings.errorAction === 'notice'
		) {
			// Show notice
			jQuery('.woocommerce-notices-wrapper').html(notices)

			// Scroll to notice
			if (
				ajaxButtonSettings.errorScrollToNotice &&
				typeof jQuery.scroll_to_notices === 'function'
			) {
				jQuery.scroll_to_notices(jQuery('.woocommerce-notices-wrapper'))
			}
		}
	})
}

/**
 * AJAX add to cart core Function
 *
 * Support looping products - Simple products only (@since 1.9)
 *
 * @since 1.6.1
 */
function bricksWooAddToCart(element, type) {
	if (typeof woocommerce_params == 'undefined') {
		return
	}

	const addToCartButton =
		type === 'single' ? element.querySelector('.single_add_to_cart_button') : element

	const data = {}

	if (type === 'single') {
		// Single product page
		const form = element
		const formData = new FormData(form)
		// Populate data for simple products
		data.product_id = addToCartButton.value
		data.quantity = formData.get('quantity')
		data.product_type = 'simple'

		// Populate data for variable products
		if (form.classList.contains('variations_form')) {
			data.product_id = formData.get('product_id')
			data.quantity = formData.get('quantity')
			data.variation_id = formData.get('variation_id')
			data.product_type = 'variable'
			// Populate attributes array with attribute names and values
			const attributes = {}
			for (const pair of formData.entries()) {
				if (pair[0].startsWith('attribute_')) {
					attributes[pair[0]] = pair[1]
				}
			}
			data.variation = attributes
		}

		// Populate data for grouped products
		if (form.classList.contains('grouped_form')) {
			// For grouped products, product_id is the ID of the parent. It wouldn't be added into cart
			data.product_id = formData.get('add-to-cart')

			// Populate products array with product IDs and quantities
			const products = {}
			for (const pair of formData.entries()) {
				if (pair[0].indexOf('quantity') > -1 && pair[1] > 0) {
					const product_id = pair[0].replace('quantity[', '').replace(']', '')
					products[product_id] = pair[1]
				}
			}
			data.products = products
			data.product_type = 'grouped'
		}

		if (data.product_type === 'grouped') {
			// If product type is grouped and data.products is empty, don't add to cart
			if (Object.keys(data.products).length === 0) {
				return
			}
		}

		// Populate other data inside the form for third party plugins (@see #862je3dz8; @since 1.7.2)
		for (const pair of formData.entries()) {
			// Skip product_id, quantity, variation_id, add-to-cart, and attributes
			if (
				pair[0] === 'product_id' ||
				pair[0] === 'quantity' ||
				pair[0] === 'variation_id' ||
				pair[0] === 'add-to-cart' ||
				pair[0].startsWith('attribute_')
			) {
				continue
			}

			// Ensure all inputs are added to data, some input might be checkboxes that support multiple values with same name
			if (data[pair[0]] === undefined) {
				data[pair[0]] = pair[1]
			} else {
				// Same key already exists

				// Convert to array if not already
				if (!Array.isArray(data[pair[0]])) {
					data[pair[0]] = [data[pair[0]]]
				}

				// Check if the value is same as the existing value
				if (Array.isArray(data[pair[0]]) && data[pair[0]].includes(pair[1])) {
					// Skip
					continue
				}

				// Add to array
				data[pair[0]].push(pair[1])
			}
		}
	} else {
		// Looping product - Only support simple products & product variations
		data.product_id = addToCartButton.dataset?.product_id || 0
		// Read the form value directly, including submissions before the quantity change listener runs.
		data.quantity =
			addToCartButton.closest('form.brx-loop-product-form')?.querySelector('input.qty')?.value ||
			addToCartButton.dataset?.quantity ||
			1
		data.product_type = addToCartButton.dataset?.product_type || 'simple'
	}

	// The PHP fragment filter is opt-in because it also runs for unrelated Woo requests. Capturing
	// the mounted targets here lets the updated in-memory cart render both fragment types before
	// Woo saves the session and sends this single response.
	const dynamicFragmentTargets = bricksWooGetDynamicFragmentTargets()

	if (dynamicFragmentTargets.length) {
		// Conditions inside a fragment can reference sibling query loops (for example,
		// `{query_results_count:ID}`). The target identifies where its data is stored, while
		// postId identifies the page being rendered. PHP needs both to resolve those references
		// against the current page instead of the context-less Woo AJAX request.
		data.postId = window.bricksData?.postId || 0
		data.bricks_woo_current_url = window.location.href.split('#')[0]
		data.bricks_woo_refresh_dynamic_fragments = '1'
		data.fragments = JSON.stringify(dynamicFragmentTargets)
	}

	// Trigger woo adding_to_cart event
	jQuery('body').trigger('adding_to_cart', [jQuery(addToCartButton), data])

	const url = woocommerce_params.wc_ajax_url
		.toString()
		.replace('%%endpoint%%', 'bricks_add_to_cart')

	// Queue cart mutations to prevent overlapping Woo session writes from losing products.
	bricksWooQueueCartMutation({
		type: 'POST',
		url: url,
		data: data,
		dataType: 'json',
		success: function (response) {
			// Redirect to product page if an error occurs
			if (response.error && response.product_url) {
				window.location = response.product_url
				return
			}

			if (response.error && response.notices) {
				// Custom event
				jQuery('body').trigger('bricks_add_to_cart_error', [
					response.notices,
					jQuery(addToCartButton)
				])
				return
			}

			// Add to cart successfully
			// Redirect to cart option from woo settings if enabled
			if (
				typeof wc_add_to_cart_params !== 'undefined' &&
				wc_add_to_cart_params.cart_redirect_after_add === 'yes' &&
				wc_add_to_cart_params.cart_url
			) {
				window.location = wc_add_to_cart_params.cart_url
				return
			}

			// Bricks selectors are page-specific. Keep them out of Woo's `added_to_cart` payload
			// because Woo persists that payload in sessionStorage and reuses it on other pages.
			const { cartFragments, dynamicFragments } = bricksWooSplitCartFragments(response.fragments)
			const allDynamicFragmentsUpdated = bricksWooInstallDynamicFragments(
				dynamicFragments,
				dynamicFragmentTargets,
				'added_to_cart'
			)

			// Replace native/third-party fragments and trigger Woo's compatibility event.
			if (response.fragments) {
				bricksWooReplaceFragments(cartFragments)
				jQuery('body').trigger('wc_fragments_refreshed', [
					{
						skipDynamicRefresh: allDynamicFragmentsUpdated
					}
				])
			}

			// Save the notices to window.bricksWooCommerce.addedToCartNotices
			if (
				response.notices &&
				typeof response.notices === 'string' &&
				response.notices.length > 0 &&
				window.bricksWooCommerce.addedToCartNotices !== undefined
			) {
				window.bricksWooCommerce.addedToCartNotices = response.notices
			}

			// The trailing options object is ignored by Woo and third parties. Bricks reads it to
			// suppress the second Dynamic Fragment request when the combined response was complete.
			jQuery('body').trigger('added_to_cart', [
				cartFragments,
				response.cart_hash,
				jQuery(addToCartButton),
				{
					skipDynamicRefresh: allDynamicFragmentsUpdated
				}
			])
		},
		error: function (response) {
			// Redirect to product page if an error occurs
			if (response.error && response.product_url) {
				window.location = response.product_url
			}
		},
		complete: function () {}
	})
}

/**
 * Overwrite WooCommerce wc_checkout_form.submit_error & wc_checkout_form.scroll_to_notices
 *
 * So error messages are displayed correctly in the Bricks WC notice element.
 *
 * @since 1.8.4
 */
function bricksWooCheckoutSubmitBehavior() {
	// Return: Not the checkout page
	if (typeof wc_checkout_params == 'undefined' || !wc_checkout_params.is_checkout) {
		return
	}

	// Get checkout form
	const $form = jQuery('form.checkout')

	if (!$form.length) {
		return
	}

	/**
	 * Use jQuery event to retrieve the wc_checkout_form object so we can overwrite its methods
	 * woocommerce/assets/js/frontend/checkout.js
	 *
	 * Just execute once, so we use .one() instead of .on()
	 * Hopefully no other plugins overwrites this event.
	 */
	$form.one('checkout_place_order', function (event, wc_checkout_form) {
		// Check if wc_checkout_form is an object
		if (typeof wc_checkout_form !== 'object') {
			return
		}

		// Check if wc_checkout_form has submit_error method
		if (typeof wc_checkout_form.submit_error !== 'function') {
			return
		}

		// Now overwrite submit_error method
		wc_checkout_form.submit_error = function (error_message) {
			bricksShowNotice(error_message)

			// These are the default actions
			wc_checkout_form.$checkout_form.removeClass('processing').unblock()
			wc_checkout_form.$checkout_form
				.find('.input-text, select, input:checkbox')
				.trigger('validate')
				.trigger('blur')
			wc_checkout_form.scroll_to_notices()
			jQuery(document.body).trigger('checkout_error', [error_message])
		}

		// Bricks notice markup should use the same scroll target as Woo's checkout errors.
		if (typeof wc_checkout_form.scroll_to_notices !== 'function') {
			return
		}

		wc_checkout_form.scroll_to_notices = bricksScrollToNotices
	})
}

/**
 * Listen to looping product quantity change event
 *
 * Use BricksFunction class (@since 1.9.2)
 *
 * @since 1.9
 */
const bricksWooLoopQtyListenerFn = new BricksFunction({
	parentNode: document,
	selector: '.brx-loop-product-form input.qty',
	windowVariableCheck: ['bricksWooCommerce.useQtyInLoop'],
	eachElement: (quantityInput) => {
		/// Change quantity function
		const updateQuantity = (event) => {
			// Our identifier
			const form = event.target.closest('form.brx-loop-product-form')

			if (form) {
				const value = event.target.value
				const addToCartButton = form.querySelector('.add_to_cart_button')

				if (addToCartButton) {
					// Retain link support for filtered markup and pages cached before the button change.
					if (addToCartButton.tagName === 'A') {
						const addToCartURL = new URL(addToCartButton.href)
						addToCartURL.searchParams.set('quantity', value)
						addToCartButton.href = addToCartURL.toString()
					}

					// Update data-quantity attribute for AJAX add to cart
					addToCartButton.setAttribute('data-quantity', value)
				}
			}
		}

		// Add event listener to all quantity inputs
		quantityInput.addEventListener('change', updateQuantity)
	}
})

/**
 * For Checkout Coupon toggleable feature
 *
 * @since 1.11: Separate from bricksCheckoutCouponForm so it can be used in template preveiew too
 */
const bricksCheckoutCouponToggleFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-woocommerce-checkout-coupon .coupon-toggle',
	subscribejQueryEvents: ['init_checkout', 'updated_checkout'], // Reinit in case the element place inside a fragment that is reloaded by WooCommerce (@since 2.4)
	eachElement: (element) => {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.slideToggle === 'undefined') {
			return
		}

		const checkouCouponElement = element.closest('.brxe-woocommerce-checkout-coupon')

		if (!checkouCouponElement) {
			return
		}

		const couponDiv = checkouCouponElement.querySelector('.coupon-div')

		if (!couponDiv) {
			return
		}

		element.addEventListener('click', function (event) {
			event.preventDefault()
			jQuery(couponDiv).slideToggle(400, function () {
				// Check if couponDiv is visible, then update aria-expanded
				if (jQuery(couponDiv).is(':visible')) {
					element.setAttribute('aria-expanded', 'true')
				} else {
					element.setAttribute('aria-expanded', 'false')
				}
				jQuery(couponDiv).find(':input:eq(0)').trigger('focus')
			})
		})
	}
})

function bricksCheckoutCouponToggle() {
	bricksCheckoutCouponToggleFn.run()
}

/**
 * WooCommerce product gallery: Add accessible names to FlexSlider controls.
 *
 * @since 2.4
 */
function bricksWooProductGallerySetA11yLabels(slider) {
	const prevLabel = slider.getAttribute('data-prev-label')
	const nextLabel = slider.getAttribute('data-next-label')
	const prevButton = slider.querySelector('.flex-direction-nav .flex-prev')
	const nextButton = slider.querySelector('.flex-direction-nav .flex-next')

	if (prevButton && prevLabel) {
		prevButton.setAttribute('aria-label', prevLabel)
		prevButton.querySelectorAll('i, svg').forEach((icon) => {
			icon.setAttribute('aria-hidden', 'true')
		})
	}

	if (nextButton && nextLabel) {
		nextButton.setAttribute('aria-label', nextLabel)
		nextButton.querySelectorAll('i, svg').forEach((icon) => {
			icon.setAttribute('aria-hidden', 'true')
		})
	}
}

/**
 * Cart quantity up/down
 *
 * @since 1.11
 */
const bricksCheckoutCouponFormFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-woocommerce-checkout-coupon .coupon-form',
	subscribejQueryEvents: ['init_checkout', 'updated_checkout'], // Reinit in case the element place inside a fragment that is reloaded by WooCommerce (@since 2.4)
	eachElement: (form) => {
		if (
			!bricksIsFrontend ||
			typeof jQuery == 'undefined' ||
			typeof wc_checkout_params == 'undefined'
		) {
			return
		}

		const couponElement = form.closest('.brxe-woocommerce-checkout-coupon')
		const couponDiv = form.closest('.brxe-woocommerce-checkout-coupon').querySelector('.coupon-div')
		const applyButton = form.querySelector('button[name="apply_coupon"]')
		const couponInput = form.querySelector('input[name="coupon_code"]')

		if (!applyButton || !couponInput || !couponDiv || !couponElement) {
			return
		}

		// Prevent duplicate listeners on the same fragment node.
		if (form.dataset.brxCheckoutCouponInit === '1') {
			return
		}
		form.dataset.brxCheckoutCouponInit = '1'

		const toggleAble = couponElement.querySelector('.coupon-toggle')

		// Remove all notices when removed_coupon_in_checkout is triggered
		jQuery(document.body).on('removed_coupon_in_checkout', function () {
			jQuery(couponDiv).find('.woocommerce-notices-wrapper').remove()
		})

		// Off default remove coupon behavior or the notice will be unstyle and not controlleable
		jQuery(document.body).off('click', '.woocommerce-remove-coupon')

		const applyCoupon = () => {
			let $couponDiv = jQuery(couponDiv)

			if ($couponDiv.hasClass('processing')) {
				return
			}

			$couponDiv.addClass('processing').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			})

			let data = {
				coupon_code: couponInput.value,
				security: wc_checkout_params.apply_coupon_nonce,
				billing_email: jQuery('form.checkout').find('input[name="billing_email"]').val()
			}

			jQuery.ajax({
				type: 'POST',
				url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
				// Woo 11.2 encodes apostrophes to avoid firewall rejection. This custom handler bypasses
				// Woo's serializer; replace only literal apostrophes after $.param() to avoid double encoding.
				// https://github.com/woocommerce/woocommerce/pull/68365
				data: jQuery.param(data).replace(/'/g, '%27'),
				success: function (code) {
					// Remove any notices added previously
					$couponDiv.find('.woocommerce-notices-wrapper').remove()
					$couponDiv.removeClass('processing').unblock()

					if (code) {
						// Add notices
						bricksShowNotice(code)

						// Scroll to notices as the coupon form might be far down the page
						bricksScrollToNotices()

						if (toggleAble) {
							$couponDiv.slideUp()
							toggleAble.setAttribute('aria-expanded', 'false')
						}

						// Woo 11.2 adds an error event with the coupon code and unchanged notice HTML.
						// Keep the applied event below on both outcomes: extensions still rely on that contract.
						// https://github.com/woocommerce/woocommerce/pull/66285
						if (code.includes('woocommerce-error') || code.includes('is-error')) {
							jQuery(document.body).trigger('errored_coupon_in_checkout', [data.coupon_code, code])
						}

						jQuery(document.body).trigger('applied_coupon_in_checkout', [data.coupon_code])
						jQuery(document.body).trigger('update_checkout', { update_shipping_method: false })
					}
				},
				dataType: 'html'
			})
		}

		// Prevent default form submit
		applyButton.addEventListener('click', function (event) {
			event.preventDefault()
			applyCoupon()
		})

		// Enter in coupon field applies coupon instead of submitting checkout. (@since 2.4)
		couponInput.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter') {
				return
			}

			event.preventDefault()
			event.stopPropagation()
			applyCoupon()
		})

		// Same as native remove coupon function but with custom notice handling
		const removeCoupon = (e) => {
			e.preventDefault()

			const $container = jQuery('form.checkout').find('.woocommerce-checkout-review-order')
			const coupon = e.target.getAttribute('data-coupon')

			$container.addClass('processing').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			})

			var data = {
				security: wc_checkout_params.remove_coupon_nonce,
				coupon: coupon
			}

			jQuery.ajax({
				type: 'POST',
				url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'remove_coupon'),
				// Removal also bypasses Woo's serializer; apostrophes in coupon codes need the same firewall protection.
				data: jQuery.param(data).replace(/'/g, '%27'),
				success: function (code) {
					$container.removeClass('processing').unblock()

					if (code) {
						bricksShowNotice(code)
						bricksScrollToNotices()

						jQuery(document.body).trigger('removed_coupon_in_checkout', [data.coupon])
						jQuery(document.body).trigger('update_checkout', { update_shipping_method: false })

						// Remove coupon code from coupon field
						jQuery('form.checkout').find('input[name="coupon_code"]').val('')
					}
				},
				error: function (jqXHR) {
					if (wc_checkout_params.debug_mode) {
						/* jshint devel: true */
						console.log(jqXHR.responseText)
					}
				},
				dataType: 'html'
			})
		}

		// Use jQuery event to remove coupon
		jQuery(document.body).on('click', '.woocommerce-remove-coupon', removeCoupon)
	}
})

function bricksCheckoutCouponForm() {
	bricksCheckoutCouponFormFn.run()
}

/**
 * Helper function: Check if a node is blocked for processing.
 *
 * @param {JQuery Object} $node
 * @return {bool} True if the DOM Element is UI Blocked, false if not.
 *
 * @since 2.4
 */
const bricksWooCartIsBlocked = function ($node) {
	return $node.is('.processing') || $node.parents('.processing').length
}

/**
 * Helper function: Block a node visually for processing.
 *
 * @param {JQuery Object} $node
 *
 * @since 2.4
 */
const bricksWooCartBlock = function ($node) {
	if (!bricksWooCartIsBlocked($node)) {
		$node.addClass('processing')

		if (typeof $node.block === 'function') {
			$node.block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			})
		}
	}
}

/**
 * Unblock a node after processing is complete.
 *
 * @param {JQuery Object} $node
 *
 * @since 2.4
 */
const bricksWooCartUnblock = function ($node) {
	$node.removeClass('processing')

	if (typeof $node.unblock === 'function') {
		$node.unblock()
	}
}

function bricksWooCartGetNoticeWrapper($beforeNode = null) {
	const $noticeWrapper = jQuery('.brxe-woocommerce-notice').first()

	if ($noticeWrapper.length) {
		return $noticeWrapper
	}

	const $wooNoticeWrapper = jQuery('.woocommerce-notices-wrapper').first()

	if ($wooNoticeWrapper.length) {
		return $wooNoticeWrapper
	}

	const $newNoticeWrapper = jQuery('<div class="woocommerce-notices-wrapper"></div>')
	let $insertBefore = $beforeNode && $beforeNode.length ? $beforeNode : jQuery()

	if (!$insertBefore.length) {
		$insertBefore = jQuery('.woocommerce-cart-form').first()
	}

	if (!$insertBefore.length) {
		$insertBefore = jQuery('.wc-empty-cart-message').first()
	}

	if ($insertBefore.length) {
		$insertBefore.before($newNoticeWrapper)
	}

	return $newNoticeWrapper
}

function bricksWooCartExtractNoticesHTML(html) {
	const $html = jQuery(html)
	const $newNoticeWrapper = $html
		.filter('.brxe-woocommerce-notice, .woocommerce-notices-wrapper')
		.add($html.find('.brxe-woocommerce-notice, .woocommerce-notices-wrapper'))
		.filter(function () {
			const $noticeWrapper = jQuery(this)

			return (
				$noticeWrapper.find('.woocommerce-error, .woocommerce-message, .woocommerce-info').length ||
				jQuery.trim($noticeWrapper.html()).length
			)
		})
		.first()

	if ($newNoticeWrapper.length) {
		return $newNoticeWrapper.html()
	}

	const $newStandaloneNotices = $html
		.filter('.woocommerce-error, .woocommerce-message, .woocommerce-info')
		.add($html.find('.woocommerce-error, .woocommerce-message, .woocommerce-info'))

	if ($newStandaloneNotices.length) {
		return jQuery('<div></div>').append($newStandaloneNotices).html()
	}

	return null
}

function bricksWooCartRenderNotices(noticesHTML, options = {}) {
	const $noticeWrapper = bricksWooCartGetNoticeWrapper(options.$beforeNode)

	if (!$noticeWrapper.length) {
		return false
	}

	$noticeWrapper.html(noticesHTML || '')
	bricksEnhanceCheckoutNotices($noticeWrapper[0])

	return !!noticesHTML
}

/**
 * Focus and smoothly reveal the first cart notice after a Bricks-owned cart update.
 *
 * WooCommerce focuses its populated live region after `updated_wc_div`. Firefox scrolls that
 * focus target into view immediately, which interrupts the intended smooth notice animation.
 * Focusing it first with `preventScroll` preserves the accessibility handoff and leaves the
 * existing Woo scroll helper in control of viewport movement.
 *
 * @see https://github.com/woocommerce/woocommerce/issues/21988
 * @since 2.4 #86cbbtgg2
 *
 * @returns {void}
 */
function bricksWooCartScrollToNotices() {
	const $noticeWrapper = jQuery('.brxe-woocommerce-notice, .woocommerce-notices-wrapper').first()

	if ($noticeWrapper.length) {
		const noticeSelector =
			'.woocommerce-message[role="alert"], .woocommerce-error[role="alert"], .wc-block-components-notice-banner[role="alert"]'
		const notice = $noticeWrapper.find(noticeSelector).addBack(noticeSelector).first()[0]

		if (notice) {
			// Keep the live-region focus expected by assistive technology without allowing Firefox
			// to perform its own immediate viewport jump.
			notice.setAttribute('tabindex', '-1')
			notice.focus({ preventScroll: true })
		}

		// Retain WooCommerce's established animation duration and header offset behavior.
		jQuery.scroll_to_notices($noticeWrapper)
	}
}

/**
 * Refresh the cart form, totals, notices, and Bricks Dynamic Fragments from one cart response.
 *
 * @param {Element|null} cartForm Cart form that initiated the update.
 * @param {Object} options Update behavior.
 * @param {boolean} options.forceUpdateCart Add WooCommerce's update-cart submit value.
 * @param {string} options.noticesHTML Explicit notice markup from a preceding request.
 * @param {boolean} options.preserveNotices Keep existing notices when the response has none.
 * @param {boolean} options.scrollToNotices Focus and reveal notices after the final queued update.
 * @param {boolean} queueHandoff Whether this call is starting an already queued update.
 * @returns {Object|undefined} Promise for the complete coalesced update cycle, or undefined without jQuery.
 */
function bricksWooCartAjaxUpdate(cartForm = null, options = {}, queueHandoff = false) {
	if (typeof jQuery == 'undefined') {
		return
	}

	const resolveWithoutRequest = () => {
		const completionDeferred = bricksWooCartAjaxUpdate.completionDeferred

		if (queueHandoff && completionDeferred) {
			// A preceding response can empty the cart before its queued update starts. With no form
			// left to submit, close the owned cycle instead of leaving every caller pending forever.
			bricksWooCartAjaxUpdate.xhr = null
			bricksWooCartAjaxUpdate.pending = null
			bricksWooCartAjaxUpdate.completionDeferred = null
			completionDeferred.resolve()

			return completionDeferred.promise()
		}

		return jQuery.Deferred().resolve().promise()
	}

	let $cartForm = cartForm
		? jQuery(cartForm).closest('.woocommerce-cart-form')
		: jQuery('.woocommerce-cart-form').first()
	const $cartTotals = jQuery('div.cart_totals')

	if ($cartForm.length && !document.documentElement.contains($cartForm[0])) {
		$cartForm = jQuery('.woocommerce-cart-form').first()
	}

	if (!$cartForm.length) {
		if (
			typeof options.noticesHTML === 'string' &&
			(options.noticesHTML || !options.preserveNotices)
		) {
			bricksWooCartRenderNotices(options.noticesHTML)
		}

		return resolveWithoutRequest()
	}

	const cartUrl = $cartForm.attr('action') || window.wc_cart_params?.cart_url

	if (!cartUrl) {
		if (
			typeof options.noticesHTML === 'string' &&
			(options.noticesHTML || !options.preserveNotices)
		) {
			bricksWooCartRenderNotices(options.noticesHTML, { $beforeNode: $cartForm })
		}

		return resolveWithoutRequest()
	}

	// Every caller in one update cycle waits for the same completion. Returning the current jqXHR
	// would let its callbacks run before an update coalesced behind it has even started.
	const completionDeferred = bricksWooCartAjaxUpdate.completionDeferred || jQuery.Deferred()
	bricksWooCartAjaxUpdate.completionDeferred = completionDeferred

	if (bricksWooCartAjaxUpdate.xhr) {
		const pendingUpdate = bricksWooCartAjaxUpdate.pending || { options: {} }
		const pendingOptions = Object.assign({}, pendingUpdate.options, options)

		if (
			typeof options.noticesHTML !== 'string' &&
			typeof pendingUpdate.options.noticesHTML === 'string'
		) {
			pendingOptions.noticesHTML = pendingUpdate.options.noticesHTML
		}

		if (pendingUpdate.options.preserveNotices || options.preserveNotices) {
			pendingOptions.preserveNotices = true
		}

		pendingUpdate.cartForm = cartForm
		pendingUpdate.options = pendingOptions
		bricksWooCartAjaxUpdate.pending = pendingUpdate

		// Coalescing here prevents overlapping form submissions from applying stale cart HTML.
		// All callers receive the cycle promise, which settles after the final authoritative response.
		return completionDeferred.promise()
	}

	// The cart response replaces its regions before `updated_wc_div`, so remember the live width first. (#86carzqa4; @since 2.4)
	bricksWooProgressBarState.remember()

	bricksWooCartBlock($cartForm)
	bricksWooCartBlock($cartTotals)

	let data = $cartForm.serialize()

	if (options?.forceUpdateCart && data.indexOf('update_cart=') === -1) {
		data += (data.length ? '&' : '') + 'update_cart=1'
	}

	const updateCartTotalsHTML = (html) => {
		const $newCartTotals = jQuery(html).find('div.cart_totals').first()

		if ($newCartTotals.length && $cartTotals.length) {
			$cartTotals.replaceWith($newCartTotals)
		}

		jQuery(document.body).trigger('updated_cart_totals')
	}

	const updateCartFormHTML = (html) => {
		const $newCartForm = jQuery(html).find('.woocommerce-cart-form').first()

		if ($newCartForm.length) {
			$cartForm.replaceWith($newCartForm)
			return
		}

		const $html = jQuery(html)
		const $emptyCartMessage = $html
			.filter('.wc-empty-cart-message')
			.add($html.find('.wc-empty-cart-message'))
			.first()
		const $emptyCart = $emptyCartMessage.closest('.woocommerce')

		if (!$emptyCartMessage.length) {
			return
		}

		const $replacement = $emptyCart.length ? $emptyCart : $emptyCartMessage
		const $cartWrapper = $cartForm.closest('.woocommerce')

		if ($cartWrapper.length) {
			$cartWrapper.replaceWith($replacement)
		} else {
			$cartForm.replaceWith($replacement)
		}

		jQuery(document.body).trigger('wc_cart_emptied')
	}

	const updateCartNoticesHTML = (html) => {
		const hasExplicitNotices =
			typeof options.noticesHTML === 'string' && (options.noticesHTML || !options.preserveNotices)
		const noticesHTML = hasExplicitNotices
			? options.noticesHTML
			: bricksWooCartExtractNoticesHTML(html)

		if (noticesHTML !== null) {
			bricksWooCartRenderNotices(noticesHTML, {
				$beforeNode: jQuery('.woocommerce-cart-form').first()
			})
			return
		}

		if (!options.preserveNotices) {
			bricksWooCartRenderNotices('', { $beforeNode: jQuery('.woocommerce-cart-form').first() })
		}
	}

	let requestOutcome = null

	// jQuery runs beforeSend/global ajaxSend handlers before returning its jqXHR. Claim ownership
	// first so a synchronous handler cannot re-enter this function and start a parallel update.
	bricksWooCartAjaxUpdate.xhr = true

	const requestXhr = jQuery.ajax({
		type: $cartForm.attr('method') || 'POST',
		url: cartUrl,
		data,
		dataType: 'html',
		success: function (response) {
			updateCartFormHTML(response)
			updateCartTotalsHTML(response)
			updateCartNoticesHTML(response)

			const hasRestoredProgressBars = bricksWooProgressBarState.restoreRemembered()
			// The full cart response already contains fresh Dynamic Fragments; avoid a redundant request. (#86carzqa4; @since 2.4)
			const dynamicFragmentResult = bricksWooReplaceDynamicFragmentsFromHTML(response)

			if (dynamicFragmentResult.targets.length && typeof bricksRunAllFunctions === 'function') {
				bricksRunAllFunctions()
			} else if (hasRestoredProgressBars && typeof bricksProgressBar === 'function') {
				bricksProgressBar()
			}

			bricksWooSkipNextDynamicCartRefresh = dynamicFragmentResult.allTargetsReplaced

			if (dynamicFragmentResult.allTargetsReplaced) {
				document.body.dispatchEvent(
					new CustomEvent('bricks/woocommerce/fragments/refreshed', {
						detail: {
							fragments: dynamicFragmentResult.fragments,
							targets: dynamicFragmentResult.targets,
							sourceEvent: 'updated_wc_div'
						}
					})
				)
			}

			jQuery(document.body).trigger('updated_wc_div')
		},
		complete: function () {
			const pendingUpdate = bricksWooCartAjaxUpdate.pending

			bricksWooCartAjaxUpdate.pending = null

			bricksWooCartUnblock($cartForm)
			bricksWooCartUnblock($cartTotals)

			if (pendingUpdate) {
				// Keep the completed xhr as a busy sentinel until this microtask starts the pending
				// update. Synchronous Woo handlers can still enqueue behind it, while a later browser
				// event cannot overtake it during the handoff.
				Promise.resolve().then(() => {
					bricksWooCartAjaxUpdate.xhr = null
					bricksWooCartAjaxUpdate(pendingUpdate.cartForm, pendingUpdate.options, true)
				})

				return
			}

			bricksWooCartAjaxUpdate.xhr = null

			// A queued response will replace the notice again. Waiting for the final request avoids
			// competing focus changes and overlapping scroll animations during rapid quantity edits.
			if (options.scrollToNotices && !pendingUpdate) {
				bricksWooCartScrollToNotices()
			}

			// Clear the shared reference before firing callbacks so a callback may safely begin a new
			// independent cycle. The final request outcome supplies the familiar jQuery arguments.
			bricksWooCartAjaxUpdate.completionDeferred = null

			if (requestOutcome?.resolved) {
				completionDeferred.resolveWith(requestOutcome.context, requestOutcome.args)
			} else {
				completionDeferred.rejectWith(requestOutcome?.context, requestOutcome?.args || [])
			}
		}
	})

	bricksWooCartAjaxUpdate.xhr = requestXhr

	// jQuery settles Deferred callbacks before the AJAX complete callback. Capture that result so
	// the shared cycle promise can mirror the final request only after the queue is truly empty.
	requestXhr
		.done(function (...args) {
			requestOutcome = { resolved: true, context: this, args }
		})
		.fail(function (...args) {
			requestOutcome = { resolved: false, context: this, args }
		})

	return completionDeferred.promise()
}

/**
 * Keep a manually entered cart quantity inside its rendered WooCommerce limits.
 *
 * The native cart form relies on browser validation before submit. Bricks updates the cart
 * from a change event instead, so normalize the value before starting that AJAX lifecycle.
 *
 * @since 2.4
 *
 * @param {HTMLInputElement} quantityInput Quantity input to normalize.
 * @return {boolean} Whether the normalized value differs from the rendered cart quantity.
 */
function bricksWooNormalizeCartQuantityInput(quantityInput) {
	const renderedValue = parseFloat(quantityInput.defaultValue)
	const enteredValue = parseFloat(quantityInput.value)
	const min = parseFloat(quantityInput.getAttribute('min'))
	const max = parseFloat(quantityInput.getAttribute('max'))

	if (!Number.isFinite(enteredValue)) {
		quantityInput.value = Number.isFinite(renderedValue) ? renderedValue : ''
		bricksWooUpdateQuantityButtonStates(quantityInput)

		return false
	}

	let normalizedValue = enteredValue

	if (Number.isFinite(min)) {
		normalizedValue = Math.max(normalizedValue, min)
	}

	if (Number.isFinite(max)) {
		normalizedValue = Math.min(normalizedValue, max)
	}

	quantityInput.value = normalizedValue
	bricksWooUpdateQuantityButtonStates(quantityInput)

	return !Number.isFinite(renderedValue) || normalizedValue !== renderedValue
}

/**
 * Auto-update the cart when Bricks cart quantity inputs change.
 *
 * @since 2.4
 */
function bricksWooCartQuantityAutoUpdate() {
	if (typeof jQuery == 'undefined') {
		return
	}

	const timers = new WeakMap()

	document.addEventListener('change', function (event) {
		const quantityInput = event.target.closest('.brx-woo-cart-quantity-update .qty')

		if (!quantityInput || quantityInput.readOnly || quantityInput.disabled) {
			return
		}

		if (!bricksWooNormalizeCartQuantityInput(quantityInput)) {
			return
		}

		const cartForm = quantityInput.closest('.woocommerce-cart-form')

		if (!cartForm) {
			bricksWooDetachedCartQuantityAjaxUpdate(quantityInput)
			return
		}

		const updateCartButton = cartForm.querySelector('button[name="update_cart"]')

		if (updateCartButton) {
			updateCartButton.removeAttribute('disabled')
			updateCartButton.setAttribute('aria-disabled', 'false')
		}

		if (timers.has(cartForm)) {
			clearTimeout(timers.get(cartForm))
		}

		timers.set(
			cartForm,
			setTimeout(() => {
				bricksWooCartAjaxUpdate(cartForm, {
					forceUpdateCart: true,
					scrollToNotices: true
				})
			}, 200)
		)
	})
}

/**
 * Remove cart coupons via AJAX and refresh the cart UI.
 *
 * @since 2.4
 */
function bricksWooCartRemoveCoupon() {
	if (typeof jQuery == 'undefined' || typeof wc_cart_params == 'undefined') {
		return
	}

	if (
		!bricksIsFrontend ||
		!wc_cart_params.remove_coupon_nonce ||
		!wc_cart_params.wc_ajax_url ||
		document.querySelector('form.checkout, form.woocommerce-checkout')
	) {
		return
	}

	const isErrorNotice = function (noticeHTML) {
		return (
			typeof noticeHTML === 'string' &&
			(noticeHTML.includes('woocommerce-error') || noticeHTML.includes('is-error'))
		)
	}

	if (bricksWooCartRemoveCoupon.bound) {
		return
	}

	bricksWooCartRemoveCoupon.bound = true

	const removeCoupon = function (event, removeButton) {
		const coupon = removeButton.getAttribute('data-coupon')

		if (!coupon) {
			return
		}

		event.preventDefault()
		event.stopImmediatePropagation()

		const $wrapper = jQuery(removeButton).closest('.cart_totals')

		if (bricksWooCartIsBlocked($wrapper)) {
			return
		}

		bricksWooCartBlock($wrapper)

		let noticeHTML = ''

		jQuery.ajax({
			type: 'POST',
			url: wc_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'remove_coupon'),
			// Preserve the Woo 11.2 apostrophe encoding for this custom cart removal request as well.
			// https://github.com/woocommerce/woocommerce/pull/68365
			data: jQuery
				.param({
					security: wc_cart_params.remove_coupon_nonce,
					coupon
				})
				.replace(/'/g, '%27'),
			success: function (code) {
				noticeHTML = code || ''

				jQuery(document.body).trigger('removed_coupon', [coupon])

				if (!isErrorNotice(noticeHTML)) {
					jQuery('#coupon_code')
						.val('')
						.removeClass('has-error')
						.removeAttr('aria-invalid')
						.removeAttr('aria-describedby')
						.closest('.coupon')
						.find('.coupon-error-notice')
						.remove()
				}
			},
			complete: function () {
				const cartUpdate = bricksWooCartAjaxUpdate(null, {
					noticesHTML: noticeHTML,
					preserveNotices: true
				})
				const afterCartUpdate = function () {
					bricksWooCartUnblock($wrapper)

					if (noticeHTML) {
						bricksWooCartRenderNotices(noticeHTML, {
							$beforeNode: jQuery('.woocommerce-cart-form').first()
						})
						bricksWooCartScrollToNotices()
					}
				}

				if (cartUpdate?.always) {
					cartUpdate.always(afterCartUpdate)
				} else {
					afterCartUpdate()
				}
			},
			dataType: 'html'
		})
	}

	const getBricksRemoveButton = function (target) {
		if (!target || typeof target.closest !== 'function') {
			return null
		}

		const removeButton = target.closest('.woocommerce-remove-coupon')

		if (!removeButton || removeButton.closest('a.woocommerce-remove-coupon')) {
			return null
		}

		return removeButton
	}

	document.addEventListener(
		'click',
		function (event) {
			const removeButton = getBricksRemoveButton(event.target)

			if (!removeButton) {
				return
			}

			removeCoupon(event, removeButton)
		},
		true
	)

	document.addEventListener(
		'keydown',
		function (event) {
			if (event.key !== ' ') {
				return
			}

			const removeButton = getBricksRemoveButton(event.target)

			if (!removeButton) {
				return
			}

			removeCoupon(event, removeButton)
		},
		true
	)
}

/**
 * Route Bricks cart remove links through the appropriate WooCommerce lifecycle.
 *
 * WooCommerce listens for `.woocommerce-cart-form .product-remove > a`. Bricks linkable
 * elements can put `.product-remove` on the link itself or on an inner icon, so create a
 * temporary native-shaped link and let WooCommerce handle native cart-form AJAX. Dynamic
 * Fragments, checkout fallbacks, and the legacy Bricks mini cart use the serialized Bricks
 * request path so stale failures can recover without an unexpected navigation.
 *
 * @since 2.4
 */
function bricksWooCartCustomRemoveLinks() {
	if (typeof jQuery === 'undefined') {
		return
	}

	if (bricksWooCartCustomRemoveLinks.bound) {
		return
	}

	bricksWooCartCustomRemoveLinks.bound = true

	const getWooAjaxUrl = function (endpoint) {
		const wcAjaxUrl =
			window.wc_cart_params?.wc_ajax_url ||
			window.wc_add_to_cart_params?.wc_ajax_url ||
			window.wc_checkout_params?.wc_ajax_url ||
			window.woocommerce_params?.wc_ajax_url ||
			''

		return wcAjaxUrl ? wcAjaxUrl.toString().replace('%%endpoint%%', endpoint) : ''
	}

	const getCartUrl = function () {
		return (
			window.wc_cart_params?.cart_url ||
			window.wc_add_to_cart_params?.cart_url ||
			window.wc_checkout_params?.cart_url ||
			''
		)
	}

	const getCartItemKey = function (removeLink) {
		const dataCartItemKey = removeLink.getAttribute('data-cart_item_key')

		if (dataCartItemKey) {
			return dataCartItemKey
		}

		try {
			return new URL(removeLink.href, window.location.href).searchParams.get('remove_item')
		} catch (error) {
			return ''
		}
	}

	const getCartNonce = function (removeLink) {
		try {
			return new URL(removeLink.href, window.location.href).searchParams.get('_wpnonce') || ''
		} catch (error) {
			return ''
		}
	}

	const checkoutUiSelector =
		'form.checkout, form.woocommerce-checkout, .woocommerce-checkout-review-order, #bricks-woo-checkout-order-summary'
	const dynamicFragmentSelector = '[data-brx-woo-fragment="true"]'
	const miniCartSelector = '.brxe-woocommerce-mini-cart'

	const hasCheckoutUi = function () {
		return !!document.querySelector(checkoutUiSelector)
	}

	const isCheckoutRemoveLink = function (removeLink) {
		return (
			!!removeLink.closest(checkoutUiSelector) ||
			(!!window.wc_checkout_params?.is_checkout && hasCheckoutUi())
		)
	}

	const isDynamicFragmentRemoveLink = function (removeLink) {
		return !!removeLink.closest(dynamicFragmentSelector)
	}

	const isMiniCartRemoveLink = function (removeLink) {
		return (
			removeLink.classList.contains('remove_from_cart_button') &&
			!!removeLink.closest(miniCartSelector)
		)
	}

	const isMiniCartEmpty = function (fragments = null) {
		const miniCartFragment = fragments?.['div.widget_shopping_cart_content']

		if (typeof miniCartFragment === 'string' && miniCartFragment.length) {
			return miniCartFragment.includes('woocommerce-mini-cart__empty-message')
		}

		return !!document.querySelector(
			'.widget_shopping_cart_content .woocommerce-mini-cart__empty-message'
		)
	}

	const getCustomRemoveLink = function (target) {
		if (!target || typeof target.closest !== 'function') {
			return null
		}

		const removeElement = target.closest('.product-remove')
		const removeLink =
			target.closest('a[href*="remove_item="]') || removeElement?.closest('a[href*="remove_item="]')
		const cartForm = removeLink?.closest('.woocommerce-cart-form')
		const isCheckoutRemove = removeLink ? isCheckoutRemoveLink(removeLink) : false
		const isDynamicFragmentRemove = removeLink ? isDynamicFragmentRemoveLink(removeLink) : false
		const isMiniCartRemove = removeLink ? isMiniCartRemoveLink(removeLink) : false
		const isNativeRemoveLink = removeLink?.classList.contains('remove')

		if (
			!removeLink ||
			(!cartForm && !isCheckoutRemove && !isDynamicFragmentRemove && !isMiniCartRemove)
		) {
			return null
		}

		// Native WooCommerce cart markup already matches `.woocommerce-cart-form .product-remove > a`.
		if (cartForm && removeLink.parentElement?.classList.contains('product-remove')) {
			return null
		}

		if (
			!isNativeRemoveLink &&
			!removeLink.classList.contains('product-remove') &&
			!removeLink.querySelector('.product-remove') &&
			(!removeElement || !removeLink.contains(removeElement))
		) {
			return null
		}

		return removeLink
	}

	const redirectToCart = function (removeLink) {
		window.location.href = getCartUrl() || removeLink.href
	}

	const recoverFailedRemoval = function (removeLink, scrollStates) {
		// A duplicate/stale cart-item key returns JSON without fragments. Re-read the authoritative
		// cart after all already queued mutations before falling back to Woo's cart-page redirect.
		// Reuse the original positions because the failed request may already have altered layout.
		bricksWooRefreshCartFragments({
			queue: true,
			refreshDynamicFragments: true,
			sourceEvent: 'removed_from_cart',
			scrollStates,
			onError() {
				redirectToCart(removeLink)
			}
		})
	}

	const triggerAjaxRemove = function (event, removeLink, options = {}) {
		const cartItemKey = getCartItemKey(removeLink)
		const removeUrl = getWooAjaxUrl('remove_from_cart')
		const dynamicFragmentTargets = options.refreshDynamicFragments
			? bricksWooGetDynamicFragmentTargets()
			: []
		// Snapshot at interaction time, before BlockUI or earlier queued removals can change the
		// scroll geometry. The WeakMap ensures only matching, still-mounted roots consume it.
		const dynamicFragmentScrollStates = bricksWooDynamicFragmentScrollState.captureMounted()

		if (!cartItemKey || !removeUrl) {
			return
		}

		event.preventDefault()
		event.stopImmediatePropagation()

		const $wrapper = jQuery(removeLink).closest(options.wrapperSelector || checkoutUiSelector)

		// Keep accepting rapid clicks while the wrapper is busy: each captured cart key is safe to
		// enqueue, whereas dropping clicks makes the visible cart diverge from user intent.
		if ($wrapper.length && !bricksWooCartIsBlocked($wrapper)) {
			bricksWooCartBlock($wrapper)
		}

		bricksWooQueueCartMutation({
			type: 'POST',
			url: removeUrl,
			data: {
				cart_item_key: cartItemKey,
				postId: window.bricksData?.postId || 0,
				bricks_woo_current_url: window.location.href.split('#')[0],
				bricks_woo_refresh_dynamic_fragments: dynamicFragmentTargets.length ? '1' : '',
				fragments: dynamicFragmentTargets.length ? JSON.stringify(dynamicFragmentTargets) : ''
			},
			success: function (response) {
				if (!response || !response.fragments) {
					recoverFailedRemoval(removeLink, dynamicFragmentScrollStates)
					return
				}

				const { cartFragments, dynamicFragments } = bricksWooSplitCartFragments(response.fragments)
				const allDynamicFragmentsUpdated = bricksWooInstallDynamicFragments(
					dynamicFragments,
					dynamicFragmentTargets,
					'removed_from_cart',
					dynamicFragmentScrollStates
				)

				jQuery(document.body).trigger('removed_from_cart', [
					cartFragments,
					response.cart_hash,
					jQuery(removeLink),
					{
						skipDynamicRefresh: allDynamicFragmentsUpdated
					}
				])

				if (options.redirectWhenEmpty && isMiniCartEmpty(response.fragments)) {
					redirectToCart(removeLink)
				}
			},
			error: function () {
				recoverFailedRemoval(removeLink, dynamicFragmentScrollStates)
			},
			complete: function () {
				if ($wrapper.length) {
					bricksWooCartUnblock($wrapper)
				}
			},
			dataType: 'json'
		})
	}

	const triggerCheckoutRemove = function (event, removeLink) {
		const checkoutForm =
			removeLink.closest('form.checkout, form.woocommerce-checkout') ||
			document.querySelector('form.checkout, form.woocommerce-checkout')
		const checkoutV2Root = checkoutForm?.closest(
			'.brxe-woocommerce-checkout-v2.brx-wc-checkout-v2--checkout'
		)
		const cartItemKey = getCartItemKey(removeLink)

		if (
			checkoutV2Root &&
			bricksWooPrepareCheckoutCartItemRemoval(
				checkoutForm,
				cartItemKey,
				removeLink,
				getCartNonce(removeLink)
			)
		) {
			event.preventDefault()
			event.stopImmediatePropagation()
			bricksWooRequestCheckoutCartUpdate(checkoutForm, 0)
			return
		}

		triggerAjaxRemove(event, removeLink, {
			wrapperSelector: checkoutUiSelector,
			redirectWhenEmpty: true
		})
	}

	const triggerDynamicFragmentRemove = function (event, removeLink) {
		triggerAjaxRemove(event, removeLink, {
			wrapperSelector: dynamicFragmentSelector,
			refreshDynamicFragments: true
		})
	}

	const triggerMiniCartRemove = function (event, removeLink) {
		// Woo's native handler redirects immediately when a removal returns no fragments. Owning
		// Bricks mini-cart removals here gives them the queue and authoritative recovery behavior.
		triggerAjaxRemove(event, removeLink, {
			wrapperSelector: miniCartSelector,
			refreshDynamicFragments: true
		})
	}

	const triggerNativeRemove = function (event, removeLink) {
		const cartForm = removeLink.closest('.woocommerce-cart-form')

		if (!cartForm) {
			return
		}

		event.preventDefault()
		event.stopImmediatePropagation()

		// Woo's native remove handler replaces the cart form and totals directly. Capture the live
		// Dynamic Fragment bars before that replacement; updated_wc_div restores them. (#86cb1pxd8)
		bricksWooProgressBarState.remember()

		const proxyWrapper = document.createElement('span')
		const proxyLink = document.createElement('a')

		proxyWrapper.className = 'product-remove'
		proxyWrapper.style.display = 'none'

		proxyLink.href = removeLink.href
		proxyLink.className = 'remove'
		proxyLink.setAttribute('role', removeLink.getAttribute('role') || 'button')
		;['aria-label', 'title', 'data-product_id', 'data-product_sku'].forEach((attribute) => {
			const value = removeLink.getAttribute(attribute)

			if (value) {
				proxyLink.setAttribute(attribute, value)
			}
		})

		proxyWrapper.appendChild(proxyLink)
		cartForm.appendChild(proxyWrapper)

		const proxyEvent = new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
			view: window
		})

		const handled = !proxyLink.dispatchEvent(proxyEvent)

		proxyWrapper.remove()

		if (!handled) {
			window.location.href = removeLink.href
		}
	}

	const triggerRemove = function (event, removeLink) {
		if (isCheckoutRemoveLink(removeLink) && !removeLink.closest('.woocommerce-cart-form')) {
			triggerCheckoutRemove(event, removeLink)
			return
		}

		if (isDynamicFragmentRemoveLink(removeLink) && !removeLink.closest('.woocommerce-cart-form')) {
			triggerDynamicFragmentRemove(event, removeLink)
			return
		}

		if (isMiniCartRemoveLink(removeLink)) {
			triggerMiniCartRemove(event, removeLink)
			return
		}

		triggerNativeRemove(event, removeLink)
	}

	document.addEventListener(
		'click',
		function (event) {
			const removeLink = getCustomRemoveLink(event.target)

			if (!removeLink) {
				return
			}

			triggerRemove(event, removeLink)
		},
		true
	)

	document.addEventListener(
		'keydown',
		function (event) {
			if (event.key !== ' ') {
				return
			}

			const removeLink = getCustomRemoveLink(event.target)

			if (!removeLink) {
				return
			}

			triggerRemove(event, removeLink)
		},
		true
	)
}

/**
 * Update cart via AJAX when coupon is applied on the cart page
 *
 * @since 2.0.2
 */
const bricksCartCouponFormFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-woocommerce-cart-coupon[data-ajax-update="true"]',
	subscribejQueryEvents: ['updated_wc_div'],
	eachElement: (form) => {
		if (typeof jQuery == 'undefined' || typeof wc_cart_params == 'undefined') {
			return
		}

		// Get elements inside the cart coupon form
		const couponInput = form.querySelector('input[name="coupon_code"]')
		const applyButton = form.querySelector('button[name="apply_coupon"]')

		// If there is no apply button or coupon input, abort
		if (!applyButton || !couponInput) {
			return
		}

		if (form.dataset.brxCartCouponInit === '1') {
			return
		}

		form.dataset.brxCartCouponInit = '1'

		const isErrorNotice = function (noticeHTML) {
			return (
				typeof noticeHTML === 'string' &&
				(noticeHTML.includes('woocommerce-error') || noticeHTML.includes('is-error'))
			)
		}

		const applyCoupon = () => {
			let $form = jQuery(form)

			// STEP: Block the form to prevent multiple submissions
			if (bricksWooCartIsBlocked($form)) {
				return
			}

			bricksWooCartBlock($form)

			// STEP: Prepare data to send
			const couponCode = couponInput.value.trim()
			const data = {
				security: wc_cart_params.apply_coupon_nonce,
				coupon_code: couponCode
			}

			jQuery.ajax({
				type: 'POST',
				url: wc_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
				// $.param() leaves apostrophes literal. Match Woo 11.2's firewall-safe checkout encoding
				// for the shared coupon endpoint without changing the coupon code passed to event listeners.
				data: jQuery.param(data).replace(/'/g, '%27'),
				success: function (code) {
					const noticeHTML = code || ''
					// Emit the error event before refreshing fragments so listeners receive the original response.
					// Woo 11.2 keeps applied_coupon on failure too; the existing event below must remain unconditional.
					// https://github.com/woocommerce/woocommerce/pull/66285
					if (isErrorNotice(noticeHTML)) {
						jQuery(document.body).trigger('errored_coupon', [couponCode, code])
					}

					const cartUpdate = bricksWooCartAjaxUpdate(null, {
						noticesHTML: noticeHTML,
						preserveNotices: true
					})
					const afterCartUpdate = function () {
						bricksWooCartUnblock($form)

						if (!isErrorNotice(noticeHTML)) {
							jQuery(couponInput)
								.val('')
								.removeClass('has-error')
								.removeAttr('aria-invalid')
								.removeAttr('aria-describedby')
								.closest('.coupon')
								.find('.coupon-error-notice')
								.remove()
						}

						if (noticeHTML) {
							bricksWooCartRenderNotices(noticeHTML, {
								$beforeNode: jQuery('.woocommerce-cart-form').first()
							})
							bricksWooCartScrollToNotices()
						}

						jQuery(document.body).trigger('applied_coupon', [couponCode])
					}

					if (cartUpdate?.always) {
						cartUpdate.always(afterCartUpdate)
					} else {
						afterCartUpdate()
					}
				},
				error: function () {
					bricksWooCartUnblock($form)
				},
				dataType: 'html'
			})
		}

		// Add event listener for the apply coupon button
		applyButton.addEventListener('click', function (event) {
			event.preventDefault()

			applyCoupon()
		})
	}
})

function bricksCartCouponForm() {
	bricksCartCouponFormFn.run()
}

const bricksCheckoutLoginToggleFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-woocommerce-checkout-login .login-toggle',
	eachElement: (element) => {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.slideToggle === 'undefined') {
			return
		}

		const checkoutLoginElement = element.closest('.brxe-woocommerce-checkout-login')

		if (!checkoutLoginElement) {
			return
		}

		const loginDiv = checkoutLoginElement.querySelector('.login-div')

		if (!loginDiv) {
			return
		}

		element.addEventListener('click', function (event) {
			event.preventDefault()
			jQuery(loginDiv).slideToggle(400, function () {
				// Check if loginDiv is visible, then update aria-expanded
				if (jQuery(loginDiv).is(':visible')) {
					element.setAttribute('aria-expanded', 'true')
				} else {
					element.setAttribute('aria-expanded', 'false')
				}
				jQuery(loginDiv).find(':input:eq(0)').trigger('focus')
			})
		})
	}
})

function bricksCheckoutLoginToggle() {
	bricksCheckoutLoginToggleFn.run()
}

/**
 * This is not a real form, as form inside Checkout form is not a allowed in HTML
 *
 * @since 1.11
 */
const bricksCheckoutLoginFormFn = new BricksFunction({
	parentNode: document,
	selector: '.brxe-woocommerce-checkout-login .login-div',
	eachElement: (loginDiv) => {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.slideToggle === 'undefined') {
			return
		}

		const loginElement = loginDiv.closest('.brxe-woocommerce-checkout-login')
		const loginButton = loginDiv.querySelector('button.woocommerce-form-login__submit')

		if (!loginElement || !loginButton) {
			return
		}

		/**
		 * Simulate a form submit for the login form which post to the same page
		 * The loginDiv is not a real form, as form inside Checkout form is not a allowed in HTML
		 */
		const login = () => {
			// STEP: Collect all input values inside the loginDiv
			const data = {}
			const inputs = loginDiv.querySelectorAll('input')
			let hasEmptyRequired = false

			// Use a for loop to be able to break when a required field is empty
			for (let i = 0; i < inputs.length; i++) {
				const input = inputs[i]
				// Check if required input is empty
				if (input.hasAttribute('required') && input.value === '') {
					// Trigger a native form validation
					input.reportValidity()
					// Set flag and break
					hasEmptyRequired = true
					break
				}

				// Handle checkbox
				if (input.type === 'checkbox' && !input.checked) {
					continue
				}

				data[input.name] = input.value
			}

			// Return if a required field is empty
			if (hasEmptyRequired) {
				return
			}

			// STEP: Include the button name and value (loginButton)
			data[loginButton.name] = loginButton.value

			// STEP: Create a fake form and submit it
			const form = document.createElement('form')
			form.method = 'POST'
			form.action = window.location.href

			// Ensure the form is not visible
			form.style.display = 'none'

			// Add all data to the form
			Object.keys(data).forEach((key) => {
				const input = document.createElement('input')
				input.name = key
				input.value = data[key]
				form.appendChild(input)
			})

			// Append the form to the body and submit it
			document.body.appendChild(form)
			form.submit()
		}

		// Prevent default form submit
		loginButton.addEventListener('click', function (event) {
			event.preventDefault()
			login()
		})
	}
})

function bricksCheckoutLoginForm() {
	bricksCheckoutLoginFormFn.run()
}

/**
 * Handle variation swatches interactions
 *
 * @since 2.0
 */
const bricksWooVariationSwatchesFn = new BricksFunction({
	parentNode: document,
	selector: '.bricks-variation-swatches',
	windowVariableCheck: ['bricksWooCommerce.useVariationSwatches'],
	eachElement: (swatchesContainer) => {
		const swatches = swatchesContainer.querySelectorAll('li')
		const originalSelect = swatchesContainer.nextElementSibling?.querySelector('select')
		const variationForm = swatchesContainer.closest('.variations_form')

		if (!swatches.length || !originalSelect) {
			return
		}

		const updateSwatchA11yState = (swatch) => {
			const button = swatch.querySelector(':scope > .bricks-swatch-button')

			if (!button) {
				return
			}

			const isDisabled = swatch.classList.contains('disabled')
			const isSelected = swatch.classList.contains('bricks-swatch-selected')

			button.disabled = isDisabled
			button.setAttribute('aria-disabled', String(isDisabled))
			button.setAttribute('aria-pressed', String(isSelected))

			if (!button.getAttribute('aria-label')) {
				const label =
					swatch.getAttribute('data-balloon') || swatch.textContent.trim() || swatch.dataset.value
				button.setAttribute('aria-label', label)
			}
		}

		const selectSwatch = (swatch) => {
			if (swatch.classList.contains('disabled')) {
				return
			}

			// Update swatch selection
			swatches.forEach((s) => s.classList.remove('bricks-swatch-selected'))
			swatch.classList.add('bricks-swatch-selected')

			// Update the original select - WooCommerce will handle emitting the change event
			originalSelect.value = swatch.dataset.value
			jQuery(originalSelect).trigger('change')
		}

		swatches.forEach((swatch) => updateSwatchA11yState(swatch))

		// Handle swatch click
		swatches.forEach((swatch) => {
			// Keep builder-configurable padding clickable. Keyboard activation still comes from the
			// nested native button and bubbles through this pointer handler.
			swatch.addEventListener('click', () => {
				selectSwatch(swatch)
			})
		})

		// Listen for changes on the original select (for reset)
		jQuery(originalSelect).on('change', () => {
			const value = originalSelect.value
			swatches.forEach((swatch) => {
				swatch.classList.toggle('bricks-swatch-selected', swatch.dataset.value === value)
				updateSwatchA11yState(swatch)
			})
		})

		// Only apply disabled states for variation forms
		if (variationForm) {
			// Get the attribute name from the select
			const attributeName = originalSelect.name

			// Listen for found_variation event to update available options and swatch images
			jQuery(variationForm).on('found_variation', function (event, variation) {
				updateAvailableOptions(swatchesContainer, variationForm, attributeName)
				updateSelectedImageSwatch(swatchesContainer, variation, attributeName)
			})

			// Listen for hide_variation event to update available options and reset swatch images
			jQuery(variationForm).on('hide_variation', function () {
				updateAvailableOptions(swatchesContainer, variationForm, attributeName)
				updateSelectedImageSwatch(swatchesContainer, null, attributeName)
			})

			// Listen for check_variations event to update available options
			jQuery(variationForm).on('check_variations', function () {
				updateAvailableOptions(swatchesContainer, variationForm, attributeName)
				updateSelectedImageSwatch(swatchesContainer, null, attributeName)
			})

			// Listen for woocommerce_update_variation_values to update available options
			jQuery(document).on('woocommerce_update_variation_values', function () {
				updateAvailableOptions(swatchesContainer, variationForm, attributeName)
				updateSelectedImageSwatch(swatchesContainer, null, attributeName)
			})

			// Initial update on load
			setTimeout(() => {
				updateAvailableOptions(swatchesContainer, variationForm, attributeName)
			}, 100)
		}
	}
})

/**
 * Update available options for variation swatches
 *
 * @since 2.0
 *
 * @param {HTMLElement} swatchesContainer The swatches container element
 * @param {HTMLElement} variationForm The variation form element
 * @param {string} attributeName The attribute name
 */
function updateAvailableOptions(swatchesContainer, variationForm, attributeName) {
	// Get all swatches
	const swatches = swatchesContainer.querySelectorAll('li')

	// Get the original select
	const originalSelect = swatchesContainer.nextElementSibling?.querySelector('select')

	if (!originalSelect) {
		return
	}

	// Get available options from the select
	const availableOptions = []

	// Loop through options and push available ones to array
	for (let i = 0; i < originalSelect.options.length; i++) {
		const option = originalSelect.options[i]

		// Skip empty option
		if (!option.value) {
			continue
		}

		// Check if option is disabled
		if (!option.disabled) {
			availableOptions.push(option.value)
		}
	}

	// Update swatches based on available options
	swatches.forEach((swatch) => {
		// Get swatch value
		const swatchValue = swatch.dataset.value

		// Set disabled class based on availability
		if (availableOptions.includes(swatchValue)) {
			swatch.classList.remove('disabled')
		} else {
			swatch.classList.add('disabled')
		}

		const button = swatch.querySelector('.bricks-swatch-button')

		if (button) {
			const isDisabled = swatch.classList.contains('disabled')
			button.disabled = isDisabled
			button.setAttribute('aria-disabled', String(isDisabled))
		}
	})
}

/**
 * Update selected image swatch source based on the matched variation
 *
 * @param {HTMLElement} swatchesContainer The swatches container element
 * @param {object|null} variation The variation data object (or null to reset)
 * @param {string} attributeName The attribute name (e.g. attribute_pa_pattern)
 */
function updateSelectedImageSwatch(swatchesContainer, variation, attributeName) {
	// Only apply to image-type swatches
	if (!swatchesContainer.classList.contains('bricks-swatch-image')) {
		return
	}

	// Get all swatches with variation-based images
	const variationSwatches = swatchesContainer.querySelectorAll('li[data-image-origin="variation"]')

	if (!variationSwatches.length) {
		return
	}

	// Process each variation-based swatch
	variationSwatches.forEach((swatch) => {
		const imgEl = swatch.querySelector('img')

		if (!imgEl) {
			return
		}

		// Cache the original src so we can restore it later
		if (!imgEl.dataset.origSrc) {
			imgEl.dataset.origSrc = imgEl.getAttribute('src')
		}

		// Skip if this variation does not match the swatch value (#86c44be92; @since 2.2)
		const swatchValue = swatch.dataset.value || ''
		const variationAttributeValue =
			variation && variation.attributes ? variation.attributes[attributeName] || null : null

		if (variationAttributeValue !== swatchValue) {
			return
		}

		// Use variation image if provided, otherwise restore to original
		if (variation && variation.image && variation.image.src) {
			imgEl.setAttribute('src', variation.image.src)
		} else {
			imgEl.setAttribute('src', imgEl.dataset.origSrc)
		}
	})
}

function bricksWooVariationSwatches() {
	bricksWooVariationSwatchesFn.run()
}

/**
 * Fix WooCommerce Clear button display when hidden
 *
 * The reset_variations button uses visibility: hidden when hidden, but still takes up space.
 * Check visibility and add display: none to properly hidethe button.
 *
 * @since 2.2
 */
const bricksWooResetVariationsDisplayFn = new BricksFunction({
	parentNode: document,
	selector: '.variations_form',
	eachElement: (form) => {
		const resetButton = form.querySelector('.reset_variations')

		if (!resetButton) {
			return
		}

		// Helper function to check visibility and set display
		const updateDisplay = () => {
			window.requestAnimationFrame(() => {
				const visibility = window.getComputedStyle(resetButton).visibility

				resetButton.style.display = visibility === 'hidden' ? 'none' : ''
			})
		}

		updateDisplay()

		jQuery(form).on('reset_data wc_variation_form woocommerce_variation_has_changed', updateDisplay)
	}
})

function bricksWooResetVariationsDisplay() {
	bricksWooResetVariationsDisplayFn.run()
}

/**
 * Update standalone {woo_product_sku} dynamic tags when a variation is selected.
 *
 * WooCommerce only updates ".product_meta .sku"; Bricks text elements can output
 * the SKU elsewhere, so keep this scoped to the active variation form's product.
 *
 * @since 2.3.9 (#86cag8x2f)
 */
const bricksWooProductSkuVariationFn = new BricksFunction({
	parentNode: document,
	selector: '.variations_form',
	eachElement: (form) => {
		if (typeof jQuery === 'undefined' || form.dataset.bricksWooProductSkuVariation) {
			return
		}

		const product = form.closest('.product')
		const productId = form.getAttribute('data-product_id') || ''

		if (!product || !productId) {
			return
		}

		form.dataset.bricksWooProductSkuVariation = 'true'

		const getLoopIndex = (element) => {
			const loopElement = element.closest('[data-query-loop-index]')

			return loopElement ? loopElement.getAttribute('data-query-loop-index') : ''
		}

		const formLoopIndex = getLoopIndex(form)

		const getSkuElements = () => {
			return Array.from(product.querySelectorAll('.brx-woo-product-sku')).filter((sku) => {
				if (sku.dataset.brxWooProductId !== productId) {
					return false
				}

				if (sku.closest('.product') !== product) {
					return false
				}

				if (getLoopIndex(sku) !== formLoopIndex) {
					return false
				}

				const skuVariationForm = sku.closest('.variations_form')

				return !skuVariationForm || skuVariationForm === form
			})
		}

		const cacheOriginalSku = (sku) => {
			if (!sku.hasAttribute('data-brx-woo-product-sku-original')) {
				sku.setAttribute(
					'data-brx-woo-product-sku-original',
					sku.getAttribute('data-o_content') || sku.textContent
				)
			}
		}

		const updateSku = (sku, variationSku) => {
			cacheOriginalSku(sku)

			sku.textContent = variationSku
		}

		const resetSku = (sku) => {
			cacheOriginalSku(sku)

			sku.textContent = sku.getAttribute('data-brx-woo-product-sku-original')
		}

		getSkuElements().forEach(cacheOriginalSku)

		jQuery(form).on('found_variation', function (event, variation) {
			const variationSku = variation?.sku || ''

			if (!variationSku) {
				getSkuElements().forEach(resetSku)

				return
			}

			getSkuElements().forEach((sku) => updateSku(sku, variationSku))
		})

		jQuery(form).on('reset_data hide_variation', function () {
			getSkuElements().forEach(resetSku)
		})
	}
})

function bricksWooProductSkuVariation() {
	bricksWooProductSkuVariationFn.run()
}

/**
 * Release lifecycle bindings for a first-paint SelectWoo shell.
 *
 * Woo may detach the shell or replace the native state select before the live
 * SelectWoo container can be detected. Cleaning through the stored references
 * prevents the detached select and its wrapper observer from retaining each other.
 *
 * (#86cb6m9n5; @since 2.4)
 *
 * @param {HTMLElement} shell First-paint SelectWoo shell.
 * @param {HTMLSelectElement} select Native country/state select.
 */
function bricksCleanupWooSelect2PreInitShell(shell, select) {
	shell?._bricksSelectWooObserver?.disconnect()

	if (shell?._bricksSelectWooSync && select?.removeEventListener) {
		select.removeEventListener('change', shell._bricksSelectWooSync)
	}

	if (shell) {
		delete shell._bricksSelectWooObserver
		delete shell._bricksSelectWooSync
	}
}

/**
 * Remove a first-paint shell after SelectWoo has installed its live container.
 *
 * (#86cb6m9n5; @since 2.4)
 *
 * @param {HTMLSelectElement} select Native country/state select.
 * @return {boolean} Whether the shell was removed.
 */
function bricksRemoveWooSelect2PreInitShell(select) {
	if (!select?.matches?.('select.country_select, select.state_select')) {
		return false
	}

	const shell = select.previousElementSibling
	const select2Container = select.nextElementSibling

	if (
		!select.classList.contains('select2-hidden-accessible') ||
		!shell?.matches?.('[data-brx-selectwoo-pre-init]') ||
		!select2Container?.matches?.('.select2-container:not(.bricks-selectwoo--pre-init)')
	) {
		return false
	}

	bricksCleanupWooSelect2PreInitShell(shell, select)
	shell.remove()

	return true
}

/**
 * Watch modular Woo fields until WooCommerce replaces their first-paint shells.
 *
 * Each observer is scoped to one input wrapper and disconnects on the first
 * successful SelectWoo initialization.
 *
 * (#86cb6m9n5; @since 2.4)
 *
 * @param {Document|Element} rootNode Root node containing modular form fields.
 */
function bricksBindWooSelect2PreInitShells(rootNode = document) {
	rootNode = rootNode?.querySelectorAll ? rootNode : document

	const selector = '[data-brx-selectwoo-pre-init]'
	const shells = rootNode.matches?.(selector)
		? [rootNode, ...rootNode.querySelectorAll(selector)]
		: Array.from(rootNode.querySelectorAll(selector))

	shells.forEach((shell) => {
		const select = shell.nextElementSibling

		if (!select?.matches?.('select.country_select, select.state_select')) {
			bricksCleanupWooSelect2PreInitShell(shell, select)
			shell.remove()
			return
		}

		if (bricksRemoveWooSelect2PreInitShell(select) || shell._bricksSelectWooObserver) {
			return
		}

		// A pointer-enabled native fallback must also keep the visible shell text current
		// until SelectWoo owns the control. textContent preserves the native option as the
		// source of truth without introducing an HTML injection path. (#86cb6m9n5; @since 2.4)
		const syncShell = () => {
			const renderedOption = shell.querySelector('.select2-selection__rendered')
			const selectedOption = select.selectedOptions?.[0]

			if (!renderedOption || !selectedOption) {
				return
			}

			renderedOption.textContent = selectedOption.textContent.trim()
			renderedOption.classList.toggle('select2-selection__placeholder', select.value === '')
		}

		select.addEventListener('change', syncShell)
		shell._bricksSelectWooSync = syncShell
		syncShell()

		const observer = new MutationObserver(() => {
			// Woo's country-to-state update can remove its generic `.select2-container`
			// (including this shell) and replace the select in the same mutation batch.
			// The normal sibling check cannot succeed once either node is detached.
			if (!shell.isConnected || !select.isConnected) {
				bricksCleanupWooSelect2PreInitShell(shell, select)
				shell.remove()
				return
			}

			bricksRemoveWooSelect2PreInitShell(select)
		})

		observer.observe(shell.parentElement, { childList: true })
		shell._bricksSelectWooObserver = observer
	})
}

/**
 * Add the originating Bricks field class to SelectWoo's detached dropdown portal.
 *
 * SelectWoo appends its search and result nodes to the document body. Mirroring the field's
 * unique class onto that portal lets individual element controls override state-level styles.
 *
 * (#86caxkuka; @since 2.4)
 */
function bricksBindWooFormFieldSelect2Portal() {
	if (bricksBindWooFormFieldSelect2Portal.bound || typeof jQuery === 'undefined') {
		return
	}

	bricksBindWooFormFieldSelect2Portal.bound = true

	jQuery(document.body).on(
		'init_checkout.bricksWooFormField updated_checkout.bricksWooFormField country_to_state_changed.bricksWooFormField',
		function () {
			bricksBindWooSelect2PreInitShells(document)
		}
	)

	jQuery(document).on(
		'select2:open.bricksWooFormField',
		'select.state_select, select.country_select',
		function () {
			const scriptId = this.dataset?.scriptId || ''

			if (!/^[a-zA-Z0-9_-]+$/.test(scriptId)) {
				return
			}

			const select2 = jQuery(this).data('select2')
			const dropdownPortal = select2?.dropdown?.$dropdownContainer?.[0]

			if (dropdownPortal) {
				dropdownPortal.classList.add(`brxe-${scriptId}`)
			}
		}
	)
}

// Function to init selectWoo inside builder for styling purposes (@since 2.4)
function bricksWooFormField(node) {
	bricksBindWooSelect2PreInitShells(node || document)

	if (typeof jQuery === 'undefined' || typeof jQuery.fn.selectWoo === 'undefined') {
		return
	}

	bricksBindWooFormFieldSelect2Portal()

	if (bricksIsFrontend) {
		return
	}

	// Only init selectWoo for select fields with .state_select & .country_select class to avoid conflicts with other select fields in the builder
	const $selectElements = jQuery('select.state_select:visible, select.country_select:visible')

	// Follow WooCommerce country-select.js
	$selectElements.each(function () {
		var $this = jQuery(this)
		var select2_args = {
			placeholder: $this.attr('data-placeholder') || $this.attr('placeholder') || '',
			label: $this.attr('data-label') || null,
			required: $this.attr('aria-required') === 'true' || null,
			width: '100%'
		}

		jQuery(this).selectWoo(select2_args)
		bricksRemoveWooSelect2PreInitShell(this)
	})
}

// Bind before WooCommerce's deferred country-select script initializes visible fields. (#86cb6m9n5; @since 2.4)
bricksBindWooSelect2PreInitShells(document)

/**
 * Extract cart fragments from a combined Checkout V2 update response.
 *
 * The server deliberately combines checkout, mini-cart, and extension fragments in one
 * response. Checkout fragments have already been installed by Woo before `updated_checkout`,
 * so they must not be handed back to Woo's `removed_from_cart` listener for a second replacement.
 *
 * @since 2.4
 *
 * @param {Object<string, string>} fragments Combined update_order_review fragments.
 * @return {Object<string, string>} Regular Woo cart and third-party fragments.
 */
function bricksWooGetCartFragmentsFromCheckoutResponse(fragments = {}) {
	if (!fragments || typeof fragments !== 'object') {
		return {}
	}

	const checkoutFragmentSelectors = new Set([
		'#bricks-woo-checkout-order-summary',
		'.brxe-woocommerce-shipping-options',
		'.brxe-woocommerce-payment-options',
		'.woocommerce-checkout-review-order-table',
		'.woocommerce-checkout-payment',
		bricksWooCheckoutCartHashSelector
	])

	return Object.fromEntries(
		Object.entries(fragments).filter(
			([selector]) =>
				!checkoutFragmentSelectors.has(selector) &&
				!selector.startsWith('[data-brx-woo-fragment="true"]')
		)
	)
}

/**
 * Emit Woo's removal completion event only after the combined checkout request confirms it.
 *
 * The final options object is ignored by Woo and third-party listeners. Bricks listeners use
 * it to avoid scheduling either a second checkout refresh or a dynamic-fragment request for
 * data that was already returned in the update_order_review response.
 *
 * @since 2.4
 *
 * @param {Object} response Woo update_order_review response.
 * @return {void}
 */
function bricksWooEmitCheckoutRemovalEvent(response = {}) {
	const pendingRemoval = bricksWooPendingCheckoutRemoval
	bricksWooPendingCheckoutRemoval = null

	if (!pendingRemoval) {
		return
	}

	const hashMarker = document.querySelector(bricksWooCheckoutCartHashSelector)

	// Do not tell integrations that an item was removed unless PHP confirmed remove_cart_item().
	if (hashMarker?.dataset.cartItemRemoved !== '1') {
		return
	}

	const cartFragments = bricksWooGetCartFragmentsFromCheckoutResponse(response.fragments)

	jQuery(document.body).trigger('removed_from_cart', [
		cartFragments,
		hashMarker.value || '',
		jQuery(pendingRemoval.trigger),
		{
			skipCheckoutUpdate: true,
			skipDynamicRefresh: true
		}
	])
}

/**
 * Keep all Checkout V2 calculation surfaces in one shared busy lifecycle.
 *
 * Woo replaces checkout fragments before emitting `updated_checkout`, so nodes are resolved
 * for every event instead of being cached. Checkout V1 is intentionally excluded: its native
 * review table and payment block remain owned by WooCommerce's checkout script.
 *
 * Cart mutation markers are removed only after `updated_checkout`, when Woo has serialized and
 * completed the marked request. A network failure leaves them in place so the next native
 * checkout refresh can safely retry the pending quantity or removal.
 *
 * @since 2.4
 *
 * @return {void}
 */
function bricksWooBlockCustomNodes() {
	if (
		!bricksIsFrontend ||
		typeof jQuery === 'undefined' ||
		!document.querySelector('.brxe-woocommerce-checkout-v2.brx-wc-checkout-v2--checkout')
	) {
		return
	}

	/**
	 * Resolve the current nodes because Woo may have replaced them in the previous response.
	 *
	 * @return {HTMLElement[]} Checkout V2 nodes that share the calculation state.
	 */
	const getCheckoutNodes = () =>
		[
			document.getElementById('bricks-woo-checkout-order-summary'),
			document.querySelector('.brxe-woocommerce-shipping-options'),
			document.querySelector('.brxe-woocommerce-payment-options')
		].filter(Boolean)

	/**
	 * Apply or release the shared accessible and visual busy state.
	 *
	 * @param {boolean} isBusy Whether a checkout calculation is in progress.
	 * @return {void}
	 */
	const setCheckoutNodesBusy = (isBusy) => {
		getCheckoutNodes().forEach((node) => {
			if (isBusy) {
				node.setAttribute('aria-busy', 'true')
				bricksWooCartBlock(jQuery(node))
				return
			}

			node.removeAttribute('aria-busy')
			bricksWooCartUnblock(jQuery(node))
		})
	}

	jQuery(document.body).on('update_checkout', function () {
		setCheckoutNodesBusy(true)
	})

	jQuery(document.body).on('updated_checkout checkout_error', function (event, response = {}) {
		setCheckoutNodesBusy(false)

		if (event.type === 'updated_checkout') {
			document
				.querySelectorAll(
					`input[name="${bricksWooCheckoutCartQuantityMarkerName}"], input[name="${bricksWooCheckoutCartRemovalMarkerName}"]`
				)
				.forEach((marker) => marker.remove())

			bricksWooEmitCheckoutRemovalEvent(response)
		}
	})
}

/**
 * Add Bricks post context to Woo checkout form so native Woo AJAX endpoints
 * can resolve the correct Bricks page/template context.
 * For conditions to work when building fragment responses
 * @since 2.4
 */
function bricksWooCheckoutAjaxContext() {
	if (!bricksIsFrontend || typeof jQuery === 'undefined') {
		return
	}

	const postId = parseInt(window.bricksData?.postId || 0, 10)

	if (!postId) {
		return
	}

	const ensurePostIdField = () => {
		const $form = jQuery('form.checkout')

		if (!$form.length) {
			return
		}

		let $field = $form.find('input[name="bricks_post_id"]')

		if (!$field.length) {
			$field = jQuery('<input type="hidden" name="bricks_post_id" />')
			$form.append($field)
		}

		$field.val(postId)
	}

	ensurePostIdField()

	// Woo may rebuild checkout fragments, so keep the hidden field in place.
	jQuery(document.body).on('init_checkout updated_checkout', ensurePostIdField)
}

/**
 * Keep cart-aware pages in sync when cart is modified via mini cart or other AJAX cart actions.
 *
 * Native Woo behavior:
 * - On full page load, empty checkout cart redirects to cart page (template_redirect).
 * - During checkout AJAX updates, empty cart returns an "expired" checkout fragment.
 *
 * Bricks enhancement:
 * - Trigger checkout refresh after cart fragment updates.
 * - Refresh cart page markup after explicit cart changes from outside the cart form.
 * - Redirect checkout to cart page if cart becomes empty after removing the last mini cart item.
 *
 * @since 2.4
 */
function bricksWooSyncCartState() {
	const checkoutUiSelector =
		'form.checkout, form.woocommerce-checkout, .woocommerce-checkout-review-order, #bricks-woo-checkout-order-summary'
	const cartUiSelector =
		'.woocommerce-cart-form, .brxe-woocommerce-cart-v2, .brxe-woocommerce-cart-v2-state-cart, .brxe-woocommerce-cart-v2-state-empty, body.woocommerce-cart'

	const hasCheckoutUi = () => {
		return !!document.querySelector(checkoutUiSelector)
	}

	const hasCartUi = () => {
		return !!document.querySelector(cartUiSelector)
	}

	if (!bricksIsFrontend || typeof jQuery === 'undefined') {
		return
	}

	const isCheckoutContext = () => !!window.wc_checkout_params?.is_checkout && hasCheckoutUi()
	const isCartContext = () => hasCartUi() && !isCheckoutContext()

	if (!isCheckoutContext() && !isCartContext()) {
		return
	}

	const getCartUrl = () => {
		return (
			window.wc_cart_params?.cart_url ||
			window.wc_add_to_cart_params?.cart_url ||
			window.wc_checkout_params?.cart_url ||
			''
		)
	}

	const isCheckoutFormPresent = () => {
		return !!document.querySelector('form.checkout, form.woocommerce-checkout')
	}

	const isCartFormPresent = () => {
		return !!document.querySelector('.woocommerce-cart-form')
	}

	const isMiniCartEmpty = (fragments = null) => {
		const miniCartFragment = fragments?.['div.widget_shopping_cart_content']

		if (typeof miniCartFragment === 'string' && miniCartFragment.length) {
			return miniCartFragment.includes('woocommerce-mini-cart__empty-message')
		}

		return !!document.querySelector(
			'.widget_shopping_cart_content .woocommerce-mini-cart__empty-message'
		)
	}

	const isCartFormEvent = ($trigger) => {
		return !!$trigger?.length && !!$trigger.closest('.woocommerce-cart-form').length
	}
	const setCartV2EmptyState = () => {
		document.querySelectorAll('.brxe-woocommerce-cart-v2').forEach((cartRoot) => {
			if (
				cartRoot.querySelector('.woocommerce-cart-form') ||
				!cartRoot.querySelector('.wc-empty-cart-message')
			) {
				return
			}

			cartRoot.classList.remove('brx-wc-cart-v2--cart')
			cartRoot.classList.add('brx-wc-cart-v2--empty', 'cart-empty')
		})
	}

	let checkoutUpdateTimer = null
	let cartUpdateTimer = null

	const requestCheckoutUpdate = () => {
		if (!isCheckoutFormPresent()) {
			return
		}

		if (checkoutUpdateTimer) {
			clearTimeout(checkoutUpdateTimer)
		}

		bricksWooCartContentsChanges.hold('checkoutSync')
		checkoutUpdateTimer = setTimeout(() => {
			bricksWooCartContentsChanges.release('checkoutSync')
			jQuery(document.body).trigger('update_checkout', { update_shipping_method: false })
		}, 60)
	}

	const requestCartUpdate = () => {
		if (!isCartFormPresent()) {
			return
		}

		if (cartUpdateTimer) {
			clearTimeout(cartUpdateTimer)
		}

		bricksWooCartContentsChanges.hold('cartSync')
		cartUpdateTimer = setTimeout(() => {
			bricksWooCartContentsChanges.release('cartSync')
			bricksWooCartAjaxUpdate()
		}, 60)
	}

	const maybeRedirectToCartIfCheckoutInvalid = (fragments = null) => {
		const cartUrl = getCartUrl()

		if (!cartUrl) {
			return false
		}

		// Most reliable signal when event provides fragments.
		if (isMiniCartEmpty(fragments)) {
			window.location = cartUrl
			return true
		}

		// Fallback: checkout markup got replaced by Woo "session/cart expired" response.
		if (!isCheckoutFormPresent()) {
			window.location = cartUrl
			return true
		}

		return false
	}

	jQuery(document.body).on(
		'removed_from_cart',
		function (event, fragments, cartHash, $trigger, syncOptions = {}) {
			/*
			 * Checkout V2 can remove the item inside update_order_review and then emit Woo's
			 * standard completion event for extension compatibility. Its response already
			 * contains the recalculated checkout state, so another update would recreate the
			 * original double-request and double-loader bug.
			 */
			if (syncOptions.skipCheckoutUpdate) {
				return
			}

			if (isCheckoutContext()) {
				if (maybeRedirectToCartIfCheckoutInvalid(fragments)) {
					return
				}

				requestCheckoutUpdate()
				return
			}

			if (isCartContext() && !isCartFormEvent($trigger)) {
				requestCartUpdate()
			}
		}
	)

	// Woo replaces the inner shortcode wrapper when the final cart item is removed. Keep the
	// persistent Cart v2 element root in sync without changing the legacy Cart v1 lifecycle.
	jQuery(document.body).on('wc_cart_emptied', setCartV2EmptyState)

	jQuery(document.body).on(
		'added_to_cart wc_fragments_refreshed',
		function (event, syncOptions = {}) {
			if (event.type === 'wc_fragments_refreshed' && syncOptions.skipCheckoutUpdate) {
				return
			}

			if (isCheckoutContext()) {
				requestCheckoutUpdate()
				return
			}

			if (isCartContext() && event.type === 'added_to_cart') {
				requestCartUpdate()
			}
		}
	)

	// Safety net for any other checkout updates that end up with no checkout form.
	jQuery(document.body).on('updated_checkout', function () {
		if (isCheckoutContext()) {
			maybeRedirectToCartIfCheckoutInvalid()
		}
	})
}

document.addEventListener('DOMContentLoaded', function (event) {
	bricksWooProductsFilter()
	bricksWooMiniModals()
	bricksWooMiniCartHideDetailsClickOutside()
	bricksWooAjaxAddToCartText()
	bricksSyncWooNoticeFieldErrors(document)
	bricksWooAjaxAddToCartFn.run()
	bricksWooCheckoutStepsFn.run() // (@since 2.4)
	bricksWooCheckoutSubmitBehavior()
	bricksWooProductGalleryEnhance()
	bricksCheckoutCouponToggle()
	bricksCheckoutCouponForm()
	bricksCartCouponForm()
	bricksWooCartRemoveCoupon() // (@since 2.4)
	bricksWooCartCustomRemoveLinks() // (@since 2.4)
	bricksCheckoutLoginToggle()
	bricksCheckoutLoginForm()
	bricksWooVariationSwatches()
	bricksWooResetVariationsDisplay()
	bricksWooProductSkuVariation()
	bricksWooStarRatingManageFill()
	bricksBindWooFormFieldSelect2Portal() // (#86caxkuka; @since 2.4)
	bricksBindWooSelect2PreInitShells(document) // (#86cb6m9n5; @since 2.4)
	bricksWooBlockCustomNodes() // (@since 2.4)
	bricksWooCheckoutAjaxContext() // (@since 2.4)
	bricksWooSyncCartState() // (@since 2.4)
	bricksWooCartQuantityAutoUpdate() // (@since 2.4)
	bricksWooDynamicFragments() // (@since 2.4)

	// Small timeout required to allow other plugins (e.g. WooCommerce Composite Products) to generate additional content (@since 1.8)
	setTimeout(function () {
		bricksWooQuantityTriggersFn.run()
		bricksWooCartQuantityStepperFn.run()
		bricksWooLoopQtyListenerFn.run()
	}, 150)
})

// Resize product gallery after all CSS is loaded (@since 2.0)
window.addEventListener('load', () => {
	if (
		!bricksIsFrontend ||
		typeof jQuery === 'undefined' ||
		typeof jQuery(this).wc_product_gallery === 'undefined'
	) {
		return
	}

	jQuery('.woocommerce-product-gallery').each(function () {
		jQuery(this).resize()
	})
})
