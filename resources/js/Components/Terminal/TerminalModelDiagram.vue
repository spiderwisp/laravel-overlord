<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close']);

const loading = ref(false);
const models = ref([]);
const relationships = ref([]);
const canvasRef = ref(null);
const canvasContainerRef = ref(null);
const searchQuery = ref('');
const layout = ref('force'); // 'force', 'hierarchical', 'circular'
const isBuildingGraph = ref(false);
const activeTab = ref('list'); // 'list' or 'diagram'
const selectedModel = ref(null); // For list view

// Zoom and pan state
const zoom = ref(1);
const panX = ref(0);
const panY = ref(0);
const isPanning = ref(false);
const panStartX = ref(0);
const panStartY = ref(0);
const isDragging = ref(false);
const dragStartX = ref(0);
const dragStartY = ref(0);
const dragThreshold = 5; // pixels to move before considering it a drag
const wasDragging = ref(false); // Track if we just finished a drag (persists until next click)

// Canvas dimensions
const canvasWidth = ref(1200);
const canvasHeight = ref(800);

// Graph state
const allNodes = ref([]); // All nodes (unfiltered)
const allEdges = ref([]); // All edges (unfiltered)
const nodes = ref([]); // Filtered nodes for display
const edges = ref([]); // Filtered edges for display
const nodePositions = ref({});
const selectedNode = ref(null);
const hoveredNode = ref(null);
const lastFilteredNodeIds = ref(null); // Track what nodes were shown last time
const isSimulationRunning = ref(false); // Track if simulation is currently running

// Force-directed layout simulation
const simulation = ref(null);
let animationFrameId = null;
let filterTimeout = null; // Debounce filter application

// Load model relationships
async function loadRelationships() {
	loading.value = true;
	try {
		const response = await axios.get(api.url('model-relationships'));
		if (response.data.success) {
			models.value = response.data.result?.models || [];
			relationships.value = response.data.result?.relationships || [];
			
			// Only build graph if diagram tab is active (canvas needs to be rendered)
			if (activeTab.value === 'diagram') {
				// Wait for canvas to be ready before building graph
				nextTick(() => {
					updateCanvasSize();
					setTimeout(() => {
						buildGraph();
					}, 100);
				});
			}
		}
	} catch (error) {
		console.error('Failed to load relationships:', error);
	} finally {
		loading.value = false;
	}
}

// Build graph nodes and edges
function buildGraph(retryCount = 0) {
	// Prevent multiple simultaneous builds
	if (isBuildingGraph.value && retryCount === 0) return;
	
	// Ensure canvas is sized first (allow up to 5 retries)
	if (!canvasRef.value || !canvasContainerRef.value) {
		if (retryCount < 5) {
			// Retry after next tick
			nextTick(() => {
				buildGraph(retryCount + 1);
			});
		}
		return;
	}
	
	isBuildingGraph.value = true;
	
	// Stop any existing simulation
	if (animationFrameId) {
		cancelAnimationFrame(animationFrameId);
		animationFrameId = null;
	}
	
	// Ensure canvas size is set
	if (!updateCanvasSize()) {
		isBuildingGraph.value = false;
		return;
	}
	
	// Check if we have models
	if (!models.value || models.value.length === 0) {
		isBuildingGraph.value = false;
		return;
	}
	
	// Initialize ALL nodes (unfiltered) with grid-based initial layout for better spacing
	const nodeCount = models.value.length;
	const cols = Math.ceil(Math.sqrt(nodeCount * 1.2)); // Reasonable grid
	const rows = Math.ceil(nodeCount / cols);
	const spacing = 180; // Reasonable spacing that fits on screen
	const totalWidth = Math.min((cols - 1) * spacing, canvasWidth.value - 200);
	const totalHeight = Math.min((rows - 1) * spacing, canvasHeight.value - 200);
	const startX = (canvasWidth.value - totalWidth) / 2;
	const startY = (canvasHeight.value - totalHeight) / 2;
	
	allNodes.value = models.value.map((model, index) => {
		const col = index % cols;
		const row = Math.floor(index / cols);
		return {
			id: model,
			label: model,
			x: startX + col * spacing,
			y: startY + row * spacing,
			vx: 0,
			vy: 0,
		};
	});

	// Build ALL edges (unfiltered)
	allEdges.value = relationships.value
		.filter(rel => {
			const fromExists = allNodes.value.some(n => n.id === rel.from);
			const toExists = allNodes.value.some(n => n.id === rel.to);
			return fromExists && toExists;
		})
		.map(rel => ({
			from: rel.from,
			to: rel.to,
			type: rel.type,
			method: rel.method,
			label: rel.method,
		}));
	
	// Apply initial filter - start with nothing shown (user must select a model)
	// Don't call applyFilter() here - let it start empty
	nodes.value = [];
	edges.value = [];
	lastFilteredNodeIds.value = '';

	// Initialize positions for filtered nodes
	nodePositions.value = {};
	nodes.value.forEach(node => {
		// Use existing position if available, otherwise use from allNodes
		const allNode = allNodes.value.find(n => n.id === node.id);
		if (allNode) {
			node.x = allNode.x;
			node.y = allNode.y;
		}
		nodePositions.value[node.id] = { x: node.x, y: node.y };
	});

	// Draw initial state
	draw();
	
	isBuildingGraph.value = false;
	
	// Start layout simulation ONCE - never restart it
	nextTick(() => {
		if (nodes.value.length > 0 && !isSimulationRunning.value) {
			startSimulation();
		}
	});
}

