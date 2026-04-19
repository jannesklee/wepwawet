import * as SecureStore from 'expo-secure-store';

const CONFIG_KEY = 'locshare_config';

export type AuthMode = 'nextcloud' | 'guest';

export interface AppConfig {
  mode: AuthMode;
  serverUrl: string;
  // Nextcloud mode
  username: string;
  appPassword: string;
  // Guest mode
  guestToken: string;
  guestName: string;
  guestDuration: number; // minutes; 0 = no expiry
  // Derived
  isValid: boolean;
}

function isValid(c: Omit<AppConfig, 'isValid'>): boolean {
  if (c.mode === 'nextcloud') {
    return !!(c.serverUrl && c.username && c.appPassword);
  }
  return !!(c.serverUrl && c.guestToken && c.guestName);
}

export async function loadConfig(): Promise<AppConfig | null> {
  const raw = await SecureStore.getItemAsync(CONFIG_KEY);
  if (!raw) return null;
  const parsed = JSON.parse(raw) as Omit<AppConfig, 'isValid'>;
  return { ...parsed, isValid: isValid(parsed) };
}

export async function saveConfig(
  c: Omit<AppConfig, 'isValid'>,
): Promise<AppConfig> {
  const config: AppConfig = { ...c, isValid: isValid(c) };
  await SecureStore.setItemAsync(CONFIG_KEY, JSON.stringify(config));
  return config;
}

export function basicAuthHeader(config: AppConfig): string {
  // btoa is available globally in React Native's JS runtime
  return 'Basic ' + btoa(`${config.username}:${config.appPassword}`);
}

export function normalizeUrl(url: string): string {
  return url.trim().replace(/\/+$/, '');
}

/** Parse a join URL like https://cloud.example.com/apps/locshare/join/{token} */
export function parseInviteUrl(
  url: string,
): { server: string; token: string } | null {
  try {
    const u = new URL(url.trim());
    const parts = u.pathname.split('/').filter(Boolean);
    const joinIdx = parts.indexOf('join');
    if (joinIdx < 0 || joinIdx + 1 >= parts.length) return null;
    return {
      server: `${u.protocol}//${u.host}`,
      token: parts[joinIdx + 1],
    };
  } catch {
    return null;
  }
}
