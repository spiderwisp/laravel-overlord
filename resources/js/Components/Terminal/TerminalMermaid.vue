<script setup>
import { ref, computed, onMounted, watch, nextTick, onBeforeUnmount } from 'vue';
import { useTerminalMermaid } from '../useTerminalMermaid';

// Load mermaid from CDN (similar to highlight.js approach)
let mermaid = null;
let mermaidLoading = false;
let mermaidLoadPromise = null;

function loadMermaid() {
	// Return existing promise if already loading
	if (mermaidLoadPromise) {
		return mermaidLoadPromise;
	}

	// Return immediately if already loaded
	if (mermaid) {
		return Promise.resolve(mermaid);
	}

	// If already loading, return the existing promise
	if (mermaidLoading) {
		return mermaidLoadPromise;
	}

	mermaidLoading = true;
	mermaidLoadPromise = new Promise((resolve, reject) => {
		// Check if mermaid is already available globally (from CDN)
		if (typeof window !== 'undefined' && window.mermaid) {
			mermaid = window.mermaid;
			mermaidLoading = false;
			resolve(mermaid);
			return;
		}

		// Load from CDN
		const script = document.createElement('script');
		script.src = 'https://cdn.jsdelivr.net/npm/mermaid@10.9.0/dist/mermaid.min.js';
		script.onload = () => {
			if (typeof window !== 'undefined' && window.mermaid) {
				mermaid = window.mermaid;
				mermaidLoading = false;
				resolve(mermaid);
			} else {
				mermaidLoading = false;
				reject(new Error('mermaid failed to load'));
			}
		};
		script.onerror = () => {
			mermaidLoading = false;
			reject(new Error('Failed to load mermaid from CDN'));
		};
		document.head.appendChild(script);
	});

	return mermaidLoadPromise;
}

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'navigate-to']);

const mermaidContainer = ref(null);
const mermaidId = ref(`mermaid-${Date.now()}`);
const { 
	loading, 
	diagram, 
	error, 
	loadDiagram, 
	regenerateDiagram,
	// Drill-down navigation
	navigationHistory,
	breadcrumb,
	isFocusedView,
	focusedModel,
	connectionDepth,
	loadFocusedDiagram,
	navigateToLevel,
	navigateBack,
	updateConnectionDepth,
	// Filters
	filters,
} = useTerminalMermaid();
const mermaidError = ref(null);
const mermaidInitialized = ref(false);

// UI state
const showFilters = ref(false);
const selectedNode = ref(null); // For showing node details

// Zoom and pan state
const zoom = ref(1);
const panX = ref(0);
const panY = ref(0);
const isPanning = ref(false);
const panStartX = ref(0);
const panStartY = ref(0);
const svgWrapper = ref(null);

// Initialize Mermaid when component mounts (if visible)
onMounted(async () => {
	if (props.visible) {
		try {
			const mermaidLib = await loadMermaid();
			mermaidLib.initialize({
				startOnLoad: false,
				theme: 'dark',
				themeVariables: {
					primaryColor: '#1e1e1e',
					primaryTextColor: '#e5e7eb',
					primaryBorderColor: '#4b5563',
					lineColor: '#6b7280',
					secondaryColor: '#374151',
					tertiaryColor: '#1f2937',
					background: '#1e1e1e',
					mainBkg: '#1e1e1e',
					secondBkg: '#252525',
					tertiaryBkg: '#2a2a2a',
					// Disable cluster/subgraph backgrounds
					clusterBkg: 'transparent',
					clusterBorder: 'transparent',
					subtitleColor: 'transparent',
				},
				flowchart: {
					useMaxWidth: false, // Allow horizontal scrolling for LR layout
					htmlLabels: true,
					curve: 'basis',
					nodeSpacing: 250, // Increased for better readability
					rankSpacing: 300, // Increased for better separation
					padding: 60,
					diagramPadding: 100,
				},
			});
			mermaid = mermaidLib;
			mermaidInitialized.value = true;
			
			// Load diagram if visible on mount
			if (!diagram.value) {
				await loadDiagram();
			}
		} catch (err) {
			console.error('Failed to initialize Mermaid:', err);
			mermaidError.value = 'Failed to load Mermaid: ' + err.message;
		}
	}
});

// Cleanup on unmount
onBeforeUnmount(() => {
	if (window.mermaidClickTimeout) {
		clearTimeout(window.mermaidClickTimeout);
	}
});