// Start force-directed layout simulation
function startSimulation() {
	if (!canvasRef.value || nodes.value.length === 0) {
		isSimulationRunning.value = false;
		return;
	}

	// Prevent multiple simultaneous simulations
	if (isSimulationRunning.value) {
		return;
	}

	// Cancel any existing simulation first
	if (animationFrameId) {
		cancelAnimationFrame(animationFrameId);
		animationFrameId = null;
	}
	
	isSimulationRunning.value = true;
	
	// Wait a frame to ensure previous simulation is fully stopped
	requestAnimationFrame(() => {
		if (!canvasRef.value || nodes.value.length === 0) {
			isSimulationRunning.value = false;
			return;
		}
		
		const canvas = canvasRef.value;
		const ctx = canvas.getContext('2d');

		// Simple force-directed layout
		let iteration = 0;
		const maxIterations = 500; // More iterations for better convergence
		const damping = 0.95; // Higher damping for smoother convergence
		const repulsionStrength = 10000; // Balanced repulsion
		const attractionStrength = 0.05; // Balanced attraction
		const targetDistance = 250; // Reasonable target distance between connected nodes
		const minDistance = 0.05; // Tighter convergence threshold

		let maxVelocity = Infinity;
		
		function simulate() {
			// Check if canvas is still valid
			if (!canvasRef.value || nodes.value.length === 0) {
				animationFrameId = null;
				isSimulationRunning.value = false;
				return;
			}
			
			if (iteration >= maxIterations) {
				animationFrameId = null;
				isSimulationRunning.value = false;
				draw();
				return;
			}

			maxVelocity = 0;

			// Calculate repulsion between all nodes
			for (let i = 0; i < nodes.value.length; i++) {
				let fx = 0, fy = 0;
				const node = nodes.value[i];

				// Repulsion from other nodes
				for (let j = 0; j < nodes.value.length; j++) {
					if (i === j) continue;
					const other = nodes.value[j];
					const dx = node.x - other.x;
					const dy = node.y - other.y;
					const distance = Math.sqrt(dx * dx + dy * dy) || 1;
					
					// Minimum distance between nodes (node radius * 2 + padding + label space)
					const minNodeDistance = 140; // Reasonable minimum distance
					if (distance < minNodeDistance) {
						// Very strong repulsion when too close - push them apart immediately
						const overlap = minNodeDistance - distance;
						const force = overlap * 200; // Much stronger force for overlapping nodes
						fx += (dx / distance) * force;
						fy += (dy / distance) * force;
					} else {
						// Normal repulsion for further nodes
						const safeDistance = Math.max(distance, 5);
						const force = repulsionStrength / (safeDistance * safeDistance);
						fx += (dx / safeDistance) * force;
						fy += (dy / safeDistance) * force;
					}
				}

				// Attraction along edges
				edges.value.forEach(edge => {
					if (edge.from === node.id) {
						const target = nodes.value.find(n => n.id === edge.to);
						if (target) {
							const dx = target.x - node.x;
							const dy = target.y - node.y;
							const distance = Math.sqrt(dx * dx + dy * dy) || 1;
							// Spring force: stronger when far, weaker when close
							const force = (distance - targetDistance) * attractionStrength;
							fx += (dx / distance) * force;
							fy += (dy / distance) * force;
						}
					}
				});

				// Update velocity and position
				node.vx = (node.vx + fx) * damping;
				node.vy = (node.vy + fy) * damping;
				
				// Track max velocity for early convergence
				const velocity = Math.sqrt(node.vx * node.vx + node.vy * node.vy);
				maxVelocity = Math.max(maxVelocity, velocity);
				
				node.x += node.vx;
				node.y += node.vy;

				// Keep nodes within bounds (with padding) and enforce minimum distance
				const padding = 80;
				node.x = Math.max(padding, Math.min(canvasWidth.value - padding, node.x));
				node.y = Math.max(padding, Math.min(canvasHeight.value - padding, node.y));
				
				// Additional check: enforce minimum distance after movement
				const minNodeDistance = 140;
				for (let k = 0; k < nodes.value.length; k++) {
					if (k === i) continue;
					const otherNode = nodes.value[k];
					const dx = node.x - otherNode.x;
					const dy = node.y - otherNode.y;
					const dist = Math.sqrt(dx * dx + dy * dy);
					if (dist < minNodeDistance && dist > 0) {
						// Push nodes apart if they're too close - use full separation
						const pushDistance = (minNodeDistance - dist);
						const pushX = (dx / dist) * pushDistance;
						const pushY = (dy / dist) * pushDistance;
						node.x += pushX;
						node.y += pushY;
						// Keep within bounds after push
						node.x = Math.max(padding, Math.min(canvasWidth.value - padding, node.x));
						node.y = Math.max(padding, Math.min(canvasHeight.value - padding, node.y));
					}
				}

				nodePositions.value[node.id] = { x: node.x, y: node.y };
			}

			// Draw every 5 iterations for better performance
			if (iteration % 5 === 0) {
				draw();
			}

			// Final pass: enforce minimum distance one more time after all movements
			if (iteration > 0 && iteration % 10 === 0) {
				const minNodeDistance = 140;
				for (let i = 0; i < nodes.value.length; i++) {
					const node = nodes.value[i];
					for (let j = i + 1; j < nodes.value.length; j++) {
						const other = nodes.value[j];
						const dx = node.x - other.x;
						const dy = node.y - other.y;
						const dist = Math.sqrt(dx * dx + dy * dy);
						if (dist < minNodeDistance && dist > 0) {
							const pushDistance = (minNodeDistance - dist) / 2;
							const pushX = (dx / dist) * pushDistance;
							const pushY = (dy / dist) * pushDistance;
							node.x += pushX;
							node.y += pushY;
							other.x -= pushX;
							other.y -= pushY;
							// Keep within bounds
							const padding = 80;
							node.x = Math.max(padding, Math.min(canvasWidth.value - padding, node.x));
							node.y = Math.max(padding, Math.min(canvasHeight.value - padding, node.y));
							other.x = Math.max(padding, Math.min(canvasWidth.value - padding, other.x));
							other.y = Math.max(padding, Math.min(canvasHeight.value - padding, other.y));
							nodePositions.value[node.id] = { x: node.x, y: node.y };
							nodePositions.value[other.id] = { x: other.x, y: other.y };
						}
					}
				}
			}
			
			// Early convergence check
			if (maxVelocity < minDistance && iteration > 50) {
				animationFrameId = null;
				isSimulationRunning.value = false;
				draw();
				return;
			}

			iteration++;
			animationFrameId = requestAnimationFrame(simulate);
		}

		simulate();
	});
}

