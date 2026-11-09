/**
 * Create TinyMCE rich text control matching ControlEditor.vue
 *
 * Stable editor IDs prevent Gutenberg inspector re-renders from remounting TinyMCE. (#86c9qmpym; @since 2.3.9)
 */
function createBricksRichTextControl(property, props) {
	const { createElement } = window.wp.element

	const editorIdSuffix = `${props.clientId || 'default'}-${property.id}`.replace(
		/[^a-zA-Z0-9_-]/g,
		'-'
	)
	const editorId = `bricks-gutenberg-editor-${editorIdSuffix}`

	return createElement(
		'div',
		{
			key: property.id,
			style: { marginBottom: '16px' }
		},
		[
			createElement(
				'label',
				{
					key: 'label',
					style: {
						display: 'block',
						marginBottom: '8px',
						fontSize: '11px',
						fontWeight: '500',
						color: '#1e1e1e'
					}
				},
				property.label
			),
			createElement(TinyMCEEditor, {
				key: 'tinymce-editor',
				editorId: editorId,
				value: props.attributes[property.id] || '',
				onChange: function (value) {
					const newAttributes = {}
					newAttributes[property.id] = value
					props.setAttributes(newAttributes)
				}
			})
		]
	)
}

/**
 * Use TinyMCE's native link dialog in Gutenberg instead of WordPress wplink.
 *
 * WordPress wplink is positioned for the editor canvas and can overflow or flicker inside
 * Gutenberg InspectorControls. (#86c9qmpym; @since 2.3.9)
 *
 * @param {Array|string} plugins TinyMCE plugin list.
 * @return {string} Comma-separated TinyMCE plugin list.
 */
function replaceWordPressLinkPlugin(plugins) {
	const pluginList = Array.isArray(plugins)
		? plugins
		: String(plugins || '')
				.split(/[\s,]+/)
				.filter(Boolean)

	const filteredPlugins = pluginList.filter((plugin) => !['wplink', 'wpdialogs'].includes(plugin))

	if (!filteredPlugins.includes('link')) {
		filteredPlugins.push('link')
	}

	return filteredPlugins.join(',')
}

/**
 * Decode REST search result titles before inserting them into the link dialog. (#86c9qmpym; @since 2.3.9)
 *
 * @param {string} value Encoded title.
 * @return {string} Decoded title.
 */
function decodeHtmlEntities(value) {
	const textarea = document.createElement('textarea')
	textarea.innerHTML = value || ''

	return textarea.value
}

/**
 * Create DOM elements for the Gutenberg-only internal link picker. (#86c9qmpym; @since 2.3.9)
 *
 * @param {string} tagName Element tag name.
 * @param {Object} attributes Element attributes.
 * @param {string} text Text content.
 * @return {HTMLElement} Created element.
 */
function createPickerElement(tagName, attributes = {}, text = '') {
	const element = document.createElement(tagName)

	Object.entries(attributes).forEach(([key, value]) => {
		if (key === 'style') {
			Object.assign(element.style, value)
		} else {
			element.setAttribute(key, value)
		}
	})

	if (text) {
		element.textContent = text
	}

	return element
}

/**
 * Search linkable WordPress content using Gutenberg's REST helper.
 *
 * Uses the core /wp/v2/search endpoint; no Bricks REST route is registered. (#86c9qmpym; @since 2.3.9)
 *
 * @param {URLSearchParams} params REST query parameters.
 * @param {AbortSignal} signal Request abort signal.
 * @return {Promise<Array>} Search results.
 */
function fetchGutenbergLinkSearch(params, signal) {
	const path = `/wp/v2/search?${params.toString()}`

	if (window.wp?.apiFetch) {
		return window.wp.apiFetch({ path, signal })
	}

	const restUrl = window.wpApiSettings?.root ? window.wpApiSettings.root.replace(/\/$/, '') : ''

	if (!restUrl) {
		return Promise.resolve([])
	}

	return fetch(`${restUrl}${path}`, {
		credentials: 'same-origin',
		headers: window.wpApiSettings?.nonce ? { 'X-WP-Nonce': window.wpApiSettings.nonce } : {},
		signal
	}).then((response) => (response.ok ? response.json() : []))
}

