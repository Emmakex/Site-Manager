import { timingSafeEqual } from 'node:crypto';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { createMcpHandler } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import type { BridgeConfig } from './config.js';
import { SiteManagerClient } from './site-manager-client.js';
import { createSiteManagerMcpServer } from './server.js';

function json(res: ServerResponse, status: number, body: Record<string, unknown>): void {
  res.statusCode = status;
  res.setHeader('content-type', 'application/json; charset=utf-8');
  res.setHeader('cache-control', 'no-store');
  res.end(JSON.stringify(body));
}

function secureEqual(left: string, right: string): boolean {
  const a = Buffer.from(left, 'utf8');
  const b = Buffer.from(right, 'utf8');
  return a.length === b.length && timingSafeEqual(a, b);
}

export function secureBearerMatches(header: string | undefined, token: string): boolean {
  if (header === undefined || !header.startsWith('Bearer ')) {
    return false;
  }
  const presented = header.slice(7);
  return presented !== '' && secureEqual(presented, token);
}

export function startHttpServer(config: BridgeConfig) {
  const client = new SiteManagerClient(config);
  const mcp = createMcpHandler(() => createSiteManagerMcpServer(client, config.connectionId));
  const handle = toNodeHandler(mcp, {
    onerror() {
      console.error('[site-manager-mcp] MCP transport error');
    },
  });

  const httpServer = createServer(async (req: IncomingMessage, res: ServerResponse) => {
    let pathname = '/';
    try {
      pathname = new URL(req.url ?? '/', 'http://localhost').pathname;
    } catch {
      json(res, 400, { ok: false, code: 'invalid_url' });
      return;
    }

    if (pathname === '/healthz') {
      if (req.method !== 'GET') {
        json(res, 405, { ok: false, code: 'method_not_allowed' });
        return;
      }
      json(res, 200, {
        ok: true,
        service: 'site-manager-mcp',
        version: '0.1.0',
        mode: 'read-only',
        connection_id: config.connectionId,
      });
      return;
    }

    if (pathname !== '/mcp') {
      json(res, 404, { ok: false, code: 'not_found' });
      return;
    }

    if (!secureBearerMatches(req.headers.authorization, config.token)) {
      res.setHeader('www-authenticate', 'Bearer');
      json(res, 401, { ok: false, code: 'authentication_failed' });
      return;
    }

    try {
      await handle(req, res);
    } catch {
      console.error('[site-manager-mcp] request failed');
      if (!res.headersSent) {
        json(res, 500, { ok: false, code: 'bridge_error' });
      } else {
        res.end();
      }
    }
  });

  httpServer.listen(config.port, config.host, () => {
    console.error(`[site-manager-mcp] read-only bridge connection=${config.connectionId} listening on ${config.host}:${config.port}`);
  });

  return { httpServer, mcp };
}
