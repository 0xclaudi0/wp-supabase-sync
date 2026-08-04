/**
 * Capture WordPress admin screenshots for the WP.org listing.
 *
 *   node tests/shoot-screenshots.mjs <out-dir> <spec.json>
 *
 * Drives a headless Chrome over the DevTools Protocol using nothing but Node 22
 * built-ins — global fetch and global WebSocket — so this adds no npm dependency
 * to a plugin that deliberately ships without a build step.
 *
 * Authentication is a real login: cookies come from a `curl` POST to
 * wp-login.php with the site's actual credentials, and are injected with
 * Network.setCookie. No auth bypass, no test-only plugin.
 *
 * The spec file is a JSON array of { url, out, label }.
 */

const [, , outDir, specPath] = process.argv;

if (!outDir || !specPath) {
	console.error('usage: node shoot-screenshots.mjs <out-dir> <spec.json>');
	process.exit(1);
}

const fs = await import('node:fs/promises');
const path = await import('node:path');

const spec = JSON.parse(await fs.readFile(specPath, 'utf8'));
const cookies = spec.cookies ?? [];
const shots = spec.shots ?? [];
const width = spec.width ?? 1440;
const height = spec.height ?? 900;
const scale = spec.deviceScaleFactor ?? 2;
const endpoint = spec.endpoint ?? 'http://127.0.0.1:9222';

/**
 * One CDP session over a page target's WebSocket.
 */
class Session {
	constructor(ws) {
		this.ws = ws;
		this.next = 1;
		this.pending = new Map();
		this.listeners = new Map();

		ws.addEventListener('message', (event) => {
			const msg = JSON.parse(event.data);

			if (msg.id && this.pending.has(msg.id)) {
				const { resolve, reject } = this.pending.get(msg.id);
				this.pending.delete(msg.id);
				msg.error ? reject(new Error(`${msg.error.message} (${JSON.stringify(msg.error)})`)) : resolve(msg.result);
				return;
			}

			if (msg.method && this.listeners.has(msg.method)) {
				for (const fn of this.listeners.get(msg.method)) fn(msg.params);
			}
		});
	}

	static async open(wsUrl) {
		const ws = new WebSocket(wsUrl);
		await new Promise((resolve, reject) => {
			ws.addEventListener('open', resolve, { once: true });
			ws.addEventListener('error', () => reject(new Error(`could not connect to ${wsUrl}`)), { once: true });
		});
		return new Session(ws);
	}

	send(method, params = {}) {
		const id = this.next++;
		this.ws.send(JSON.stringify({ id, method, params }));
		return new Promise((resolve, reject) => {
			this.pending.set(id, { resolve, reject });
			setTimeout(() => {
				if (this.pending.delete(id)) reject(new Error(`${method} timed out`));
			}, 45000);
		});
	}

	once(method) {
		return new Promise((resolve) => {
			const fn = (params) => {
				this.listeners.get(method).delete(fn);
				resolve(params);
			};
			if (!this.listeners.has(method)) this.listeners.set(method, new Set());
			this.listeners.get(method).add(fn);
		});
	}

	close() {
		this.ws.close();
	}
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Chrome switched /json/new to requiring PUT; try both.
async function newTarget() {
	for (const method of ['PUT', 'GET']) {
		const res = await fetch(`${endpoint}/json/new?about:blank`, { method });
		if (res.ok) return res.json();
	}
	throw new Error('could not open a new Chrome target');
}

const target = await newTarget();
const session = await Session.open(target.webSocketDebuggerUrl);

await session.send('Page.enable');
await session.send('Network.enable');
await session.send('Emulation.setDeviceMetricsOverride', {
	width,
	height,
	deviceScaleFactor: scale,
	mobile: false,
});

for (const cookie of cookies) {
	await session.send('Network.setCookie', cookie);
}

for (const shot of shots) {
	const loaded = session.once('Page.loadEventFired');
	await session.send('Page.navigate', { url: shot.url });
	await loaded;

	// Hide dev-site chrome that is not part of the plugin: the WordPress core
	// update nag dates the screenshot and is not this plugin's UI. Nothing
	// belonging to the plugin is ever hidden.
	if (spec.hideSelectors?.length) {
		await session.send('Runtime.evaluate', {
			expression: `
				(() => {
					const css = document.createElement('style');
					css.textContent = ${JSON.stringify(spec.hideSelectors.join(', '))} + '{display:none !important}';
					document.head.appendChild(css);
				})()
			`,
		});
	}

	// Let webfonts and the admin CSS settle so text is not mid-swap.
	await sleep(shot.settle ?? 1200);

	// captureBeyondViewport gets the whole document, not just the fold — the
	// diagnostics page is far taller than 900px and the point of the screenshot
	// is that all twelve checks are visible at once.
	const { data } = await session.send('Page.captureScreenshot', {
		format: 'png',
		captureBeyondViewport: shot.fullPage !== false,
		optimizeForSpeed: false,
	});

	const file = path.join(outDir, shot.out);
	await fs.writeFile(file, Buffer.from(data, 'base64'));

	const { size } = await fs.stat(file);
	console.log(`  wrote ${shot.out}  (${Math.round(size / 1024)} KB)  ${shot.label ?? ''}`);
}

session.close();
await fetch(`${endpoint}/json/close/${target.id}`).catch(() => {});
