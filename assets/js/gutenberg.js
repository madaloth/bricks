/**
 * Gutenberg editor: "Edit with Bricks" button
 *
 * https://wordpress.org/gutenberg/handbook/designers-developers/developers/data/data-core-editor/
 */

/**
 * Localized Bricks admin data (renderWithBricks, i18n, etc.)
 *
 * When the post editor canvas runs in the editor-canvas iframe, wp_localize_script on bricks-admin
 * only runs in the parent document — read from parent when same-origin (@since 2.3.3).
 *
 * @return {object|undefined}
 */
function bricksGetGutenbergBricksData() {
	if (window.bricksData) {
		return window.bricksData
	}

	if (window.self !== window.top) {
		try {
			if (window.parent && window.parent.bricksData) {
				return window.parent.bricksData
			}
		} catch (e) {
			// Cross-origin parent (should not happen in wp-admin editor)
		}
	}

	return undefined
}

/**
 * Localized Bricks Gutenberg payload (builderEditLink, ajaxUrl, etc.)
 *
 * wp-blocks may only be localized in the parent when the canvas is iframed (@since 2.3.3).
 *
 * @return {object|undefined}
 */
function bricksGetGutenbergPayload() {
	if (window.bricksGutenbergData) {
		return window.bricksGutenbergData
	}

	if (window.self !== window.top) {
		try {
			if (window.parent && window.parent.bricksGutenbergData) {
				return window.parent.bricksGutenbergData
			}
		} catch (e) {
			// Cross-origin parent
		}
	}

	return undefined
}

/**
 * Get the server-generated page or template Builder redirect URL.
 *
 * The iframe asks the top wp-admin window to handle the redirect. Page Builder navigation saves the
 * post first; template navigation leaves the page untouched. Use localized URLs from the parent
 * instead of trusting posted URLs: WP_HOME and WP_SITEURL can differ. (#86c9vmnpq; @since 2.3.6)
 *
 * @param {boolean} editTemplate Whether to open the matched content template.
 * @return {string} Builder URL, or empty string when invalid.
 */
function bricksGetBuilderRedirectUrl(editTemplate = false) {
	const url = editTemplate
		? bricksGetGutenbergBricksData()?.contentTemplateEditUrl
		: bricksGetGutenbergPayload()?.builderEditLink

	if (!url || url === '#') {
		return ''
	}

	try {
		const parsedUrl = new URL(url, window.location.origin)

		if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
			return ''
		}

		return parsedUrl.href
	} catch (e) {
		return ''
	}
}

function bricksAdminGutenbergEditWithBricks() {
	if (window.self !== window.top) {
		return
	}

	var editWithBricksLink = document.querySelector('#wp-admin-bar-edit_with_bricks a')

	// If the "Edit with Bricks" link is not available in the admin bar, create it (@since 1.8.6)
	if (!editWithBricksLink) {
		editWithBricksLink = document.createElement('a')
		editWithBricksLink.id = 'wp-admin-bar-edit_with_bricks'
		editWithBricksLink.href = window.bricksGutenbergData.builderEditLink
		editWithBricksLink.innerText = window.bricksData.i18n.editWithBricks
	}

	// Add Bricks buttons to Gutenberg: Listen to window.wp.data store changes to remount buttons
	window.wp.data.subscribe(function () {
		setTimeout(function () {
			var postHeaderToolbar = document.querySelector('.edit-post-header-toolbar')

			// Exclude Meta Box Gutenberg editor areas (@since 2.4)
			if (postHeaderToolbar && postHeaderToolbar.closest('.rwmb-block_editor-wrapper')) {
				return
			}

			if (
				postHeaderToolbar &&
				postHeaderToolbar instanceof HTMLElement &&
				!postHeaderToolbar.querySelector('#toolbar-edit_with_bricks')
			) {
				var editWithBricksButton = document.createElement('a')
				editWithBricksButton.id = 'toolbar-edit_with_bricks'
				editWithBricksButton.classList.add('button')
				editWithBricksButton.classList.add('button-primary')
				editWithBricksButton.innerText = editWithBricksLink.innerText
				editWithBricksButton.href = editWithBricksLink.href

				postHeaderToolbar.append(editWithBricksButton)

				// "Edit with Bricks" button click listener
				editWithBricksButton.addEventListener('click', function (e) {
					e.preventDefault()

					var title = window.wp.data.select('core/editor').getEditedPostAttribute('title')
					var postId = window.wp.data.select('core/editor').getCurrentPostId()

					// Add title
					if (!title) {
						window.wp.data.dispatch('core/editor').editPost({ title: 'Bricks #' + postId })
					}

					// Save draft
					window.wp.data.dispatch('core/editor').savePost()

					// Redirect to edit in Bricks builder
					var redirectToBuilder = function (url) {
						setTimeout(function () {
							if (
								window.wp.data.select('core/editor').isSavingPost() ||
								window.wp.data.select('core/editor').isAutosavingPost()
							) {
								redirectToBuilder(url)
							} else {
								window.location.href = url
							}
						}, 400)
					}

					redirectToBuilder(e.target.href)
				})
			}
		}, 1)
	})
}

