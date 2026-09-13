import * as SecureStore from 'expo-secure-store';

const KEY = 'sopdet_group_nicknames';

export type Nicknames = Record<number, string>;

export async function loadNicknames(): Promise<Nicknames> {
  const raw = await SecureStore.getItemAsync(KEY);
  if (!raw) return {};
  try {
    return JSON.parse(raw) as Nicknames;
  } catch {
    return {};
  }
}

/** Sets (or clears, if `nickname` is blank) a group's local-only display name. */
export async function setNickname(
  groupId: number,
  nickname: string,
): Promise<Nicknames> {
  const current = await loadNicknames();
  const next = { ...current };
  const trimmed = nickname.trim();
  if (trimmed) {
    next[groupId] = trimmed;
  } else {
    delete next[groupId];
  }
  await SecureStore.setItemAsync(KEY, JSON.stringify(next));
  return next;
}
