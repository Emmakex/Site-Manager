export interface BridgeConfig {
  restBase: URL;
  token: string;
  connectionId: string;
  host: string;
  port: number;
  timeoutMs: number;
}

function required(name: string, env: NodeJS.ProcessEnv): string {
  const value = (env[name] ?? '').trim();
  if (value === '') {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function normalizeRestBase(raw: string): URL {
  const url = new URL(raw);
  const localHttp = url.protocol === 'http:' && ['127.0.0.1', 'localhost', '::1'].includes(url.hostname);
  if (url.protocol !== 'https:' && !localHttp) {
    throw new Error('SITE_MANAGER_REST_BASE must use HTTPS (HTTP is allowed only for localhost development).');
  }
  if (url.username !== '' || url.password !== '') {
    throw new Error('SITE_MANAGER_REST_BASE must not contain credentials.');
  }
  url.search = '';
  url.hash = '';
  if (!url.pathname.endsWith('/')) {
    url.pathname += '/';
  }
  return url;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): BridgeConfig {
  const restBase = normalizeRestBase(required('SITE_MANAGER_REST_BASE', env));
  const token = required('SITE_MANAGER_TOKEN', env);
  const connectionId = required('SITE_MANAGER_CONNECTION_ID', env);
  if (!connectionId.startsWith('smc_')) {
    throw new Error('SITE_MANAGER_CONNECTION_ID must be a Site Manager connection id (smc_...).');
  }

  const port = Number(env.PORT ?? '8787');
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error('PORT must be an integer between 1 and 65535.');
  }

  const timeoutMs = Number(env.SITE_MANAGER_TIMEOUT_MS ?? '10000');
  if (!Number.isInteger(timeoutMs) || timeoutMs < 1000 || timeoutMs > 60000) {
    throw new Error('SITE_MANAGER_TIMEOUT_MS must be an integer between 1000 and 60000.');
  }

  return {
    restBase,
    token,
    connectionId,
    host: (env.HOST ?? '0.0.0.0').trim() || '0.0.0.0',
    port,
    timeoutMs,
  };
}
