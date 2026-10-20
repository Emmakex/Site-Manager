import type { BridgeConfig } from './config.js';

export type SiteManagerResource = 'discovery' | 'capabilities' | 'diagnostics';

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

  public async siteRead(): Promise<JsonRecord> {
    const discovery = await this.readResource('discovery');
    const capabilities = await this.readResource('capabilities');
    return {
      connection_id: this.config.connectionId,
      discovery,
      capabilities,
    };
  }

  public async diagnostics(): Promise<JsonRecord> {
    const diagnostics = await this.readResource('diagnostics');
    return {
      connection_id: this.config.connectionId,
      diagnostics,
    };
  }

  private async readResource(resource: SiteManagerResource): Promise<unknown> {
    const endpoint = new URL(resource, this.config.restBase);
    if (endpoint.origin !== this.config.restBase.origin) {
      throw new SiteManagerClientError('Site Manager target origin changed unexpectedly.');
    }

    let response: Response;
    try {
      response = await this.fetchImpl(endpoint, {
        method: 'GET',
        headers: {
          accept: 'application/json',
          authorization: `Bearer ${this.config.token}`,
          'user-agent': 'site-manager-mcp/0.1.0',
        },
        redirect: 'error',
        signal: AbortSignal.timeout(this.config.timeoutMs),
      });
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
