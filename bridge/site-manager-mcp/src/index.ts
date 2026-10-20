import { loadConfig } from './config.js';
import { startHttpServer } from './http.js';

const config = loadConfig();
const { httpServer, mcp } = startHttpServer(config);

async function shutdown(signal: string): Promise<void> {
  console.error(`[site-manager-mcp] ${signal}; shutting down`);
  httpServer.close();
  await mcp.close();
}

process.once('SIGINT', () => void shutdown('SIGINT'));
process.once('SIGTERM', () => void shutdown('SIGTERM'));
