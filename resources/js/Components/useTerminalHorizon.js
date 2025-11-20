import { ref } from 'vue';
import axios from 'axios';

export function useTerminalHorizon(api) {
	const horizonInstalled = ref(false);

	// Load Horizon installation status
	async function loadHorizonStatus() {
		try {
			const response = await axios.get(api.horizon.check());
			if (response.data && response.data.success && response.data.result) {
				horizonInstalled.value = response.data.result.installed || false;
			}
		} catch (error) {
			horizonInstalled.value = false;
		}
	}

	return {
		horizonInstalled,
		loadHorizonStatus,
	};
}