// Draw the graph
function draw() {
	if (!canvasRef.value) return;

	const canvas = canvasRef.value;
	const ctx = canvas.getContext('2d');

	// Clear canvas
	ctx.clearRect(0, 0, canvasWidth.value, canvasHeight.value);
	
	// Apply zoom and pan transformation
	ctx.save();
	ctx.translate(panX.value, panY.value);
	ctx.scale(zoom.value, zoom.value);

	// Draw edges
	edges.value.forEach(edge => {
		const fromNode = nodes.value.find(n => n.id === edge.from);
		const toNode = nodes.value.find(n => n.id === edge.to);
		if (!fromNode || !toNode) return;

		const fromPos = nodePositions.value[edge.from];
		const toPos = nodePositions.value[edge.to];
		
		// Skip if positions are invalid
		if (!fromPos || !toPos || 
			typeof fromPos.x !== 'number' || typeof fromPos.y !== 'number' ||
			typeof toPos.x !== 'number' || typeof toPos.y !== 'number') {
			return;
		}

		// Calculate arrow position (stop before node edge)
		const nodeRadius = 20;
		const dx = toPos.x - fromPos.x;
		const dy = toPos.y - fromPos.y;
		const distance = Math.sqrt(dx * dx + dy * dy);
		
		if (distance < nodeRadius * 2) return; // Skip if nodes too close
		
		const unitX = dx / distance;
		const unitY = dy / distance;
		
		// Start and end points accounting for node radius
		const startX = fromPos.x + unitX * nodeRadius;
		const startY = fromPos.y + unitY * nodeRadius;
		const endX = toPos.x - unitX * nodeRadius;
		const endY = toPos.y - unitY * nodeRadius;
		
		// Draw line
		ctx.beginPath();
		ctx.moveTo(startX, startY);
		ctx.lineTo(endX, endY);
		
		// Color based on relationship type
		let color = '#6b7280';
		let arrowColor = '#6b7280';
		if (edge.type === 'hasMany' || edge.type === 'hasOne') {
			color = '#3b82f6';
			arrowColor = '#3b82f6';
		} else if (edge.type === 'belongsTo') {
			color = '#10b981';
			arrowColor = '#10b981';
		} else if (edge.type === 'belongsToMany' || edge.type === 'morphToMany') {
			color = '#f59e0b';
			arrowColor = '#f59e0b';
		} else if (edge.type === 'pivot') {
			color = '#9ca3af';
			arrowColor = '#9ca3af';
		} else if (edge.type.includes('Morph')) {
			color = '#8b5cf6';
			arrowColor = '#8b5cf6';
		}

		ctx.strokeStyle = color;
		ctx.lineWidth = 2;
		ctx.stroke();

		// Draw arrow at the end point
		const angle = Math.atan2(endY - startY, endX - startX);
		const arrowLength = 12;
		const arrowAngle = Math.PI / 6;

		ctx.beginPath();
		ctx.moveTo(endX, endY);
		ctx.lineTo(
			endX - arrowLength * Math.cos(angle - arrowAngle),
			endY - arrowLength * Math.sin(angle - arrowAngle)
		);
		ctx.lineTo(
			endX - arrowLength * Math.cos(angle + arrowAngle),
			endY - arrowLength * Math.sin(angle + arrowAngle)
		);
		ctx.closePath();
		ctx.fillStyle = arrowColor;
		ctx.fill();

		// Draw relationship label - only show short method names or on hover
		// Skip labels if there are too many edges to avoid clutter
		if (edges.value.length <= 20) {
			const midX = (startX + endX) / 2;
			const midY = (startY + endY) / 2;
			
			// Truncate long method names
			let labelText = edge.method;
			if (labelText.length > 15) {
				labelText = labelText.substring(0, 12) + '...';
			}
			
			// Draw label background for readability
			ctx.font = '10px monospace';
			const textMetrics = ctx.measureText(labelText);
			const textWidth = textMetrics.width;
			const textHeight = 12;
			const padding = 3;
			
			// Draw background rectangle
			ctx.fillStyle = 'rgba(30, 30, 30, 0.95)';
			ctx.fillRect(
				midX - textWidth / 2 - padding,
				midY - textHeight / 2 - padding,
				textWidth + padding * 2,
				textHeight + padding * 2
			);
			
			// Draw label text in bright color
			ctx.fillStyle = '#e5e7eb'; // Light gray for better visibility
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(labelText, midX, midY);
		}
	});

	// Draw nodes
	nodes.value.forEach(node => {
		const pos = nodePositions.value[node.id];
		if (!pos || typeof pos.x !== 'number' || typeof pos.y !== 'number') return;

		const isSelected = selectedNode.value === node.id;
		const isHovered = hoveredNode.value === node.id;

		const radius = isSelected ? 25 : isHovered ? 22 : 20;

		// Node circle
		ctx.beginPath();
		ctx.arc(pos.x, pos.y, radius, 0, 2 * Math.PI);
		ctx.fillStyle = isSelected ? '#3b82f6' : isHovered ? '#60a5fa' : '#4b5563';
		ctx.fill();
		ctx.strokeStyle = isSelected ? '#1e40af' : '#374151';
		ctx.lineWidth = isSelected ? 3 : 2;
		ctx.stroke();

		// Node label background for better readability
		ctx.fillStyle = '#1e1e1e';
		ctx.font = 'bold 11px monospace';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		
		// Measure text for background
		const metrics = ctx.measureText(node.label);
		const textWidth = metrics.width;
		const textHeight = 14;
		
		// Draw label background
		ctx.fillRect(
			pos.x - textWidth / 2 - 4,
			pos.y - textHeight / 2 - 10,
			textWidth + 8,
			textHeight
		);
		
		// Draw label text
		ctx.fillStyle = '#ffffff';
		ctx.fillText(node.label, pos.x, pos.y - 10);
	});
	
	// Restore transformation
	ctx.restore();
}

// Handle mouse events
function handleMouseMove(event) {
	if (!canvasRef.value) return;
	
	const rect = canvasRef.value.getBoundingClientRect();
	const rawX = event.clientX - rect.left;
	const rawY = event.clientY - rect.top;
	
	// Convert screen coordinates to canvas coordinates (accounting for zoom/pan)
	const x = (rawX - panX.value) / zoom.value;
	const y = (rawY - panY.value) / zoom.value;

	// Handle panning
	if (isPanning.value) {
		const deltaX = rawX - panStartX.value;
		const deltaY = rawY - panStartY.value;
		
		// Check if we've moved enough to consider it a drag
		const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY);
		if (distance > dragThreshold) {
			isDragging.value = true;
			wasDragging.value = true; // Mark that we're dragging
		}
		
		panX.value += deltaX;
		panY.value += deltaY;
		panStartX.value = rawX;
		panStartY.value = rawY;
		draw();
		return;
	}

	// Find hovered node
	let found = false;
	nodes.value.forEach(node => {
		const pos = nodePositions.value[node.id];
		if (!pos) return;
		const dx = x - pos.x;
		const dy = y - pos.y;
		const distance = Math.sqrt(dx * dx + dy * dy);
		if (distance <= 20 / zoom.value) { // Adjust for zoom
			hoveredNode.value = node.id;
			found = true;
		}
	});

	if (!found) {
		hoveredNode.value = null;
	}

	draw();
}

