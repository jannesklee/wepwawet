import * as SecureStore from 'expo-secure-store';

const CONFIG_KEY = 'wepwawet_config';

export type AuthMode = 'nextcloud' | 'guest';

// One joined group in guest mode. A guest can hold several of these at once
// (one per invite link they've pasted in), each with its own display name
// and share duration, sent to independently on every location update.
export interface GuestLink {
  token: string;
  server: string;
  name: string;
  // The group's own name (e.g. "Mom's Location"), fetched from the server at
  // join time - optional because configs saved before this field existed
  // won't have it; UI code falls back to a placeholder when absent.
  groupName?: string;
  duration: number; // minutes; 0 = no expiry
  enabled: boolean;
}

export interface AppConfig {
  mode: AuthMode;
  serverUrl: string;
  // Nextcloud mode
  username: string;
  appPassword: string;
  // Guest mode
  guestLinks: GuestLink[];
  // Derived
  isValid: boolean;
}

function isValid(c: Omit<AppConfig, 'isValid'>): boolean {
  if (c.mode === 'nextcloud') {
    return !!(c.serverUrl && c.username && c.appPassword);
  }
  return c.guestLinks.length > 0;
}

// Older stored configs held a single guestToken/guestName/guestDuration
// instead of guestLinks; default to an empty list so a stale on-device
// config doesn't crash a raw JSON.parse of SecureStore's contents (both
// here and in locationTask.ts, which reads the store directly).
export function normalizeConfig(parsed: Omit<AppConfig, 'isValid'>): AppConfig {
  const guestLinks = Array.isArray(parsed.guestLinks) ? parsed.guestLinks : [];
  return { ...parsed, guestLinks, isValid: isValid({ ...parsed, guestLinks }) };
}

export async function loadConfig(): Promise<AppConfig | null> {
  const raw = await SecureStore.getItemAsync(CONFIG_KEY);
  if (!raw) return null;
  return normalizeConfig(JSON.parse(raw) as Omit<AppConfig, 'isValid'>);
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

/** Parse a join URL like https://cloud.example.com/apps/wepwawet/join/{token} */
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
