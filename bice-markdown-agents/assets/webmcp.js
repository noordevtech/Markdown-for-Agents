/**
 * WebMCP tool registration (https://webmachinelearning.github.io/webmcp/).
 *
 * Registers this site's key read actions with agents embedded in the
 * browser via navigator.modelContext.provideContext(). Silent no-op when
 * the WebMCP API is absent.
 *
 * Configuration is injected as window.bmaWebMcp by the plugin
 * (homeUrl, restUrl, siteName).
 */
(function () {
	'use strict';

	if (
		typeof navigator === 'undefined' ||
		! ( 'modelContext' in navigator ) ||
		typeof navigator.modelContext.provideContext !== 'function'
	) {
		return;
	}

	var cfg = window.bmaWebMcp || {};
	var homeUrl = cfg.homeUrl || window.location.origin + '/';
	var restUrl = cfg.restUrl || homeUrl + 'wp-json/';
	var siteName = cfg.siteName || document.title;

	function textResult( text ) {
		return { content: [ { type: 'text', text: String( text ) } ] };
	}

	navigator.modelContext.provideContext( {
		tools: [
			{
				name: 'search_content',
				description:
					'Search the published content of ' +
					siteName +
					' (pages, posts, products). Returns matching items with titles and URLs.',
				inputSchema: {
					type: 'object',
					properties: {
						query: {
							type: 'string',
							description: 'Search terms.'
						}
					},
					required: [ 'query' ]
				},
				async execute( args ) {
					var url =
						restUrl +
						'wp/v2/search?per_page=10&search=' +
						encodeURIComponent( args.query );
					var response = await fetch( url, {
						headers: { Accept: 'application/json' }
					} );
					if ( ! response.ok ) {
						throw new Error( 'Search failed with HTTP ' + response.status );
					}
					var items = await response.json();
					return textResult(
						JSON.stringify(
							items.map( function ( item ) {
								return {
									title: item.title,
									url: item.url,
									type: item.subtype || item.type
								};
							} ),
							null,
							2
						)
					);
				}
			},
			{
				name: 'get_page_markdown',
				description:
					'Fetch any page of ' +
					siteName +
					' as Markdown (token-efficient, structure preserved) via text/markdown content negotiation. Same-origin URLs only.',
				inputSchema: {
					type: 'object',
					properties: {
						url: {
							type: 'string',
							description:
								'Absolute or relative URL of the page on this site.'
						}
					},
					required: [ 'url' ]
				},
				async execute( args ) {
					var target = new URL( args.url, homeUrl );
					if ( target.origin !== new URL( homeUrl ).origin ) {
						throw new Error( 'Only same-origin URLs can be fetched.' );
					}
					var response = await fetch( target.href, {
						headers: { Accept: 'text/markdown' }
					} );
					if ( ! response.ok ) {
						throw new Error( 'Fetch failed with HTTP ' + response.status );
					}
					return textResult( await response.text() );
				}
			}
		]
	} );
})();
