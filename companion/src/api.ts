import { type AppConfig, type GuestLink, basicAuthHeader, normalizeUrl } from './config';

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
  return `${normalizeUrl(config.serverUrl)}/apps/sopdet${path}`;
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

// Nextcloud mode only - config carries the credentials used to authenticate.
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
    const endpoint = url(config, '/position');
    const body = { lat, lon, acc, alt, speed, bearing: heading };
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: headers(config),
      body: JSON.stringify(body),
    });
    if (!res.ok) console.error('[Sopdet] sendPosition failed:', res.status, endpoint);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] sendPosition error:', e);
    return false;
  }
}

const guestHeaders: HeadersInit = {
  'Content-Type': 'application/json',
  'OCS-APIRequest': 'true',
};

// Guest mode - one call per joined group, since each carries its own
// server/token/name and is otherwise independent of the others.
export async function sendGuestPosition(
  link: GuestLink,
  lat: number,
  lon: number,
  acc: number | null,
  alt: number | null,
  speed: number | null,
  heading: number | null,
): Promise<boolean> {
  try {
    const endpoint = `${normalizeUrl(link.server)}/apps/sopdet/guest/${link.token}`;
    const body = {
      name: link.name,
      lat,
      lon,
      acc,
      alt,
      speed,
      bearing: heading,
      timestamp: Math.floor(Date.now() / 1000),
      ...(link.duration > 0 ? { duration: link.duration } : {}),
    };
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: guestHeaders,
      body: JSON.stringify(body),
    });
    if (!res.ok) console.error('[Sopdet] sendGuestPosition failed:', res.status, endpoint);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] sendGuestPosition error:', e);
    return false;
  }
}

// Public lookup used to label a joined guest group with its real name
// instead of the guest's own display name - see GroupController::info().
export async function fetchGroupInfo(
  server: string,
  token: string,
): Promise<{ name: string; ownerDisplayName: string } | null> {
  try {
    const res = await fetch(
      `${normalizeUrl(server)}/apps/sopdet/join/${token}/info`,
      { headers: guestHeaders },
    );
    if (!res.ok) return null;
    return (await res.json()) as { name: string; ownerDisplayName: string };
  } catch (e) {
    console.error('[Sopdet] fetchGroupInfo error:', e);
    return null;
  }
}

// Same shape as fetchMembers(), but for guest mode - uses the group's
// invite token as the auth boundary instead of a Nextcloud session. The
// `name` param marks the caller's own entry as "me", mirroring
// GroupController::guestPositions().
export async function fetchGuestPositions(link: GuestLink): Promise<Member[]> {
  try {
    const params = new URLSearchParams({ name: link.name });
    const res = await fetch(
      `${normalizeUrl(link.server)}/apps/sopdet/guest/${link.token}/positions?${params}`,
      { headers: guestHeaders },
    );
    if (!res.ok) {
      console.error('[Sopdet] fetchGuestPositions failed:', res.status, await res.text());
      return [];
    }
    return (await res.json()) as Member[];
  } catch (e) {
    console.error('[Sopdet] fetchGuestPositions error:', e);
    return [];
  }
}

export async function stopGuestLink(link: GuestLink): Promise<void> {
  try {
    const params = new URLSearchParams({ name: link.name, stop: '1' });
    await fetch(
      `${normalizeUrl(link.server)}/apps/sopdet/guest/${link.token}?${params}`,
      { method: 'POST', headers: guestHeaders },
    );
  } catch (e) {
    console.error('[Sopdet] stopGuestLink error:', e);
  }
}

export async function fetchGroups(
  config: AppConfig,
): Promise<GroupsResponse | null> {
  try {
    const res = await fetch(url(config, '/api/groups'), { headers: headers(config) });
    if (!res.ok) {
      console.error('[Sopdet] fetchGroups failed:', res.status, await res.text());
      return null;
    }
    return (await res.json()) as GroupsResponse;
  } catch (e) {
    console.error('[Sopdet] fetchGroups error:', e);
    return null;
  }
}

export async function joinGroup(
  config: AppConfig,
  token: string,
): Promise<boolean> {
  try {
    const res = await fetch(
      url(config, `/join/${token}/accept`),
      { method: 'POST', headers: headers(config) },
    );
    if (!res.ok) console.error('[Sopdet] joinGroup failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] joinGroup error:', e);
    return false;
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
      console.error('[Sopdet] createGroup failed:', res.status, await res.text());
      return null;
    }
    return (await res.json()) as Group;
  } catch (e) {
    console.error('[Sopdet] createGroup error:', e);
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
    if (!res.ok) console.error('[Sopdet] setGroupVisibility failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] setGroupVisibility error:', e);
    return false;
  }
}

export async function removeGroupMember(
  config: AppConfig,
  groupId: number,
  userId: string,
): Promise<boolean> {
  try {
    const res = await fetch(
      url(config, `/group/${groupId}/members/${encodeURIComponent(userId)}/remove`),
      { method: 'POST', headers: headers(config) },
    );
    if (!res.ok) console.error('[Sopdet] removeGroupMember failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] removeGroupMember error:', e);
    return false;
  }
}

export async function deleteGroup(
  config: AppConfig,
  groupId: number,
): Promise<boolean> {
  try {
    const res = await fetch(
      url(config, `/group/${groupId}/delete`),
      { method: 'POST', headers: headers(config) },
    );
    if (!res.ok) console.error('[Sopdet] deleteGroup failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] deleteGroup error:', e);
    return false;
  }
}

export async function fetchMembers(
  config: AppConfig,
  groupId: number,
): Promise<Member[]> {
  try {
    const res = await fetch(
      `${normalizeUrl(config.serverUrl)}/apps/sopdet/group/${groupId}/positions`,
      { headers: headers(config) },
    );
    if (!res.ok) {
      console.error('[Sopdet] fetchMembers failed:', res.status, await res.text());
      return [];
    }
    return (await res.json()) as Member[];
  } catch (e) {
    console.error('[Sopdet] fetchMembers error:', e);
    return [];
  }
}

export async function fetchShares(config: AppConfig): Promise<Share[]> {
  try {
    const res = await fetch(url(config, '/shares'), { headers: headers(config) });
    if (!res.ok) {
      console.error('[Sopdet] fetchShares failed:', res.status, await res.text());
      return [];
    }
    return (await res.json()) as Share[];
  } catch (e) {
    console.error('[Sopdet] fetchShares error:', e);
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
      console.error('[Sopdet] createShare failed:', res.status, await res.text());
      return null;
    }
    return (await res.json()) as Share;
  } catch (e) {
    console.error('[Sopdet] createShare error:', e);
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
    if (!res.ok) console.error('[Sopdet] revokeShare failed:', res.status);
    return res.ok;
  } catch (e) {
    console.error('[Sopdet] revokeShare error:', e);
    return false;
  }
}
