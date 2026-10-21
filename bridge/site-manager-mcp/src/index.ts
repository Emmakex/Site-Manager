import { loadConfig } from './config.js';
import { startHttpServer } from './http.js';
import { SiteManagerClient } from './site-manager-client.js';
import { startRemoteAcceptanceRunner } from './remote-acceptance-runner.js';

const config = loadConfig();
const { httpServer, mcp } = await startHttpServer(config);
const acceptanceRunner = startRemoteAcceptanceRunner(new SiteManagerClient(config));

async function shutdown(signal: string): Promise<void> {
  console.error(`[site-manager-mcp] ${signal}; shutting down`);
  acceptanceRunner.stop();
  httpServer.close();
  await mcp.close();
}

process.once('SIGINT', () => void shutdown('SIGINT'));
process.once('SIGTERM', () => void shutdown('SIGTERM'));