// Render diagram
async function renderDiagram() {
	if (!diagram.value || !mermaidContainer.value) return;
	
	// Ensure mermaid is loaded
	if (!mermaidInitialized.value) {
		try {
			const mermaidLib = await loadMermaid();
			if (!mermaid) {
				mermaidLib.initialize({
					startOnLoad: false,
					theme: 'dark',
					themeVariables: {
						primaryColor: '#1e1e1e',
						primaryTextColor: '#d4d4d4',
						primaryBorderColor: '#3e3e3e',
						lineColor: '#d4d4d4',
						secondaryColor: '#2e2e2e',
						tertiaryColor: '#1e1e1e',
					},
			flowchart: {
				useMaxWidth: false, // Allow horizontal scrolling for LR layout
				htmlLabels: true,
				curve: 'basis',
				nodeSpacing: 250, // Increased for better readability
				rankSpacing: 300, // Increased for better separation
				padding: 60,
				diagramPadding: 100,
			},
				});
				mermaid = mermaidLib;
				mermaidInitialized.value = true;
			}
		} catch (err) {
			console.error('Failed to load Mermaid:', err);
			error.value = 'Failed to load Mermaid: ' + err.message;
			return;
		}
	}
	
	try {
		// Clear previous content
		if (svgWrapper.value) {
			svgWrapper.value.innerHTML = '';
		}
		
		// Create a unique ID for this render
		const renderId = `mermaid-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
		
		// Render the diagram - mermaid.render returns { svg: string, bindFunctions: function }
		let { svg } = await mermaid.render(renderId, diagram.value);
		
		// Remove cluster backgrounds from SVG string before inserting (clean, no flash)
		// This is the proper way - modify the SVG before DOM insertion
		svg = removeClustersFromSvgString(svg);
		
		// Insert the cleaned SVG into the wrapper
		if (svgWrapper.value) {
			svgWrapper.value.innerHTML = svg;
		}
		
		// Reset zoom and pan after rendering
		nextTick(() => {
			resetZoom();
			makeNodesClickable();
			// Use setTimeout to ensure SVG is fully rendered
			setTimeout(() => {
				styleEdgeLabels(); // Style relationship labels
				highlightFocusedNode(); // Highlight focused node if in focused view
			}, 100);
			applyFilters(); // Apply filters after rendering
		});
	} catch (err) {
		console.error('Failed to render Mermaid diagram:', err);
		error.value = 'Failed to render diagram: ' + err.message;
		// Show error details in console for debugging
		if (err.message) {
			console.error('Mermaid error details:', err.message);
		}
	}
}

// Observer to catch clusters that appear after initial render
// Remove cluster backgrounds from SVG string before DOM insertion (clean approach)
function removeClustersFromSvgString(svgString) {
	// Create a temporary DOM element to parse and modify the SVG
	const parser = new DOMParser();
	const svgDoc = parser.parseFromString(svgString, 'image/svg+xml');
	const svgElement = svgDoc.documentElement;
	
	if (!svgElement) return svgString;
	
	// Find and remove all cluster elements
	const clusters = svgElement.querySelectorAll('.cluster, g.cluster, [class*="cluster"], [id*="cluster"]');
	clusters.forEach(cluster => {
		// Remove cluster background shapes
		const bgShapes = cluster.querySelectorAll('rect, polygon, path, ellipse');
		bgShapes.forEach(shape => {
			if (!shape.closest('.node') && !shape.closest('.edgeLabel')) {
				shape.remove();
			}
		});
		
		// Remove cluster labels
		const labels = cluster.querySelectorAll('.cluster-label, text[id*="cluster"], text[class*="cluster"]');
		labels.forEach(label => {
			if (!label.closest('.node') && !label.closest('.edgeLabel')) {
				label.remove();
			}
		});
	});
	
	// Remove any large background rectangles that aren't nodes or labels
	const allRects = svgElement.querySelectorAll('rect');
	allRects.forEach(rect => {
		if (rect.closest('.node') || rect.closest('.edgeLabel')) {
			return;
		}
		
		try {
			const width = parseFloat(rect.getAttribute('width') || '0');
			const height = parseFloat(rect.getAttribute('height') || '0');
			const fill = (rect.getAttribute('fill') || '').toLowerCase();
			const stroke = (rect.getAttribute('stroke') || '').toLowerCase();
			
			// Remove large rectangles that look like backgrounds
			if (width > 100 && height > 80) {
				const isGreen = fill.includes('green') || fill.includes('#10b981') || 
				                fill.includes('rgba(16') || fill.includes('rgb(16') ||
				                stroke.includes('green') || stroke.includes('#10b981');
				
				if (isGreen || (fill && fill !== 'none' && fill !== 'transparent')) {
					rect.remove();
				}
			}
		} catch (e) {
			// Ignore errors
		}
	});
	
	// Serialize back to string
	return new XMLSerializer().serializeToString(svgElement);
}

// Highlight focused node and center it in view
function highlightFocusedNode() {
	if (!svgWrapper.value || !isFocusedView.value || !focusedModel.value) return;
	
	const svg = svgWrapper.value.querySelector('svg');
	if (!svg) return;
	
	const focusedModelName = focusedModel.value.name;
	
	// Find the focused node
	const nodes = svg.querySelectorAll('g.node');
	let focusedNode = null;
	
	nodes.forEach(node => {
		const nodeText = node.textContent || node.innerText || '';
		const nodeId = node.getAttribute('id') || '';
		const dataId = node.getAttribute('data-id') || '';
		
		// Check if this is the focused node
		if (nodeText.includes(focusedModelName) || 
		    nodeId.includes(focusedModelName) || 
		    dataId.includes(focusedModelName)) {
			focusedNode = node;
		}
	});
	
	if (focusedNode) {
		// Add focused class
		focusedNode.classList.add('focused');
		
		// Make text larger
		const textElements = focusedNode.querySelectorAll('text, tspan, foreignObject');
		textElements.forEach(text => {
			const currentSize = parseFloat(text.getAttribute('font-size') || text.style.fontSize || '14');
			text.setAttribute('font-size', (currentSize * 1.5).toString());
			text.style.fontSize = (currentSize * 1.5) + 'px';
			text.style.fontWeight = '800';
		});
		
		// Make node larger
		const shapes = focusedNode.querySelectorAll('rect, polygon, ellipse');
		shapes.forEach(shape => {
			const currentWidth = parseFloat(shape.getAttribute('width') || '120');
			const currentHeight = parseFloat(shape.getAttribute('height') || '60');
			if (shape.tagName === 'rect') {
				shape.setAttribute('width', (currentWidth * 1.3).toString());
				shape.setAttribute('height', (currentHeight * 1.3).toString());
			}
			// Add glow effect
			shape.style.filter = 'drop-shadow(0 0 20px rgba(255, 107, 107, 0.8)) drop-shadow(0 4px 12px rgba(0, 0, 0, 0.6))';
		});
		
		// Center the focused node in view
		try {
			const bbox = focusedNode.getBBox();
			const svgBbox = svg.getBBox();
			const container = mermaidContainer.value;
			
			if (container && svgBbox.width > 0) {
				// Calculate center position
				const centerX = bbox.x + bbox.width / 2;
				const centerY = bbox.y + bbox.height / 2;
				
				// Get container dimensions
				const containerRect = container.getBoundingClientRect();
				const containerCenterX = containerRect.width / 2;
				const containerCenterY = containerRect.height / 2;
				
				// Calculate pan to center the node
				const currentZoom = zoom.value || 1;
				const targetPanX = containerCenterX - (centerX * currentZoom);
				const targetPanY = containerCenterY - (centerY * currentZoom);
				
				// Smoothly pan to center
				panX.value = targetPanX;
				panY.value = targetPanY;
			}
		} catch (e) {
			console.log('Could not center focused node:', e);
		}
	}
}

// Style edge labels based on relationship type with improved detection and modern design
function styleEdgeLabels() {
	if (!svgWrapper.value) return;
	
	const svg = svgWrapper.value.querySelector('svg');
	if (!svg) return;
	
	// Find all edge labels - Mermaid uses various patterns
	// Get all potential label containers, then filter out actual containers
	const allLabels = svg.querySelectorAll('g.edgeLabel, .edgeLabel, g[id*="L-"]');
	const edgeLabels = Array.from(allLabels).filter(label => {
		// Exclude containers (plural .edgeLabels class or multiple .edgeLabel children)
		return !label.classList.contains('edgeLabels') && 
		       label.querySelectorAll('.edgeLabel').length <= 1 &&
		       !(label.classList.contains('edgeLabel') && label.querySelector('g.edgeLabel') && label.querySelectorAll('g.edgeLabel').length > 1);
	});
	
	console.log('Found edge labels:', edgeLabels.length, 'out of', allLabels.length, 'total');
	
	// Color mapping for relationship types
	const relTypeColors = {
		hasMany: {
			color: '#3498db',
			bgColor: 'rgba(52, 152, 219, 0.25)',
			borderColor: '#3498db',
			textColor: '#60a5fa',
		},
		belongsTo: {
			color: '#10b981',
			bgColor: 'rgba(16, 185, 129, 0.25)',
			borderColor: '#10b981',
			textColor: '#34d399',
		},
		hasOne: {
			color: '#06b6d4',
			bgColor: 'rgba(6, 182, 212, 0.25)',
			borderColor: '#06b6d4',
			textColor: '#22d3ee',
		},
		belongsToMany: {
			color: '#a855f7',
			bgColor: 'rgba(168, 85, 247, 0.25)',
			borderColor: '#a855f7',
			textColor: '#c084fc',
		},
		morph: {
			color: '#f59e0b',
			bgColor: 'rgba(245, 158, 11, 0.25)',
			borderColor: '#f59e0b',
			textColor: '#fbbf24',
		},
		hasManyThrough: {
			color: '#9ca3af',
			bgColor: 'rgba(156, 163, 175, 0.25)',
			borderColor: '#9ca3af',
			textColor: '#d1d5db',
		},
	};
	
	edgeLabels.forEach((label, index) => {
		// Get text content - try multiple methods
		let labelText = '';
		
		// Method 1: Direct text elements
		const textElements = label.querySelectorAll('text, tspan');
		textElements.forEach(el => {
			const text = (el.textContent || el.innerHTML || '').trim();
			if (text) labelText += text + ' ';
		});
		
		// Method 2: ForeignObject
		if (!labelText) {
			const foreignObject = label.querySelector('foreignObject');
			if (foreignObject) {
				labelText = (foreignObject.textContent || foreignObject.innerText || '').trim();
			}
		}
		
		// Method 3: Get all text from label
		if (!labelText) {
			labelText = (label.textContent || label.innerText || '').trim();
		}
		
		if (!labelText) {
			return;
		}
		
		const lowerText = labelText.toLowerCase();
		
		// Determine relationship type
		let relType = '';
		if (lowerText.includes('hasmany') && !lowerText.includes('hasmanythrough')) {
			relType = 'hasMany';
		} else if (lowerText.includes('belongsto') && !lowerText.includes('belongstomany')) {
			relType = 'belongsTo';
		} else if (lowerText.includes('hasone')) {
			relType = 'hasOne';
		} else if (lowerText.includes('belongstomany')) {
			relType = 'belongsToMany';
		} else if (lowerText.includes('morph')) {
			relType = 'morph';
		} else if (lowerText.includes('hasmanythrough')) {
			relType = 'hasManyThrough';
		}
		
		if (!relType || !relTypeColors[relType]) {
			return;
		}
		
		const colors = relTypeColors[relType];
		
		// Find or create background rectangle - only for individual labels, not containers
		// Skip if this is actually a container (has class .edgeLabels plural, or contains multiple .edgeLabel children)
		const isContainer = label.classList.contains('edgeLabels') || 
		                    label.querySelectorAll('.edgeLabel').length > 1 ||
		                    (label.classList.contains('edgeLabel') && label.querySelector('g.edgeLabel'));
		
		if (isContainer) {
			return; // This is a container, not an individual label
		}
		
		// Check if there's an existing rect - use it if it's reasonable size, otherwise create new one
		let bgRect = label.querySelector('rect');
		const existingRectWidth = bgRect ? parseFloat(bgRect.getAttribute('width') || '0') : 0;
		const existingRectHeight = bgRect ? parseFloat(bgRect.getAttribute('height') || '0') : 0;
		
		// If existing rect is too large (container), remove it
		if (bgRect && (existingRectWidth > 300 || existingRectHeight > 200)) {
			bgRect.remove();
			bgRect = null;
		}
		
		if (!bgRect) {
			// Create background rect
			try {
				const bbox = label.getBBox();
				// More lenient size check - allow up to 200px wide for longer labels like "belongsToMany: favoritedBy"
				if (bbox.width > 250 || bbox.height > 80) {
					return; // Too large, probably a container
				}
				
				bgRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
				bgRect.setAttribute('x', (bbox.x - 6).toString());
				bgRect.setAttribute('y', (bbox.y - 4).toString());
				bgRect.setAttribute('width', (bbox.width + 12).toString());
				bgRect.setAttribute('height', (bbox.height + 8).toString());
				bgRect.setAttribute('rx', '10');
				bgRect.setAttribute('ry', '10');
				bgRect.style.pointerEvents = 'none';
				label.insertBefore(bgRect, label.firstChild);
			} catch (e) {
				// If getBBox fails, try to estimate from text elements
				if (textElements.length > 0) {
					try {
						// Get bounding box from all text elements combined
						let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
						textElements.forEach(textEl => {
							try {
								const textBbox = textEl.getBBox();
								minX = Math.min(minX, textBbox.x);
								minY = Math.min(minY, textBbox.y);
								maxX = Math.max(maxX, textBbox.x + textBbox.width);
								maxY = Math.max(maxY, textBbox.y + textBbox.height);
							} catch (e) {
								// Skip if getBBox fails for this element
							}
						});
						
						if (minX !== Infinity && minY !== Infinity) {
							const width = maxX - minX;
							const height = maxY - minY;
							
							// More lenient size check
							if (width > 250 || height > 80) {
								return;
							}
							
							bgRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
							bgRect.setAttribute('x', (minX - 6).toString());
							bgRect.setAttribute('y', (minY - 4).toString());
							bgRect.setAttribute('width', (width + 12).toString());
							bgRect.setAttribute('height', (height + 8).toString());
							bgRect.setAttribute('rx', '10');
							bgRect.setAttribute('ry', '10');
							bgRect.style.pointerEvents = 'none';
							label.insertBefore(bgRect, label.firstChild);
						}
					} catch (e2) {
						console.log('Could not create background:', e2, label);
					}
				}
			}
		}
		
		// Style background - always style if we have a rect (even if it existed before)
		if (bgRect) {
			bgRect.setAttribute('fill', colors.bgColor);
			bgRect.setAttribute('stroke', colors.borderColor);
			bgRect.setAttribute('stroke-width', '2.5');
			bgRect.setAttribute('rx', '10');
			bgRect.setAttribute('ry', '10');
			bgRect.style.filter = 'drop-shadow(0 4px 10px rgba(0, 0, 0, 0.5))';
			bgRect.style.transition = 'all 0.3s ease';
			bgRect.style.display = 'block';
			bgRect.style.visibility = 'visible';
			bgRect.style.opacity = '1';
		} else {
			// If we couldn't create a background, log it for debugging
			console.warn('Could not create background for label:', labelText, label);
		}
		
		// Style text elements with better alignment
		textElements.forEach((textElement, textIndex) => {
			textElement.setAttribute('fill', colors.textColor);
			textElement.setAttribute('font-weight', '700');
			textElement.setAttribute('font-size', '13px');
			textElement.setAttribute('font-family', 'Inter, -apple-system, BlinkMacSystemFont, sans-serif');
			textElement.setAttribute('text-anchor', 'middle');
			textElement.setAttribute('dominant-baseline', 'middle');
			textElement.style.fontWeight = '700';
			textElement.style.fontSize = '13px';
			textElement.style.letterSpacing = '0.4px';
			textElement.style.textShadow = '0 2px 4px rgba(0, 0, 0, 0.7)';
			
			// Fix alignment - ensure text is centered
			if (textIndex === 0 && bgRect) {
				try {
					const textBBox = textElement.getBBox();
					const bgX = parseFloat(bgRect.getAttribute('x'));
					const bgY = parseFloat(bgRect.getAttribute('y'));
					const bgWidth = parseFloat(bgRect.getAttribute('width'));
					const bgHeight = parseFloat(bgRect.getAttribute('height'));
					
					// Center text in background
					const centerX = bgX + bgWidth / 2;
					const centerY = bgY + bgHeight / 2;
					
					textElement.setAttribute('x', centerX.toString());
					textElement.setAttribute('y', centerY.toString());
				} catch (e) {
					// Ignore alignment errors
				}
			}
		});
		
		// Find and style associated edge path
		const labelId = label.getAttribute('id') || '';
		let edgePath = null;
		
		// Try ID-based matching
		if (labelId) {
			const patterns = [
				labelId.replace('L-', 'LS-'),
				labelId.replace(/L-(\d+)-(\d+)/, 'LS-$1-$2'),
				labelId.replace('edgeLabel', 'edge'),
			];
			
			for (const pattern of patterns) {
				edgePath = svg.querySelector(`#${pattern}`) || 
				           svg.querySelector(`path[id*="${pattern}"]`);
				if (edgePath) break;
			}
		}
		
		// Proximity-based matching if ID failed
		if (!edgePath) {
			const allPaths = svg.querySelectorAll('path.edge-path, path[id*="LS-"], path[id*="edge"]');
			let minDist = Infinity;
			
			try {
				const labelRect = label.getBoundingClientRect();
				allPaths.forEach(path => {
					try {
						const pathRect = path.getBoundingClientRect();
						const dist = Math.sqrt(
							Math.pow(pathRect.left + pathRect.width/2 - (labelRect.left + labelRect.width/2), 2) +
							Math.pow(pathRect.top + pathRect.height/2 - (labelRect.top + labelRect.height/2), 2)
						);
						if (dist < minDist && dist < 250) {
							minDist = dist;
							edgePath = path;
						}
					} catch (e) {}
				});
			} catch (e) {}
		}
		
		// Style edge path
		if (edgePath) {
			edgePath.setAttribute('stroke', colors.color);
			edgePath.setAttribute('stroke-width', '3');
			edgePath.style.filter = 'drop-shadow(0 2px 6px rgba(0, 0, 0, 0.4))';
			edgePath.style.transition = 'all 0.3s ease';
			
			if (relType === 'morph' || relType === 'hasManyThrough') {
				edgePath.setAttribute('stroke-dasharray', relType === 'morph' ? '6,4' : '8,4');
			}
			
			edgePath.classList.add(`rel-${relType}`);
		}
		
		// Style arrowheads
		const arrowheads = svg.querySelectorAll('marker path, marker polygon, .arrowheadPath, path[id*="arrowhead"]');
		arrowheads.forEach(ah => {
			const fill = ah.getAttribute('fill');
			if (fill && fill !== 'none' && fill !== 'transparent') {
				ah.setAttribute('fill', colors.color);
			}
		});
		
		// Add classes and data attributes
		label.classList.add(`rel-${relType}`);
		label.setAttribute('data-rel-type', relType);
	});
	
	// Second pass: style edge paths that might have been missed
	const allEdgePaths = svg.querySelectorAll('path.edge-path, path[id*="LS-"], path[id*="edge"]');
	allEdgePaths.forEach(edgePath => {
		if (!edgePath.classList.toString().match(/rel-\w+/)) {
			const edgeId = edgePath.getAttribute('id') || '';
			let label = null;
			
			if (edgeId) {
				const patterns = [
					edgeId.replace('LS-', 'L-'),
					edgeId.replace('edge', 'edgeLabel'),
				];
				
				for (const pattern of patterns) {
					label = svg.querySelector(`#${pattern}`) || 
					        svg.querySelector(`g[id*="${pattern}"]`);
					if (label) break;
				}
			}
			
			if (label) {
				const relType = label.getAttribute('data-rel-type');
				if (relType && relTypeColors[relType]) {
					const colors = relTypeColors[relType];
					edgePath.setAttribute('stroke', colors.color);
					edgePath.setAttribute('stroke-width', '3');
					edgePath.classList.add(`rel-${relType}`);
					if (relType === 'morph' || relType === 'hasManyThrough') {
						edgePath.setAttribute('stroke-dasharray', relType === 'morph' ? '6,4' : '8,4');
					}
				}
			}
		}
	});
}

