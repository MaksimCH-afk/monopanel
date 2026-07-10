// Exit-IP test (TZ §5.4). The server assembles the profile's URL and makes ONE
// GET to an IP-echo endpoint THROUGH the proxy, then returns the observed
// exit-IP / geo. HTTP(S) profiles use an HTTP(S)-proxy agent; SOCKS5 profiles a
// SOCKS agent (socks5h). Everything is wrapped so any proxy failure surfaces as
// a human-readable reason (mapped to 502 by the caller), never a 500.
//
// Node's global fetch (undici) can't take a per-request SOCKS/HTTP-proxy agent,
// so we use the built-in http/https client with the agent — both proxy agents
// expose an http.Agent-compatible object that tunnels (CONNECT) to the https
// echo target.

import http from 'node:http';
import https from 'node:https';
import { buildString } from '../core/proxystring.js';

export class ProxyTestError extends Error {
  constructor(message, detail) {
    super(message);
    this.detail = detail || message;
  }
}

async function makeAgent(proxyUrl, proto) {
  try {
    if (proto === 'socks5') {
      const { SocksProxyAgent } = await import('socks-proxy-agent');
      return new SocksProxyAgent(proxyUrl);
    }
    const { HttpsProxyAgent } = await import('https-proxy-agent');
    return new HttpsProxyAgent(proxyUrl);
  } catch (e) {
    throw new ProxyTestError('proxy_agent_unavailable', `proxy agent not installed: ${e.message}`);
  }
}

function fetchThroughAgent(targetUrl, agent, timeoutMs) {
  return new Promise((resolve, reject) => {
    const mod = targetUrl.startsWith('https') ? https : http;
    let req;
    let settled = false;
    const finish = (fn, arg) => {
      if (settled) return;
      settled = true;
      clearTimeout(hardTimer);
      fn(arg);
    };

    // Hard wall-clock timeout. The socket `timeout` option only covers idle time
    // on an established socket and is unreliable during the proxy CONNECT/TLS
    // handshake — if the gateway is unreachable the request would otherwise hang.
    // This timer guarantees a bounded failure that the caller maps to 502.
    const hardTimer = setTimeout(() => {
      if (req) req.destroy(new ProxyTestError('timeout', 'timeout'));
      finish(reject, new ProxyTestError('timeout', 'timeout'));
    }, timeoutMs);

    try {
      req = mod.get(targetUrl, { agent, timeout: timeoutMs }, (res) => {
        if (res.statusCode < 200 || res.statusCode >= 300) {
          res.resume();
          return finish(reject, new ProxyTestError('bad_status', `echo returned HTTP ${res.statusCode}`));
        }
        let data = '';
        res.setEncoding('utf8');
        res.on('data', (c) => {
          data += c;
          if (data.length > 1_000_000) req.destroy(new ProxyTestError('too_large', 'echo response too large'));
        });
        res.on('end', () => finish(resolve, data));
      });
    } catch (e) {
      return finish(reject, new ProxyTestError('proxy_unreachable', e.message));
    }
    req.on('timeout', () => req.destroy(new ProxyTestError('timeout', 'timeout')));
    req.on('error', (e) =>
      finish(reject, e instanceof ProxyTestError ? e : new ProxyTestError('proxy_unreachable', e.message))
    );
  });
}

/**
 * Run the exit-IP test for a profile.
 * @param {object} profile
 * @param {object} account  { username, authKey }
 * @param {object} cfg      { echoUrl, timeoutMs }
 * @returns {Promise<{ip, country_iso, city, asn_org, latency_ms}>}
 * @throws {ProxyTestError}
 */
export async function testProfile(profile, account, cfg) {
  const proxyUrl = buildString(profile, account, 'url');
  const agent = await makeAgent(proxyUrl, profile.proto);
  const started = Date.now();

  const raw = await fetchThroughAgent(cfg.echoUrl, agent, cfg.timeoutMs);
  const latency_ms = Date.now() - started;

  let d;
  try {
    d = JSON.parse(raw);
  } catch {
    throw new ProxyTestError('bad_echo', 'echo response was not valid JSON');
  }

  return {
    ip: d.ip || d.query || null,
    // ifconfig.co uses country_iso; other echoes use countryCode/country_code.
    country_iso: d.country_iso || d.country_code || d.countryCode || null,
    city: d.city || null,
    asn_org: d.asn_org || d.org || d.isp || null,
    latency_ms,
  };
}