/**
 * Open a bounded internal link picker for TinyMCE's native link dialog. (#86c9qmpym; @since 2.3.9)
 *
 * @param {Function} callback TinyMCE file picker callback.
 */
function openBricksInternalLinkPicker(callback) {
	const i18n = window.bricksData?.i18n || {}
	let abortController = null
	let searchTimeout = null

	const existingPicker = document.querySelector('.bricks-gutenberg-link-picker')
	if (existingPicker) {
		existingPicker.remove()
	}

	const overlay = createPickerElement('div', {
		class: 'bricks-gutenberg-link-picker',
		style: {
			position: 'fixed',
			inset: '0',
			zIndex: '1000000',
			background: 'rgba(0, 0, 0, 0.35)'
		}
	})

	const dialog = createPickerElement('div', {
		role: 'dialog',
		'aria-modal': 'true',
		'aria-label': i18n.searchPosts,
		style: {
			position: 'fixed',
			top: '50%',
			left: '50%',
			transform: 'translate(-50%, -50%)',
			width: 'min(420px, calc(100vw - 32px))',
			maxHeight: 'calc(100vh - 32px)',
			overflow: 'hidden',
			background: '#fff',
			borderRadius: '2px',
			boxShadow: '0 12px 32px rgba(0, 0, 0, 0.25)',
			color: '#1e1e1e',
			fontFamily:
				'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
		}
	})

	const header = createPickerElement('div', {
		style: {
			display: 'flex',
			alignItems: 'center',
			justifyContent: 'space-between',
			gap: '12px',
			padding: '12px 16px',
			borderBottom: '1px solid #ddd'
		}
	})
	const title = createPickerElement(
		'strong',
		{
			style: {
				fontSize: '14px'
			}
		},
		i18n.searchPosts
	)
	const closeButton = createPickerElement(
		'button',
		{
			type: 'button',
			class: 'button-link',
			style: {
				cursor: 'pointer'
			}
		},
		i18n.close
	)

	const body = createPickerElement('div', {
		style: {
			padding: '16px'
		}
	})
	const input = createPickerElement('input', {
		type: 'search',
		placeholder: i18n.searchPosts,
		'aria-label': i18n.searchPosts,
		style: {
			boxSizing: 'border-box',
			width: '100%',
			minHeight: '36px',
			margin: '0 0 12px',
			padding: '6px 10px'
		}
	})
	const status = createPickerElement(
		'div',
		{
			role: 'status',
			style: {
				minHeight: '20px',
				marginBottom: '8px',
				color: '#757575',
				fontSize: '12px'
			}
		},
		i18n.searchPostsDescription
	)
	const results = createPickerElement('div', {
		style: {
			maxHeight: '280px',
			overflowY: 'auto',
			border: '1px solid #ddd'
		}
	})

	const closePicker = () => {
		if (abortController) {
			abortController.abort()
		}

		clearTimeout(searchTimeout)
		document.removeEventListener('keydown', handleKeydown)
		overlay.remove()
	}

	const handleKeydown = (event) => {
		if (event.key === 'Escape' && overlay.isConnected) {
			closePicker()
		}
	}

	const renderResults = (items) => {
		results.textContent = ''

		if (!items.length) {
			status.textContent = i18n.noResults
			return
		}

		status.textContent = ''

		items.forEach((item) => {
			const itemTitle = decodeHtmlEntities(item.title)
			const button = createPickerElement('button', {
				type: 'button',
				style: {
					display: 'block',
					boxSizing: 'border-box',
					width: '100%',
					padding: '9px 10px',
					border: '0',
					borderBottom: '1px solid #eee',
					background: '#fff',
					color: '#1e1e1e',
					cursor: 'pointer',
					textAlign: 'left'
				}
			})
			const buttonTitle = createPickerElement(
				'span',
				{
					style: {
						display: 'block',
						fontWeight: '500'
					}
				},
				itemTitle
			)
			const buttonUrl = createPickerElement(
				'span',
				{
					style: {
						display: 'block',
						marginTop: '2px',
						color: '#757575',
						fontSize: '12px',
						overflow: 'hidden',
						textOverflow: 'ellipsis',
						whiteSpace: 'nowrap'
					}
				},
				item.url
			)

			button.append(buttonTitle, buttonUrl)
			button.addEventListener('click', () => {
				callback(item.url, {
					text: itemTitle,
					title: itemTitle
				})
				closePicker()
			})
			results.appendChild(button)
		})
	}

	const searchPosts = (search = '') => {
		if (abortController) {
			abortController.abort()
		}

		abortController = new AbortController()
		status.textContent = i18n.loading

		const params = new URLSearchParams({
			type: 'post',
			subtype: 'any',
			per_page: '20',
			search
		})

		fetchGutenbergLinkSearch(params, abortController.signal)
			.then((items) => renderResults(Array.isArray(items) ? items.filter((item) => item.url) : []))
			.catch((error) => {
				if (error.name !== 'AbortError') {
					status.textContent = i18n.noResults
				}
			})
	}

	closeButton.addEventListener('click', closePicker)
	overlay.addEventListener('click', (event) => {
		if (event.target === overlay) {
			closePicker()
		}
	})
	document.addEventListener('keydown', handleKeydown)
	input.addEventListener('input', () => {
		clearTimeout(searchTimeout)
		searchTimeout = setTimeout(() => searchPosts(input.value.trim()), 250)
	})

	header.append(title, closeButton)
	body.append(input, status, results)
	dialog.append(header, body)
	overlay.appendChild(dialog)
	document.body.appendChild(overlay)
	input.focus()
	searchPosts()
}