// Make nodes clickable for navigation using event delegation
let nodeClickStartX = 0;
let nodeClickStartY = 0;
let nodeWasDragged = false;
let currentNodeText = '';
let currentNodeId = '';

function makeNodesClickable() {
	if (!svgWrapper.value) return;
	
	const svg = svgWrapper.value.querySelector('svg');
	if (!svg) return;
	
	// Remove old listeners if any
	svg.removeEventListener('mousedown', handleSvgMouseDown);
	svg.removeEventListener('mousemove', handleSvgMouseMove);
	svg.removeEventListener('click', handleSvgClick);
	
	// Use event delegation on the SVG
	svg.addEventListener('mousedown', handleSvgMouseDown, true);
	svg.addEventListener('mousemove', handleSvgMouseMove, true);
	svg.addEventListener('click', handleSvgClick, true);
	
	// Find all node elements - Mermaid uses g.node with class "node"
	const nodes = svg.querySelectorAll('g.node');
	console.log('Found nodes:', nodes.length);
	
	nodes.forEach(node => {
		node.style.cursor = 'pointer';
		node.style.pointerEvents = 'all';
		
		// Make all child shapes clickable
		const shapes = node.querySelectorAll('rect, circle, ellipse, polygon, path, foreignObject');
		shapes.forEach(shape => {
			shape.style.cursor = 'pointer';
			shape.style.pointerEvents = 'all';
		});
		
		// Also make text elements clickable
		const texts = node.querySelectorAll('text, tspan');
		texts.forEach(text => {
			text.style.cursor = 'pointer';
			text.style.pointerEvents = 'all';
		});
		
		// Add hover effect
		node.addEventListener('mouseenter', () => {
			shapes.forEach(shape => {
				shape.style.opacity = '0.8';
			});
		});
		
		node.addEventListener('mouseleave', () => {
			shapes.forEach(shape => {
				shape.style.opacity = '1';
			});
		});
	});
}