function handleClick(event) {
	if (!canvasRef.value) return;
	
	// If we were dragging, don't treat this as a click - just clear the flag
	if (wasDragging.value) {
		wasDragging.value = false;
		return;
	}
	
	const rect = canvasRef.value.getBoundingClientRect();
	const rawX = event.clientX - rect.left;
	const rawY = event.clientY - rect.top;
	
	// Convert screen coordinates to canvas coordinates (accounting for zoom/pan)
	const x = (rawX - panX.value) / zoom.value;
	const y = (rawY - panY.value) / zoom.value;

	// Find clicked node (use larger hit radius for better UX)
	let clickedNode = null;
	let closestDistance = Infinity;
	
	nodes.value.forEach(node => {
		const pos = nodePositions.value[node.id];
		if (!pos) return;
		const dx = x - pos.x;
		const dy = y - pos.y;
		const distance = Math.sqrt(dx * dx + dy * dy);
		
		// Use hit radius of 30 (larger than node radius of 20-25), adjusted for zoom
		if (distance <= 30 / zoom.value && distance < closestDistance) {
			clickedNode = node.id;
			closestDistance = distance;
		}
	});

	// If a node was clicked, select it (always select, not toggle)
	if (clickedNode) {
		selectedNode.value = clickedNode;
		applyFilter();
	} else {
		// Clicked on empty space - only clear selection if we're not already showing a filtered view
		// Or if they explicitly want to clear it, we could add a double-click or something
		// For now, let's just not clear on empty space click to prevent accidental resets
		// selectedNode.value = null;
		// applyFilter();
	}
}

// Handle mouse wheel for zooming
function handleWheel(event) {
	if (!canvasRef.value) return;
	
	event.preventDefault();
	
	const rect = canvasRef.value.getBoundingClientRect();
	const mouseX = event.clientX - rect.left;
	const mouseY = event.clientY - rect.top;
	
	// Zoom factor
	const zoomFactor = event.deltaY > 0 ? 0.9 : 1.1;
	const newZoom = Math.max(0.2, Math.min(3, zoom.value * zoomFactor));
	
	// Zoom towards mouse position
	const zoomChange = newZoom / zoom.value;
	panX.value = mouseX - (mouseX - panX.value) * zoomChange;
	panY.value = mouseY - (mouseY - panY.value) * zoomChange;
	
	zoom.value = newZoom;
	draw();
}

// Handle mouse down for panning
function handleMouseDown(event) {
	if (!canvasRef.value) return;
	
	const rect = canvasRef.value.getBoundingClientRect();
	const rawX = event.clientX - rect.left;
	const rawY = event.clientY - rect.top;
	
	// Convert screen coordinates to canvas coordinates (accounting for zoom/pan)
	const x = (rawX - panX.value) / zoom.value;
	const y = (rawY - panY.value) / zoom.value;
	
	// Check if we're clicking on a node
	let clickedOnNode = false;
	nodes.value.forEach(node => {
		const pos = nodePositions.value[node.id];
		if (!pos) return;
		const dx = x - pos.x;
		const dy = y - pos.y;
		const distance = Math.sqrt(dx * dx + dy * dy);
		if (distance <= 30 / zoom.value) {
			clickedOnNode = true;
		}
	});
	
	// Allow panning with:
	// - Middle mouse button
	// - Ctrl+Left click
	// - Left mouse button (but not on a node)
	if (event.button === 1 || (event.button === 0 && event.ctrlKey) || (event.button === 0 && !clickedOnNode)) {
		event.preventDefault();
		isPanning.value = true;
		isDragging.value = false;
		panStartX.value = rawX;
		panStartY.value = rawY;
		dragStartX.value = rawX;
		dragStartY.value = rawY;
		canvasRef.value.style.cursor = 'grabbing';
	}
}

// Handle mouse up for panning
function handleMouseUp(event) {
	if (isPanning.value) {
		isPanning.value = false;
		// Don't clear wasDragging here - let handleClick check it
		// Only clear isDragging since we're done moving
		isDragging.value = false;
		if (canvasRef.value) {
			canvasRef.value.style.cursor = 'pointer';
		}
	}
}

// Handle mouse leave for panning
function handleMouseLeave() {
	isPanning.value = false;
	isDragging.value = false;
	wasDragging.value = false; // Clear drag flag if mouse leaves
	if (canvasRef.value) {
		canvasRef.value.style.cursor = 'pointer';
	}
}

// Zoom functions
function zoomIn() {
	const newZoom = Math.min(3, zoom.value * 1.2);
	const centerX = canvasWidth.value / 2;
	const centerY = canvasHeight.value / 2;
	const zoomChange = newZoom / zoom.value;
	panX.value = centerX - (centerX - panX.value) * zoomChange;
	panY.value = centerY - (centerY - panY.value) * zoomChange;
	zoom.value = newZoom;
	draw();
}

function zoomOut() {
	const newZoom = Math.max(0.2, zoom.value / 1.2);
	const centerX = canvasWidth.value / 2;
	const centerY = canvasHeight.value / 2;
	const zoomChange = newZoom / zoom.value;
	panX.value = centerX - (centerX - panX.value) * zoomChange;
	panY.value = centerY - (centerY - panY.value) * zoomChange;
	zoom.value = newZoom;
	draw();
}

function resetZoom() {
	zoom.value = 1;
	panX.value = 0;
	panY.value = 0;
	draw();
}

// Apply filter based on selected node (debounced)
function applyFilter() {
	// Clear any pending filter application
	if (filterTimeout) {
		clearTimeout(filterTimeout);
		filterTimeout = null;
	}
	
	// Debounce filter application to prevent rapid calls
	filterTimeout = setTimeout(() => {
		applyFilterInternal();
	}, 50);
}