/**
 * TinyMCE Editor Component for Gutenberg
 */
function TinyMCEEditor({ editorId, value, onChange }) {
	const { createElement, useEffect, useRef, useState } = window.wp.element
	const editorRef = useRef(null)
	const onChangeRef = useRef(onChange)
	// Compare against the latest prop value inside TinyMCE events to avoid unnecessary Gutenberg updates. (#86c9qmpym; @since 2.3.9)
	const valueRef = useRef(value)
	const [isInitialized, setIsInitialized] = useState(false)

	// Keep onChange ref current to avoid stale closures in TinyMCE event listeners
	useEffect(() => {
		onChangeRef.current = onChange
	}, [onChange])

	useEffect(() => {
		valueRef.current = value
	}, [value])

	useEffect(() => {
		if (!editorRef.current || isInitialized) return

		// Check if we have the necessary WordPress globals
		if (!window.tinyMCEPreInit || !window.tinymce || !window.switchEditors) {
			console.warn('TinyMCE not available, falling back to textarea')
			return
		}

		initializeTinyMCE()
		setIsInitialized(true)

		// Cleanup on unmount
		return () => {
			cleanupEditor()
		}
	}, [])

	// Update content when value changes externally
	useEffect(() => {
		if (isInitialized && window.tinymce) {
			const editor = window.tinymce.get(editorId)
			const textarea = document.getElementById(editorId)

			if (editor && editor.getContent() !== value) {
				editor.setContent(value || '')
			}

			// Also update textarea content for HTML mode
			if (textarea && textarea.value !== value) {
				textarea.value = value || ''
			}
		}
	}, [value, isInitialized])

	const initializeTinyMCE = () => {
		try {
			// Create editor HTML structure
			const editorHTML = `
				<div class="wp-core-ui wp-editor-wrap tmce-active" style="border: none;">
					<div class="wp-editor-tools">
						<div class="wp-editor-tabs">
							<button type="button" class="wp-switch-editor switch-tmce" data-wp-editor-id="${editorId}">${
								window.bricksData.i18n.visual
							}</button>
							<button type="button" class="wp-switch-editor switch-html" data-wp-editor-id="${editorId}">${
								window.bricksData.i18n.text
							}</button>
						</div>
					</div>
					<div class="wp-editor-container">
						<textarea class="wp-editor-area" rows="10" cols="40" name="${editorId}" id="${editorId}">${
							value || ''
						}</textarea>
					</div>
				</div>
			`

			editorRef.current.innerHTML = editorHTML

			// Setup TinyMCE settings using WordPress defaults (like ControlEditor.vue)
			let editorSettings = {}

			const mceInit = window.tinyMCEPreInit?.mceInit || {}

			if (mceInit.brickswpeditor) {
				// Use WordPress pre-configured settings (like ControlEditor.vue does)
				editorSettings = window.jQuery
					? window.jQuery.extend(true, {}, mceInit.brickswpeditor)
					: Object.assign({}, mceInit.brickswpeditor)
			} else {
				// Fallback to basic settings if WordPress config not available
				editorSettings = {
					theme: 'modern',
					skin: 'lightgray',
					plugins:
						'charmap,colorpicker,hr,lists,paste,tabfocus,textcolor,fullscreen,wordpress,wpeditimage,wpgallery,link,wpview',
					toolbar1:
						'bold,italic,strikethrough,bullist,numlist,blockquote,hr,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,fullscreen,wp_adv',
					toolbar2:
						'formatselect,underline,alignjustify,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
					wpautop: true,
					indent: false
				}
			}

			// Override selector and specific settings for Gutenberg context
			editorSettings.selector = `#${editorId}`
			editorSettings.menubar = false
			editorSettings.statusbar = false // Remove TinyMCE branding/status bar
			editorSettings.body_class = editorId
			editorSettings.resize = 'vertical'
			editorSettings.height = 300
			// Replace the unstable WordPress link popup with TinyMCE's native dialog and Bricks internal search. (#86c9qmpym; @since 2.3.9)
			editorSettings.plugins = replaceWordPressLinkPlugin(editorSettings.plugins)
			// Hide TinyMCE's default <top>/<bottom> anchor suggestions from the URL field. (#86c9qmpym; @since 2.3.9)
			editorSettings.anchor_top = false
			editorSettings.anchor_bottom = false
			// Disable TinyMCE's per-session URL autocomplete history; internal post search is handled by the browse picker. (#86c9qmpym; @since 2.3.9)
			editorSettings.typeahead_urls = false
			editorSettings.link_context_toolbar = false
			editorSettings.file_picker_types = 'file'
			editorSettings.file_picker_callback = (callback) => {
				openBricksInternalLinkPicker(callback)
			}

			// Ensure format selector is available by moving it to toolbar1 if it's not there
			if (editorSettings.toolbar1 && !editorSettings.toolbar1.includes('formatselect')) {
				// Add formatselect to the beginning of toolbar1 for better visibility
				editorSettings.toolbar1 = 'formatselect,' + editorSettings.toolbar1
			}

			// Force show the second toolbar by default (where formatselect usually is)
			if (editorSettings.toolbar2) {
				// Make sure the advanced toolbar is visible by default
				editorSettings.wordpress_adv_hidden = false
			}

			// Setup function for editor initialization and event handling
			editorSettings.setup = (editor) => {
				// Handle content changes
				editor.on('change keyup undo redo', () => {
					const content = editor.getContent()
					if (content !== valueRef.current) {
						onChangeRef.current(content)
					}
				})

				editor.on('blur', () => {
					const content = editor.getContent()
					if (content !== valueRef.current) {
						onChangeRef.current(content)
					}
				})

				// Remove TinyMCE branding after editor initialization
				editor.on('init', () => {
					// Hide any branding elements
					const statusbar = editor.getContainer().querySelector('.mce-statusbar')
					if (statusbar) {
						statusbar.style.display = 'none'
					}

					// Setup editor container and styling
					const editorContainer = editor.getContainer()
					if (editorContainer) {
						editorContainer.style.border = 'none'
						editorContainer.style.display = 'block'
						editorContainer.style.visibility = 'visible'
					}

					// Style the iframe body for better integration
					const iframe = editor.getWin()
					if (iframe && iframe.document) {
						const style = iframe.document.createElement('style')
						style.innerHTML = `
							body {
								margin: 8px !important;
								font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
								font-size: 13px;
								line-height: 1.4;
							}
						`
						iframe.document.head.appendChild(style)
					}

					// Set initial content in both editor and textarea
					const textarea = document.getElementById(editorId)
					if (value) {
						editor.setContent(value)
						if (textarea) {
							textarea.value = value
						}
					}

					// Ensure textarea is hidden by default
					if (textarea) {
						textarea.style.display = 'none'
						textarea.setAttribute('aria-hidden', 'true')
					}

					// Force show the advanced toolbar (where formatselect is)
					if (editor.theme && editor.theme.panel) {
						// Show the second toolbar by default
						const advancedToolbar = editor.getContainer().querySelector('.mce-toolbar:nth-child(2)')
						if (advancedToolbar) {
							advancedToolbar.style.display = 'block'
						}
					}

					// Trigger wp_adv button to show advanced toolbar
					setTimeout(() => {
						const wpAdvButton = editor.getContainer().querySelector('.mce-i-wp_adv')
						if (wpAdvButton) {
							wpAdvButton.click()
						}
					}, 100)
				})
			}

			// Register per-instance settings so stable editor IDs do not reuse stale TinyMCE config. (#86c9qmpym; @since 2.3.9)
			window.tinyMCEPreInit.mceInit[editorId] = editorSettings
			window.tinymce.init(editorSettings)

			// Setup editor tabs functionality
			const tabs = editorRef.current.querySelectorAll('.wp-switch-editor')
			const textarea = document.getElementById(editorId)

			tabs.forEach((tab) => {
				tab.addEventListener('click', (e) => {
					e.preventDefault()
					const mode = tab.classList.contains('switch-tmce') ? 'tmce' : 'html'

					const editor = window.tinymce.get(editorId)
					if (!editor || !textarea) return

					// Sync content before switching modes
					if (mode === 'html') {
						// Switching to HTML mode - get content from TinyMCE and put it in textarea
						const content = editor.getContent()
						textarea.value = content
					} else {
						// Switching to Visual mode - get content from textarea and put it in TinyMCE
						const content = textarea.value || ''
						editor.setContent(content)
					}

					// Always use manual switching since switchEditors.go doesn't work reliably in Gutenberg
					const editorContainer = editor.getContainer()

					if (mode === 'html') {
						// Show textarea, hide TinyMCE
						if (textarea) {
							textarea.style.display = 'block'
							textarea.style.width = '100%'
							textarea.style.height = '300px'
							textarea.style.padding = '8px'
							textarea.style.border = '1px solid #ddd'
							textarea.style.borderRadius = '4px'
							textarea.style.fontFamily = 'Consolas, Monaco, monospace'
							textarea.style.fontSize = '13px'
							textarea.style.resize = 'vertical'
							textarea.setAttribute('aria-hidden', 'false')
						}
						if (editorContainer) {
							editorContainer.style.display = 'none'
						}
						// Update tab states
						tabs.forEach((t) => {
							if (t.classList.contains('switch-html')) {
								t.classList.add('wp-switch-editor-active')
							} else {
								t.classList.remove('wp-switch-editor-active')
							}
						})
						editorRef.current.querySelector('.wp-editor-wrap').classList.remove('tmce-active')
						editorRef.current.querySelector('.wp-editor-wrap').classList.add('html-active')
					} else {
						// Show TinyMCE, hide textarea
						if (textarea) {
							textarea.style.display = 'none'
							textarea.setAttribute('aria-hidden', 'true')
						}
						if (editorContainer) {
							editorContainer.style.display = 'block'
							editorContainer.style.visibility = 'visible'
						}
						// Update tab states
						tabs.forEach((t) => {
							if (t.classList.contains('switch-tmce')) {
								t.classList.add('wp-switch-editor-active')
							} else {
								t.classList.remove('wp-switch-editor-active')
							}
						})
						editorRef.current.querySelector('.wp-editor-wrap').classList.remove('html-active')
						editorRef.current.querySelector('.wp-editor-wrap').classList.add('tmce-active')
					}
				})
			})

			// Add event listener to textarea for HTML mode changes
			if (textarea) {
				textarea.addEventListener('input', (e) => {
					// Update the value and trigger onChange when in HTML mode
					const newValue = e.target.value
					if (newValue !== value) {
						onChangeRef.current(newValue)
					}
				})
			}
		} catch (error) {
			// Fallback to textarea if TinyMCE fails to initialize
			const { TextareaControl } = window.wp.components
			return createElement(TextareaControl, {
				key: property.id,
				label: property.label,
				value: value || '',
				onChange: onChange
			})
		}
	}

	const cleanupEditor = () => {
		if (window.tinymce) {
			const editor = window.tinymce.get(editorId)
			if (editor) {
				editor.remove()
			}
		}

		if (window.tinyMCEPreInit?.mceInit?.[editorId]) {
			// Clean up the per-instance TinyMCE config when Gutenberg unmounts the control. (#86c9qmpym; @since 2.3.9)
			delete window.tinyMCEPreInit.mceInit[editorId]
		}
	}

	return createElement('div', {
		ref: editorRef,
		style: {
			border: 'none'
		}
	})
}

// Expose function globally
window.createBricksRichTextControl = createBricksRichTextControl