function handleSvgMouseDown(e) {
	// Find the closest node - could be clicking on text, shape, or node itself
	let node = e.target.closest('g.node');
	
	// If not found, try finding parent node from text/shape/foreignObject
	if (!node) {
		const textParent = e.target.closest('text, tspan')?.closest('g.node');
		const shapeParent = e.target.closest('rect, circle, ellipse, polygon, path')?.closest('g.node');
		const foreignParent = e.target.closest('foreignObject')?.closest('g.node');
		node = textParent || shapeParent || foreignParent;
	}
	
	if (node) {
		nodeClickStartX = e.clientX;
		nodeClickStartY = e.clientY;
		nodeWasDragged = false;
		
		// Get text from node - try multiple methods
		// First try data-id attribute (Mermaid stores the label here)
		currentNodeText = node.getAttribute('data-id') || '';
		currentNodeId = node.getAttribute('id') || '';
		
		// If no data-id, try to find text in foreignObject (HTML labels)
		if (!currentNodeText) {
			const foreignObject = node.querySelector('foreignObject');
			if (foreignObject) {
				const labelElement = foreignObject.querySelector('.nodeLabel, span, div');
				if (labelElement) {
					currentNodeText = labelElement.textContent || labelElement.innerText || '';
				}
			}
		}
		
		// Fallback to regular text element
		if (!currentNodeText) {
			const textElement = node.querySelector('text, tspan');
			if (textElement) {
				currentNodeText = textElement.textContent || textElement.innerHTML || '';
			}
		}
		
		// Last resort: try data-label or title
		if (!currentNodeText) {
			currentNodeText = node.getAttribute('data-label') || node.getAttribute('title') || '';
		}
		
		console.log('Node mousedown:', currentNodeText, currentNodeId, node);
		
		e.stopPropagation(); // Prevent panning
		e.preventDefault();
		return true; // Indicate we handled it
	}
	return false;
}

function handleSvgMouseMove(e) {
	if (nodeClickStartX !== 0 || nodeClickStartY !== 0) {
		const deltaX = Math.abs(e.clientX - nodeClickStartX);
		const deltaY = Math.abs(e.clientY - nodeClickStartY);
		if (deltaX > 5 || deltaY > 5) {
			nodeWasDragged = true;
		}
	}
}

function handleSvgClick(e) {
	console.log('Click event fired:', e.target, 'wasDragged:', nodeWasDragged, 'currentText:', currentNodeText);
	
	// Find the closest node
	let node = e.target.closest('g.node');
	
	// If not found, try finding parent node from text/shape/foreignObject
	if (!node) {
		const textParent = e.target.closest('text, tspan')?.closest('g.node');
		const shapeParent = e.target.closest('rect, circle, ellipse, polygon, path')?.closest('g.node');
		const foreignParent = e.target.closest('foreignObject')?.closest('g.node');
		node = textParent || shapeParent || foreignParent;
	}
	
	console.log('Found node:', node, 'wasDragged:', nodeWasDragged);
	
	if (node) {
		// Get text from node if we don't have it
		if (!currentNodeText) {
			// First try data-id attribute (Mermaid stores the label here)
			currentNodeText = node.getAttribute('data-id') || '';
			currentNodeId = node.getAttribute('id') || '';
			
			// If no data-id, try to find text in foreignObject (HTML labels)
			if (!currentNodeText) {
				const foreignObject = node.querySelector('foreignObject');
				if (foreignObject) {
					const labelElement = foreignObject.querySelector('.nodeLabel, span, div');
					if (labelElement) {
						currentNodeText = labelElement.textContent || labelElement.innerText || '';
					}
				}
			}
			
			// Fallback to regular text element
			if (!currentNodeText) {
				const textElement = node.querySelector('text, tspan');
				if (textElement) {
					currentNodeText = textElement.textContent || textElement.innerHTML || '';
				}
			}
			
			// Last resort: try data-label or title
			if (!currentNodeText) {
				currentNodeText = node.getAttribute('data-label') || node.getAttribute('title') || '';
			}
			
			currentNodeId = node.getAttribute('id') || '';
		}
		
		console.log('Node click - text:', currentNodeText, 'id:', currentNodeId, 'wasDragged:', nodeWasDragged);
		
		if (currentNodeText && currentNodeText.trim() && !nodeWasDragged) {
			e.stopPropagation();
			e.preventDefault();
			
			// Store text and ID before resetting (for setTimeout closure)
			const clickedText = currentNodeText.trim();
			const clickedId = currentNodeId;
			const clickedNode = node;
			
			// Detect double-click
			const now = Date.now();
			const timeSinceLastClick = now - lastClickTime;
			const isDoubleClick = timeSinceLastClick < 300 && lastClickNode === currentNodeId;
			
			if (isDoubleClick) {
				// Clear the single-click timeout
				if (window.mermaidClickTimeout) {
					clearTimeout(window.mermaidClickTimeout);
					window.mermaidClickTimeout = null;
				}
				handleNodeDoubleClick(clickedText, clickedId, clickedNode);
				lastClickTime = 0; // Reset to prevent triple-click
				lastClickNode = null;
			} else {
				// Single click - wait a bit to see if it's a double click
				lastClickTime = now;
				lastClickNode = currentNodeId;
				
				if (window.mermaidClickTimeout) {
					clearTimeout(window.mermaidClickTimeout);
				}
				
				window.mermaidClickTimeout = setTimeout(() => {
					handleNodeClick(clickedText, clickedId, clickedNode, false);
					window.mermaidClickTimeout = null;
				}, 300);
			}
		}
		
		// Reset
		nodeClickStartX = 0;
		nodeClickStartY = 0;
		nodeWasDragged = false;
		currentNodeText = '';
		currentNodeId = '';
	} else {
		// Reset even if not a node click
		nodeClickStartX = 0;
		nodeClickStartY = 0;
		nodeWasDragged = false;
	}
}

// Handle node click - single click for selection, double click for drill-down
let lastClickTime = 0;
let lastClickNode = null;

function handleNodeClick(nodeText, nodeId, nodeElement = null, isDoubleClick = false) {
	console.log('Node clicked:', nodeText, nodeId, 'isDoubleClick:', isDoubleClick, 'nodeElement:', nodeElement);
	
	if (!nodeText || !nodeText.trim()) {
		console.warn('No node text provided', { nodeText, nodeId });
		return;
	}
	
	// Extract component name (remove any HTML tags like <br/>)
	const cleanText = nodeText.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, '').trim();
	const firstLine = cleanText.split(/\s+/)[0]; // Get first word
	const text = cleanText.toLowerCase();
	
	// Get node element if not provided
	if (!nodeElement && svgWrapper.value) {
		nodeElement = svgWrapper.value.querySelector(`g.node[id="${nodeId}"]`) || 
		               svgWrapper.value.querySelector(`g.node[data-id="${firstLine}"]`);
	}
	
	// Check node's CSS class first (most reliable - Mermaid applies these classes)
	const nodeClasses = nodeElement?.getAttribute('class') || '';
	
	// Determine node type
	let nodeType = null;
	if (nodeClasses.includes('controller')) {
		nodeType = 'controller';
	} else if (nodeClasses.includes('service')) {
		nodeType = 'service';
	} else if (nodeClasses.includes('model')) {
		nodeType = 'model';
	} else if (nodeClasses.includes('job')) {
		nodeType = 'job';
	} else if (nodeClasses.includes('route') || text.startsWith('/')) {
		nodeType = 'route';
	}
	
	console.log('Determined node type:', nodeType, 'from classes:', nodeClasses, 'firstLine:', firstLine);
	
	// Handle double-click: drill down into focused view
	if (isDoubleClick && nodeType) {
		console.log('Drilling down into:', firstLine, nodeType);
		loadFocusedDiagram(firstLine, nodeType);
		return;
	}
	
	// Handle single click: show node details
	selectedNode.value = {
		name: firstLine,
		type: nodeType,
		nodeId: nodeId,
	};
	
	console.log('Selected node:', selectedNode.value);
}

// Handle double click detection
function handleNodeDoubleClick(nodeText, nodeId, nodeElement) {
	// Extract model name
	const cleanText = nodeText.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, '').trim();
	const modelName = cleanText.split(/\s+/)[0];
	console.log('Double-clicked model:', modelName);
	loadFocusedDiagram(modelName);
}

// Navigate to class/controller/etc from selected node
function navigateToNodeDetails() {
	if (!selectedNode.value) return;
	
	const node = selectedNode.value;
	if (node.type) {
		emit('navigate-to', {
			type: node.type,
			identifier: node.name,
			name: node.name,
		});
	}
}