// Internal filter application (actual implementation)
function applyFilterInternal() {
	// Determine which nodes should be visible
	let relatedModelIds;
	if (!selectedNode.value) {
		// Show all models
		relatedModelIds = new Set(allNodes.value.map(n => n.id));
	} else {
		// Show only selected model and its direct relationships
		relatedModelIds = new Set([selectedNode.value]);
		allEdges.value.forEach(edge => {
			if (edge.from === selectedNode.value) {
				relatedModelIds.add(edge.to);
			}
			if (edge.to === selectedNode.value) {
				relatedModelIds.add(edge.from);
			}
		});
	}
	
	// Check if filter actually changed
	const currentIds = Array.from(relatedModelIds).sort().join(',');
	if (lastFilteredNodeIds.value === currentIds && nodes.value.length > 0) {
		// Filter hasn't changed, don't restart
		return;
	}
	lastFilteredNodeIds.value = currentIds;
	
	// Reset zoom and pan when filter changes
	zoom.value = 1;
	panX.value = 0;
	panY.value = 0;
	
	// NEVER restart simulation - just filter and redraw
	if (!selectedNode.value) {
		// Show all models when nothing is selected - create copies
		nodes.value = allNodes.value.map(node => ({
			...node,
			x: nodePositions.value[node.id]?.x ?? node.x,
			y: nodePositions.value[node.id]?.y ?? node.y,
			vx: 0,
			vy: 0,
		}));
		edges.value = [...allEdges.value];
	} else {
		// Filter and copy nodes to show related ones (create new objects)
		nodes.value = allNodes.value
			.filter(node => relatedModelIds.has(node.id))
			.map(node => ({
				...node,
				x: nodePositions.value[node.id]?.x ?? node.x,
				y: nodePositions.value[node.id]?.y ?? node.y,
				vx: 0,
				vy: 0,
			}));
		
		// Filter edges to only show connections between visible nodes
		edges.value = allEdges.value.filter(edge => {
			const fromVisible = relatedModelIds.has(edge.from);
			const toVisible = relatedModelIds.has(edge.to);
			return fromVisible && toVisible;
		});
	}
	
	// Reinitialize positions for filtered nodes (use existing positions)
	nodePositions.value = {};
	nodes.value.forEach(node => {
		// Use existing position if available, otherwise use from allNodes
		const existingPos = allNodes.value.find(n => n.id === node.id);
		if (existingPos && existingPos.x !== undefined && existingPos.y !== undefined) {
			node.x = existingPos.x;
			node.y = existingPos.y;
		}
		nodePositions.value[node.id] = { x: node.x, y: node.y };
	});
	
	// Just draw - NO simulation restart
	draw();
}

// Function to select a model from the sidebar list
function selectModel(model) {
	selectedNode.value = selectedNode.value === model ? null : model;
	applyFilter();
}

// Filter models and relationships
const filteredModels = computed(() => {
	if (!searchQuery.value) return models.value;
	const query = searchQuery.value.toLowerCase();
	return models.value.filter(m => m.toLowerCase().includes(query));
});

const filteredRelationships = computed(() => {
	if (!searchQuery.value) return relationships.value;
	const query = searchQuery.value.toLowerCase();
	return relationships.value.filter(r => 
		r.from.toLowerCase().includes(query) || 
		r.to.toLowerCase().includes(query) ||
		r.method.toLowerCase().includes(query)
	);
});

// Get relationships for selected model (for list view)
const selectedModelRelationships = computed(() => {
	const model = selectedModel.value;
	if (!model) return [];
	return relationships.value.filter(r => 
		r.from === model || r.to === model
	);
});

// Get relationships for selected node (for diagram view)
const selectedNodeRelationships = computed(() => {
	if (!selectedNode.value) return [];
	return relationships.value.filter(r => 
		r.from === selectedNode.value || r.to === selectedNode.value
	);
});

// Update canvas size
function updateCanvasSize() {
	if (canvasRef.value && canvasContainerRef.value) {
		const container = canvasContainerRef.value;
		const newWidth = Math.max(400, container.clientWidth - 40);
		const newHeight = Math.max(300, container.clientHeight - 40);
		
		// Only update if size actually changed
		if (newWidth === canvasWidth.value && newHeight === canvasHeight.value) {
			return true; // No change, skip update
		}
		
		// Update canvas dimensions
		canvasWidth.value = newWidth;
		canvasHeight.value = newHeight;
		canvasRef.value.width = canvasWidth.value;
		canvasRef.value.height = canvasHeight.value;
		
		// Only redraw if we have nodes (never restart simulation)
		if (nodes.value.length > 0 && !isBuildingGraph.value) {
			draw();
		}
		
		return true;
	}
	return false;
}

// Watch for visibility changes
// Watch for tab changes to rebuild graph when switching to diagram view
watch(() => activeTab.value, (newTab) => {
	if (newTab === 'diagram' && models.value.length > 0 && relationships.value.length > 0) {
		// Wait for canvas to be rendered in DOM before building graph
		nextTick(() => {
			// Update canvas size first
			updateCanvasSize();
			// Then build the graph with retries if needed
			setTimeout(() => {
				buildGraph();
			}, 100);
		});
	}
});

watch(() => props.visible, (visible) => {
	if (visible) {
		// Wait for DOM to be ready, then load
		nextTick(() => {
			loadRelationships();
			nextTick(() => {
				updateCanvasSize();
				
				// Add resize observer
				if (canvasContainerRef.value) {
					const resizeObserver = new ResizeObserver(() => {
						updateCanvasSize();
					});
					resizeObserver.observe(canvasContainerRef.value);
					// Store observer for cleanup
					window._modelDiagramResizeObserver = resizeObserver;
				}
			});
		});
	} else {
		// Stop all animations and clean up
		if (animationFrameId) {
			cancelAnimationFrame(animationFrameId);
			animationFrameId = null;
		}
		isSimulationRunning.value = false;
		if (filterTimeout) {
			clearTimeout(filterTimeout);
			filterTimeout = null;
		}
		if (window._modelDiagramResizeObserver) {
			window._modelDiagramResizeObserver.disconnect();
			delete window._modelDiagramResizeObserver;
		}
	}
});

onMounted(() => {
	// Handle window resize
	window.addEventListener('resize', updateCanvasSize);
});

onUnmounted(() => {
	// Stop all animations and clean up
	if (animationFrameId) {
		cancelAnimationFrame(animationFrameId);
		animationFrameId = null;
	}
	isSimulationRunning.value = false;
	if (filterTimeout) {
		clearTimeout(filterTimeout);
		filterTimeout = null;
	}
	if (window._modelDiagramResizeObserver) {
		window._modelDiagramResizeObserver.disconnect();
		delete window._modelDiagramResizeObserver;
	}
	window.removeEventListener('resize', updateCanvasSize);
});
</script>