/**
 * Handles empty block (Gutenberg) editor state for Bricks-enabled posts/pages
 *
 * @since 1.12
 */
function bricksHandleEmptyContent() {
	let rootContainer = document.querySelector('.is-root-container')
	let attempts = 0
	const maxAttempts = 10

	function tryFindContainer() {
		if (attempts >= maxAttempts) {
			return
		}

		rootContainer = document.querySelector('.is-root-container')

		if (!rootContainer) {
			attempts++
			setTimeout(tryFindContainer, 50)
			return
		}

		// Exclude Meta Box Gutenberg editor areas (@since 2.4)
		if (rootContainer.closest('.rwmb-block_editor-wrapper')) {
			return
		}

		// Found the container, proceed with normal flow
		if (window.self !== window.top) {
			// Canvas iframe: persist notice through React re-renders when wp.data is available
			if (window.wp && window.wp.data) {
				window.wp.data.subscribe(function () {
					setTimeout(function () {
						handleEmptyContentCore(rootContainer)
					}, 1)
				})
			} else {
				handleEmptyContentCore(rootContainer)
			}
		} else {
			const editorIframe = document.querySelector('iframe[name="editor-canvas"]')
			if (!editorIframe && window.wp && window.wp.data) {
				window.wp.data.subscribe(function () {
					setTimeout(function () {
						handleEmptyContentCore(rootContainer)
					}, 1)
				})
			}
		}
	}

	tryFindContainer()
}

/**
 * Core logic for handling empty content state
 *
 * When Gutenberg is empty, shows the action that edits the rendered content:
 * - Page content: "Edit with Bricks" and an option to use the default editor
 * - Template-only content: "Edit template" and an option to use the default editor
 *
 * Uses window.wp.data.subscribe to persist through React re-renders
 * Choice of default editor persists until page reload
 *
 * @since 1.12
 */