// Watch for diagram changes
watch(diagram, () => {
	if (diagram.value && props.visible && mermaidInitialized.value) {
		nextTick(() => {
			renderDiagram();
		});
	}
}, { immediate: false });

// Watch for focused model changes to highlight it
watch([isFocusedView, focusedModel], () => {
	if (isFocusedView.value && focusedModel.value && svgWrapper.value) {
		setTimeout(() => {
			highlightFocusedNode();
		}, 200);
	}
}, { deep: true });

// Watch for namespace filter changes and apply them

watch(() => filters.value.namespace, () => {
	if (diagram.value && svgWrapper.value) {
		nextTick(() => {
			applyFilters();
		});
	}
});

// Apply filters to hide/show nodes
function applyFilters() {
	if (!svgWrapper.value) return;
	
	const svg = svgWrapper.value.querySelector('svg');
	if (!svg) return;
	
	const nodes = svg.querySelectorAll('g.node');
	const namespaceFilter = filters.value.namespace.toLowerCase().trim();
	
	nodes.forEach(node => {
		const nodeId = node.getAttribute('id') || '';
		const dataId = node.getAttribute('data-id') || '';
		
		// Check namespace filter (all nodes are models now)
		const namespaceMatch = !namespaceFilter || 
			dataId.toLowerCase().includes(namespaceFilter) ||
			nodeId.toLowerCase().includes(namespaceFilter);
		
		// Show/hide node
		if (namespaceMatch) {
			node.style.display = '';
		} else {
			node.style.display = 'none';
		}
	});
	
	// Hide/show edges connected to hidden nodes
	const edges = svg.querySelectorAll('path.edge-path, line.edge');
	edges.forEach(edge => {
		const edgeId = edge.getAttribute('id') || '';
		const fromMatch = edgeId.match(/flowchart-(.+?)-/);
		const toMatch = edgeId.match(/-(\d+)-flowchart-(.+?)-/);
		
		if (fromMatch && toMatch) {
			const fromId = fromMatch[1];
			const toId = toMatch[1];
			const fromNode = svg.querySelector(`g.node[id*="${fromId}"]`);
			const toNode = svg.querySelector(`g.node[id*="${toId}"]`);
			
			if (fromNode && toNode) {
				const fromVisible = fromNode.style.display !== 'none';
				const toVisible = toNode.style.display !== 'none';
				edge.style.display = (fromVisible && toVisible) ? '' : 'none';
			}
		}
	});
}

// Watch for visibility changes and render if diagram exists
watch(() => props.visible, async (newValue) => {
	if (newValue) {
		// Ensure mermaid is loaded first
		if (!mermaidInitialized.value) {
			try {
				const mermaidLib = await loadMermaid();
				mermaidLib.initialize({
					startOnLoad: false,
					theme: 'dark',
					themeVariables: {
						primaryColor: '#1e1e1e',
						primaryTextColor: '#d4d4d4',
						primaryBorderColor: '#3e3e3e',
						lineColor: '#d4d4d4',
						secondaryColor: '#2e2e2e',
						tertiaryColor: '#1e1e1e',
					},
			flowchart: {
				useMaxWidth: false, // Allow horizontal scrolling for LR layout
				htmlLabels: true,
				curve: 'basis',
				nodeSpacing: 250, // Increased for better readability
				rankSpacing: 300, // Increased for better separation
				padding: 60,
				diagramPadding: 100,
			},
				});
				mermaid = mermaidLib;
				mermaidInitialized.value = true;
			} catch (err) {
				console.error('Failed to load Mermaid:', err);
				mermaidError.value = 'Failed to load Mermaid: ' + err.message;
				return;
			}
		}
		
		// Load diagram if not already loaded
		if (!diagram.value) {
			await loadDiagram();
		} else if (mermaidInitialized.value) {
			// Diagram exists, just render it
			nextTick(() => {
				renderDiagram();
			});
		}
	}
}, { immediate: true });

// Handle detail level toggle
async function handleToggleDetail() {
	await toggleDetailLevel();
}

// Handle regenerate
async function handleRegenerate() {
	await regenerateDiagram();
}

// Zoom functions
function zoomIn() {
	const newZoom = Math.min(10, zoom.value * 1.2);
	zoom.value = newZoom;
}

function zoomOut() {
	const newZoom = Math.max(0.2, zoom.value / 1.2);
	zoom.value = newZoom;
}

function resetZoom() {
	zoom.value = 1;
	panX.value = 0;
	panY.value = 0;
}

// Handle mouse wheel for zooming
function handleWheel(event) {
	if (!mermaidContainer.value) return;
	
	event.preventDefault();
	
	const rect = mermaidContainer.value.getBoundingClientRect();
	const mouseX = event.clientX - rect.left;
	const mouseY = event.clientY - rect.top;
	
	// Zoom factor
	const zoomFactor = event.deltaY > 0 ? 0.9 : 1.1;
	const newZoom = Math.max(0.2, Math.min(10, zoom.value * zoomFactor));
	
	// Zoom towards mouse position
	const zoomChange = newZoom / zoom.value;
	panX.value = mouseX - (mouseX - panX.value) * zoomChange;
	panY.value = mouseY - (mouseY - panY.value) * zoomChange;
	
	zoom.value = newZoom;
}

// Handle mouse down for panning
function handleMouseDown(event) {
	if (!mermaidContainer.value) return;
	if (event.button !== 0) return; // Only left mouse button
	
	// Don't start panning if clicking on a node or its children
	const target = event.target;
	if (target.closest('g.node') || target.closest('[id^="flowchart-"]') || 
		target.closest('rect') || target.closest('circle') || target.closest('polygon') ||
		target.closest('path') || target.closest('ellipse')) {
		// Check if it's actually part of a node
		const nodeGroup = target.closest('g.node') || target.closest('g[id^="flowchart-"]');
		if (nodeGroup) {
			return; // Let the node click handler deal with it
		}
	}
	
	const rect = mermaidContainer.value.getBoundingClientRect();
	const rawX = event.clientX - rect.left;
	const rawY = event.clientY - rect.top;
	
	isPanning.value = true;
	panStartX.value = rawX;
	panStartY.value = rawY;
	
	event.preventDefault();
}

// Handle mouse move for panning
function handleMouseMove(event) {
	if (!isPanning.value || !mermaidContainer.value) return;
	
	const rect = mermaidContainer.value.getBoundingClientRect();
	const rawX = event.clientX - rect.left;
	const rawY = event.clientY - rect.top;
	
	const deltaX = rawX - panStartX.value;
	const deltaY = rawY - panStartY.value;
	
	panX.value += deltaX;
	panY.value += deltaY;
	panStartX.value = rawX;
	panStartY.value = rawY;
	
	event.preventDefault();
}

// Handle mouse up for panning
function handleMouseUp() {
	isPanning.value = false;
}

// Handle mouse leave for panning
function handleMouseLeave() {
	isPanning.value = false;
}

</script>