<template>
	<div v-if="visible" class="terminal-model-diagram">
		<div class="terminal-model-diagram-header">
			<div class="terminal-model-diagram-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
				</svg>
				<span>Models</span>
			</div>
			<div class="terminal-model-diagram-controls">
				<input
					v-model="searchQuery"
					type="text"
					placeholder="Search models..."
					class="terminal-model-diagram-search"
				/>
				<button
					@click="loadRelationships"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Reload relationships"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Diagram"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Tabs -->
		<div class="terminal-model-diagram-tabs">
			<button
				@click="activeTab = 'list'"
				class="terminal-model-diagram-tab"
				:class="{ 'active': activeTab === 'list' }"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
				</svg>
				<span>List View</span>
			</button>
			<button
				@click="activeTab = 'diagram'"
				class="terminal-model-diagram-tab"
				:class="{ 'active': activeTab === 'diagram' }"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
				</svg>
				<span>Diagram View</span>
			</button>
		</div>

		<!-- Legend (only show in diagram view) -->
		<div v-if="activeTab === 'diagram'" class="terminal-model-diagram-legend">
			<div class="terminal-model-diagram-legend-items">
				<div class="terminal-model-diagram-legend-item">
					<span class="legend-color" style="background: #3b82f6;"></span>
					<span>hasOne / hasMany</span>
				</div>
				<div class="terminal-model-diagram-legend-item">
					<span class="legend-color" style="background: #10b981;"></span>
					<span>belongsTo</span>
				</div>
				<div class="terminal-model-diagram-legend-item">
					<span class="legend-color" style="background: #f59e0b;"></span>
					<span>belongsToMany</span>
				</div>
				<div class="terminal-model-diagram-legend-item">
					<span class="legend-color" style="background: #8b5cf6;"></span>
					<span>Morph*</span>
				</div>
				<div class="terminal-model-diagram-legend-item">
					<span class="legend-color" style="background: #9ca3af;"></span>
					<span>Pivot</span>
				</div>
			</div>
			<span class="terminal-model-diagram-legend-title">Relationship Types:</span>
		</div>

		<div class="terminal-model-diagram-content">
			<div v-if="loading" class="terminal-model-diagram-loading">
				<span class="spinner"></span>
				Analyzing models...
			</div>

			<div v-else-if="models.length === 0" class="terminal-model-diagram-empty">
				<p>No models found.</p>
			</div>

			<!-- List View Tab -->
			<div v-else-if="activeTab === 'list'" class="terminal-model-diagram-main">
				<!-- Models List -->
				<div class="terminal-model-diagram-list">
					<div class="terminal-model-diagram-list-header">
						<h3>Models ({{ filteredModels.length }})</h3>
					</div>
					<div class="terminal-model-diagram-list-scroll">
						<div
							v-for="model in filteredModels"
							:key="model"
							class="terminal-model-diagram-item"
							:class="{ 'active': selectedModel === model }"
							@click="selectedModel = model"
						>
							<div class="terminal-model-diagram-item-header">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
								</svg>
								<span class="terminal-model-diagram-item-name">{{ model }}</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Model Details -->
				<div class="terminal-model-diagram-details">
					<div v-if="!selectedModel" class="terminal-model-diagram-empty-details">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
							<path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
						</svg>
						<h3>Select a Model</h3>
						<p>Choose a model from the list to view its relationships</p>
					</div>
					<div v-else class="terminal-model-diagram-details-content">
						<div class="terminal-model-diagram-details-header">
							<h3>{{ selectedModel }}</h3>
							<button
								@click="selectedModel = null"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
								title="Clear Selection"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
						</div>
						<div class="terminal-model-diagram-details-info">
							<div class="terminal-model-diagram-info-item">
								<span class="terminal-model-diagram-info-label">Model:</span>
								<span class="terminal-model-diagram-info-value">{{ selectedModel }}</span>
							</div>
							<div class="terminal-model-diagram-info-item">
								<span class="terminal-model-diagram-info-label">Relationships:</span>
								<span class="terminal-model-diagram-info-value">{{ selectedModelRelationships.length }}</span>
							</div>
						</div>
						<div v-if="selectedModelRelationships.length > 0" class="terminal-model-diagram-relationships">
							<h4>Relationships</h4>
							<div class="terminal-model-diagram-relationships-list">
								<div
									v-for="rel in selectedModelRelationships"
									:key="`${rel.from}-${rel.to}-${rel.method}`"
									class="terminal-model-diagram-relationship-item"
									:class="`relationship-${rel.type}`"
								>
									<div class="terminal-model-diagram-relationship-method">{{ rel.method }}</div>
									<div class="terminal-model-diagram-relationship-details">
										<span v-if="rel.from === selectedModel" class="relationship-arrow">→</span>
										<span v-else class="relationship-arrow">←</span>
										<span class="relationship-model">{{ rel.from === selectedModel ? rel.to : rel.from }}</span>
										<span class="relationship-type">({{ rel.type }})</span>
									</div>
								</div>
							</div>
						</div>
						<div v-else class="terminal-model-diagram-no-relationships">
							<p>No relationships found for this model.</p>
						</div>
					</div>
				</div>
			</div>

			<!-- Diagram View Tab -->
			<div v-else-if="activeTab === 'diagram'" class="terminal-model-diagram-main">
				<!-- Sidebar (Left) -->
				<div class="terminal-model-diagram-sidebar">
					<!-- Models List -->
					<div class="terminal-model-diagram-models-list">
						<h3 class="terminal-model-diagram-models-title">Models ({{ filteredModels.length }})</h3>
						<div class="terminal-model-diagram-models-scroll">
							<div
								v-for="model in filteredModels"
								:key="model"
								class="terminal-model-diagram-model-item"
								:class="{ 'active': selectedNode === model }"
								@click="selectModel(model)"
							>
								{{ model }}
							</div>
						</div>
					</div>

					<!-- Selected Model Info -->
					<div v-if="selectedNode" class="terminal-model-diagram-info">
						<div class="terminal-model-diagram-info-header">
							<h3 class="terminal-model-diagram-info-title">{{ selectedNode }}</h3>
							<button
								@click="selectedNode = null; applyFilter();"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
								title="Clear Selection"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
						</div>
						<div class="terminal-model-diagram-relationships-scroll">
							<div class="terminal-model-diagram-relationships-list">
								<div
									v-for="rel in selectedNodeRelationships"
									:key="`${rel.from}-${rel.to}-${rel.method}`"
									class="terminal-model-diagram-relationship-item"
									:class="`relationship-${rel.type}`"
								>
									<div class="terminal-model-diagram-relationship-method">{{ rel.method }}</div>
									<div class="terminal-model-diagram-relationship-details">
										<span v-if="rel.from === selectedNode" class="relationship-arrow">→</span>
										<span v-else class="relationship-arrow">←</span>
										<span class="relationship-model">{{ rel.from === selectedNode ? rel.to : rel.from }}</span>
										<span class="relationship-type">({{ rel.type }})</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Canvas (Right) -->
				<div class="terminal-model-diagram-canvas-container" ref="canvasContainerRef">
					<canvas
						ref="canvasRef"
						@mousemove="handleMouseMove"
						@click="handleClick"
						@wheel="handleWheel"
						@mousedown="handleMouseDown"
						@mouseup="handleMouseUp"
						@mouseleave="handleMouseLeave"
						@contextmenu.prevent
						class="terminal-model-diagram-canvas"
					></canvas>
					
					<!-- Zoom Controls -->
					<div v-if="nodes.length > 0" class="terminal-model-diagram-zoom-controls">
						<button
							@click="zoomIn"
							class="terminal-btn terminal-btn-secondary terminal-model-diagram-zoom-btn"
							title="Zoom In (Mouse Wheel Up)"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
							</svg>
						</button>
						<button
							@click="zoomOut"
							class="terminal-btn terminal-btn-secondary terminal-model-diagram-zoom-btn"
							title="Zoom Out (Mouse Wheel Down)"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
							</svg>
						</button>
						<button
							@click="resetZoom"
							class="terminal-btn terminal-btn-secondary terminal-model-diagram-zoom-btn"
							title="Reset Zoom"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
							</svg>
						</button>
					</div>
					
					<!-- Empty State -->
					<div v-if="nodes.length === 0" class="terminal-model-diagram-empty">
						<div class="terminal-model-diagram-empty-content">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
								<path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
							</svg>
							<h3>Select a Model to View Relationships</h3>
							<p>Choose a model from the list on the left to see its relationships diagram</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-model-diagram {
	flex: 1;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg, #1e1e1e);
	overflow: hidden;
}