function handleEmptyContentCore(rootContainer) {
	const bricksData = bricksGetGutenbergBricksData()
	const gutenbergData = bricksGetGutenbergPayload()

	if (
		rootContainer &&
		!rootContainer.querySelector('.bricks-block-editor-notice-wrapper') &&
		bricksData?.renderWithBricks == 1 &&
		(bricksData?.hasBricksData || bricksData?.contentTemplateId) &&
		!window.useDefaultEditor // Only proceed if user hasn't chosen default editor
	) {
		// Hide existing appender block
		rootContainer.querySelectorAll(':scope > *').forEach((el) => {
			if (!el.classList.contains('bricks-block-editor-notice-wrapper')) {
				el.style.display = 'none'
			}
		})

		const editorWrapper = document.createElement('div')
		editorWrapper.className = 'bricks-block-editor-notice-wrapper'

		const message = document.createElement('p')
		message.className = 'bricks-editor-message'

		// Show different message when page is rendered through a Bricks template (@since 2.3.3)
		if (bricksData.contentTemplateId && bricksData.contentTemplateName) {
			message.textContent = bricksData.i18n.bricksTemplateMessage.replace(
				'%s',
				bricksData.contentTemplateName
			)
		} else {
			message.textContent = bricksData.i18n.bricksActiveMessage
		}

		const buttonWrapper = document.createElement('div')
		buttonWrapper.className = 'bricks-editor-buttons'

		const editTemplate = !!bricksData.contentTemplateEditUrl
		const editUrl = editTemplate
			? bricksData.contentTemplateEditUrl
			: gutenbergData?.builderEditLink || '#'

		const editButton = document.createElement('a')
		editButton.className = 'button button-primary'
		editButton.href = editUrl
		editButton.textContent = editTemplate
			? bricksData.i18n.editContentTemplate
			: bricksData.i18n.editWithBricks

		// Edit the template directly, or save the page before opening its Builder (@since 2.3.3).
		editButton.addEventListener('click', (e) => {
			e.preventDefault()

			if (window.self !== window.top) {
				// We're in an iframe, send message to parent
				window.top.postMessage(
					{
						type: 'bricksOpenBuilder',
						editTemplate
					},
					'*'
				)
			} else if (window.wp && window.wp.data) {
				if (editTemplate) {
					window.location.href = editUrl
					return
				}

				// We're in top window: Save post first, then redirect to the PHP-generated builder URL (#86c9vmnpq; @since 2.3.6)
				const postId = window.wp.data.select('core/editor').getCurrentPostId()
				const title = window.wp.data.select('core/editor').getEditedPostAttribute('title')

				// Draft/sample permalinks can resolve through the frontend and trigger "Invalid post type"; keep the server-generated builder URL. (#86c9vmnpq; @since 2.3.6)
				const builderUrl = editUrl

				if (!title) {
					window.wp.data.dispatch('core/editor').editPost({ title: 'Bricks #' + postId })
				}

				window.wp.data.dispatch('core/editor').savePost()

				const redirectToBuilder = () => {
					setTimeout(() => {
						if (
							window.wp.data.select('core/editor').isSavingPost() ||
							window.wp.data.select('core/editor').isAutosavingPost()
						) {
							redirectToBuilder()
						} else {
							if (!builderUrl || builderUrl === '#') {
								return
							}

							window.location.href = builderUrl
						}
					}, 400)
				}

				redirectToBuilder()
			} else {
				// Fallback: Navigate directly
				window.location.href = editUrl
			}
		})

		const defaultEditorLink = document.createElement('a')
		defaultEditorLink.className = 'button'
		defaultEditorLink.href = '#'
		defaultEditorLink.textContent = bricksData.i18n.useDefaultEditor
		defaultEditorLink.addEventListener('click', (e) => {
			e.preventDefault()
			window.useDefaultEditor = true

			rootContainer.querySelectorAll(':scope > *').forEach((el) => {
				if (!el.classList.contains('bricks-block-editor-notice-wrapper')) {
					el.style.display = ''
				}
			})

			editorWrapper.remove()
		})

		buttonWrapper.append(editButton)
		buttonWrapper.append(defaultEditorLink)
		editorWrapper.append(message, buttonWrapper)
		rootContainer.appendChild(editorWrapper)
	}
}

/*
 * Listen for messages from the editor iframe to open the page or template Builder.
 * Save only page edits before redirecting, then use the PHP-generated URL. (#86c9vmnpq; @since 2.3.6)
 *
 * @since 1.12
 */
if (window.self === window.top) {
	window.addEventListener('message', (event) => {
		// The iframe still posts with "*" for compatibility, so the parent owns the origin/source checks.
		if (event.origin !== window.location.origin || event.data?.type !== 'bricksOpenBuilder') {
			return
		}

		const editorIframe = document.querySelector('iframe[name="editor-canvas"]')

		if (!editorIframe || event.source !== editorIframe.contentWindow) {
			return
		}

		const builderUrl = bricksGetBuilderRedirectUrl(event.data?.editTemplate === true)

		if (!builderUrl) {
			return
		}

		if (event.data?.editTemplate === true) {
			window.location.href = builderUrl
			return
		}

		if (window.wp && window.wp.data) {
			const postId = window.wp.data.select('core/editor').getCurrentPostId()
			const title = window.wp.data.select('core/editor').getEditedPostAttribute('title')

			if (!title) {
				window.wp.data.dispatch('core/editor').editPost({ title: 'Bricks #' + postId })
			}

			window.wp.data.dispatch('core/editor').savePost()

			const redirectToBuilder = () => {
				setTimeout(() => {
					if (
						window.wp.data.select('core/editor').isSavingPost() ||
						window.wp.data.select('core/editor').isAutosavingPost()
					) {
						redirectToBuilder()
					} else {
						window.location.href = builderUrl
					}
				}, 400)
			}

			redirectToBuilder()
		} else {
			window.location.href = builderUrl
		}
	})
}

document.addEventListener('DOMContentLoaded', function (e) {
	bricksAdminGutenbergEditWithBricks()
	bricksHandleEmptyContent()
})
