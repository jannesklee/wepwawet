import { type AppConfig, basicAuthHeader, normalizeUrl } from './config';

export interface Share {
  id: number;
  token: string;
  url: string;
  expiresAt: number | null;
  createdAt: number;
}

export interface Member {
  userId: string;
  displayName: string;
  avatarUrl: string | null;
  hasPosition: boolean;
  lat: number | null;
  lon: number | null;
  acc: number | null;
  updatedAt: number | null;
  isMe: boolean;
  type: 'user' | 'guest';
}

export interface GroupInfo {
  groupId: number;
  groupToken: string;
  inviteUrl: string;
  positionsUrl: string;
  updateUrl: string;
}

function url(config: AppConfig, path: string): string {
  return `${normalizeUrl(config.serverUrl)}/apps/locshare${path}`;
}

function headers(config: AppConfig): HeadersInit {
  const h: Record<string, string> = {
    'Content-Type': 'application/json',
    'OCS-APIRequest': 'true',
  };
  if (config.mode === 'nextcloud') {
    h['Authorization'] = basicAuthHeader(config);
  }
  return h;
}

export async function sendPosition(
  config: AppConfig,
  lat: number,
  lon: number,
  acc: number | null,
  alt: number | null,
  speed: number | null,
  heading: number | null,
): Promise<boolean> {
  try {
    let endpoint: string;
    let body: Record<string, unknown>;

    if (config.mode === 'nextcloud') {
      endpoint = url(config, '/position');
      body = { lat, lon, acc, alt, speed, bearing: heading };
    } else {
      endpoint = url(config, `/guest/${config.guestToken}`);
      body = {
        name: config.guestName,
        lat,
        lon,
        acc,
        alt,
        speed,
        bearing: heading,
        timestamp: Math.floor(Date.now() / 1000),
        ...(config.guestDuration > 0 ? { duration: config.guestDuration } : {}),
      };
    }

    const res = await fetch(endpoint, {
      method: 'POST',
      headers: headers(config),
      body: JSON.stringify(body),
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function stopGuestSharing(config: AppConfig): Promise<void> {
  if (config.mode !== 'guest') return;
  try {
    const params = new URLSearchParams({ name: config.guestName, stop: '1' });
    await fetch(url(config, `/guest/${config.guestToken}?${params}`), {
      method: 'POST',
      headers: headers(config),
    });
  } catch {
    // best-effort
  }
}

export async function fetchGroupInfo(
  config: AppConfig,
): Promise<GroupInfo | null> {
  try {
    const res = await fetch(url(config, '/api/me'), { headers: headers(config) });
    if (!res.ok) return null;
    return (await res.json()) as GroupInfo;
  } catch {
    return null;
  }
}

export async function fetchMembers(
  config: AppConfig,
  groupId: number,
): Promise<Member[]> {
  try {
    const res = await fetch(
      `${normalizeUrl(config.serverUrl)}/apps/locshare/group/${groupId}/positions`,
      { headers: headers(config) },
    );
    if (!res.ok) return [];
    return (await res.json()) as Member[];
  } catch {
    return [];
  }
}

export async function fetchShares(config: AppConfig): Promise<Share[]> {
  try {
    const res = await fetch(url(config, '/shares'), { headers: headers(config) });
    if (!res.ok) return [];
    return (await res.json()) as Share[];
  } catch {
    return [];
  }
}

export async function createShare(
  config: AppConfig,
  durationMinutes: number,
): Promise<Share | null> {
  try {
    const res = await fetch(
      url(config, `/share?duration=${durationMinutes}`),
      { method: 'POST', headers: headers(config) },
    );
    if (!res.ok) return null;
    return (await res.json()) as Share;
  } catch {
    return null;
  }
}

export async function revokeShare(
  config: AppConfig,
  id: number,
): Promise<boolean> {
  try {
    const res = await fetch(url(config, `/share/${id}/revoke`), {
      method: 'POST',
      headers: headers(config),
    });
    return res.ok;
  } catch {
    return false;
  }
}