.terminal-model-diagram-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary, #252526);
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-model-diagram-title {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--terminal-text, #d4d4d4);
	font-weight: 600;
	font-size: 14px;
}

.terminal-model-diagram-title svg {
	width: 20px !important;
	height: 20px !important;
	max-width: 20px !important;
	max-height: 20px !important;
	flex-shrink: 0;
}

.terminal-model-diagram-beta-badge {
	background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
	color: #ffffff;
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.5px;
	padding: 2px 6px;
	border-radius: 3px;
	text-transform: uppercase;
	margin-left: 4px;
	box-shadow: 0 1px 2px var(--terminal-shadow-medium, rgba(0, 0, 0, 0.2));
}

.terminal-model-diagram-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-model-diagram-controls .terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-model-diagram-zoom-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-model-diagram-search {
	padding: 6px 12px !important;
	background: var(--terminal-bg, #1e1e1e) !important;
	border: 1px solid var(--terminal-border, #3e3e42) !important;
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4) !important;
	font-size: 12px;
	width: 250px;
	outline: none;
}

.terminal-model-diagram-search:focus {
	border-color: var(--terminal-primary, #0e639c) !important;
	background: var(--terminal-bg, #1e1e1e) !important;
	outline: none !important;
}

.terminal-model-diagram-search::placeholder {
	color: var(--terminal-text-muted, #6b7280) !important;
}

.terminal-model-diagram-tabs {
	display: flex;
	gap: 4px;
	padding: 8px 16px;
	background: var(--terminal-bg-secondary, #252526);
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-model-diagram-tab {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 16px;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--terminal-text-secondary, #858585);
	font-size: 12px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-model-diagram-tab svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-model-diagram-tab:hover {
	color: var(--terminal-text, #d4d4d4);
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-model-diagram-tab.active {
	color: var(--terminal-primary, #0e639c);
	border-bottom-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg, #1e1e1e);
}

.terminal-model-diagram-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-model-diagram-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	color: var(--terminal-text-secondary, #858585);
	padding: 40px;
}

.terminal-model-diagram-main {
	flex: 1;
	display: flex;
	overflow: hidden;
}

.terminal-model-diagram-canvas-container {
	flex: 1;
	overflow: hidden;
	background: var(--terminal-bg, #1e1e1e);
	padding: 20px;
	position: relative;
}

.terminal-model-diagram-empty {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--terminal-bg, #1e1e1e);
	z-index: 10;
}

.terminal-model-diagram-empty-content {
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-model-diagram-empty-content svg {
	width: 24px !important;
	height: 24px !important;
	max-width: 24px !important;
	max-height: 24px !important;
	color: var(--terminal-text-secondary, #858585);
	margin-bottom: 16px;
}

/* List View Styles */
.terminal-model-diagram-list {
	width: 450px;
	min-width: 450px;
	background: var(--terminal-bg-secondary, #252526);
	border-right: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	height: 100%;
}

.terminal-model-diagram-list-header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-model-diagram-list-header h3 {
	color: var(--terminal-text, #d4d4d4);
	font-size: 12px;
	font-weight: 600;
	margin: 0;
}

.terminal-model-diagram-list-scroll {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 8px;
	min-height: 0;
}

.terminal-model-diagram-item {
	padding: 8px 12px;
	margin-bottom: 2px;
	border-radius: 4px;
	cursor: pointer;
	transition: background 0.2s;
}

.terminal-model-diagram-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-model-diagram-item.active {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-left: 2px solid var(--terminal-primary, #0e639c);
}

.terminal-model-diagram-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-model-diagram-item-header svg {
	flex-shrink: 0;
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-model-diagram-item-name {
	flex: 1;
	color: var(--terminal-text, #d4d4d4);
	font-size: 12px;
	font-weight: 500;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-model-diagram-details {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-model-diagram-empty-details {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	gap: 16px;
	color: #858585;
}

.terminal-model-diagram-empty-details svg {
	width: 24px !important;
	height: 24px !important;
	max-width: 24px !important;
	max-height: 24px !important;
	flex-shrink: 0;
	color: #858585;
}

.terminal-model-diagram-empty-details h3 {
	color: #d4d4d4;
	font-size: 16px;
	margin: 0;
}

.terminal-model-diagram-empty-details p {
	font-size: 12px;
	margin: 0;
}

.terminal-model-diagram-details-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 20px;
	min-height: 0;
	padding-bottom: 72px;
}

.terminal-model-diagram-details-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 20px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-model-diagram-details-header h3 {
	color: var(--terminal-text, #d4d4d4);
	font-size: 16px;
	font-weight: 600;
	margin: 0;
}

.terminal-model-diagram-details-info {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background: var(--terminal-bg-secondary, #252526);
	border-radius: 4px;
	border: 1px solid var(--terminal-border, #3e3e42);
	margin-bottom: 24px;
}

.terminal-model-diagram-info-item {
	display: flex;
	gap: 12px;
}

.terminal-model-diagram-info-label {
	color: var(--terminal-text-secondary, #858585);
	font-size: 12px;
	font-weight: 500;
	min-width: 100px;
}

.terminal-model-diagram-info-value {
	color: var(--terminal-text, #d4d4d4);
	font-size: 12px;
	font-family: 'Courier New', monospace;
}

.terminal-model-diagram-relationships {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-height: 0;
	overflow: hidden;
}

.terminal-model-diagram-relationships h4 {
	color: var(--terminal-text, #d4d4d4);
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 16px 0;
	flex-shrink: 0;
}

.terminal-model-diagram-no-relationships {
	padding: 40px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-model-diagram-no-relationships p {
	margin: 0;
	font-size: 12px;
}

.terminal-model-diagram-empty-content h3 {
	color: var(--terminal-text, #d4d4d4);
	font-size: 18px;
	font-weight: 600;
	margin-bottom: 8px;
}

.terminal-model-diagram-empty-content p {
	color: var(--terminal-text-secondary, #858585);
	font-size: 14px;
}

.terminal-model-diagram-canvas {
	background: var(--terminal-bg, #1e1e1e);
	cursor: pointer;
	display: block;
}

.terminal-model-diagram-zoom-controls {
	position: absolute;
	top: 20px;
	right: 20px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	z-index: 20;
}

.terminal-model-diagram-zoom-btn {
	padding: 8px;
	min-width: 36px;
	height: 36px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: color-mix(in srgb, var(--terminal-bg-secondary, #252526) 95%, transparent);
	border: 1px solid var(--terminal-border, #3e3e42);
	backdrop-filter: blur(10px);
}

.terminal-model-diagram-zoom-btn:hover {
	background: color-mix(in srgb, var(--terminal-bg-tertiary, #2d2d30) 95%, transparent);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-model-diagram-sidebar {
	width: 300px;
	background: var(--terminal-bg-secondary, #252526);
	border-right: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	flex-shrink: 0;
}

.terminal-model-diagram-info {
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	flex-direction: column;
	max-height: 40vh;
	overflow: hidden;
}

.terminal-model-diagram-info-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.terminal-model-diagram-info-title {
	color: var(--terminal-accent, #4ec9b0);
	font-size: 14px;
	font-weight: 600;
	margin: 0;
}

.terminal-model-diagram-relationships-scroll {
	flex: 1;
	overflow-y: auto;
	min-height: 0;
}

.terminal-model-diagram-relationships-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-model-diagram-relationship-item {
	padding: 8px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 4px;
	font-size: 11px;
}

.terminal-model-diagram-relationship-method {
	color: var(--terminal-accent, #4ec9b0);
	font-weight: 600;
	margin-bottom: 4px;
}

.terminal-model-diagram-relationship-details {
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--terminal-text-secondary, #858585);
}

.relationship-arrow {
	color: var(--terminal-text-secondary, #858585);
}

.relationship-model {
	color: var(--terminal-text, #d4d4d4);
}

.relationship-type {
	color: var(--terminal-text-muted, #6b7280);
	font-size: 10px;
}

.terminal-model-diagram-legend {
	padding: 8px 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}

.terminal-model-diagram-legend-title {
	color: var(--terminal-text-secondary, #858585);
	font-size: 11px;
	font-weight: 600;
	margin: 0;
	white-space: nowrap;
}

.terminal-model-diagram-legend-items {
	display: flex;
	flex-direction: row;
	gap: 16px;
	flex-wrap: wrap;
	align-items: center;
}

.terminal-model-diagram-legend-item {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 11px;
	color: var(--terminal-text-secondary, #858585);
	white-space: nowrap;
}

.legend-color {
	width: 12px;
	height: 2px;
	border-radius: 1px;
	flex-shrink: 0;
}

.terminal-model-diagram-models-list {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-model-diagram-models-title {
	color: var(--terminal-text, #d4d4d4);
	font-size: 12px;
	font-weight: 600;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-model-diagram-models-scroll {
	flex: 1;
	overflow-y: auto;
	padding: 8px;
}

.terminal-model-diagram-model-item {
	padding: 8px 12px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 12px;
	cursor: pointer;
	border-radius: 4px;
	transition: all 0.2s;
	font-family: 'Courier New', monospace;
}

.terminal-model-diagram-model-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-model-diagram-model-item.active {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	font-weight: 500;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	gap: 4px;
}

.terminal-btn-secondary {
	background: var(--terminal-border, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-btn-secondary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary, #858585);
	padding: 4px;
	border: none;
	min-width: auto;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-btn-close:hover {
	background: var(--terminal-border, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-btn-sm {
	padding: 4px 6px;
	min-width: 24px;
	font-size: 11px;
}

.spinner {
	width: 12px;
	height: 12px;
	border: 2px solid var(--terminal-border, #3e3e42);
	border-top-color: var(--terminal-accent, #4ec9b0);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* Scrollbar */
.terminal-model-diagram-models-scroll::-webkit-scrollbar,
.terminal-model-diagram-relationships-scroll::-webkit-scrollbar {
	width: 10px;
}

.terminal-model-diagram-models-scroll::-webkit-scrollbar-track,
.terminal-model-diagram-relationships-scroll::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
}

.terminal-model-diagram-models-scroll::-webkit-scrollbar-thumb,
.terminal-model-diagram-relationships-scroll::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
}

.terminal-model-diagram-models-scroll::-webkit-scrollbar-thumb:hover,
.terminal-model-diagram-relationships-scroll::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

/* Firefox scrollbar styling */
.terminal-model-diagram-models-scroll,
.terminal-model-diagram-relationships-scroll {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}
</style>