<template>
	<div v-if="visible" class="terminal-mermaid">
		<div class="mermaid-header">
			<div class="mermaid-controls-left">
				<!-- Breadcrumb Navigation -->
				<div v-if="isFocusedView || breadcrumb.length > 0" class="mermaid-breadcrumb">
					<button 
						@click="navigateToLevel(-1)"
						class="breadcrumb-home"
						title="Back to root"
					>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
							<polyline points="9 22 9 12 15 12 15 22"></polyline>
						</svg>
					</button>
					<template v-if="breadcrumb.length > 0">
						<template v-for="(item, index) in breadcrumb" :key="index">
							<span class="breadcrumb-separator">›</span>
							<button 
								@click="navigateToLevel(item.level)"
								class="breadcrumb-item"
								:class="{ 'breadcrumb-active': index === breadcrumb.length - 1 }"
							>
								{{ item.name }}
							</button>
						</template>
					</template>
					<template v-else-if="focusedModel">
						<span class="breadcrumb-separator">›</span>
						<span class="breadcrumb-item breadcrumb-active">{{ focusedModel }}</span>
					</template>
				</div>
				
				<!-- Main controls -->
				<button 
					@click="handleRegenerate" 
					:disabled="loading"
					class="btn-regenerate"
					title="Regenerate diagram"
				>
					Regenerate
				</button>
				<button 
					@click="showFilters = !showFilters"
					class="btn-filters"
					:class="{ 'active': showFilters }"
					title="Toggle filters"
				>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
					</svg>
					Filters
				</button>
			</div>
			<div class="mermaid-controls-right">
				<span v-if="isFocusedView" class="focused-badge">Focused</span>
				<span class="zoom-level" v-if="diagram && !loading && !error && !mermaidError">Zoom: {{ Math.round(zoom * 100) }}%</span>
			</div>
		</div>
		
		<!-- Filter Panel -->
		<div v-if="showFilters" class="mermaid-filters">
			<div class="filters-section">
				<h4>Namespace Filter</h4>
				<input 
					type="text" 
					v-model="filters.namespace" 
					placeholder="Filter by namespace..."
					class="filter-input"
				/>
			</div>
			<div v-if="isFocusedView" class="filters-section">
				<h4>Connection Depth</h4>
				<div class="connection-depth-controls">
					<label>
						<input 
							type="radio" 
							v-model="connectionDepth" 
							:value="1"
							@change="updateConnectionDepth(1)"
						/>
						1 Level
					</label>
					<label>
						<input 
							type="radio" 
							v-model="connectionDepth" 
							:value="2"
							@change="updateConnectionDepth(2)"
						/>
						2 Levels
					</label>
					<label>
						<input 
							type="radio" 
							v-model="connectionDepth" 
							:value="3"
							@change="updateConnectionDepth(3)"
						/>
						3 Levels
					</label>
				</div>
			</div>
		</div>
		
		<!-- Node Details Panel -->
		<div v-if="selectedNode" class="mermaid-node-details">
			<div class="node-details-header">
				<h4>{{ selectedNode.name }}</h4>
				<button @click="selectedNode = null" class="btn-close-details">×</button>
			</div>
			<div class="node-details-content">
				<p><strong>Type:</strong> {{ selectedNode.type || 'Unknown' }}</p>
				<button @click="navigateToNodeDetails" class="btn-view-details">
					View Details
				</button>
						<button 
							@click="loadFocusedDiagram(selectedNode.name)"
							class="btn-drill-down"
						>
							Drill Down
						</button>
			</div>
		</div>
		
		<div v-if="loading" class="mermaid-loading">
			<div class="loading-spinner"></div>
			<p>Generating diagram...</p>
		</div>
		
		<div v-else-if="mermaidError" class="mermaid-error">
			<p class="error-message">{{ mermaidError }}</p>
			<p class="error-hint">Please run: <code>npm run dev</code> to rebuild assets</p>
		</div>
		
		<div v-else-if="error" class="mermaid-error">
			<p class="error-message">{{ error }}</p>
			<button @click="loadDiagram()" class="btn-retry">Retry</button>
		</div>
		
		<div v-else-if="!diagram" class="mermaid-loading">
			<p>No diagram data. Click "Regenerate" to generate a diagram.</p>
		</div>
		
		<div 
			v-else 
			ref="mermaidContainer" 
			:id="mermaidId"
			class="mermaid-container"
			@wheel="handleWheel"
			@mousedown="handleMouseDown"
			@mousemove="handleMouseMove"
			@mouseup="handleMouseUp"
			@mouseleave="handleMouseLeave"
			:style="{ cursor: isPanning ? 'grabbing' : 'grab' }"
		>
			<div 
				ref="svgWrapper"
				class="mermaid-svg-wrapper"
				:style="{
					transform: `translate(${panX}px, ${panY}px) scale(${zoom})`,
					transformOrigin: '0 0'
				}"
			></div>
		</div>
		
		<!-- Zoom Controls -->
		<div v-if="diagram && !loading && !error && !mermaidError" class="mermaid-zoom-controls">
			<button 
				@click="zoomIn"
				class="mermaid-zoom-btn"
				title="Zoom In (Mouse Wheel Up)"
			>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<circle cx="11" cy="11" r="8"></circle>
					<path d="M11 8v6M8 11h6"></path>
				</svg>
			</button>
			<button 
				@click="zoomOut"
				class="mermaid-zoom-btn"
				title="Zoom Out (Mouse Wheel Down)"
			>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<circle cx="11" cy="11" r="8"></circle>
					<path d="M8 11h6"></path>
				</svg>
			</button>
			<button 
				@click="resetZoom"
				class="mermaid-zoom-btn"
				title="Reset Zoom"
			>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
					<path d="M21 3v5h-5"></path>
					<path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
					<path d="M3 21v-5h5"></path>
				</svg>
			</button>
			<div class="mermaid-zoom-level">{{ Math.round(zoom * 100) }}%</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-mermaid {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #d4d4d4);
}

.mermaid-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e3e);
	background: var(--terminal-bg-secondary, #252525);
	flex-wrap: wrap;
	gap: 12px;
}

