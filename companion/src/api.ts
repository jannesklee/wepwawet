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

export interface Group {
  id: number;
  name: string;
  token: string;
  isOwner: boolean;
  memberCount: number;
  visible: boolean;
  inviteUrl: string;
  positionsUrl: string;
}

interface GroupsResponse {
  updateUrl: string;
  groups: Group[];
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
    if (!res.ok) console.error('[LocShare] sendPosition failed:', res.status, endpoint);
    return res.ok;
  } catch (e) {
    console.error('[LocShare] sendPosition error:', e);
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
  } catch (e) {
    console.error('[LocShare] stopGuestSharing error:', e);
  }
}

export async function fetchGroups(
  config: AppConfig,
): Promise<GroupsResponse | null> {
  try {
    const res = await fetch(url(config, '/api/groups'), { headers: headers(config) });
    if (!res.ok) {
      console.error('[LocShare] fetchGroups failed:', res.status, await res.text());
      return null;
    }
    return (await res.json()) as GroupsResponse;
  } catch (e) {
    console.error('[LocShare] fetchGroups error:', e);
    return null;
  }
}

export async function createGroup(
  config: AppConfig,
  name: string,
): Promise<Group | null> {
  try {
    const res = await fetch(
      url(config, `/groups?name=${encodeURIComponent(name)}`),
      { method: 'POST', headers: headers(config) },
    );
    if (!res.ok) {
      console.error('[LocShare] createGroup failed:', res.status, await res.text());
      return null;
    }
    return (await res.json()) as Group;
  } catch (e) {
    console.error('[LocShare] createGroup error:', e);
    return null;
  }
}

export async function setGroupVisibility(
  config: AppConfig,
  groupId: number,
  visible: boolean,
): Promise<boolean> {
  try {
    const res = await fetch(
      url(config, `/group/${groupId}/visibility?visible=${visible ? '1' : '0'}`),
      { method: 'POST', headers: headers(config) },
    );
    if (!res.ok) console.error('[LocShare] setGroupVisibility failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[LocShare] setGroupVisibility error:', e);
    return false;
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
    if (!res.ok) {
      console.error('[LocShare] fetchMembers failed:', res.status, await res.text());
      return [];
    }
    return (await res.json()) as Member[];
  } catch (e) {
    console.error('[LocShare] fetchMembers error:', e);
    return [];
  }
}

export async function fetchShares(config: AppConfig): Promise<Share[]> {
  try {
    const res = await fetch(url(config, '/shares'), { headers: headers(config) });
    if (!res.ok) {
      console.error('[LocShare] fetchShares failed:', res.status, await res.text());
      return [];
    }
    return (await res.json()) as Share[];
  } catch (e) {
    console.error('[LocShare] fetchShares error:', e);
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
    if (!res.ok) {
      console.error('[LocShare] createShare failed:', res.status, await res.text());
      return null;
    }
    return (await res.json()) as Share;
  } catch (e) {
    console.error('[LocShare] createShare error:', e);
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
    if (!res.ok) console.error('[LocShare] revokeShare failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[LocShare] revokeShare error:', e);
    return false;
  }
}
