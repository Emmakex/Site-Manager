import type { BridgeConfig } from './config.js';

export type SiteManagerReadResource = 'connection' | 'discovery' | 'capabilities' | 'diagnostics';
export type SiteManagerWriteResource =
  | 'write/content/plan'
  | 'write/content/apply'
  | 'write/content/rollback'
  | 'write/navigation/plan'
  | 'write/navigation/apply'
  | 'write/navigation/rollback';

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export class SiteManagerClientError extends Error {
  public constructor(message: string) {
    super(message);
    this.name = 'SiteManagerClientError';
  }
}

export class SiteManagerClient {
  private readonly fetchImpl: typeof fetch;

  public constructor(
    private readonly config: Pick<BridgeConfig, 'restBase' | 'token' | 'connectionId' | 'timeoutMs'>,
    fetchImpl: typeof fetch = fetch,
  ) {
    this.fetchImpl = fetchImpl;
  }

  public async connectionState(): Promise<JsonRecord> {
    const data = await this.request('connection', 'GET');
    if (!isRecord(data)) {
      throw new SiteManagerClientError('Site Manager returned an invalid connection contract.');
    }
    return data;
  }

  public async grantedScopes(): Promise<string[]> {
    const state = await this.connectionState();
    const scopes = state.scopes;
    if (!Array.isArray(scopes)) return [];
    return scopes.filter((scope): scope is string => typeof scope === 'string');
  }

  public async siteRead(): Promise<JsonRecord> {
    const discovery = await this.request('discovery', 'GET');
    const capabilities = await this.request('capabilities', 'GET');
    return {
      connection_id: this.config.connectionId,
      discovery,
      capabilities,
    };
  }

  public async diagnostics(): Promise<JsonRecord> {
    const diagnostics = await this.request('diagnostics', 'GET');
    return {
      connection_id: this.config.connectionId,
      diagnostics,
    };
  }

  public async contentUpdatePlan(input: { post_id: number; fields: JsonRecord; reason?: string }): Promise<unknown> {
    return this.request('write/content/plan', 'POST', input);
  }

  public async contentUpdateApply(input: { plan_id: string; confirmed: boolean }): Promise<unknown> {
    return this.request('write/content/apply', 'POST', input);
  }

  public async contentUpdateRollback(input: { plan_id: string; confirmed: boolean }): Promise<unknown> {
    return this.request('write/content/rollback', 'POST', input);
  }

  public async navigationUpdatePlan(input: { provider: string; target_id: number; fields: JsonRecord; reason?: string }): Promise<unknown> {
    return this.request('write/navigation/plan', 'POST', input);
  }

  public async navigationUpdateApply(input: { plan_id: string; confirmed: boolean }): Promise<unknown> {
    return this.request('write/navigation/apply', 'POST', input);
  }

  public async navigationUpdateRollback(input: { plan_id: string; confirmed: boolean }): Promise<unknown> {
    return this.request('write/navigation/rollback', 'POST', input);
  }

  private async request(resource: SiteManagerReadResource | SiteManagerWriteResource, method: 'GET' | 'POST', body?: unknown): Promise<unknown> {
    const endpoint = new URL(resource, this.config.restBase);
    if (endpoint.origin !== this.config.restBase.origin) {
      throw new SiteManagerClientError('Site Manager target origin changed unexpectedly.');
    }

    const headers: Record<string, string> = {
      accept: 'application/json',
      authorization: `Bearer ${this.config.token}`,
      'user-agent': 'site-manager-mcp/0.3.0',
    };
    const init: RequestInit = {
      method,
      headers,
      redirect: 'error',
      signal: AbortSignal.timeout(this.config.timeoutMs),
    };
    if (method === 'POST') {
      headers['content-type'] = 'application/json';
      init.body = JSON.stringify(body ?? {});
    }

    let response: Response;
    try {
      response = await this.fetchImpl(endpoint, init);
    } catch {
      throw new SiteManagerClientError('Site Manager request failed before a valid response was received.');
    }

    if (!response.ok) {
      throw new SiteManagerClientError(`Site Manager request failed with HTTP ${response.status}.`);
    }

    let payload: unknown;
    try {
      payload = await response.json();
    } catch {
      throw new SiteManagerClientError('Site Manager returned invalid JSON.');
    }

    if (!isRecord(payload) || !Object.prototype.hasOwnProperty.call(payload, 'data')) {
      throw new SiteManagerClientError('Site Manager returned an unexpected response contract.');
    }

    return payload.data;
  }
}