.mermaid-controls-left {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.mermaid-controls-right {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mermaid-breadcrumb {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e3e);
	border-radius: 6px;
}

.breadcrumb-home {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #a0a0a0);
	cursor: pointer;
	border-radius: 4px;
	transition: all 0.2s;
}

.breadcrumb-home:hover {
	background: var(--terminal-button-hover, #3e3e3e);
	color: var(--terminal-text, #d4d4d4);
}

.breadcrumb-separator {
	color: var(--terminal-text-secondary, #6b7280);
	margin: 0 4px;
	font-size: 14px;
}

.breadcrumb-item {
	padding: 4px 8px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #9ca3af);
	cursor: pointer;
	border-radius: 4px;
	font-size: 13px;
	transition: all 0.2s;
}

.breadcrumb-item:hover {
	color: var(--terminal-text, #d4d4d4);
	background: var(--terminal-button-hover, #3e3e3e);
}

.breadcrumb-item.breadcrumb-active {
	color: var(--terminal-text, #d4d4d4);
	font-weight: 500;
}

.btn-toggle-detail,
.btn-regenerate,
.btn-filters {
	padding: 6px 12px;
	background: var(--terminal-button-bg, #3e3e3e);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 6px;
	cursor: pointer;
	font-size: 13px;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	gap: 6px;
}

.btn-filters.active {
	background: var(--terminal-accent, #3498db);
	border-color: var(--terminal-accent, #3498db);
	color: #fff;
}

.btn-toggle-detail:hover:not(:disabled),
.btn-regenerate:hover:not(:disabled),
.btn-filters:hover:not(:disabled) {
	background: var(--terminal-button-hover, #4e4e4e);
	border-color: var(--terminal-border-hover, #5e5e5e);
	transform: translateY(-1px);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.btn-toggle-detail:disabled,
.btn-regenerate:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.mermaid-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.detail-badge,
.focused-badge {
	padding: 4px 8px;
	background: var(--terminal-badge-bg, #2e2e2e);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 6px;
	font-size: 12px;
	color: var(--terminal-text-secondary, #a0a0a0);
	font-weight: 500;
}

.focused-badge {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	border-color: #667eea;
	color: #fff;
}

.zoom-level {
	padding: 4px 8px;
	background: var(--terminal-badge-bg, #2e2e2e);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 6px;
	font-size: 12px;
	color: var(--terminal-text-secondary, #a0a0a0);
	font-weight: 500;
}

.mermaid-loading,
.mermaid-error {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	flex: 1;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--terminal-border, #3e3e3e);
	border-top-color: var(--terminal-accent, #3498db);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin-bottom: 16px;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.error-message {
	color: var(--terminal-error, #e74c3c);
	margin-bottom: 16px;
}

.error-hint {
	color: var(--terminal-text-secondary, #a0a0a0);
	margin-bottom: 16px;
	font-size: 12px;
}

.error-hint code {
	background: var(--terminal-bg-secondary, #2e2e2e);
	padding: 2px 6px;
	border-radius: 3px;
	font-family: monospace;
}

.btn-retry {
	padding: 8px 16px;
	background: var(--terminal-button-bg, #3e3e3e);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 4px;
	cursor: pointer;
}

.btn-retry:hover {
	background: var(--terminal-button-hover, #4e4e4e);
}

/* Filter Panel */
.mermaid-filters {
	padding: 16px;
	background: var(--terminal-bg-secondary, #252525);
	border-bottom: 1px solid var(--terminal-border, #3e3e3e);
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
}

.filters-section h4 {
	margin: 0 0 8px 0;
	font-size: 13px;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.filter-checkboxes {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.filter-checkboxes label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-size: 13px;
	color: var(--terminal-text-secondary, #a0a0a0);
}

.filter-checkboxes input[type="checkbox"] {
	cursor: pointer;
}

.filter-input,
.filter-select {
	width: 100%;
	padding: 6px 10px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
}

.filter-input:focus,
.filter-select:focus {
	outline: none;
	border-color: var(--terminal-accent, #3498db);
	box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

.connection-depth-controls {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-bottom: 12px;
}

.connection-depth-controls label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-size: 13px;
	color: var(--terminal-text-secondary, #a0a0a0);
}

.checkbox-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-size: 13px;
	color: var(--terminal-text-secondary, #a0a0a0);
}

/* Node Details Panel */
.mermaid-node-details {
	position: fixed;
	top: 120px;
	right: 20px;
	width: 300px;
	max-width: calc(100vw - 40px);
	background: var(--terminal-bg-secondary, #252525);
	border: 1px solid var(--terminal-border, #3e3e3e);
	border-radius: 8px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
	z-index: 1000;
}

.node-details-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e3e);
}

.node-details-header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.btn-close-details {
	width: 24px;
	height: 24px;
	padding: 0;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #a0a0a0);
	cursor: pointer;
	font-size: 20px;
	line-height: 1;
	border-radius: 4px;
	transition: all 0.2s;
}

.btn-close-details:hover {
	background: var(--terminal-button-hover, #3e3e3e);
	color: var(--terminal-text, #d4d4d4);
}

.node-details-content {
	padding: 12px 16px;
}

.node-details-content p {
	margin: 0 0 12px 0;
	font-size: 13px;
	color: var(--terminal-text-secondary, #a0a0a0);
}

.btn-view-details,
.btn-drill-down {
	width: 100%;
	padding: 8px 12px;
	margin-bottom: 8px;
	background: var(--terminal-button-bg, #3e3e3e);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 6px;
	cursor: pointer;
	font-size: 13px;
	transition: all 0.2s;
}

.btn-view-details:hover,
.btn-drill-down:hover {
	background: var(--terminal-button-hover, #4e4e4e);
	border-color: var(--terminal-border-hover, #5e5e5e);
	transform: translateY(-1px);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.btn-drill-down {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	border-color: #667eea;
	color: #fff;
}

.mermaid-container {
	flex: 1;
	overflow: auto; /* Allow scrolling for LR layout */
	position: relative;
	background: var(--terminal-bg, #1e1e1e);
	cursor: grab;
}

.mermaid-container:active {
	cursor: grabbing;
}

.mermaid-container :deep(.node) {
	cursor: pointer;
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
	pointer-events: all;
	min-width: 140px;
	min-height: 70px;
	filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.4));
}

/* Modern node styling with vibrant colors, strong shadows, and better visual distinction */
.mermaid-container :deep(.node rect),
.mermaid-container :deep(.node polygon),
.mermaid-container :deep(.node ellipse) {
	rx: 12 !important;
	ry: 12 !important;
	filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.5));
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
	stroke-width: 3px !important;
}

/* Controller nodes - Bright blue with strong contrast */
.mermaid-container :deep(.node.controller rect),
.mermaid-container :deep(.node.controller polygon),
.mermaid-container :deep(.node.controller ellipse) {
	stroke: #1e6fa8 !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(52, 152, 219, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.controller:hover rect),
.mermaid-container :deep(.node.controller:hover polygon),
.mermaid-container :deep(.node.controller:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(52, 152, 219, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* Service nodes - Vibrant purple with strong contrast */
.mermaid-container :deep(.node.service rect),
.mermaid-container :deep(.node.service polygon),
.mermaid-container :deep(.node.service ellipse) {
	stroke: #6c3483 !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(155, 89, 182, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.service:hover rect),
.mermaid-container :deep(.node.service:hover polygon),
.mermaid-container :deep(.node.service:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(155, 89, 182, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* Model nodes - Vibrant red with strong contrast */
.mermaid-container :deep(.node.model rect),
.mermaid-container :deep(.node.model polygon),
.mermaid-container :deep(.node.model ellipse) {
	stroke: #a93226 !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(231, 76, 60, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.model:hover rect),
.mermaid-container :deep(.node.model:hover polygon),
.mermaid-container :deep(.node.model:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(231, 76, 60, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* Job nodes - Vibrant orange with strong contrast */
.mermaid-container :deep(.node.job rect),
.mermaid-container :deep(.node.job polygon),
.mermaid-container :deep(.node.job ellipse) {
	stroke: #b9770e !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(243, 156, 18, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.job:hover rect),
.mermaid-container :deep(.node.job:hover polygon),
.mermaid-container :deep(.node.job:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(243, 156, 18, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* Route nodes - Vibrant teal with strong contrast */
.mermaid-container :deep(.node.route rect),
.mermaid-container :deep(.node.route polygon),
.mermaid-container :deep(.node.route ellipse) {
	stroke: #138d75 !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(26, 188, 156, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.route:hover rect),
.mermaid-container :deep(.node.route:hover polygon),
.mermaid-container :deep(.node.route:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(26, 188, 156, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* Frontend nodes - Vibrant green with strong contrast */
.mermaid-container :deep(.node.frontend rect),
.mermaid-container :deep(.node.frontend polygon),
.mermaid-container :deep(.node.frontend ellipse) {
	stroke: #2d8659 !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(66, 185, 131, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.frontend:hover rect),
.mermaid-container :deep(.node.frontend:hover polygon),
.mermaid-container :deep(.node.frontend:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(66, 185, 131, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* External nodes - Dark grey with strong contrast */
.mermaid-container :deep(.node.external rect),
.mermaid-container :deep(.node.external polygon),
.mermaid-container :deep(.node.external ellipse) {
	stroke: #1b2631 !important;
	stroke-width: 3px !important;
	filter: drop-shadow(0 4px 10px rgba(52, 73, 94, 0.4)) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.5));
}

.mermaid-container :deep(.node.external:hover rect),
.mermaid-container :deep(.node.external:hover polygon),
.mermaid-container :deep(.node.external:hover ellipse) {
	filter: drop-shadow(0 6px 14px rgba(52, 73, 94, 0.6)) drop-shadow(0 3px 6px rgba(0, 0, 0, 0.6));
	transform: scale(1.05);
}

/* Focused nodes - Bright red with pulsing glow and larger size */
.mermaid-container :deep(.node.focused rect),
.mermaid-container :deep(.node.focused polygon),
.mermaid-container :deep(.node.focused ellipse) {
	stroke: #c92a2a !important;
	stroke-width: 5px !important;
	filter: drop-shadow(0 0 25px rgba(255, 107, 107, 1)) drop-shadow(0 6px 20px rgba(0, 0, 0, 0.7)) !important;
	animation: pulse-focused 2s ease-in-out infinite;
	transform: scale(1.2) !important;
	z-index: 1000 !important;
}

.mermaid-container :deep(.node.focused text),
.mermaid-container :deep(.node.focused tspan) {
	font-size: 20px !important;
	font-weight: 900 !important;
	fill: #ffffff !important;
	text-shadow: 0 0 10px rgba(255, 107, 107, 0.8), 0 2px 4px rgba(0, 0, 0, 0.8) !important;
}

@keyframes pulse-focused {
	0%, 100% {
		filter: drop-shadow(0 6px 16px rgba(255, 107, 107, 0.6)) drop-shadow(0 3px 8px rgba(0, 0, 0, 0.6));
		transform: scale(1);
	}
	50% {
		filter: drop-shadow(0 8px 20px rgba(255, 107, 107, 0.8)) drop-shadow(0 4px 10px rgba(0, 0, 0, 0.7));
		transform: scale(1.03);
	}
}

/* General hover effect for all nodes */
.mermaid-container :deep(.node:hover) {
	z-index: 10;
}

.mermaid-container :deep(.node text),
.mermaid-container :deep(.node tspan) {
	overflow: visible !important;
	text-overflow: clip !important;
	white-space: normal !important;
	word-wrap: break-word !important;
	dominant-baseline: middle !important;
	text-anchor: middle !important;
	font-weight: 600 !important;
	letter-spacing: 0.5px !important;
	font-size: 14px !important;
	fill: #ffffff !important;
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
	text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.mermaid-container :deep(.node-label) {
	overflow: visible !important;
}

.mermaid-container :deep(.node rect),
.mermaid-container :deep(.node polygon),
.mermaid-container :deep(.node ellipse),
.mermaid-container :deep(.node circle) {
	overflow: visible !important;
	width: auto !important;
	height: auto !important;
}

.mermaid-container :deep(.node foreignObject) {
	overflow: visible !important;
}

.mermaid-container :deep(.node foreignObject div) {
	overflow: visible !important;
	white-space: normal !important;
	word-wrap: break-word !important;
}

.mermaid-zoom-controls {
	position: absolute;
	top: 80px;
	right: 20px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	z-index: 10;
	background: var(--terminal-bg-secondary, #252525);
	border: 1px solid var(--terminal-border, #3e3e3e);
	border-radius: 6px;
	padding: 8px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.mermaid-zoom-btn {
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--terminal-button-bg, #3e3e3e);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #4e4e4e);
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.2s;
	padding: 0;
}

.mermaid-zoom-btn:hover {
	background: var(--terminal-button-hover, #4e4e4e);
	border-color: var(--terminal-border-hover, #5e5e5e);
}

.mermaid-zoom-btn svg {
	width: 16px;
	height: 16px;
}

.mermaid-zoom-level {
	font-size: 11px;
	color: var(--terminal-text-secondary, #a0a0a0);
	text-align: center;
	padding: 4px 0;
	border-top: 1px solid var(--terminal-border, #3e3e3e);
	margin-top: 4px;
}

/* Hide subgraph/cluster backgrounds completely - CSS prevents flash */
.mermaid-container :deep(.cluster),
.mermaid-container :deep(g.cluster),
.mermaid-container :deep([class*="cluster"]),
.mermaid-container :deep([id*="cluster"]) {
	display: none !important;
	visibility: hidden !important;
	opacity: 0 !important;
	pointer-events: none !important;
}

.mermaid-container :deep(.cluster rect),
.mermaid-container :deep(.cluster polygon),
.mermaid-container :deep(.cluster path),
.mermaid-container :deep(.cluster ellipse),
.mermaid-container :deep(g.cluster rect),
.mermaid-container :deep(g.cluster polygon),
.mermaid-container :deep(g.cluster path),
.mermaid-container :deep(g.cluster ellipse) {
	display: none !important;
	visibility: hidden !important;
	opacity: 0 !important;
	pointer-events: none !important;
	fill: transparent !important;
	stroke: transparent !important;
}

.mermaid-container :deep(.cluster-label),
.mermaid-container :deep(.cluster-label text),
.mermaid-container :deep(.cluster-label tspan),
.mermaid-container :deep(text[id*="cluster"]),
.mermaid-container :deep(text[class*="cluster"]) {
	display: none !important;
	visibility: hidden !important;
	opacity: 0 !important;
}

/* Hide oversized edge label container backgrounds - but NOT individual label backgrounds */
.mermaid-container :deep(.edgeLabels > rect),
.mermaid-container :deep(g.edgeLabels > rect),
.mermaid-container :deep(g[class*="edgeLabels"] > rect) {
	display: none !important;
}

/* Keep individual edgeLabel backgrounds visible - higher specificity */
.mermaid-container :deep(.edgeLabel rect),
.mermaid-container :deep(g.edgeLabel rect),
.mermaid-container :deep(g.edgeLabel > rect),
.mermaid-container :deep(.edgeLabel:not(.edgeLabels) rect) {
	display: block !important;
	visibility: visible !important;
	opacity: 1 !important;
}

/* Ensure individual label backgrounds are not hidden by container rules */
.mermaid-container :deep(g.edgeLabel:not(.edgeLabels) rect),
.mermaid-container :deep(g[id*="L-"]:not(.edgeLabels) rect) {
	display: block !important;
	visibility: visible !important;
	opacity: 1 !important;
}


/* Enhanced edge/connection styling with relationship type colors */
.mermaid-container :deep(.edge-path) {
	stroke-width: 3px !important;
	filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
	stroke: #6b7280 !important; /* Default gray */
}

/* Relationship type-specific edge colors (applied via JavaScript) */
.mermaid-container :deep(.edge-path.rel-hasMany) {
	stroke: #3498db !important;
}

.mermaid-container :deep(.edge-path.rel-belongsTo) {
	stroke: #10b981 !important;
}

.mermaid-container :deep(.edge-path.rel-hasOne) {
	stroke: #06b6d4 !important;
}

.mermaid-container :deep(.edge-path.rel-belongsToMany) {
	stroke: #a855f7 !important;
}

.mermaid-container :deep(.edge-path.rel-morph) {
	stroke: #f59e0b !important;
	stroke-dasharray: 5,5 !important;
}

.mermaid-container :deep(.edge-path.rel-hasManyThrough) {
	stroke: #9ca3af !important;
	stroke-dasharray: 8,4 !important;
}

.mermaid-container :deep(.edge-path:hover) {
	stroke-width: 4px !important;
	filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.5));
	opacity: 1 !important;
	z-index: 10;
}

/* Arrowhead styling */
.mermaid-container :deep(.arrowheadPath) {
	transition: fill 0.3s ease;
}

.mermaid-container :deep(.edge-path:hover ~ .arrowheadPath),
.mermaid-container :deep(.edge-path:hover + .arrowheadPath) {
	filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.4));
}

/* Enhanced edge label styling - Modern design with perfect alignment */
.mermaid-container :deep(.edgeLabel) {
	background: transparent !important;
	pointer-events: none !important; /* Allow clicks to pass through to edge */
}

/* Default label background styling */
.mermaid-container :deep(.edgeLabel rect),
.mermaid-container :deep(.edgeLabel polygon) {
	stroke-width: 2.5px !important;
	rx: 10px !important;
	ry: 10px !important;
	filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.5));
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
	pointer-events: none !important;
}

/* Relationship type-specific label backgrounds with vibrant colors */
.mermaid-container :deep(.edgeLabel.rel-hasMany rect),
.mermaid-container :deep(.edgeLabel.rel-hasMany polygon) {
	fill: rgba(52, 152, 219, 0.25) !important;
	stroke: #3498db !important;
	stroke-width: 2.5px !important;
}

.mermaid-container :deep(.edgeLabel.rel-belongsTo rect),
.mermaid-container :deep(.edgeLabel.rel-belongsTo polygon) {
	fill: rgba(16, 185, 129, 0.25) !important;
	stroke: #10b981 !important;
	stroke-width: 2.5px !important;
}

.mermaid-container :deep(.edgeLabel.rel-hasOne rect),
.mermaid-container :deep(.edgeLabel.rel-hasOne polygon) {
	fill: rgba(6, 182, 212, 0.25) !important;
	stroke: #06b6d4 !important;
	stroke-width: 2.5px !important;
}

.mermaid-container :deep(.edgeLabel.rel-belongsToMany rect),
.mermaid-container :deep(.edgeLabel.rel-belongsToMany polygon) {
	fill: rgba(168, 85, 247, 0.25) !important;
	stroke: #a855f7 !important;
	stroke-width: 2.5px !important;
}

.mermaid-container :deep(.edgeLabel.rel-morph rect),
.mermaid-container :deep(.edgeLabel.rel-morph polygon) {
	fill: rgba(245, 158, 11, 0.25) !important;
	stroke: #f59e0b !important;
	stroke-width: 2.5px !important;
}

.mermaid-container :deep(.edgeLabel.rel-hasManyThrough rect),
.mermaid-container :deep(.edgeLabel.rel-hasManyThrough polygon) {
	fill: rgba(156, 163, 175, 0.25) !important;
	stroke: #9ca3af !important;
	stroke-width: 2.5px !important;
}

/* Edge label text styling with perfect alignment */
.mermaid-container :deep(.edgeLabel text),
.mermaid-container :deep(.edgeLabel tspan) {
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
	font-size: 13px !important;
	font-weight: 700 !important;
	letter-spacing: 0.4px !important;
	text-anchor: middle !important;
	dominant-baseline: middle !important;
	text-shadow: 0 2px 4px rgba(0, 0, 0, 0.7) !important;
	pointer-events: none !important;
	alignment-baseline: middle !important;
}

/* Relationship type-specific text colors */
.mermaid-container :deep(.edgeLabel.rel-hasMany text),
.mermaid-container :deep(.edgeLabel.rel-hasMany tspan) {
	fill: #60a5fa !important;
}

.mermaid-container :deep(.edgeLabel.rel-belongsTo text),
.mermaid-container :deep(.edgeLabel.rel-belongsTo tspan) {
	fill: #34d399 !important;
}

.mermaid-container :deep(.edgeLabel.rel-hasOne text),
.mermaid-container :deep(.edgeLabel.rel-hasOne tspan) {
	fill: #22d3ee !important;
}

.mermaid-container :deep(.edgeLabel.rel-belongsToMany text),
.mermaid-container :deep(.edgeLabel.rel-belongsToMany tspan) {
	fill: #c084fc !important;
}

.mermaid-container :deep(.edgeLabel.rel-morph text),
.mermaid-container :deep(.edgeLabel.rel-morph tspan) {
	fill: #fbbf24 !important;
}

.mermaid-container :deep(.edgeLabel.rel-hasManyThrough text),
.mermaid-container :deep(.edgeLabel.rel-hasManyThrough tspan) {
	fill: #d1d5db !important;
}

/* Hover effects for edge labels - enhanced */
.mermaid-container :deep(.edgeLabel:hover) rect,
.mermaid-container :deep(.edgeLabel:hover) polygon {
	filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.7)) !important;
	transform: scale(1.08);
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.mermaid-container :deep(.edgeLabel:hover) text,
.mermaid-container :deep(.edgeLabel:hover) tspan {
	font-weight: 800 !important;
	text-shadow: 0 3px 6px rgba(0, 0, 0, 0.8) !important;
}

/* Ensure foreignObject labels are also styled */
.mermaid-container :deep(.edgeLabel foreignObject) {
	pointer-events: none !important;
}

.mermaid-container :deep(.edgeLabel foreignObject div),
.mermaid-container :deep(.edgeLabel foreignObject span) {
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
	font-size: 13px !important;
	font-weight: 700 !important;
	letter-spacing: 0.4px !important;
	text-align: center !important;
	text-shadow: 0 2px 4px rgba(0, 0, 0, 0.7) !important;
}

/* SVG wrapper styling for better horizontal scrolling */
.mermaid-container :deep(svg) {
	max-width: none !important;
	max-height: none !important;
	width: auto !important;
	height: auto !important;
	display: block;
	min-width: 100%;
}

.mermaid-svg-wrapper {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	display: flex;
	justify-content: flex-start;
	align-items: center;
	min-width: fit-content;
}
</style>


